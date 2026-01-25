<?php

namespace App\Console\Commands;

use App\Services\PackingService;
use Illuminate\Console\Command;

class ReleaseStalePackingOrders extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'packing:auto-release';

    /**
     * The console command description.
     */
    protected $description = 'Auto-release stale packing orders';

    public function handle(PackingService $packing): int
    {
        $released = $packing->releaseStaleOrders();
        $this->info("Auto-released: {$released}");

        return Command::SUCCESS;
    }
}
