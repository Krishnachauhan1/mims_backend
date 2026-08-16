<?php

namespace App\Console\Commands;

use Database\Seeders\ItemSeeder;
use Illuminate\Console\Command;

class SeedItemCatalogCommand extends Command
{
    protected $signature = 'items:seed-catalog {--force : Skip confirmation}';

    protected $description = 'Seed steel rod grades, diameters, and raw material product catalog';

    public function handle(): int
    {
        if (! $this->option('force') && ! $this->confirm(
            'Seed steel grades and raw material items into the items table?',
            true,
        )) {
            $this->warn('Cancelled.');

            return self::SUCCESS;
        }

        $this->call('db:seed', [
            '--class' => ItemSeeder::class,
            '--force' => true,
        ]);

        $this->info('Item catalog seeded (steel rods + consumables).');

        return self::SUCCESS;
    }
}
