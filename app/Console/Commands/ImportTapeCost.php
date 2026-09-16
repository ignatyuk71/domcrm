<?php

namespace App\Console\Commands;

use App\Http\Requests\TapeCostRequest;

class ImportTapeCost extends ImportProductionCostBatch
{
    protected $signature = 'production-costs:import-tape {--file= : JSON-файл або - для stdin}';

    protected $description = 'Внести погоджену партію стрічки без приватних цін у коді';

    protected string $component = 'tape';

    protected string $requestClass = TapeCostRequest::class;
}
