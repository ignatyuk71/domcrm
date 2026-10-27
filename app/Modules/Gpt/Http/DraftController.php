<?php

namespace App\Modules\Gpt\Http;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Gpt\Infrastructure\CatalogReader;
use App\Modules\Gpt\Jobs\GenerateDraft;
use App\Modules\Gpt\Models\GptDialogue;
use App\Modules\Gpt\Models\GptRun;
use App\Modules\Gpt\Models\GptSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class DraftController extends Controller
{
    public function upload(Request $request)
    {
        $request->validate([
            'files' => 'required|array|min:1|max:3',
            'files.*' => 'required|file|image|mimes:jpg,jpeg,png,webp|max:5120|dimensions:max_width=12000,max_height=12000',
        ], [
            'files.*.max' => 'Фото має бути не більшим за 5 МБ.',
            'files.*.mimes' => 'Оберіть фото JPG, PNG або WEBP.',
            'files.*.image' => 'Файл має бути зображенням JPG, PNG або WEBP.',
            'files.*.dimensions' => 'Максимальний розмір фото — 12000 × 12000 пікселів.',
            'files.max' => 'За раз можна додати до трьох фото.',
        ]);
        $stored = [];
        $files = [];
        try {
            foreach ($request->file('files') as $file) {
                $extension = match ($file->getMimeType()) {
                    'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
                    default => throw ValidationException::withMessages(['files' => ['Оберіть фото JPG, PNG або WEBP.']]),
                };
                $name = Str::uuid().'.'.$extension;
                $path = $file->storeAs('gpt-drafts/uploads/'.$request->user()->id, $name, 'local');
                if (! $path) {
                    throw ValidationException::withMessages(['files' => ['Не вдалося зберегти фото. Спробуйте ще раз.']]);
                }
                $stored[] = $path;
                $files[] = ['id' => $name, 'name' => $file->getClientOriginalName(), 'size' => $file->getSize(),
                    'url' => route('settings.gpt.attachment', ['file' => $name])];
            }
        } catch (Throwable $error) {
            Storage::disk('local')->delete($stored);
            throw $error;
        }

        return response()->json(['files' => $files], 201);
    }

    public function attachment(Request $request, string $file)
    {
        // Файли доступні лише власнику, який їх завантажив.
        $path = 'gpt-drafts/uploads/'.$request->user()->id.'/'.$file;
        abort_unless(Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), [
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    public function page()
    {
        return view('settings.gpt');
    }

    public function data(Request $request, CatalogReader $catalog)
    {
        return response()->json([
            'settings' => GptSetting::current()->publicData(),
            'groups' => $catalog->groups(),
            'dialogues' => GptDialogue::where('created_by', $request->user()->id)->latest('updated_at')->limit(30)->get(['id', 'title', 'updated_at']),
        ]);
    }

    public function saveSettings(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'enabled' => 'required|boolean', 'mode' => 'sometimes|in:drafts',
            'api_key' => 'nullable|string|max:512|starts_with:sk-',
            'remove_api_key' => 'sometimes|boolean',
            'model' => ['required', 'string', 'max:100', 'regex:/\Agpt-[a-zA-Z0-9._:-]+\z/'],
            'store_policies' => 'nullable|string|max:8000', 'version' => 'required|integer|min:0',
        ], ['*' => 'Перевірте значення поля. Доступний лише режим чернеток.']);
        // Відповідаємо JSON навіть без Accept: ключ не повинен потрапити до old input сесії.
        if ($validator->fails()) {
            return response()->json(['message' => 'Перевірте налаштування GPT.', 'errors' => $validator->errors()], 422);
        }
        $data = $validator->validated();

        return DB::transaction(function () use ($data) {
            DB::table('gpt_settings')->insertOrIgnore(['id' => 1, 'enabled' => false, 'version' => 0,
                'created_at' => now(), 'updated_at' => now()]);
            $settings = GptSetting::query()->lockForUpdate()->findOrFail(1);
            if ((int) $settings->version !== (int) $data['version']) {
                return response()->json(['message' => 'Налаштування вже змінилися. Оновіть їх перед збереженням.'], 409);
            }
            $settings->fill([
                'enabled' => $data['enabled'], 'model' => trim($data['model']),
                'store_policies' => $data['store_policies'] ?? null, 'version' => (int) $settings->version + 1,
            ]);
            if ($data['remove_api_key'] ?? false) {
                $settings->api_key = null;
            } elseif (! empty($data['api_key'])) {
                $settings->api_key = trim($data['api_key']);
            }
            if ($settings->enabled && ! $settings->key()) {
                return response()->json(['message' => 'Додайте окремий API-ключ GPT.', 'errors' => ['api_key' => ['API-ключ обов’язковий для створення чернеток.']]], 422);
            }
            $settings->save();

            return response()->json(['settings' => $settings->publicData()]);
        });
    }

    public function dialogue(Request $request, GptDialogue $dialogue)
    {
        abort_unless((int) $dialogue->created_by === (int) $request->user()->id, 404);

        return response()->json(['dialogue' => $dialogue->only(['id', 'title', 'memory']),
            'runs' => $dialogue->runs()->latest('id')->limit(30)->get()->reverse()->values()->map->publicData()]);
    }

    public function create(Request $request)
    {
        $data = $request->validate([
            'message' => 'required|string|max:3000', 'request_id' => 'required|uuid',
            'dialogue_id' => 'nullable|integer|min:1',
            'gallery_group_id' => 'nullable|integer|exists:ai_photo_groups,id',
            'mode' => 'sometimes|in:drafts',
        ], ['*' => 'Перевірте повідомлення, модель галереї та параметри чернетки.']);
        $message = trim($data['message']);
        if ($message === '') {
            throw ValidationException::withMessages(['message' => ['Введіть повідомлення для тестування.']]);
        }

        $created = false;
        $run = DB::transaction(function () use ($request, $data, $message, &$created) {
            $ownerId = $request->user()->id;
            User::whereKey($ownerId)->lockForUpdate()->firstOrFail();
            $existing = GptRun::where('created_by', $ownerId)->where('request_id', $data['request_id'])->first();
            if ($existing) {
                abort_unless($existing->input_message === $message
                    && (int) $existing->gallery_group_id === (int) ($data['gallery_group_id'] ?? 0)
                    && (empty($data['dialogue_id']) || (int) $existing->gpt_dialogue_id === (int) $data['dialogue_id']), 409, 'Цей ключ запиту вже використано для іншої чернетки.');

                return $existing;
            }
            $settings = GptSetting::current();
            if (! $settings->enabled || ! $settings->key() || ! $settings->modelName()) {
                throw ValidationException::withMessages(['settings' => ['Спочатку налаштуйте та дозвольте ручні чернетки GPT.']]);
            }
            // Перервані завдання не повинні назавжди блокувати ручний тест.
            GptRun::where('created_by', $ownerId)->where(function ($q) {
                $q->where(fn ($p) => $p->where('status', 'pending')->where('created_at', '<', now()->subMinutes(15)))
                    ->orWhere(fn ($p) => $p->where('status', 'running')->where('started_at', '<', now()->subMinutes(3)));
            })->update(['status' => 'failed', 'error_code' => 'expired', 'error_message' => 'Час очікування вичерпано. Повідомлення збережено.', 'finished_at' => now()]);
            if (GptRun::where('created_by', $ownerId)->whereIn('status', ['pending', 'running'])->exists()) {
                abort(409, 'Попередня чернетка ще обробляється. Дочекайтеся результату.');
            }
            $dialogue = ! empty($data['dialogue_id'])
                ? GptDialogue::where('created_by', $ownerId)->findOrFail($data['dialogue_id'])
                : GptDialogue::create(['created_by' => $ownerId, 'title' => Str::limit($message, 90, '…')]);
            $created = true;

            return $dialogue->runs()->create([
                'created_by' => $ownerId, 'request_id' => $data['request_id'], 'input_message' => $message,
                'gallery_group_id' => $data['gallery_group_id'] ?? null,
                'model' => $settings->modelName(), 'settings_version' => $settings->version, 'status' => 'pending',
            ]);
        });
        if ($created) {
            try {
                GenerateDraft::dispatch($run->id)->afterCommit();
            } catch (Throwable) {
                $run->update(['status' => 'failed', 'error_code' => 'queue_unavailable', 'finished_at' => now(),
                    'error_message' => 'Не вдалося поставити чернетку в чергу. Повідомлення збережено; повторіть спробу.']);
            }
        }

        return response()->json(['run' => $run->fresh()->publicData()], $created ? 202 : 200);
    }

    public function show(Request $request, GptRun $run)
    {
        abort_unless((int) $run->created_by === (int) $request->user()->id, 404);

        return response()->json(['run' => $run->publicData()]);
    }
}
