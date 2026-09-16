<?php

namespace App\Console\Commands;

use App\Http\Requests\FoamCostRequest;

class ImportFoamCost extends ImportProductionCostBatch
{
    protected $signature = 'production-costs:import-foam {--file= : JSON-файл або - для stdin}';

    protected $description = 'Внести погоджений розрахунок поролонової вставки без фінансових даних у коді';

    protected string $component = 'foam';

    protected string $requestClass = FoamCostRequest::class;
}
