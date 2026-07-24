<?php

namespace Tests\Unit;

use App\Services\OperationsDiagnosticService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class OperationsDiagnosticServiceTest extends TestCase
{
    public function test_exit_code_contract_maps_statuses(): void
    {
        $service = new OperationsDiagnosticService;

        $this->assertSame(0, $service->exitCode(['status' => OperationsDiagnosticService::OK]));
        $this->assertSame(1, $service->exitCode(['status' => OperationsDiagnosticService::WARN]));
        $this->assertSame(2, $service->exitCode(['status' => OperationsDiagnosticService::FAIL]));
    }

    public function test_diagnostic_check_redacts_sensitive_values(): void
    {
        $service = new OperationsDiagnosticService;
        $method = new ReflectionMethod($service, 'check');

        $check = $method->invoke($service, 'secret.test', 'Secrets', OperationsDiagnosticService::FAIL, 'password=plain token=abc123', [
            'DB_PASSWORD' => 'plain',
            'WIALON_TOKEN' => 'abc123',
            'nested' => [
                'authorization' => 'Bearer value',
                'safe' => 'visible',
            ],
        ]);

        $encoded = json_encode($check, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('plain', $encoded);
        $this->assertStringNotContainsString('abc123', $encoded);
        $this->assertStringNotContainsString('Bearer value', $encoded);
        $this->assertStringContainsString('visible', $encoded);
        $this->assertStringContainsString('[redacted]', $encoded);
    }

    public function test_foreign_geofence_environment_precedence_is_documented(): void
    {
        $config = file_get_contents(__DIR__.'/../../config/fleet.php');
        $envExample = file_get_contents(__DIR__.'/../../.env.example');

        $this->assertStringContainsString("env('FOREIGN_GEOFENCE_POSITION_MAX_AGE_MINUTES', env('FOREIGN_GEOFENCE_STALE_AFTER_MINUTES', 30))", $config);
        $this->assertStringContainsString('FOREIGN_GEOFENCE_POSITION_MAX_AGE_MINUTES=30', $envExample);
        $this->assertStringContainsString('FOREIGN_GEOFENCE_STALE_AFTER_MINUTES=30', $envExample);
    }
}
