<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GeofenceViolationsModuleIsolationTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function moduleFiles(): array
    {
        return [
            'controller' => [__DIR__.'/../../app/Http/Controllers/GeofenceViolationsDashboardController.php'],
            'request' => [__DIR__.'/../../app/Http/Requests/GeofenceViolationsDashboardRequest.php'],
            'dashboard service' => [__DIR__.'/../../app/Services/GeofenceViolationsDashboardService.php'],
            'report importer' => [__DIR__.'/../../app/Services/GeofenceViolationReportImporter.php'],
        ];
    }

    #[DataProvider('moduleFiles')]
    public function test_module_does_not_depend_on_legacy_dashboard_or_wialon_services(string $path): void
    {
        $source = (string) file_get_contents($path);

        $this->assertStringNotContainsString('use App\\Services\\DashboardService;', $source);
        $this->assertStringNotContainsString('use App\\Services\\WialonService;', $source);
        $this->assertStringNotContainsString('use App\\Services\\GeofenceViolationService;', $source);
        $this->assertStringNotContainsString('use App\\Models\\UnitForeignGeofenceInterval;', $source);
        $this->assertStringNotContainsString('use App\\Models\\GeofenceEvent;', $source);
    }
}
