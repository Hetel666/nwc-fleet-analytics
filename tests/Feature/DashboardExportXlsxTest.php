<?php

namespace Tests\Feature;

use App\Jobs\GenerateDashboardExportJob;
use App\Models\DashboardExport;
use App\Models\Equipment;
use App\Models\EquipmentDailyStat;
use App\Models\EquipmentType;
use App\Models\Project;
use App\Models\ProjectWialonGroup;
use App\Models\User;
use App\Services\DashboardService;
use App\Services\XlsxExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use ZipArchive;

class DashboardExportXlsxTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_export_downloads_real_xlsx_file(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_ADMIN, 'active' => true]);
        $project = Project::create(['name' => 'Export Project', 'active' => true]);
        ProjectWialonGroup::create([
            'project_id' => $project->id,
            'wialon_group_id' => '601701903',
            'name' => 'Export Project - NWC',
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ]);
        $type = EquipmentType::create(['name' => 'Truck']);
        $equipment = Equipment::create([
            'name' => 'Unit <script>alert(1)</script>',
            'wialon_unit_id' => '1001',
            'equipment_type_id' => $type->id,
            'project_id' => $project->id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'matched_wialon_group_id' => '601701903',
            'active' => true,
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

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $this->assertNotFalse($zip->locateName('xl/workbook.xml'));
        $this->assertNotFalse($zip->locateName('xl/worksheets/sheet1.xml'));
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($path);

        $this->assertStringContainsString('Unit &lt;script&gt;alert(1)&lt;/script&gt;', $sheet);
    }

    public function test_xlsx_export_escapes_formula_like_text_values(): void
    {
        $content = app(XlsxExportService::class)->build([
            'title' => 'Formula safety',
            'filters' => [],
            'sections' => [
                [
                    'title' => 'Rows',
                    'columns' => ['A', 'B', 'C', 'D'],
                    'rows' => [
                        ['=SUM(1,1)', '+SUM(1,1)', '-SUM(1,1)', '@SUM(1,1)'],
                    ],
                ],
            ],
        ]);

        $path = tempnam(sys_get_temp_dir(), 'xlsx-test-');
        file_put_contents($path, $content);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($path);

        $this->assertStringContainsString("'=SUM(1,1)", $sheet);
        $this->assertStringContainsString("'+SUM(1,1)", $sheet);
        $this->assertStringContainsString("'-SUM(1,1)", $sheet);
        $this->assertStringContainsString("'@SUM(1,1)", $sheet);
    }

    public function test_large_dashboard_export_is_queued_and_owned_by_requesting_user(): void
    {
        Queue::fake();
        config(['fleet.dashboard.export_sync_max_days' => 1]);
        $user = User::factory()->create(['role' => User::ROLE_VIEWER, 'active' => true]);

        $response = $this->actingAs($user)->get(route('dashboard.export', [
            'block' => 'overview',
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-02',
        ]));

        $response->assertOk()->assertSee('Excel faylı hazırlanır');

        $export = DashboardExport::query()->firstOrFail();
        $this->assertSame($user->id, $export->user_id);
        $this->assertSame(DashboardExport::STATUS_PENDING, $export->status);
        Queue::assertPushed(
            GenerateDashboardExportJob::class,
            fn (GenerateDashboardExportJob $job): bool => $job->exportId === $export->id
        );

        $this->get(route('dashboard.exports.status', $export))
            ->assertOk()
            ->assertJson(['status' => DashboardExport::STATUS_PENDING]);
    }

    public function test_background_dashboard_export_generates_downloadable_file(): void
    {
        $exportRoot = storage_path('framework/testing/dashboard-exports');
        File::deleteDirectory($exportRoot);
        config(['fleet.dashboard.export_root' => $exportRoot]);
        $user = User::factory()->create(['role' => User::ROLE_VIEWER, 'active' => true]);
        $record = DashboardExport::query()->create([
            'user_id' => $user->id,
            'block' => 'overview',
            'filters' => [
                'from' => '2026-07-01',
                'to' => '2026-07-01',
                'project_id' => null,
                'equipment_type_id' => null,
                'ownership_type' => null,
            ],
            'status' => DashboardExport::STATUS_PENDING,
        ]);

        (new GenerateDashboardExportJob($record->id))->handle(
            app(DashboardService::class),
            app(XlsxExportService::class)
        );

        $record->refresh();
        $this->assertSame(DashboardExport::STATUS_READY, $record->status);
        $this->assertFileExists($exportRoot.DIRECTORY_SEPARATOR.$record->path);

        $this->actingAs($user)
            ->get(route('dashboard.exports.download', $record))
            ->assertOk()
            ->assertDownload($record->file_name);

        File::deleteDirectory($exportRoot);
    }

    public function test_background_export_can_exceed_modal_period_limit(): void
    {
        config([
            'fleet.dashboard.modal_max_period_days' => 7,
            'fleet.dashboard.export_max_period_days' => 366,
        ]);

        $export = app(DashboardService::class)->getDashboardExport([
            'date_from' => '2026-01-01',
            'date_to' => '2026-04-30',
        ], 'least-working');

        $this->assertSame([], $export['sections'][0]['rows']);
    }
}
