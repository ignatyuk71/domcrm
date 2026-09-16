<?php

namespace App\Console\Commands;

use App\Http\Requests\CardboardCostBatchRequest;

class ImportCardboardCostBatch extends ImportProductionCostBatch
{
    protected $signature = 'production-costs:import-cardboard {--file= : JSON-файл або - для stdin}';

    protected $description = 'Внести погоджений розрахунок картону без фінансових даних у коді проєкту';

    protected string $component = 'cardboard';

    protected string $requestClass = CardboardCostBatchRequest::class;
}
