<?php

namespace App\Http\Controllers;

use App\Models\NovaPayAccount;
use App\Models\NovaPayConnection;
use App\Models\NovaPayOperation;
use App\Models\NovaPaySyncRun;
use App\Services\NovaPay\NovaPayException;
use App\Services\NovaPay\SyncDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class NovaPayController extends Controller
{
    public function index()
    {
        return view('finance.novapay');
    }

    public function data(Request $request)
    {
        $today = CarbonImmutable::now(config('novapay.timezone'))->toDateString();
        $request->mergeIfMissing(['from' => $today, 'to' => $today, 'direction' => 'in']);
        $filters = $request->validate([
            'account_id' => ['nullable', 'integer', 'min:1'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'direction' => ['required', 'in:in,out,all'],
            'search' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        if (CarbonImmutable::parse($filters['from'])->diffInDays(CarbonImmutable::parse($filters['to'])) > 365) {
            throw ValidationException::withMessages(['to' => 'Оберіть період не довший за один рік.']);
        }
        $accounts = NovaPayAccount::query()->with('balances', 'connection')->orderByDesc('enabled')->orderBy('id')->get();
        $account = isset($filters['account_id']) ? $accounts->firstWhere('id', $filters['account_id']) : $accounts->first();
        if (isset($filters['account_id']) && ! $account) {
            abort(404);
        }
        $operations = null;
        $incoming = null;
        $coverageComplete = false;
        if ($account) {
            $query = NovaPayOperation::query()->where('account_id', $account->id)
                ->whereBetween('booked_on', [$filters['from'], $filters['to']]);
            $incoming = (string) (clone $query)->where('direction', 'in')->where('status', 'posted')->sum('amount_minor');
            $coverage = NovaPaySyncRun::query()->where('account_id', $account->id)->where('source', 'operations')->where('status', 'success')
                ->whereBetween('date_from', [$filters['from'], $filters['to']])->distinct()->pluck('date_from')
                ->map(fn ($date) => $date->toDateString())->all();
            $coverageComplete = true;
            for ($day = CarbonImmutable::parse($filters['from']); $day->toDateString() <= $filters['to']; $day = $day->addDay()) {
                if (! in_array($day->toDateString(), $coverage, true)) {
                    $coverageComplete = false;
                    break;
                }
            }
            if ($filters['direction'] !== 'all') {
                $query->where('direction', $filters['direction']);
            }
            if (! empty($filters['search'])) {
                $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['search']).'%';
                $query->where(fn ($builder) => $builder->whereRaw("counterparty LIKE ? ESCAPE '!'", [$term])->orWhereRaw("purpose LIKE ? ESCAPE '!'", [$term]));
            }
            $operations = $query->orderByDesc('booked_on')->orderByDesc('id')->paginate(25)->through(fn ($operation) => [
                'id' => $operation->id, 'booked_on' => $operation->booked_on->toDateString(),
                'direction' => $operation->direction, 'amount_minor' => (string) $operation->amount_minor,
                'status' => $operation->status, 'source_status' => $operation->source_status,
                'counterparty' => $operation->counterparty, 'purpose' => $operation->purpose,
            ]);
        }
        $runs = NovaPaySyncRun::query()
            ->when($account, fn ($query) => $query->where(fn ($q) => $q->where('account_id', $account->id)
                ->orWhere(fn ($q) => $q->where('connection_id', $account->connection_id)->where('source', 'discovery'))))
            ->orderByDesc('id')->limit(20)->get();

        return response()->json([
            'accounts' => $accounts->map(fn ($item) => [
                'id' => $item->id, 'connection_id' => $item->connection_id,
                'client_name' => $item->client_name, 'iban' => $item->maskedIban(), 'currency' => $item->currency,
                'timezone' => $item->timezone, 'enabled' => $item->enabled, 'requires_auth' => $item->connection->requires_auth,
            ]),
            'connections' => NovaPayConnection::query()->get()->map(fn ($item) => [
                'id' => $item->id, 'verified_at' => $item->verified_at?->toIso8601String(), 'requires_auth' => $item->requires_auth,
            ]),
            'account_id' => $account?->id,
            'balance' => $account?->balances->firstWhere('type', 'available') ? [
                'amount_minor' => (string) $account->balances->firstWhere('type', 'available')->amount_minor,
                'received_at' => $account->balances->firstWhere('type', 'available')->received_at->toIso8601String(),
                'type' => 'available',
            ] : null,
            'incoming_minor' => $coverageComplete ? $incoming : null,
            'coverage_complete' => $coverageComplete,
            'operations_verified' => (bool) config('novapay.operations_verified'),
            'operations' => $operations,
            'sync' => $runs->map(fn ($run) => [
                'id' => $run->id, 'source' => $run->source, 'status' => $run->status,
                'date_from' => $run->date_from?->toDateString(), 'date_to' => $run->date_to?->toDateString(),
                'created_at' => $run->created_at->toIso8601String(),
                'succeeded_at' => $run->succeeded_at?->toIso8601String(),
                'error_code' => $run->error_code, 'error_message' => $run->error_message,
            ]),
            'operations_updated_at' => $account ? NovaPaySyncRun::query()->where('account_id', $account->id)
                ->where('source', 'operations')->whereNotNull('succeeded_at')->orderByDesc('succeeded_at')->first()?->succeeded_at?->toIso8601String() : null,
            'refresh_minutes' => config('novapay.refresh_minutes'),
        ]);
    }

    public function saveConnection(Request $request, SyncDispatcher $dispatcher)
    {
        $data = $request->validate([
            'connection_id' => ['nullable', 'integer', 'exists:novapay_connections,id'],
            'login' => ['required_without:connection_id', 'nullable', 'string', 'max:255'],
            'refresh_token' => ['required', 'string', 'max:16000'],
            'public_certificate' => ['required', 'string', 'max:32000'],
        ], [
            'login.required_without' => 'Вкажіть логін бізнес-кабінету NovaPay.',
            'refresh_token.required' => 'Вставте новий Refresh Token із налаштувань API NovaPay.',
            'public_certificate.required' => 'Вставте відкритий сертифікат із налаштувань API NovaPay.',
        ]);
        $existing = isset($data['connection_id']) ? NovaPayConnection::findOrFail($data['connection_id']) : null;
        $login = $existing?->login ?? trim($data['login']);
        $id = $existing?->id ?? NovaPayConnection::query()->where('login', $login)->value('id');
        // Нова пара токенів не повинна перезаписати одночасну ротацію авторизації.
        $lock = Cache::lock('novapay:auth:'.($id ?? 'new:'.hash('sha256', $login)), 45);
        if (! $lock->get()) {
            throw ValidationException::withMessages(['refresh_token' => 'Авторизація вже оновлюється. Повторіть збереження за кілька секунд.']);
        }
        try {
            $connection = DB::transaction(function () use ($data, $login, $request) {
                return NovaPayConnection::updateOrCreate(['login' => $login], [
                    'refresh_token' => trim($data['refresh_token']), 'public_certificate' => trim($data['public_certificate']),
                    'access_token' => null, 'token_expires_at' => null, 'requires_auth' => false,
                    'verified_at' => null, 'updated_by' => $request->user()->id,
                ]);
            });
        } finally {
            $lock->release();
        }
        try {
            $run = $dispatcher->enqueue($connection, 'discovery');
        } catch (NovaPayException $e) {
            return response()->json(['message' => 'Параметри збережено. '.$e->getMessage(), 'connection_id' => $connection->id], 503);
        }

        return response()->json(['connection_id' => $connection->id, 'run_id' => $run?->id], 202);
    }

    public function discover(NovaPayConnection $connection, SyncDispatcher $dispatcher)
    {
        if ($connection->requires_auth) {
            return response()->json(['message' => 'Оновіть параметри авторизації NovaPay.'], 422);
        }

        return response()->json(['run_id' => $dispatcher->enqueue($connection, 'discovery')?->id], 202);
    }

    public function updateAccount(Request $request, NovaPayAccount $account, SyncDispatcher $dispatcher)
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $account->update([
            'enabled' => $data['enabled'],
            'import_from' => $account->import_from ?? CarbonImmutable::now($account->timezone)->subDays(max(1, config('novapay.initial_days')) - 1)->toDateString(),
        ]);
        if ($account->enabled) {
            $dispatcher->refresh($account);
        }

        return response()->json(['enabled' => $account->enabled]);
    }

    public function refresh(NovaPayAccount $account, SyncDispatcher $dispatcher)
    {
        if (! $account->enabled || $account->connection->requires_auth) {
            return response()->json(['message' => 'Увімкніть рахунок і перевірте авторизацію NovaPay.'], 422);
        }

        return response()->json(['queued' => $dispatcher->refresh($account)], 202);
    }
}
