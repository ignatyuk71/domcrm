<?php

namespace App\Console\Commands;

use App\Http\Requests\LaminateCostRequest;

class ImportLaminateCost extends ImportProductionCostBatch
{
    protected $signature = 'production-costs:import-laminate {--file= : JSON-файл або - для stdin}';

    protected $description = 'Внести погоджений розрахунок склеєного полотна без приватних цін у коді';

    protected string $component = 'laminate';

    protected string $requestClass = LaminateCostRequest::class;
}
