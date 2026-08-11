<?php

namespace Tests\Unit;

use Tests\TestCase;

class HistoricalWorkerComposeTest extends TestCase
{
    public function test_historical_workers_use_the_database_queue_connection(): void
    {
        $compose = file_get_contents(base_path('deploy/docker-compose.production.yml'));

        $this->assertIsString($compose);
        $this->assertStringContainsString(
            '["php", "artisan", "queue:work", "database", "--queue=historical-recalculations"',
            $compose
        );
        $this->assertStringContainsString(
            '["php", "artisan", "queue:work", "database", "--queue=historical-monthly-efficiency"',
            $compose
        );
    }
}
