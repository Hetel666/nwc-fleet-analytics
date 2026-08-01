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
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
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
        Storage::fake('dashboard_exports');
        $user = User::factory()->create(['role' => User::ROLE_VIEWER, 'active' => true]);
        $record = $this->exportRecord($user);

        (new GenerateDashboardExportJob($record->id))->handle(
            app(DashboardService::class),
            app(XlsxExportService::class)
        );

        $record->refresh();
        $this->assertSame(DashboardExport::STATUS_READY, $record->status);
        $this->assertSame('dashboard_exports', $record->disk);
        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $record->mime_type);
        $this->assertGreaterThan(0, $record->file_size);
        Storage::disk('dashboard_exports')->assertExists($record->path);

        $this->actingAs($user)
            ->get(route('dashboard.exports.download', $record))
            ->assertOk()
            ->assertDownload($record->file_name);
    }

    public function test_export_job_and_database_queue_have_explicit_runtime_policies(): void
    {
        $job = new GenerateDashboardExportJob(1);

        $this->assertSame(2, $job->tries);
        $this->assertSame(1800, $job->timeout);
        $this->assertTrue($job->failOnTimeout);
        $this->assertSame([120], $job->backoff());
        $this->assertSame(2100, config('queue.connections.database.retry_after'));
        $this->assertTrue(config('queue.connections.database.after_commit'));
    }

    public function test_background_export_job_is_idempotent_after_success(): void
    {
        Storage::fake('dashboard_exports');
        $user = User::factory()->create(['role' => User::ROLE_VIEWER, 'active' => true]);
        $record = $this->exportRecord($user);
        $job = new GenerateDashboardExportJob($record->id);

        $job->handle(app(DashboardService::class), app(XlsxExportService::class));
        $firstPath = $record->refresh()->path;
        $job->handle(app(DashboardService::class), app(XlsxExportService::class));

        $this->assertSame($firstPath, $record->refresh()->path);
        $this->assertCount(1, Storage::disk('dashboard_exports')->allFiles());
    }

    public function test_export_download_is_forbidden_for_another_user(): void
    {
        Storage::fake('dashboard_exports');
        $owner = User::factory()->create(['role' => User::ROLE_VIEWER, 'active' => true]);
        $other = User::factory()->create(['role' => User::ROLE_VIEWER, 'active' => true]);
        $record = $this->readyExportRecord($owner);

        $this->actingAs($other)
            ->get(route('dashboard.exports.download', $record))
            ->assertForbidden();
    }

    public function test_export_download_returns_not_found_when_file_is_missing(): void
    {
        Storage::fake('dashboard_exports');
        $user = User::factory()->create(['role' => User::ROLE_VIEWER, 'active' => true]);
        $record = $this->readyExportRecord($user, storeFile: false);

        $this->actingAs($user)
            ->get(route('dashboard.exports.download', $record))
            ->assertNotFound();
    }

    public function test_export_download_returns_conflict_while_pending(): void
    {
        Storage::fake('dashboard_exports');
        $user = User::factory()->create(['role' => User::ROLE_VIEWER, 'active' => true]);
        $record = $this->exportRecord($user);

        $this->actingAs($user)
            ->get(route('dashboard.exports.download', $record))
            ->assertStatus(409);
    }

    public function test_export_download_returns_gone_after_expiration(): void
    {
        Storage::fake('dashboard_exports');
        $user = User::factory()->create(['role' => User::ROLE_VIEWER, 'active' => true]);
        $record = $this->readyExportRecord($user, expiresAt: now()->subMinute());

        $this->actingAs($user)
            ->get(route('dashboard.exports.download', $record))
            ->assertStatus(410);
    }

    public function test_export_write_failure_does_not_mark_record_ready(): void
    {
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('put')->once()->andReturnFalse();
        $disk->shouldReceive('delete')->once()->andReturnTrue();
        Storage::shouldReceive('disk')->with('dashboard_exports')->andReturn($disk);
        $user = User::factory()->create(['role' => User::ROLE_VIEWER, 'active' => true]);
        $record = $this->exportRecord($user);

        try {
            (new GenerateDashboardExportJob($record->id))->handle(
                app(DashboardService::class),
                app(XlsxExportService::class)
            );
            $this->fail('The export job should fail when the disk rejects the write.');
        } catch (RuntimeException) {
            // Expected write failure.
        }

        $record->refresh();
        $this->assertSame(DashboardExport::STATUS_FAILED, $record->status);
        $this->assertNull($record->completed_at);
        $this->assertNull($record->path);
    }

    public function test_prune_command_deletes_expired_export_file_and_record(): void
    {
        Storage::fake('dashboard_exports');
        $user = User::factory()->create(['role' => User::ROLE_VIEWER, 'active' => true]);
        $record = $this->readyExportRecord($user, expiresAt: now()->subMinute());

        $this->artisan('fleet:prune-dashboard-exports')->assertSuccessful();

        $this->assertDatabaseMissing('dashboard_exports', ['id' => $record->id]);
        Storage::disk('dashboard_exports')->assertMissing($record->path);
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

    private function exportRecord(User $user): DashboardExport
    {
        return DashboardExport::query()->create([
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
    }

    private function readyExportRecord(User $user, bool $storeFile = true, mixed $expiresAt = null): DashboardExport
    {
        $path = $user->id.'/test-export.xlsx';

        if ($storeFile) {
            Storage::disk('dashboard_exports')->put($path, 'xlsx-content');
        }

        return DashboardExport::query()->create([
            'user_id' => $user->id,
            'block' => 'overview',
            'filters' => [],
            'status' => DashboardExport::STATUS_READY,
            'disk' => 'dashboard_exports',
            'path' => $path,
            'file_name' => 'dashboard.xlsx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'file_size' => 12,
            'completed_at' => now(),
            'expires_at' => $expiresAt ?? now()->addHour(),
        ]);
    }
}
