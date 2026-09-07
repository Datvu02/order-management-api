<?php

namespace App\Console\Commands;

use App\Services\ProductService;
use Illuminate\Console\Command;

class DeactivateStaleProductsCommand extends Command
{
    protected $signature = 'products:deactivate-stale {--years=2 : Inactive if no sale within this many years}';

    protected $description = 'Set is_active=false for products with no valid sale in the given years';

    public function handle(ProductService $productService): int
    {
        $years = max(1, (int) $this->option('years'));
        $count = $productService->deactivateStale($years);

        $this->info("Deactivated {$count} stale product(s).");

        return self::SUCCESS;
    }
}
