<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DashboardReadPathArchitectureTest extends TestCase
{
    #[DataProvider('readPathFiles')]
    public function test_dashboard_read_path_does_not_depend_on_wialon_service(string $file): void
    {
        $source = file_get_contents($file);

        $this->assertIsString($source);
        $this->assertStringNotContainsString('WialonService', $source);
        $this->assertStringNotContainsString('getReportTablesRows', $source);
        $this->assertStringNotContainsString('executeReport', $source);
        $this->assertStringNotContainsString('findReportTemplateIdByName', $source);
    }

    public static function readPathFiles(): array
    {
        $base = dirname(__DIR__, 2).DIRECTORY_SEPARATOR;

        return [
            'DashboardService' => [$base.'app/Services/DashboardService.php'],
            'DashboardController' => [$base.'app/Http/Controllers/DashboardController.php'],
            'ProjectDashboardController' => [$base.'app/Http/Controllers/ProjectDashboardController.php'],
            'DashboardExportController' => [$base.'app/Http/Controllers/DashboardExportController.php'],
            'DashboardDrilldownController' => [$base.'app/Http/Controllers/DashboardDrilldownController.php'],
            'DashboardTopWorkingUnitsExportController' => [$base.'app/Http/Controllers/DashboardTopWorkingUnitsExportController.php'],
            'DashboardOwnershipExportController' => [$base.'app/Http/Controllers/DashboardOwnershipExportController.php'],
        ];
    }
}
