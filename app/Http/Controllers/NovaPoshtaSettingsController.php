<?php

namespace App\Http\Controllers;

use App\Models\NovaPoshtaSetting;
use App\Services\NovaPoshtaService;
use Illuminate\Http\Request;

class NovaPoshtaSettingsController extends Controller
{
    public function index(Request $request)
    {
        $settings = NovaPoshtaSetting::current();

        if ($request->expectsJson()) {
            return response()->json($settings);
        }

        return view('settings.nova-poshta');
    }

    public function save(Request $request)
    {
        $data = $request->validate([
            'api_key' => ['nullable', 'string'],
            'sender_ref' => ['nullable', 'string'],
            'contact_ref' => ['nullable', 'string'],
            'sender_phone' => ['nullable', 'string'],
            'sender_city_ref' => ['nullable', 'string'],
            'sender_warehouse_ref' => ['nullable', 'string'],
        ]);

        $settings = NovaPoshtaSetting::current();
        if ($settings) {
            $settings->fill($data)->save();
        } else {
            $settings = NovaPoshtaSetting::create($data);
        }

        return response()->json($settings);
    }

    public function fetchRefs(Request $request, NovaPoshtaService $service)
    {
        $data = $request->validate([
            'api_key' => ['required', 'string'],
        ]);

        $resp = $service->fetchRefsByApiKey($data['api_key']);

        if (!($resp['success'] ?? false)) {
            return response()->json([
                'message' => $resp['message'] ?? 'Не вдалося отримати рефи',
                'errors' => $resp['errors'] ?? null,
            ], 422);
        }

        return response()->json($resp['data'] ?? []);
    }
}
