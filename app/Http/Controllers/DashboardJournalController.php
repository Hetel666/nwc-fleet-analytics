<?php

namespace App\Http\Controllers;

use App\Models\EquipmentType;
use App\Services\DashboardEventNoteService;
use App\Services\DashboardService;
use App\Services\EfficiencyDashboardService;
use App\Services\GeofenceViolationsDashboardService;
use App\Services\GeofenceViolationService;
use App\Services\XlsxExportService;
use App\Support\EfficiencyStatus;
use Illuminate\Http\Request;

class DashboardJournalController extends Controller
{
    public function __invoke(Request $request, DashboardService $dashboard, EfficiencyDashboardService $efficiency,
        GeofenceViolationService $transfers, GeofenceViolationsDashboardService $violations, XlsxExportService $xlsx)
    {
        $section = (string) $request->query('section');
        abort_unless(in_array($section, ['efficiency', 'geozones'], true), 422);
        abort_unless(array_key_exists($section, $request->user()->visibleDashboardTabs()), 403);
        $filters = $dashboard->normalizeFilters($request->only(['date_from', 'date_to', 'project_id', 'equipment_type_id', 'ownership_type']), 'export');
        if ($section === 'efficiency') {
            $rows = $efficiency->journalRows([...$filters, 'visible_statuses' => array_keys(EfficiencyStatus::labels())]);
        } else {
            $rows = $transfers->journalRows($filters);
            $violationFilters = [
                'date_from' => $filters['from'], 'date_to' => $filters['to'],
                'project_id' => $filters['project_id'],
                'equipment_type' => filled($filters['equipment_type_id'] ?? null) ? EquipmentType::find($filters['equipment_type_id'])?->name : null,
                'ownership_type' => $filters['ownership_type'], 'status' => null, 'search' => '',
            ];
            foreach ($violations->filteredQuery($violationFilters)->get() as $event) {
                $rows[] = $violations->drilldownRow($event, false);
            }
            $rows = app(DashboardEventNoteService::class)->attachNotes($rows, true);
        }
        $rows = collect($rows)->map(fn (array $row): array => [
            'date' => $row['event_date'] ?? $row['date'] ?? '',
            'unit' => $row['name'] ?? $row['equipment_name'] ?? $row['equipment'] ?? '',
            'type' => $row['vehicle_type'] ?? $row['equipment_type'] ?? '',
            'ownership' => ($row['ownership'] ?? $row['ownership_type'] ?? '') === 'ICARE' ? 'İCARƏ' : ($row['ownership'] ?? $row['ownership_type'] ?? ''),
            'project' => $row['project'] ?? $row['home_project'] ?? '',
            'current_project' => $row['current_project'] ?? $row['project'] ?? '',
            'event' => match ($row['dashboard_key'] ?? '') {
                'geofence_transfers' => 'Geofence Transferləri',
                'geofence_violations' => 'Geofence Pozuntuları',
                default => $row['status_label'] ?? '',
            },
            'start' => $row['started_at'] ?? $row['entered_at'] ?? $row['exited_at'] ?? '',
            'end' => $row['ended_at'] ?? $row['left_at'] ?? $row['last_confirmed_at'] ?? '',
            'value' => $row['engine_hours'] ?? $row['duration'] ?? $row['outside_duration'] ?? '',
            'note' => $row['note'] ?? '',
            'status' => $row['investigation_status_label'] ?? 'Araşdırılır',
        ])->sortBy([['date', 'asc'], ['unit', 'asc']])->values();
        $columns = ['date' => 'Tarix', 'unit' => 'D.Q.N.', 'type' => 'Texnika növü', 'ownership' => 'Sahiblik', 'project' => 'Ev layihəsi', 'current_project' => 'Cari layihə', 'event' => 'Hadisə', 'start' => 'Başlama', 'end' => 'Bitmə', 'value' => 'Müddət', 'note' => 'Qeyd', 'status' => 'Araşdırma statusu'];
        $selected = $request->input('columns', []);
        abort_unless(is_array($selected), 422);
        foreach ($selected as $key => $values) {
            abort_unless(array_key_exists($key, $columns) && is_array($values), 422);
            if ($values !== []) {
                $rows = $rows->filter(fn (array $row): bool => in_array((string) $row[$key], $values, true));
            }
        }
        if ($request->boolean('export')) {
            $content = $xlsx->build(['title' => 'Statuslar jurnalı', 'filters' => [['Dövr', $filters['from'].' - '.$filters['to']]], 'sections' => [[
                'title' => 'Statuslar jurnalı', 'columns' => array_values($columns),
                'rows' => $rows->map(fn (array $row): array => array_values($row))->values()->all(),
            ]]]);

            return response($content, 200, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Content-Disposition' => 'attachment; filename="status-journal-'.$filters['from'].'-'.$filters['to'].'.xlsx"']);
        }

        return view('dashboard.journal', ['rows' => $rows->values(), 'columns' => $columns]);
    }
}
