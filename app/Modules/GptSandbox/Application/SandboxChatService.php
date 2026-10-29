<?php

namespace App\Modules\GptSandbox\Application;

use App\Modules\GptSandbox\Models\SandboxChat;
use App\Modules\GptSandbox\Models\SandboxSetting;
use App\Modules\GptSandbox\Models\SandboxTurn;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class SandboxChatService
{
    public function __construct(private CatalogAssistant $assistant) {}

    public function send(SandboxChat $chat, array $data, ?SandboxTurn $retry = null): SandboxTurn
    {
        $lock = Cache::lock('gpt-sandbox-chat:'.$chat->id, 120);
        if (! $lock->get()) {
            abort(409, 'GPT ще готує відповідь для цієї розмови. Зачекайте та повторіть.');
        }

        try {
            $setting = SandboxSetting::where('user_id', $chat->user_id)->first();
            if (! $setting) {
                throw ValidationException::withMessages(['settings' => 'Спочатку збережіть API-ключ та модель.']);
            }

            $turn = $retry?->refresh() ?? $chat->turns()->where('request_id', $data['request_id'])->first();
            if ($turn?->status === 'completed') {
                return $turn;
            }
            $checkout = $chat->checkout_data;
            if (($checkout['status'] ?? null) === 'ready_for_manager'
                || (($checkout['status'] ?? null) === 'needs_human' && ! empty($checkout['paused_until'])
                    && \Carbon\Carbon::parse($checkout['paused_until'])->isFuture())) {
                throw ValidationException::withMessages(['text' => 'Розмову передано на опрацювання. Для нового тесту почніть нову розмову.']);
            }

            if ($chat->turns()->where('status', '!=', 'completed')
                ->when($turn, fn ($q) => $q->where('id', '!=', $turn->id))->exists()) {
                abort(409, 'Спочатку повторіть попередню відповідь або почніть нову розмову.');
            }

            if (! $turn) {
                $images = [];
                try {
                    foreach ($data['images'] ?? [] as $file) {
                        $path = $file->store('gpt-sandbox/'.$chat->user_id.'/'.$chat->id, 'local');
                        if (! $path) {
                            throw new SandboxFailure('Не вдалося зберегти фото. Повторіть завантаження.');
                        }
                        $images[] = ['path' => $path, 'mime' => $file->getMimeType(), 'name' => mb_substr($file->getClientOriginalName(), 0, 180)];
                    }
                    $turn = $chat->turns()->create([
                        'request_id' => $data['request_id'], 'text' => $data['text'] ?? null,
                        'images' => $images, 'model' => $setting->model,
                    ]);
                } catch (\Throwable $e) {
                    Storage::disk('local')->delete(array_column($images, 'path'));
                    throw $e;
                }
            }

            $turn->update(['status' => 'pending', 'error' => null, 'model' => $setting->model]);
            try {
                $result = $this->assistant->reply($setting->api_key, $setting->model, $this->input($chat, $turn), $chat->checkout_data,
                    $setting->meta_connection_id, $this->recentPhotoIds($chat), ['turn_id' => $turn->id, 'has_image' => count($turn->images ?? []) > 0]);
                DB::transaction(function () use ($chat, $turn, $result) {
                    $chat->update(['checkout_data' => $result['checkout']]);
                    $turn->update(['status' => 'completed', 'reply' => $result['text'], 'response_output' => $result['output'],
                        'gallery_photos' => $result['photos'], 'checkout_messages' => $result['checkout_messages']]);
                });
                SandboxSetting::recordCheck($chat->user_id, $setting->api_key);
            } catch (SandboxFailure $e) {
                $turn->update(['status' => 'failed', 'error' => $e->getMessage()]);
                SandboxSetting::recordCheck($chat->user_id, $setting->api_key, $e->getMessage());
            }

            return $turn->refresh();
        } finally {
            $lock->release();
        }
    }

    private function input(SandboxChat $chat, SandboxTurn $current): array
    {
        $turns = $chat->turns()->where('id', '<=', $current->id)->latest('id')->limit(20)->get()->reverse()->values();
        // Обмежуємо також загальну кількість фото з історії, щоб не вичерпати пам’ять PHP.
        $visionPaths = $turns->reverse()->flatMap(fn ($turn) => array_reverse($turn->images ?? []))
            ->take(3)->pluck('path')->all();
        $input = [];
        foreach ($turns as $turn) {
            $content = [];
            if (trim((string) $turn->text) !== '') {
                $content[] = ['type' => 'input_text', 'text' => $turn->text];
            }
            foreach ($turn->images ?? [] as $image) {
                if (! in_array($image['path'], $visionPaths, true)) {
                    $content[] = ['type' => 'input_text', 'text' => '(У цій репліці було фото.)'];

                    continue;
                }
                if (! Storage::disk('local')->exists($image['path'])) {
                    throw new SandboxFailure('Збережене фото недоступне. Почніть нову розмову та додайте його знову.');
                }
                $content[] = ['type' => 'input_image', 'image_url' => 'data:'.$image['mime'].';base64,'.base64_encode(Storage::disk('local')->get($image['path'])), 'detail' => 'auto'];
            }
            $input[] = ['role' => 'user', 'content' => $content];
            if ($turn->status === 'completed') {
                if ($turn->model === $current->model) {
                    // Повторно передаємо всі елементи, включно з reasoning та phase.
                    array_push($input, ...($turn->response_output ?? []));
                } else {
                    // Службові елементи іншої моделі можуть бути несумісними; зміст діалогу зберігаємо.
                    $input[] = ['role' => 'assistant', 'content' => $turn->reply];
                }
                foreach ($turn->checkout_messages ?? [] as $message) {
                    $input[] = ['role' => 'assistant', 'content' => $message];
                }
            }
        }

        // Та сама часова позначка магазину, без зміни правил привітання.
        if ($input && ($input[array_key_last($input)]['role'] ?? null) === 'user') {
            $input[array_key_last($input)]['content'][] = ['type' => 'input_text', 'text' => ConversationNotes::clockNote()];
        }

        return $input;
    }

    /** Останні десять вихідних повідомлень, як у робочому агенті магазину. */
    private function recentPhotoIds(SandboxChat $chat): array
    {
        $messages = [];
        foreach ($chat->turns()->where('status', 'completed')->latest('id')->limit(10)->get() as $turn) {
            if ($turn->reply !== '') {
                $messages[] = null;
            }
            foreach (array_reverse($turn->checkout_messages ?? []) as $message) {
                $messages[] = null;
            }
            foreach (array_reverse($turn->gallery_photos ?? []) as $photo) {
                $messages[] = $photo['id'];
            }
            if (count($messages) >= 10) {
                break;
            }
        }

        return array_values(array_filter(array_slice($messages, 0, 10), fn ($id) => $id !== null));
    }
}
