<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\EfficiencyDashboardService;
use App\Services\XlsxExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class DashboardJournalTest extends TestCase
{
    use RefreshDatabase;

    private function source(): void
    {
        $this->mock(EfficiencyDashboardService::class, function ($mock): void {
            $mock->shouldReceive('journalRows')->once()->with(Mockery::on(fn ($filters) => $filters['from'] === '2026-09-15' && $filters['to'] === '2026-09-30'
                && $filters['visible_statuses'] === ['0_1', 'no_data']))->andReturn([
                    ['event_date' => '2026-09-15', 'name' => '110-FB-814', 'project' => 'LOT3', 'status_label' => '0 - 1 saat arası işləyən', 'note' => 'Təmirdə olub', 'investigation_status_label' => 'Əsaslandırıldı'],
                    ['event_date' => '2026-09-16', 'name' => '110-FB-814', 'project' => 'LOT3', 'status_label' => '0 - 1 saat arası işləyən'],
                ]);
        });
    }

    public function test_journal_keeps_unannotated_events_and_date_specific_notes(): void
    {
        $this->source();
        $this->actingAs(User::factory()->create(['active' => true]))
            ->get(route('dashboard.status-journal', ['section' => 'efficiency', 'date_from' => '2026-09-15', 'date_to' => '2026-09-30']))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->count() === 2
                && $rows[0]['note'] === 'Təmirdə olub' && $rows[1]['note'] === ''
                && $rows[1]['status'] === 'Araşdırılır');
    }

    public function test_export_uses_same_column_filters(): void
    {
        $this->source();
        $this->mock(XlsxExportService::class, function ($mock): void {
            $mock->shouldReceive('build')->once()->with(Mockery::on(fn ($export) => count($export['sections'][0]['rows']) === 1
                && $export['sections'][0]['rows'][0][0] === '2026-09-15'
                && $export['sections'][0]['rows'][0][10] === 'Təmirdə olub'))->andReturn('xlsx');
        });
        $this->actingAs(User::factory()->create(['active' => true]))
            ->get(route('dashboard.status-journal', ['section' => 'efficiency', 'date_from' => '2026-09-15', 'date_to' => '2026-09-30', 'export' => 1, 'columns' => ['status' => ['Əsaslandırıldı']]]))
            ->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_journal_enforces_section_access(): void
    {
        $this->actingAs(User::factory()->create(['active' => true, 'role' => 'viewer', 'dashboard_sections' => ['overview']]))
            ->get(route('dashboard.status-journal', ['section' => 'geozones']))->assertForbidden();
    }
}
