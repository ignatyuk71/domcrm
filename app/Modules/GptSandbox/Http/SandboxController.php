<?php

namespace App\Modules\GptSandbox\Http;

use App\Http\Controllers\Controller;
use App\Models\AiSetting;
use App\Modules\GptSandbox\Application\SandboxChatService;
use App\Modules\GptSandbox\Application\SandboxFailure;
use App\Modules\GptSandbox\Infrastructure\CatalogReader;
use App\Modules\GptSandbox\Infrastructure\OpenAiClient;
use App\Modules\GptSandbox\Models\SandboxChat;
use App\Modules\GptSandbox\Models\SandboxSetting;
use App\Modules\GptSandbox\Models\SandboxTurn;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class SandboxController extends Controller
{
    private const SETTINGS_MESSAGES = [
        'api_key.string' => 'API-ключ має бути текстом.',
        'api_key.max' => 'API-ключ не може містити понад 512 символів.',
        'api_key.regex' => 'Приберіть пробіли й переноси рядків з API-ключа.',
        'model.required' => 'Оберіть модель або введіть її ID.',
        'model.string' => 'Введіть коректний ID моделі.',
        'model.max' => 'ID моделі не може містити понад 120 символів.',
        'model.regex' => 'ID моделі містить непідтримувані символи.',
    ];

    public function index()
    {
        return view('gpt-sandbox::index');
    }

    public function state(Request $request, CatalogReader $catalog)
    {
        $setting = SandboxSetting::where('user_id', $request->user()->id)->first();
        $chat = SandboxChat::where('user_id', $request->user()->id)->latest('created_at')->latest('id')->first();

        return response()->json([
            'settings' => ($setting ?? new SandboxSetting)->present(),
            'stores' => AiSetting::query()->whereNotNull('meta_connection_id')->with('connection:id,page_name')
                ->get(['id', 'meta_connection_id'])->map(fn ($store) => [
                    'id' => $store->meta_connection_id, 'name' => $store->connection?->page_name ?? 'магазин',
                ])->values()->all(),
            'chat' => $chat?->present(),
            'catalog' => $catalog->overview(),
        ]);
    }

    public function save(Request $request)
    {
        $data = $request->validate([
            'api_key' => ['nullable', 'string', 'max:512', 'regex:/^\S+$/'],
            'model' => ['required', 'string', 'max:120', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9._:-]*$/'],
            'meta_connection_id' => ['sometimes', 'nullable', 'integer', 'exists:ai_settings,meta_connection_id'],
        ], self::SETTINGS_MESSAGES);
        $setting = SandboxSetting::firstOrNew(['user_id' => $request->user()->id]);
        if (! empty($data['api_key'])) {
            if (! $setting->api_key || ! hash_equals($setting->api_key, $data['api_key'])) {
                $setting->verified_at = null;
                $setting->connection_error = null;
            }
            $setting->api_key = $data['api_key'];
        }
        if (! $setting->api_key) {
            throw ValidationException::withMessages(['api_key' => 'Введіть API-ключ OpenAI.']);
        }
        $verification = $request->session()->get('gpt_sandbox_key_verification');
        if (is_array($verification) && ($verification['user_id'] ?? null) === $request->user()->id
            && hash_equals($verification['hash'], hash('sha256', $setting->api_key))
            && $verification['at'] >= now()->subMinutes(10)->timestamp) {
            $setting->verified_at = \Carbon\Carbon::createFromTimestamp($verification['at']);
            $setting->connection_error = null;
        }
        $setting->model = $data['model'];
        if (array_key_exists('meta_connection_id', $data)) {
            $setting->meta_connection_id = $data['meta_connection_id'];
        }
        $setting->save();

        return response()->json(['settings' => $setting->present()]);
    }

    public function connection(Request $request, OpenAiClient $client)
    {
        $data = $request->validate(['api_key' => ['nullable', 'string', 'max:512', 'regex:/^\S+$/']], self::SETTINGS_MESSAGES);
        $key = $data['api_key'] ?? SandboxSetting::where('user_id', $request->user()->id)->first()?->api_key;
        if (! $key) {
            throw ValidationException::withMessages(['api_key' => 'Введіть API-ключ для перевірки.']);
        }
        try {
            $models = $client->models($key);
            $request->session()->put('gpt_sandbox_key_verification', [
                'user_id' => $request->user()->id, 'hash' => hash('sha256', $key), 'at' => now()->timestamp,
            ]);
            SandboxSetting::recordCheck($request->user()->id, $key);

            return response()->json(['models' => $models, 'settings' => $this->currentSettings($request->user()->id)]);
        } catch (SandboxFailure $e) {
            $request->session()->forget('gpt_sandbox_key_verification');
            SandboxSetting::recordCheck($request->user()->id, $key, $e->getMessage());

            return response()->json([
                'message' => $e->getMessage(), 'errors' => ['api_key' => [$e->getMessage()]],
                'settings' => $this->currentSettings($request->user()->id),
            ], 422);
        }
    }

    public function newChat(Request $request)
    {
        $chat = SandboxChat::create(['user_id' => $request->user()->id]);

        return response()->json(['chat' => $chat->present()], 201);
    }

    public function send(Request $request, SandboxChat $chat, SandboxChatService $service)
    {
        $this->assertOwner($request, $chat);
        $data = $request->validate([
            'request_id' => ['required', 'uuid'],
            'text' => ['nullable', 'string', 'max:6000', 'required_without:images'],
            'images' => ['nullable', 'array', 'max:3', 'required_without:text'],
            'images.*' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096', 'dimensions:max_width=6000,max_height=6000'],
        ], [
            'request_id.required' => 'Оновіть сторінку та повторіть надсилання.',
            'request_id.uuid' => 'Ідентифікатор запиту некоректний. Оновіть сторінку.',
            'text.required_without' => 'Напишіть повідомлення або додайте фото.',
            'text.string' => 'Повідомлення має бути текстом.',
            'text.max' => 'Повідомлення не може містити понад 6000 символів.',
            'images.required_without' => 'Напишіть повідомлення або додайте фото.',
            'images.array' => 'Додайте фото через кнопку завантаження.',
            'images.max' => 'До одного повідомлення можна додати до 3 фото.',
            'images.*.required' => 'Виберіть файл зображення.',
            'images.*.file' => 'Не вдалося прочитати файл зображення.',
            'images.*.uploaded' => 'Не вдалося завантажити фото. Перевірте його розмір.',
            'images.*.image' => 'Файл має бути зображенням JPG, PNG або WEBP.',
            'images.*.mimes' => 'Дозволені лише фото JPG, PNG або WEBP.',
            'images.*.max' => 'Кожне фото має бути розміром до 4 МБ.',
            'images.*.dimensions' => 'Розміри фото мають бути не більше 6000×6000 пікселів.',
        ]);

        try {
            return $this->turnResponse($service->send($chat, $data));
        } catch (SandboxFailure $e) {
            throw ValidationException::withMessages(['images' => $e->getMessage()]);
        }
    }

    public function retry(Request $request, SandboxChat $chat, SandboxTurn $turn, SandboxChatService $service)
    {
        $this->assertOwner($request, $chat);
        abort_unless($turn->chat_id === $chat->id, 404);

        return $this->turnResponse($service->send($chat, [], $turn));
    }

    public function image(Request $request, SandboxTurn $turn, int $index)
    {
        $this->assertOwner($request, $turn->chat);
        $image = $turn->images[$index] ?? null;
        abort_unless($image && Storage::disk('local')->exists($image['path']), 404);

        return Storage::disk('local')->response($image['path'], $image['name'], [
            'Content-Type' => $image['mime'], 'Cache-Control' => 'private, no-store',
        ]);
    }

    public function catalogImage(int $photo, CatalogReader $catalog)
    {
        $image = $catalog->image($photo);
        abort_unless($image, 404);

        return response()->file($image['path'], ['Content-Type' => $image['mime'], 'Cache-Control' => 'private, no-store']);
    }

    private function assertOwner(Request $request, SandboxChat $chat): void
    {
        abort_unless((int) $chat->user_id === (int) $request->user()->id, 404);
    }

    private function turnResponse(SandboxTurn $turn)
    {
        return response()->json([
            'turn' => $turn->present(),
            'checkout' => $turn->chat->checkout_data,
            'message' => $turn->status === 'completed' ? null : $turn->error,
            'settings' => $this->currentSettings($turn->chat->user_id),
        ], $turn->status === 'completed' ? 200 : 502);
    }

    private function currentSettings(int $userId): array
    {
        return (SandboxSetting::where('user_id', $userId)->first() ?? new SandboxSetting)->present();
    }
}
