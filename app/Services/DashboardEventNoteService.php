<?php

namespace App\Services;

use App\Models\DashboardEventNote;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DashboardEventNoteService
{
    public const DASHBOARD_GENERAL_EFFICIENCY = 'general_efficiency';
    public const DASHBOARD_GEOFENCE_TRANSFERS = 'geofence_transfers';
    public const DASHBOARD_GEOFENCE_VIOLATIONS = 'geofence_violations';

    /**
     * @return array<string, string>
     */
    public function investigationStatusLabels(): array
    {
        return DashboardEventNote::investigationStatusLabels();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    public function attachNotes(array $rows, bool $includeInvestigationStatus = false): array
    {
        $notes = DashboardEventNote::query()
            ->whereIn('event_key', collect($rows)->pluck('event_key')->filter()->unique()->values()->all())
            ->get()
            ->keyBy('event_key');

        return collect($rows)
            ->map(function (array $row) use ($notes, $includeInvestigationStatus): array {
                $note = $notes->get((string) ($row['event_key'] ?? ''));
                $row['note'] = $note?->note ?? '';

                if ($includeInvestigationStatus) {
                    $row['investigation_status'] = $note?->investigation_status
                        ?: DashboardEventNote::STATUS_INVESTIGATING;
                    $row['investigation_status_options'] = DashboardEventNote::statusLabelsForDashboard((string) ($row['dashboard_key'] ?? ''));
                    $row['investigation_status_label'] = $row['investigation_status_options'][$row['investigation_status']]
                        ?? $row['investigation_status'];
                }

                return $row;
            })
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    public function saveMany(array $items, int $userId): void
    {
        DB::transaction(function () use ($items, $userId): void {
            foreach ($items as $item) {
                $eventKey = trim((string) ($item['event_key'] ?? ''));

                if ($eventKey === '') {
                    continue;
                }

                $note = DashboardEventNote::query()->firstOrNew(['event_key' => $eventKey]);

                $note->fill([
                    'dashboard_key' => (string) $item['dashboard_key'],
                    'event_type' => (string) $item['event_type'],
                    'event_date' => filled($item['event_date'] ?? null) ? Carbon::parse($item['event_date'])->toDateString() : null,
                    'project_id' => filled($item['project_id'] ?? null) ? (int) $item['project_id'] : null,
                    'equipment_id' => filled($item['equipment_id'] ?? null) ? (int) $item['equipment_id'] : null,
                    'wialon_unit_id' => filled($item['wialon_unit_id'] ?? null) ? (string) $item['wialon_unit_id'] : null,
                    'unit_name' => filled($item['unit_name'] ?? null) ? (string) $item['unit_name'] : null,
                    'event_status' => filled($item['event_status'] ?? null) ? (string) $item['event_status'] : null,
                    'note' => filled($item['note'] ?? null) ? (string) $item['note'] : null,
                    'investigation_status' => filled($item['investigation_status'] ?? null)
                        ? (string) $item['investigation_status']
                        : null,
                    'updated_by' => $userId,
                ]);

                if (! $note->exists) {
                    $note->created_by = $userId;
                }

                $note->save();
            }
        });
    }

    public function efficiencyEventKey(object $row): string
    {
        return $this->eventKey([
            self::DASHBOARD_GENERAL_EFFICIENCY,
            'work_status',
            $row->business_date ?? '',
            $row->project_id ?? '',
            $row->wialon_unit_id ?? '',
            $row->efficiency_status ?? '',
        ]);
    }

    public function geofenceTransferEventKey(object $interval): string
    {
        return $this->eventKey([
            self::DASHBOARD_GEOFENCE_TRANSFERS,
            'foreign_geofence_interval',
            optional($interval->entered_at)->toDateString(),
            $interval->wialon_unit_id ?: $interval->unit_id,
            $interval->home_project_id,
            $interval->foreign_project_id,
            $interval->foreign_geofence_id,
            optional($interval->entered_at)->format('Y-m-d H:i:s'),
            optional($interval->left_at)->format('Y-m-d H:i:s'),
        ]);
    }

    public function geofenceViolationEventKey(object $row): string
    {
        return $this->eventKey([
            self::DASHBOARD_GEOFENCE_VIOLATIONS,
            'outside_project_geofence',
            optional($row->exited_at)->toDateString(),
            $row->wialon_unit_id ?: $row->equipment_id,
            $row->project_id,
            $row->last_project_geofence,
            optional($row->exited_at)->format('Y-m-d H:i:s'),
            optional($row->last_confirmed_at)->format('Y-m-d H:i:s'),
            (int) ($row->is_active ?? false),
        ]);
    }

    /**
     * @param  array<int, mixed>  $parts
     */
    private function eventKey(array $parts): string
    {
        $normalized = collect($parts)
            ->map(fn (mixed $part): string => trim((string) ($part ?? '')))
            ->implode('|');

        return sha1($normalized);
    }
}
