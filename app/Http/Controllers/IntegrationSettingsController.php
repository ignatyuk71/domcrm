<?php

namespace App\Http\Controllers;

use App\Models\OrderSource;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class IntegrationSettingsController extends Controller
{
    public function index()
    {
        $sources = OrderSource::query()
            ->where('is_integration', true)
            ->orderBy('name')
            ->get();

        $intakeUrl = url('/api/v1/orders/intake');

        return view('settings.integrations', compact('sources', 'intakeUrl'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'adapter' => ['required', Rule::in(['custom', 'shopify', 'opencart', 'prom'])],
            'mode' => ['required', Rule::in(['push', 'pull'])],
        ]);

        OrderSource::create([
            'code' => $this->uniqueCode($data['name']),
            'name' => $data['name'],
            'type' => 'order',
            'is_integration' => true,
            'mode' => $data['mode'],
            'adapter' => $data['adapter'],
            'api_key' => $this->newApiKey(),
            'api_secret' => Str::random(64),
            'is_enabled' => true,
        ]);

        return redirect()->route('settings.integrations.index')
            ->with('success', 'Інтеграцію створено. Скопіюйте API-ключ і секрет для налаштування сайту.');
    }

    public function update(Request $request, OrderSource $source)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'adapter' => ['required', Rule::in(['custom', 'shopify', 'opencart', 'prom'])],
            'mode' => ['required', Rule::in(['push', 'pull'])],
            'is_enabled' => ['nullable', 'boolean'],
            'prom_api_key' => ['nullable', 'string', 'max:255'],
        ]);

        $pullConfig = $source->pull_config ?? [];
        if ($request->has('prom_api_key')) {
            $pullConfig['api_key'] = $data['prom_api_key'] ?: null;
        }

        $source->update([
            'name' => $data['name'],
            'adapter' => $data['adapter'],
            'mode' => $data['mode'],
            'is_enabled' => (bool) ($data['is_enabled'] ?? false),
            'pull_config' => $pullConfig,
        ]);

        return redirect()->route('settings.integrations.index')
            ->with('success', 'Зміни збережено.');
    }

    public function regenerate(OrderSource $source)
    {
        $source->update([
            'api_key' => $this->newApiKey(),
            'api_secret' => Str::random(64),
        ]);

        return redirect()->route('settings.integrations.index')
            ->with('success', 'Ключі перевипущено. Онови їх на боці сайту.');
    }

    public function destroy(OrderSource $source)
    {
        if ($source->orders()->exists()) {
            return redirect()->route('settings.integrations.index')
                ->with('error', 'Не можна видалити: у джерела вже є замовлення. Натомість вимкніть інтеграцію.');
        }

        $source->externalProducts()->delete();
        $source->delete();

        return redirect()->route('settings.integrations.index')
            ->with('success', 'Інтеграцію видалено.');
    }

    protected function newApiKey(): string
    {
        return 'dkey_' . Str::random(48);
    }

    protected function uniqueCode(string $name): string
    {
        $base = Str::slug($name) ?: 'site';
        $code = $base;
        $i = 1;
        while (OrderSource::where('code', $code)->exists()) {
            $code = $base . '-' . (++$i);
        }

        return $code;
    }
}
