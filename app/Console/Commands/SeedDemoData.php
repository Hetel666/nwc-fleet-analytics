<?php

namespace App\Console\Commands;

use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;

class SeedDemoData extends Command
{
    protected $signature = 'fleet:seed-demo';

    protected $description = 'Seed demo fleet analytics data.';

    public function handle(): int
    {
        $this->call('db:seed', ['--class' => DemoSeeder::class]);

        return self::SUCCESS;
    }
}
