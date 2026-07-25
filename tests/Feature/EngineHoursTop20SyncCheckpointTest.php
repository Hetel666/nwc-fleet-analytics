<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\EquipmentType;
use App\Models\Project;
use App\Models\ProjectWialonGroup;
use App\Models\WialonReportSyncItem;
use App\Services\EngineHoursTop20SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class EngineHoursTop20SyncCheckpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_planner_reopens_completed_top20_item_when_no_included_rows_were_saved(): void
    {
        $group = $this->group('601701914');
        $this->equipment('Loader A', '7001', $group);
        $item = $this->checkpoint($group);

        app(EngineHoursTop20SyncService::class)->plan([
            'from' => '2026-07-19',
            'to' => '2026-07-19',
        ]);

        $item->refresh();
        $this->assertSame(WialonReportSyncItem::STATUS_PENDING, $item->status);
        $this->assertSame(0, $item->attempts);
        $this->assertSame(0, $item->rows_received);
        $this->assertSame(0, $item->rows_saved);
    }

    private function group(string $wialonGroupId): ProjectWialonGroup
    {
        $project = Project::query()->create(['name' => 'Project '.$wialonGroupId, 'active' => true]);

        return ProjectWialonGroup::query()->create([
            'project_id' => $project->id,
            'wialon_group_id' => $wialonGroupId,
            'name' => 'Group '.$wialonGroupId,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
        ]);
    }

    private function equipment(string $name, string $wialonId, ProjectWialonGroup $group): Equipment
    {
        $type = EquipmentType::query()->firstOrCreate(['name' => 'Loader']);

        return Equipment::query()->create([
            'name' => $name,
            'wialon_unit_id' => $wialonId,
            'equipment_type_id' => $type->id,
            'project_id' => $group->project_id,
            'project_wialon_group_id' => $group->id,
            'matched_wialon_group_id' => $group->wialon_group_id,
            'ownership_type' => Equipment::OWNERSHIP_NWC,
            'active' => true,
        ]);
    }

    private function checkpoint(ProjectWialonGroup $group): WialonReportSyncItem
    {
        return WialonReportSyncItem::query()->create([
            'sync_type' => WialonReportSyncItem::TYPE_ENGINE_HOURS_TOP20,
            'report_date' => '2026-07-19',
            'wialon_group_id' => $group->wialon_group_id,
            'wialon_group_name' => $group->name,
            'status' => WialonReportSyncItem::STATUS_COMPLETED,
            'attempts' => 2,
            'rows_received' => 1,
            'rows_saved' => 1,
            'finished_at' => Carbon::parse('2026-07-19 12:00:00', 'Asia/Baku'),
        ]);
    }
}
