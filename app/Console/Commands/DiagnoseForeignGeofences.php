<?php

namespace App\Console\Commands;

use App\Services\GeofenceViolationService;
use Illuminate\Console\Command;

class DiagnoseForeignGeofences extends Command
{
    protected $signature = 'fleet:diagnose-foreign-geofences
        {--details : Show inclusion or exclusion reason for every unit}
        {--unit= : Limit details to a local equipment id or Wialon unit id}
        {--from= : Dashboard period start date}
        {--to= : Dashboard period end date}';

    protected $description = 'Diagnose the current foreign project geofence dashboard widget.';

    public function handle(GeofenceViolationService $violations): int
    {
        $diagnostics = $violations->diagnostics([
            'unit' => $this->option('unit'),
            'date_from' => $this->option('from'),
            'date_to' => $this->option('to'),
        ]);

        $this->info('Foreign project geofence diagnostics');
        $this->table(['Metric', 'Value'], [
            ['total units', $diagnostics['total_units']],
            ['allowed vehicle type units', $diagnostics['allowed_type_units']],
            ['allowed type units with project', $diagnostics['units_with_project']],
            ['projects with geofence', $diagnostics['projects_with_geofence']],
            ['projects without geofence', $diagnostics['projects_without_geofence'] ?? 0],
            ['Wialon groups without project', $diagnostics['groups_without_project'] ?? 0],
            ['units with fresh position', $diagnostics['units_with_fresh_position']],
            ['units with invalid position', $diagnostics['units_with_invalid_position']],
            ['units with stale position', $diagnostics['units_with_stale_position']],
            ['open intervals', $diagnostics['open_intervals']],
            ['intervals below 3 hours', $diagnostics['intervals_below_minimum']],
            ['intervals 3 hours and above', $diagnostics['intervals_at_or_above_minimum']],
            ['stale intervals', $diagnostics['stale_intervals']],
            ['Dashboard total', $diagnostics['dashboard_total']],
        ]);

        $this->info('Grouping by current foreign project/geofence');

        if (($diagnostics['groups'] ?? []) === []) {
            $this->line('No data.');
        } else {
            $this->table(
                ['Current foreign project/geofence', 'Project', 'Geofence', 'Units'],
                collect($diagnostics['groups'])
                    ->map(fn (array $row): array => [
                        $row['label'] ?? $row['project'] ?? '-',
                        $row['project'] ?? '-',
                        $row['geofence'] ?? '-',
                        (int) ($row['count'] ?? 0),
                    ])
                    ->all()
            );
        }

        $this->info('Consistency check');
        $this->table(['Value', 'Count'], [
            ['diagnose total', $diagnostics['consistency']['diagnose_total'] ?? 0],
            ['donut center', $diagnostics['consistency']['donut_center'] ?? 0],
            ['right table sum', $diagnostics['consistency']['table_sum'] ?? 0],
            ['modal total', $diagnostics['consistency']['modal_total'] ?? 0],
            ['Excel rows', $diagnostics['consistency']['excel_rows'] ?? 0],
        ]);

        if ($this->option('details')) {
            $this->info('Details');
            $this->table(
                ['Unit', 'Wialon ID', 'Group IDs', 'Type', 'Home project', 'Home geofence', 'Allowed home geofences', 'Current containing geofences', 'Selected current geofence', 'Foreign project', 'Entered at', 'Duration', 'Last position at', 'Stale', 'Included', 'Reason'],
                collect($diagnostics['details'] ?? [])
                    ->map(fn (array $row): array => [
                        $row['unit'] ?? '',
                        $row['wialon_id'] ?? '',
                        $row['group_ids'] ?? '',
                        $row['type'] ?? '',
                        $row['home_project'] ?? '',
                        $row['home_geofence'] ?? '',
                        $row['allowed_home_geofences'] ?? '',
                        $row['current_containing_geofences'] ?? '',
                        $row['selected_current_geofence'] ?? '',
                        $row['foreign_project'] ?? '',
                        $row['entered_at'] ?? '',
                        $row['duration'] ?? '',
                        $row['last_position_at'] ?? '',
                        $row['stale'] ?? '',
                        $row['included'] ?? '',
                        $row['reason'] ?? '',
                    ])
                    ->all()
            );
        }

        return self::SUCCESS;
    }
}
