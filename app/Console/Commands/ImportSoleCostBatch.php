<?php

namespace App\Console\Commands;

use App\Http\Requests\SoleCostBatchRequest;

class ImportSoleCostBatch extends ImportProductionCostBatch
{
    protected $signature = 'production-costs:import-soles {--file= : JSON-файл або - для stdin}';

    protected $description = 'Внести погоджену партію підошви без фінансових даних у коді проєкту';

    protected string $component = 'soles';

    protected string $requestClass = SoleCostBatchRequest::class;
}
