<?php

namespace App\Console\Commands;

use App\Http\Requests\FurCostRequest;

class ImportFurCost extends ImportProductionCostBatch
{
    protected $signature = 'production-costs:import-fur {--file= : JSON-файл або - для stdin}';

    protected $description = 'Внести погоджену партію хутра без приватних рахунків у коді';

    protected string $component = 'fur';

    protected string $requestClass = FurCostRequest::class;
}
