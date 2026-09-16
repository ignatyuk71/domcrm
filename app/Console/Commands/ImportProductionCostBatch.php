<?php

namespace App\Console\Commands;

use App\Services\Costs\ProductionCostService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

abstract class ImportProductionCostBatch extends Command
{
    protected string $component;

    protected string $requestClass;

    public function handle(ProductionCostService $service): int
    {
        $path = $this->option('file');
        if (! $path || ($path !== '-' && (! is_file($path) || ! is_readable($path)))) {
            $this->error('Передайте --file зі шляхом до JSON або --file=- для stdin.');

            return self::FAILURE;
        }
        $stream = fopen($path === '-' ? 'php://stdin' : $path, 'rb');
        $json = stream_get_contents($stream, 65537);
        fclose($stream);
        if (strlen($json) > 65536) {
            $this->error('Файл завеликий: дозволено до 64 КБ.');

            return self::FAILURE;
        }
        try {
            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->error('Некоректний JSON.');

            return self::FAILURE;
        }
        if (! is_array($payload)) {
            $this->error('Очікується JSON-об’єкт із даними однієї партії.');

            return self::FAILURE;
        }
        $request = $this->requestClass::create('/', 'POST', $payload);
        $validator = Validator::make($payload, $request->rules(), $request->messages(), $request->attributes());
        if (method_exists($request, 'after')) {
            foreach ($request->after() as $callback) {
                $validator->after($callback);
            }
        }
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }
        $data = $validator->validated();
        // Повторний імпорт не перезаписує подальші ручні зміни власника.
        if ($existing = DB::table('production_cost_batches')->where('request_key', $data['request_key'])->first()) {
            if ($existing->component !== $this->component) {
                $this->error('Цей ключ належить іншій складовій. Дані не змінені.');

                return self::FAILURE;
            }
            $this->info('Партія вже існує. Дані не змінені.');

            return self::SUCCESS;
        }
        $result = $service->save($data, null, null, $this->component);
        $this->info('Партію збережено. ID: '.$result['id']);

        return self::SUCCESS;
    }
}
