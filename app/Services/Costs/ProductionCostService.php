<?php

namespace App\Services\Costs;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class ProductionCostService
{
    public function __construct(private SoleCostCalculator $soles, private CardboardCostCalculator $cardboard, private FoamCostCalculator $foam, private FurCostCalculator $fur, private LaminateCostCalculator $laminate, private TapeCostCalculator $tape) {}

    private function calculator(string $component): SoleCostCalculator|CardboardCostCalculator|FoamCostCalculator|FurCostCalculator|LaminateCostCalculator|TapeCostCalculator
    {
        return match ($component) {
            'soles' => $this->soles, 'cardboard' => $this->cardboard, 'foam' => $this->foam, 'fur' => $this->fur, 'laminate' => $this->laminate, 'tape' => $this->tape,
            default => abort(404),
        };
    }

    public function listing(string $component = 'soles', ?int $modelId = null): array
    {
        $this->calculator($component);
        $modelId = app(ProductionCostModels::class)->resolve($modelId);
        $page = DB::table('production_cost_batches')->where('model_id', $modelId)->where('component', $component)->orderByDesc('id')->paginate(20);

        return [
            'data' => array_map(fn ($row) => $this->present($row), $page->items()),
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(),
        ];
    }

    public function save(array $data, ?int $userId, ?int $batchId = null, string $component = 'soles'): array
    {
        $calculator = $this->calculator($component);
        $modelId = app(ProductionCostModels::class)->resolve(isset($data['model_id']) ? (int) $data['model_id'] : null);
        $inputs = $calculator->normalize($data);
        $quantity = in_array($component, ['foam', 'fur', 'laminate', 'tape'], true) ? 1 : (int) $data['quantity'];
        $calculation = $calculator->calculate($quantity, $inputs);
        $values = [
            'model_id' => $modelId, 'name' => trim($data['name']), 'purchased_on' => $data['purchased_on'] ?? null, 'quantity' => $quantity,
            'inputs' => json_encode($inputs, JSON_THROW_ON_ERROR), 'note' => $data['note'] ?? null,
            'total_uah' => number_format($calculation['total_uah'], 2, '.', ''),
            'unit_cost_uah' => $calculation['unit_cost_uah'] === null ? null : number_format($calculation['unit_cost_uah'], 6, '.', ''),
        ];
        try {
            $id = DB::transaction(function () use ($data, $values, $userId, $batchId, $component, $modelId) {
                $previous = null;
                if ($batchId) {
                    $previous = DB::table('production_cost_batches')->where('model_id', $modelId)->where('component', $component)->where('id', $batchId)->lockForUpdate()->first();
                    abort_unless($previous, 404);
                    abort_if((int) $previous->version !== (int) $data['version'], 409, 'Цю партію вже змінили. Оновіть список і відкрийте її ще раз.');
                } elseif ($existing = DB::table('production_cost_batches')->where('request_key', $data['request_key'])->first()) {
                    return $this->sameRetry($existing, $values, $component);
                }
                $write = $values + ['version' => (int) ($previous->version ?? 0) + 1, 'updated_at' => now()];
                if ($previous) {
                    DB::table('production_cost_batches')->where('id', $batchId)->update($write);
                    $id = $batchId;
                } else {
                    $id = DB::table('production_cost_batches')->insertGetId($write + [
                        'component' => $component, 'request_key' => $data['request_key'], 'user_id' => $userId, 'created_at' => now(),
                    ]);
                }
                DB::table('production_cost_batch_revisions')->insert([
                    'batch_id' => $id, 'before' => $previous ? json_encode($previous, JSON_THROW_ON_ERROR) : null,
                    'after' => json_encode($write, JSON_THROW_ON_ERROR), 'user_id' => $userId, 'created_at' => now(),
                ]);

                return $id;
            });
        } catch (UniqueConstraintViolationException $exception) {
            // Одночасні повтори POST мають повертати ту саму партію, а не створювати дубль.
            $existing = ! $batchId ? DB::table('production_cost_batches')->where('request_key', $data['request_key'])->first() : null;
            if (! $existing) {
                throw $exception;
            }
            $id = $this->sameRetry($existing, $values, $component);
        }

        return $this->present(DB::table('production_cost_batches')->where('id', $id)->first());
    }

    private function sameRetry(object $existing, array $values, string $component): int
    {
        abort_unless($existing->component === $component, 409, 'Цей запит належить іншій складовій. Створіть новий розрахунок.');
        abort_unless((int) $existing->model_id === $values['model_id'], 409, 'Цей запит належить іншій категорії капців.');
        $calculator = $this->calculator($component);
        $same = true;
        foreach (['name', 'purchased_on', 'quantity', 'note'] as $field) {
            $same = $same && (string) $existing->$field === (string) $values[$field];
        }
        // Порядок ключів JSON у MySQL може відрізнятися від SQLite.
        $same = $same && $calculator->normalize(json_decode($existing->inputs, true)) === $calculator->normalize(json_decode($values['inputs'], true));
        abort_unless($same, 409, 'Цей запит уже збережений з іншими даними. Оновіть список партій.');

        return (int) $existing->id;
    }

    private function present(object $row): array
    {
        $calculator = $this->calculator($row->component);
        $inputs = $calculator->normalize(json_decode($row->inputs, true));

        return [
            'id' => (int) $row->id, 'model_id' => (int) $row->model_id, 'name' => $row->name, 'purchased_on' => $row->purchased_on,
            'quantity' => (int) $row->quantity, 'inputs' => $inputs, 'note' => $row->note,
            'version' => (int) $row->version, 'updated_at' => $row->updated_at,
            'calculation' => $calculator->calculate((int) $row->quantity, $inputs),
        ];
    }
}
