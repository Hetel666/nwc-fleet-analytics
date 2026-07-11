<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\EquipmentDailyStat;
use App\Models\EquipmentType;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

class DashboardExportXlsxTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_export_downloads_real_xlsx_file(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_ADMIN, 'active' => true]);
        $project = Project::create(['name' => 'Export Project', 'active' => true]);
        $type = EquipmentType::create(['name' => 'Truck']);
        $equipment = Equipment::create([
            'name' => 'Unit <script>alert(1)</script>',
            'wialon_unit_id' => '1001',
            'equipment_type_id' => $type->id,
            'project_id' => $project->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ]);

        EquipmentDailyStat::create([
            'stat_date' => '2026-07-11',
            'equipment_id' => $equipment->id,
            'project_id' => $project->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'worked_hours' => 5.5,
            'distance_km' => 12.3,
        ]);

        $response = $this->actingAs($user)->get(route('dashboard.export', [
            'block' => 'overview',
            'date_from' => '2026-07-11',
            'date_to' => '2026-07-11',
            'project_id' => $project->id,
        ]));

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $content = $response->getContent();

        $this->assertStringContainsString('.xlsx', $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('PK', $content);

        $path = tempnam(sys_get_temp_dir(), 'xlsx-test-');
        file_put_contents($path, $content);

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path));
        $this->assertNotFalse($zip->locateName('xl/workbook.xml'));
        $this->assertNotFalse($zip->locateName('xl/worksheets/sheet1.xml'));
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($path);

        $this->assertStringContainsString('Unit &lt;script&gt;alert(1)&lt;/script&gt;', $sheet);
    }
}
