<?php

namespace App\Http\Controllers;

use App\Models\MetaConnection;
use App\Services\Meta\AdLibraryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MarketResearchController extends Controller
{
    public function index(): View
    {
        $initial = [
            'connections' => MetaConnection::query()
                ->where('status', '!=', 'disconnected')
                ->orderByDesc('id')
                ->get(['id', 'page_id', 'page_name', 'ig_username', 'status', 'webhook_subscribed'])
                ->toArray(),
            'library_token_configured' => filled(config('meta_ad_library.access_token')),
            'countries' => AdLibraryService::COUNTRIES,
        ];

        return view('market-research.index', compact('initial'));
    }

    public function check(Request $request, AdLibraryService $library): JsonResponse
    {
        if (is_string($request->input('search'))) {
            $request->merge(['search' => trim($request->input('search'))]);
        }
        $data = $request->validate([
            'connection_id' => ['nullable', 'integer', Rule::exists('meta_connections', 'id')->whereNot('status', 'disconnected')],
            'search' => ['required', 'string', 'max:100'],
            'country' => ['required', Rule::in(array_keys(AdLibraryService::COUNTRIES))],
        ], [
            'connection_id.exists' => 'Підключення недоступне. Оновіть сторінку або оберіть інше.',
            'search.required' => 'Введіть товар, бренд або ключове слово.',
            'search.max' => 'Запит має містити не більше 100 символів.',
            'country.in' => 'Оберіть країну зі списку.',
        ]);

        $connection = isset($data['connection_id'])
            ? MetaConnection::query()->findOrFail($data['connection_id'])
            : null;

        return response()->json($library->check($connection, $data['search'], $data['country']))
            ->header('Cache-Control', 'no-store, private');
    }
}
