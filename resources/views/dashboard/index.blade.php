@extends('layouts.app')

@section('title', __('app.dashboard').' | '.__('app.app_name'))
@section('page-title', $selectedProject ? $selectedProject->name : __('app.dashboard'))
@section('page-subtitle', __('app.period').': '.$filters['from'].' - '.$filters['to'])

@php
    $nwc = \App\Models\Equipment::OWNERSHIP_NWC;
    $icare = \App\Models\Equipment::OWNERSHIP_ICARE;
    $overview = $data['overview'];
    $ownershipLabelFor = fn (?string $value): string => $value === $icare ? __('app.ownership_icare') : __('app.ownership_nwc');
    $typeGroups = $data['equipmentTypesByOwnership'];
    $typeNwc = collect($typeGroups[$nwc] ?? [])->sortByDesc('total')->values();
    $typeIcare = collect($typeGroups[$icare] ?? [])->sortByDesc('total')->values();
    $typeNwcTop = $typeNwc->take(10)->values();
    $typeIcareTop = $typeIcare->take(10)->values();
    $typeNwcHasMore = $typeNwc->count() > 10;
    $typeIcareHasMore = $typeIcare->count() > 10;
    $ownershipShareRaw = collect($overview['ownership_share'])->keyBy('label');
    $ownershipShare = collect([
        ['label' => $nwc, 'count' => (int) ($ownershipShareRaw[$nwc]['count'] ?? 0)],
        ['label' => $icare, 'count' => (int) ($ownershipShareRaw[$icare]['count'] ?? 0)],
    ]);
    $totalOwnershipCount = (int) $ownershipShare->sum('count');
    $ownershipAverages = collect($data['averageMetricsByOwnership'] ?? []);
    $ownershipAverageRows = $ownershipAverages->keyBy('ownership');
    $averageEngineMax = max(0.1, (float) $ownershipAverages->max(fn ($row) => (float) ($row['avg_hours'] ?? 0)));
    $averageMileageMax = max(0.1, (float) $ownershipAverages->max(fn ($row) => (float) ($row['avg_mileage'] ?? 0)));
    $dailyAverageDashboards = $data['dailyAverageDashboards'] ?? [];
    $projectWorkCategoryGroups = $data['projectActualWorkHourCategoriesByOwnership'] ?? [$nwc => [], $icare => []];
    $projectWorkCategoryRowsNwc = collect($projectWorkCategoryGroups[$nwc] ?? []);
    $projectWorkCategoryRowsIcare = collect($projectWorkCategoryGroups[$icare] ?? []);
    $actualWorkCategoryLabels = collect([
        'less_than_1_hour' => __('app.worked_less_than_1_hour'),
        'less_than_7_hours' => __('app.worked_less_than_7_hours'),
        'between_7_and_10_hours' => __('app.worked_7_to_10_hours'),
        'over_10_hours' => __('app.worked_over_10_hours'),
        'overtime' => __('app.worked_overtime_hours'),
    ]);
    $actualWorkCategoryRanges = collect([
        'less_than_1_hour' => '< 1 saat',
        'less_than_7_hours' => '1 - 7 saat',
        'between_7_and_10_hours' => '7 - 10 saat',
        'over_10_hours' => '> 10 saat',
        'overtime' => '18:00 - 07:59 (Overtime)',
    ]);
    $actualWorkCategoryColors = collect([
        'less_than_1_hour' => '#1f6feb',
        'less_than_7_hours' => '#f97316',
        'between_7_and_10_hours' => '#24b35b',
        'over_10_hours' => '#8b5cf6',
        'overtime' => '#ef4444',
    ]);
    $efficiencyVehicleTypes = collect(config('fleet_efficiency.efficiency_vehicle_types', config('fleet_efficiency.allowed_vehicle_types', [])))
        ->unique()
        ->mapWithKeys(fn (string $value): array => [$value => \App\Support\FleetVehicleType::label($value)])
        ->all();
    $projectWorkCategorySummaryFor = function ($rows) use ($actualWorkCategoryLabels) {
        $rows = collect($rows);
        $summary = [];

        foreach ($actualWorkCategoryLabels->keys() as $key) {
            $summary[$key] = (int) $rows->sum($key);
        }

        $summary['total'] = (int) array_sum(array_intersect_key($summary, array_flip(['less_than_1_hour', 'less_than_7_hours', 'between_7_and_10_hours', 'over_10_hours'])));
        $summary['missing_data'] = (int) $rows->sum('missing_data');
        $summary['overtime_denominator'] = (int) $rows->sum('overtime_denominator');
        $summary['overtime_unknown'] = (int) $rows->sum('overtime_unknown');

        return collect($summary);
    };
    $projectWorkCategorySummaryNwc = $projectWorkCategorySummaryFor($projectWorkCategoryRowsNwc);
    $projectWorkCategorySummaryIcare = $projectWorkCategorySummaryFor($projectWorkCategoryRowsIcare);
    $projectComparisonRows = collect($data['projectOwnershipComparison'] ?? []);
    $projectComparisonTop = $projectComparisonRows->take(10)->values();
    $projectComparisonHasMore = $projectComparisonRows->count() > 10;
    $projectComparisonChartHeight = min(max($projectComparisonTop->count() * 34 + 80, 260), 520);
    $geofenceViolations = $data['geofenceViolations'] ?? ['labels' => [], 'counts' => [], 'project_ids' => [], 'geofence_ids' => [], 'sector_keys' => [], 'total' => 0, 'rows' => []];
    $geofenceViolationRows = collect($geofenceViolations['rows'] ?? [])->sortByDesc('count')->values();
    $geofenceViolationTotal = (int) ($geofenceViolations['total'] ?? 0);
    $geofenceViolationMaxCount = max(1, (int) $geofenceViolationRows->max('count'));
    $geofenceViolationActiveProjects = $geofenceViolationRows->count();
    $geofenceViolationTopRow = $geofenceViolationRows->first();
    $geofenceViolationPalette = ['#2563EB', '#22C55E', '#F59E0B', '#8B5CF6', '#14B8A6', '#EF4444', '#64748B', '#0EA5E9', '#A855F7', '#F97316'];
    $geofenceHomeProjectLabel = $selectedProject?->name ?? ($filters['project_id'] ? 'ID '.$filters['project_id'] : __('app.all_projects'));
    $utilizationTrendByOwnership = $data['utilizationTrendByOwnership'] ?? ['labels' => [], 'series' => [$nwc => [], $icare => []], 'has_data' => false];
    $today = \Illuminate\Support\Carbon::today(config('app.timezone'));
    $periodPresets = [
        'today' => ['label' => __('app.period_today'), 'from' => $today->toDateString(), 'to' => $today->toDateString()],
        'yesterday' => ['label' => __('app.period_yesterday'), 'from' => $today->copy()->subDay()->toDateString(), 'to' => $today->copy()->subDay()->toDateString()],
        'last_7_days' => ['label' => __('app.period_last_7_days'), 'from' => $today->copy()->subDays(6)->toDateString(), 'to' => $today->toDateString()],
        'this_month' => ['label' => __('app.period_this_month'), 'from' => $today->copy()->startOfMonth()->toDateString(), 'to' => $today->toDateString()],
        'last_month' => ['label' => __('app.period_last_month'), 'from' => $today->copy()->subMonthNoOverflow()->startOfMonth()->toDateString(), 'to' => $today->copy()->subMonthNoOverflow()->endOfMonth()->toDateString()],
        'custom' => ['label' => __('app.period_custom'), 'from' => $filters['from'], 'to' => $filters['to']],
    ];
    $selectedPeriod = request()->query('period', 'custom');

    if (! array_key_exists($selectedPeriod, $periodPresets)) {
        $selectedPeriod = 'custom';
    }
    $exportUrl = function (string $block) use ($filters): string {
        return route('dashboard.export', array_filter([
            'block' => $block,
            'date_from' => $filters['from'],
            'date_to' => $filters['to'],
            'project_id' => $filters['project_id'],
            'equipment_type_id' => $filters['equipment_type_id'],
            'ownership_type' => $filters['ownership_type'],
        ], fn ($value) => $value !== null && $value !== ''));
    };
    $ownershipExportUrl = function (?string $type = null) use ($filters): string {
        return route('dashboard.ownership.export', array_filter([
            'project_id' => $filters['project_id'],
            'equipment_type_id' => $filters['equipment_type_id'],
            'type' => $type,
        ], fn ($value) => $value !== null && $value !== ''));
    };
    $kpis = [
        ['label' => __('app.total_hours'), 'value' => number_format($overview['total_hours'], 1).' '.__('app.hours'), 'icon' => 'bi-clock', 'tone' => '#eaf2ff', 'color' => '#1f6feb', 'change' => $overview['changes']['total_hours']],
        ['label' => __('app.total_distance'), 'value' => number_format($overview['total_distance'], 1).' '.__('app.km'), 'icon' => 'bi-signpost-split', 'tone' => '#eaf8ef', 'color' => '#24b35b', 'change' => $overview['changes']['total_distance']],
        ['label' => __('app.avg_hours'), 'value' => number_format($overview['avg_hours_per_equipment'], 1).' '.__('app.hours'), 'icon' => 'bi-speedometer', 'tone' => '#f2ebff', 'color' => '#8b5cf6', 'change' => $overview['changes']['avg_hours_per_equipment']],
        ['label' => __('app.avg_distance'), 'value' => number_format($overview['avg_distance_per_equipment'], 1).' '.__('app.km'), 'icon' => 'bi-geo-alt', 'tone' => '#fff1e9', 'color' => '#f97316', 'change' => $overview['changes']['avg_distance_per_equipment']],
        ['label' => __('app.utilization'), 'value' => number_format($overview['utilization'], 1).' %', 'icon' => 'bi-graph-up-arrow', 'tone' => '#e8f8fb', 'color' => '#0ea5b7', 'change' => $overview['changes']['utilization']],
    ];
    $dashboardLayoutItems = collect($dashboardLayout ?? [])->keyBy('key');
    $dashboardWidgetLayoutFor = function (string $key, string $defaultClass, int $defaultWidth) use ($dashboardLayoutItems): array {
        $item = $dashboardLayoutItems->get($key, []);

        return [
            'class' => $item['column_class'] ?? $defaultClass,
            'order' => (int) ($item['order'] ?? 999),
            'width' => (int) ($item['width'] ?? $defaultWidth),
        ];
    };
    $dashboardWidgetTitleFor = function (string $key, string $defaultTitle) use ($dashboardLayoutItems): string {
        $title = trim((string) ($dashboardLayoutItems->get($key, [])['title'] ?? ''));

        return $title !== '' ? $title : $defaultTitle;
    };
    $dashboardWidgetVisibleFor = fn (string $key): bool => (bool) ($dashboardLayoutItems->get($key, [])['visible'] ?? true);
    $dashboardWidgetVisibilityClassFor = fn (string $key): string => $dashboardWidgetVisibleFor($key) ? '' : ' dashboard-widget-hidden';
@endphp

@push('styles')
<style>
    .dashboard-page {
        width: min(100%, 1540px);
        margin: 0 auto;
    }
    .dashboard-layout-actions {
        min-height: 34px;
    }
    .dashboard-layout-status {
        min-height: 24px;
        color: var(--fleet-muted);
    }
    .dashboard-widget {
        transition: opacity .15s ease, transform .15s ease;
    }
    .dashboard-widget.is-editable .panel {
        outline: 1px dashed rgba(31, 111, 235, .38);
        outline-offset: 3px;
    }
    .dashboard-page:not(.dashboard-layout-editing) .dashboard-title-input {
        display: none !important;
    }
    .dashboard-layout-editing .dashboard-card-title-text {
        display: none;
    }
    .dashboard-layout-editing .dashboard-title-input {
        display: block !important;
        font-weight: 700;
        max-width: 420px;
    }
    .dashboard-widget-hidden {
        display: none;
    }
    .dashboard-layout-editing .dashboard-widget-hidden {
        display: block;
        opacity: .58;
    }
    .dashboard-layout-editing .dashboard-widget-hidden .panel {
        filter: grayscale(.25);
    }
    .dashboard-widget.dragging {
        opacity: .48;
        transform: scale(.995);
    }
    .dashboard-drilldown-trigger {
        cursor: pointer;
    }
    .dashboard-drilldown-trigger:hover {
        background: #f4f8ff;
    }
    .dashboard-drilldown-trigger:focus-visible {
        outline: 2px solid rgba(31, 111, 235, .55);
        outline-offset: 2px;
    }
    .dashboard-drilldown-table-wrapper {
        max-height: 65vh;
        overflow: auto;
    }
    .dashboard-drilldown-table thead th {
        position: sticky;
        top: 0;
        z-index: 2;
        background: #f8fafc;
    }
    .dashboard-drilldown-status {
        min-height: 24px;
    }
    .dashboard-drilldown-filter-panel {
        border: 1px solid #dbe5f4;
        border-radius: 14px;
        background: #f8fbff;
        box-shadow: 0 18px 45px rgba(15, 31, 58, .08);
        padding: 14px;
        margin-bottom: 12px;
    }
    .dashboard-drilldown-filter-panel .form-label {
        font-size: 11px;
        font-weight: 700;
        color: #5b6b84;
        margin-bottom: 4px;
    }
    .dashboard-drilldown-chips {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        margin-bottom: 10px;
    }
    .dashboard-drilldown-chip {
        border: 1px solid #cfe0ff;
        background: #f1f6ff;
        color: #1f4ea3;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 700;
        line-height: 1;
        padding: 7px 10px;
    }
    .dashboard-drilldown-chip button {
        border: 0;
        background: transparent;
        color: inherit;
        font-weight: 900;
        margin-left: 6px;
        padding: 0;
    }
    .dashboard-drilldown-sort {
        border: 0;
        background: transparent;
        color: inherit;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 0;
        font: inherit;
        font-weight: 700;
    }
    .dashboard-drilldown-loading {
        opacity: .55;
        pointer-events: none;
    }
    .dashboard-widget.drag-over .panel {
        border-color: rgba(31, 111, 235, .55);
        box-shadow: 0 10px 30px rgba(31, 111, 235, .12);
    }
    .dashboard-panel-header {
        min-height: 32px;
    }
    .dashboard-drag-handle,
    .dashboard-export-button,
    .dashboard-visibility-toggle,
    .dashboard-reset-order {
        width: 32px;
        height: 32px;
        padding: 0;
        display: inline-grid;
        place-items: center;
        color: var(--fleet-muted);
    }
    .dashboard-drag-handle {
        cursor: grab;
        border-color: transparent;
        background: transparent;
        flex: 0 0 auto;
    }
    .dashboard-page:not(.dashboard-layout-editing) .dashboard-drag-handle,
    .dashboard-page:not(.dashboard-layout-editing) .dashboard-visibility-toggle,
    .dashboard-page:not(.dashboard-layout-editing) .dashboard-reset-order {
        display: none;
    }
    .dashboard-drag-handle:hover,
    .dashboard-drag-handle:focus,
    .dashboard-export-button:hover,
    .dashboard-export-button:focus,
    .dashboard-visibility-toggle:hover,
    .dashboard-visibility-toggle:focus,
    .dashboard-reset-order:hover,
    .dashboard-reset-order:focus {
        color: var(--fleet-blue);
        background: #eef4ff;
        border-color: #d8e5ff;
    }
    .dashboard-widget.dragging .dashboard-drag-handle {
        cursor: grabbing;
    }
    .dashboard-card {
        min-height: 100%;
    }
    .dashboard-card--compact {
        height: 360px;
    }
    .dashboard-card--medium {
        min-height: 390px;
    }
    .dashboard-card-body {
        min-height: 0;
    }
    .chart-box {
        height: 260px;
        min-height: 0;
    }
    .chart-box--donut {
        width: 100%;
        max-width: 220px;
        height: auto;
        aspect-ratio: 1 / 1;
        margin: 0 auto;
    }
    .dashboard-chart-scroll {
        max-height: 430px;
        overflow: auto;
        padding-right: 4px;
    }
    .dashboard-donut-layout {
        display: grid;
        grid-template-columns: minmax(180px, 230px) minmax(160px, 1fr);
        align-items: center;
        gap: 18px;
    }
    .dashboard-type-card-body {
        overflow: hidden;
    }
    .dashboard-type-layout {
        display: grid;
        grid-template-columns: minmax(170px, 220px) minmax(0, 1fr);
        align-items: stretch;
        gap: 18px;
        height: 100%;
        min-height: 0;
    }
    .dashboard-type-chart-panel {
        display: flex;
        align-items: center;
        justify-content: center;
        min-width: 0;
        min-height: 0;
    }
    .dashboard-type-chart-box {
        flex: 0 0 auto;
        max-width: 100%;
    }
    .dashboard-type-table-panel {
        display: flex;
        flex-direction: column;
        min-width: 0;
        min-height: 0;
    }
    .dashboard-type-table,
    .dashboard-type-table.is-expanded {
        flex: 1 1 auto;
        max-height: 252px;
    }
    .dashboard-type-table th:first-child,
    .dashboard-type-table td:first-child {
        white-space: normal;
        overflow-wrap: anywhere;
    }
    .dashboard-share-row {
        display: grid;
        grid-template-columns: 14px minmax(70px, 1fr) auto;
        gap: 10px;
        align-items: center;
    }
    .dashboard-color-dot {
        width: 10px;
        height: 10px;
        border-radius: 999px;
        display: inline-block;
    }
    .dashboard-scroll-table {
        max-height: 252px;
        overflow: auto;
    }
    .dashboard-scroll-table.is-expanded {
        max-height: 420px;
    }
    .dashboard-scroll-table.dashboard-type-table,
    .dashboard-scroll-table.dashboard-type-table.is-expanded {
        max-height: 252px;
    }
    .dashboard-work-status-card {
        min-height: 470px;
    }
    .dashboard-work-status-title span {
        font-weight: 800;
    }
    .dashboard-work-status-layout {
        display: grid;
        grid-template-columns: minmax(210px, 290px) minmax(0, 1fr);
        align-items: center;
        gap: 24px;
    }
    .dashboard-work-status-chart {
        width: min(100%, 280px);
        aspect-ratio: 1 / 1;
        min-height: 0;
        margin: 0 auto;
        position: relative;
    }
    .dashboard-work-status-table {
        min-width: 0;
    }
    .dashboard-work-status-table th,
    .dashboard-work-status-table td {
        padding-top: .72rem;
        padding-bottom: .72rem;
    }
    .dashboard-work-status-label {
        display: inline-flex;
        align-items: center;
        gap: 10px;
        min-width: 0;
    }
    .dashboard-work-status-label-text {
        overflow-wrap: anywhere;
    }
    .dashboard-work-status-total td {
        font-weight: 800;
    }
    .dashboard-work-status-note {
        border-top: 1px solid var(--fleet-line);
        color: var(--fleet-muted);
    }
    .dashboard-work-status-legend {
        display: grid;
        grid-template-columns: repeat(4, minmax(120px, 1fr));
        gap: 10px 18px;
        border: 1px solid var(--fleet-line);
        border-radius: 8px;
        padding: 12px 14px;
        background: #fff;
    }
    .dashboard-work-status-legend-item {
        display: inline-flex;
        align-items: flex-start;
        gap: 9px;
        min-width: 0;
    }
    .dashboard-work-status-legend small {
        color: var(--fleet-muted);
        display: block;
        line-height: 1.25;
    }
    .dashboard-expand-toggle {
        font-size: .82rem;
        padding: .2rem .45rem;
    }
    .dashboard-empty {
        min-height: 180px;
        border: 1px dashed #d8e0ec;
        border-radius: 8px;
        display: grid;
        place-items: center;
        color: var(--fleet-muted);
        background: #fbfcff;
        text-align: center;
        padding: 18px;
    }
    .dashboard-kpi-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
    }
    .dashboard-mini-kpi {
        border: 1px solid var(--fleet-line);
        border-radius: 8px;
        padding: 12px;
        background: #fff;
    }
    .dashboard-mini-kpi-value {
        font-size: 1.5rem;
        font-weight: 800;
        line-height: 1.1;
    }
    .dashboard-average-card {
        min-height: 330px;
    }
    .dashboard-average-header {
        display: grid;
        grid-template-columns: 56px minmax(0, 1fr);
        gap: 14px;
        align-items: center;
    }
    .dashboard-average-icon {
        width: 56px;
        height: 56px;
        border-radius: 8px;
        display: grid;
        place-items: center;
        font-size: 1.7rem;
    }
    .dashboard-average-row {
        display: grid;
        grid-template-columns: minmax(110px, 150px) minmax(140px, 1fr) auto;
        gap: 14px;
        align-items: center;
        padding: 18px 0;
    }
    .dashboard-average-row + .dashboard-average-row {
        border-top: 1px dashed var(--fleet-line);
    }
    .dashboard-average-title {
        font-weight: 800;
        color: var(--fleet-ink);
    }
    .dashboard-average-meta {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        color: var(--fleet-muted);
        font-size: .88rem;
        margin-top: 6px;
    }
    .dashboard-average-bar {
        height: 12px;
        border-radius: 999px;
        overflow: hidden;
        background: #edf1f7;
    }
    .dashboard-average-bar-value {
        height: 100%;
        min-width: 0;
        border-radius: inherit;
    }
    .dashboard-average-value {
        min-width: 92px;
        text-align: right;
        font-size: 1.45rem;
        font-weight: 800;
        line-height: 1.1;
    }
    .dashboard-average-chart {
        position: relative;
        min-height: 230px;
        flex: 1 1 auto;
    }
    .dashboard-average-note {
        border-radius: 8px;
        padding: 12px 14px;
        background: #f2f7ff;
        color: var(--fleet-muted);
        font-size: .9rem;
    }
    .dashboard-average-insight-card {
        min-height: 620px;
        gap: 0;
    }
    .dashboard-average-filter-pill {
        min-height: 32px;
        display: inline-flex;
        align-items: center;
        gap: 7px;
        border: 1px solid var(--fleet-line);
        border-radius: 8px;
        padding: 6px 10px;
        background: #fff;
        color: var(--fleet-muted);
        font-size: .82rem;
        white-space: nowrap;
    }
    .dashboard-average-info {
        display: flex;
        align-items: center;
        gap: 10px;
        border-radius: 8px;
        padding: 10px 12px;
        background: #f6f9ff;
        color: var(--fleet-muted);
        font-size: .88rem;
    }
    .dashboard-average-info span:last-child {
        min-width: 0;
        overflow-wrap: anywhere;
    }
    .dashboard-average-info-icon {
        width: 38px;
        height: 38px;
        border-radius: 8px;
        display: inline-grid;
        place-items: center;
        flex: 0 0 auto;
        font-size: 1.1rem;
    }
    .dashboard-average-kpis {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 12px;
    }
    .dashboard-average-kpi {
        border: 1px solid var(--fleet-line);
        border-radius: 8px;
        background: #fff;
        padding: 12px 14px;
        min-width: 0;
        min-height: 78px;
        max-height: 90px;
        display: grid;
        grid-template-columns: 10px minmax(0, 1fr) auto;
        align-items: center;
        gap: 12px;
    }
    .dashboard-average-kpi-dot {
        width: 10px;
        height: 10px;
        border-radius: 999px;
        display: inline-block;
    }
    .dashboard-average-kpi-label {
        color: var(--fleet-ink);
        font-weight: 800;
        line-height: 1.1;
        margin-bottom: 5px;
    }
    .dashboard-average-kpi-value {
        font-size: 1.35rem;
        line-height: 1.1;
        font-weight: 800;
        white-space: nowrap;
    }
    .dashboard-average-kpi-meta {
        display: grid;
        justify-items: end;
        gap: 2px;
        color: var(--fleet-muted);
        font-size: .82rem;
        line-height: 1.15;
        white-space: nowrap;
    }
    .dashboard-average-kpi-meta strong {
        color: var(--fleet-ink);
        font-size: 1rem;
    }
    .dashboard-average-kpi-meta small {
        font-size: .72rem;
    }
    .dashboard-average-main {
        min-width: 0;
        display: flex;
        flex-direction: column;
        order: 1;
    }
    .dashboard-chart-mode-tabs {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        align-self: center;
        gap: 0;
        flex-wrap: nowrap;
        height: 32px;
        padding: 2px;
        border: 1px solid var(--fleet-line);
        border-radius: 8px;
        background: #f3f6fb;
    }
    .dashboard-chart-mode-tabs .btn {
        min-width: 72px;
        height: 26px;
        border: 0;
        border-radius: 6px;
        padding: 0 14px;
        font-size: .82rem;
        line-height: 26px;
    }
    .dashboard-average-chart--large {
        min-height: 340px;
        flex: 1 1 auto;
        width: 100%;
    }
    .dashboard-average-daily-table {
        min-width: 0;
        width: 100%;
    }
    .dashboard-average-daily-table .dashboard-scroll-table {
        max-height: 280px;
        border: 1px solid var(--fleet-line);
        border-radius: 8px;
        background: #fff;
    }
    .dashboard-average-daily-table th,
    .dashboard-average-daily-table td {
        white-space: nowrap;
    }
    .dashboard-average-day-strip {
        border-top: 1px solid var(--fleet-line);
        padding-top: 16px;
    }
    .dashboard-average-day-cards {
        display: flex;
        gap: 12px;
        overflow-x: auto;
        padding-bottom: 4px;
    }
    .dashboard-average-day-card {
        border: 1px solid var(--fleet-line);
        border-radius: 8px;
        background: #fff;
        padding: 9px 11px;
        flex: 0 0 150px;
        height: 70px;
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 3px 8px;
        text-align: left;
        color: var(--fleet-ink);
        font-size: .78rem;
        line-height: 1.15;
    }
    .dashboard-average-day-card span:not(.dashboard-average-day-date) {
        white-space: nowrap;
    }
    .dashboard-average-day-date {
        font-weight: 800;
        grid-column: 1 / -1;
    }
    .dashboard-average-day-card i {
        align-self: end;
        justify-self: end;
        grid-column: 2;
        grid-row: 2 / span 2;
    }
    .dashboard-average-day-card:hover,
    .dashboard-average-day-card:focus {
        border-color: rgba(31, 111, 235, .45);
        box-shadow: 0 8px 24px rgba(31, 111, 235, .08);
    }
    .dashboard-average-type-card {
        min-height: 420px;
    }
    .dashboard-average-formula-help {
        width: 24px;
        height: 24px;
        border-radius: 999px;
        display: inline-grid;
        place-items: center;
        color: var(--fleet-blue);
        background: #eef5ff;
        font-size: .9rem;
        flex: 0 0 auto;
    }
    .dashboard-average-type-panel {
        display: grid;
        gap: 10px;
    }
    .dashboard-average-type-head,
    .dashboard-average-type-row {
        display: grid;
        grid-template-columns: minmax(126px, 170px) minmax(0, 1fr) minmax(0, 1fr);
        gap: 12px;
        align-items: center;
    }
    .dashboard-average-type-head {
        padding: 0 12px 6px;
        color: var(--fleet-muted);
        font-size: .78rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: .02em;
    }
    .dashboard-average-type-row {
        border: 1px solid var(--fleet-line);
        border-radius: 12px;
        background: #fff;
        padding: 10px 12px;
        transition: transform .22s ease, box-shadow .22s ease, border-color .22s ease;
    }
    .dashboard-average-type-row:hover {
        transform: translateY(-1px);
        border-color: rgba(37, 99, 235, .22);
        box-shadow: 0 10px 26px rgba(15, 31, 58, .08);
    }
    .dashboard-average-type-name {
        min-width: 0;
        display: grid;
        gap: 3px;
    }
    .dashboard-average-type-name strong {
        color: var(--fleet-ink);
        font-size: .95rem;
        line-height: 1.15;
    }
    .dashboard-average-type-name span {
        color: var(--fleet-muted);
        font-size: .76rem;
    }
    .dashboard-average-type-cell {
        min-width: 0;
        border: 0;
        border-radius: 10px;
        background: #f8fafc;
        padding: 8px 10px;
        display: grid;
        grid-template-columns: minmax(72px, 1fr) auto;
        gap: 5px 10px;
        align-items: center;
        text-align: left;
        color: var(--fleet-ink);
        transition: background .22s ease, box-shadow .22s ease;
    }
    .dashboard-average-type-cell:hover,
    .dashboard-average-type-cell:focus-visible {
        background: #eef5ff;
        box-shadow: inset 0 0 0 1px rgba(37, 99, 235, .18);
    }
    .dashboard-average-type-cell--empty {
        color: var(--fleet-muted);
        cursor: default;
    }
    .dashboard-average-type-track {
        height: 10px;
        border-radius: 999px;
        background: #e6edf7;
        overflow: hidden;
    }
    .dashboard-average-type-fill {
        display: block;
        height: 100%;
        border-radius: inherit;
        transition: width .28s ease;
    }
    .dashboard-average-type-value {
        justify-self: end;
        font-weight: 900;
        font-size: .96rem;
        white-space: nowrap;
    }
    .dashboard-average-type-meta {
        grid-column: 1 / -1;
        color: var(--fleet-muted);
        font-size: .75rem;
        line-height: 1.2;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .dashboard-drilldown-formula {
        border: 1px solid #d9e6fb;
        border-radius: 12px;
        background: #f7fbff;
        padding: 12px;
        display: grid;
        grid-template-columns: repeat(5, minmax(0, 1fr));
        gap: 10px;
        margin-bottom: 12px;
    }
    .dashboard-drilldown-formula-item {
        min-width: 0;
        display: grid;
        gap: 3px;
    }
    .dashboard-drilldown-formula-item span {
        color: var(--fleet-muted);
        font-size: .74rem;
        line-height: 1.15;
    }
    .dashboard-drilldown-formula-item strong {
        color: var(--fleet-ink);
        font-size: .92rem;
        line-height: 1.2;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .dashboard-loading-overlay {
        position: fixed;
        inset: 0;
        z-index: 2000;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 24px;
        background: rgba(245, 247, 251, .82);
        backdrop-filter: blur(10px);
    }
    .dashboard-loading-overlay.is-active {
        display: flex;
    }
    .dashboard-loading-card {
        width: min(460px, 100%);
        border: 1px solid var(--fleet-line);
        border-radius: 8px;
        background: #fff;
        box-shadow: 0 20px 60px rgba(24, 39, 75, .18);
        padding: 24px;
    }
    .dashboard-loading-icon {
        width: 48px;
        height: 48px;
        border-radius: 8px;
        display: grid;
        place-items: center;
        color: #fff;
        background: var(--fleet-blue);
        font-size: 1.45rem;
    }
    .dashboard-loading-percent {
        font-size: 2.1rem;
        line-height: 1;
        font-weight: 800;
        color: var(--fleet-ink);
    }
    .dashboard-loading-bar {
        height: 10px;
        border-radius: 999px;
        overflow: hidden;
        background: #e8eef8;
    }
    .dashboard-loading-bar-value {
        width: 0%;
        height: 100%;
        border-radius: inherit;
        background: linear-gradient(90deg, var(--fleet-blue), #24b35b);
        transition: width .35s ease;
    }
    body.dashboard-is-loading {
        cursor: progress;
    }
    @media (max-width: 1199px) {
        .dashboard-card--compact {
            height: auto;
            min-height: 330px;
        }
        .dashboard-average-kpis {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }
    }
    @media (max-width: 767px) {
        .dashboard-donut-layout,
        .dashboard-type-layout,
        .dashboard-work-status-layout,
        .dashboard-average-row,
        .dashboard-kpi-grid {
            grid-template-columns: 1fr;
        }
        .dashboard-average-row {
            gap: 10px;
        }
        .dashboard-average-value {
            min-width: 0;
            text-align: left;
        }
        .dashboard-work-status-card {
            min-height: auto;
        }
        .dashboard-work-status-legend {
            grid-template-columns: 1fr;
        }
        .chart-box--donut {
            max-width: 210px;
        }
        .dashboard-type-chart-box {
            flex-basis: auto;
        }
        .dashboard-drilldown-filter-panel {
            position: fixed;
            inset: 10px;
            z-index: 1065;
            overflow: auto;
            margin: 0;
        }
        .dashboard-average-insight-header {
            align-items: stretch !important;
        }
        .dashboard-average-kpis {
            grid-template-columns: 1fr;
        }
        .dashboard-average-kpi {
            max-height: none;
        }
        .dashboard-average-chart--large {
            min-height: 260px;
        }
        .dashboard-chart-mode-tabs {
            width: 100%;
            justify-content: center;
        }
        .dashboard-chart-mode-tabs .btn {
            flex: 1 1 0;
            min-width: 0;
        }
        .dashboard-average-type-head {
            display: none;
        }
        .dashboard-average-type-row {
            grid-template-columns: 1fr;
            gap: 8px;
        }
        .dashboard-average-type-cell {
            grid-template-columns: 1fr;
        }
        .dashboard-average-type-value {
            justify-self: start;
        }
        .dashboard-drilldown-formula {
            grid-template-columns: 1fr 1fr;
        }
    }
    .dashboard-widget[data-widget-key="geofence-analysis"] {
        flex: 0 0 100%;
        max-width: 100%;
    }
    .foreign-geofence-shell {
        background: transparent;
        border: 0;
        box-shadow: none;
        padding: 0;
        max-width: 1540px;
        width: 100%;
        margin: 0 auto;
    }
    .foreign-geofence-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 12px;
    }
    .foreign-geofence-title {
        margin: 0;
        color: #0f172a;
        font-size: clamp(1.25rem, 1.65vw, 1.75rem);
        font-weight: 800;
        line-height: 1.2;
    }
    .foreign-geofence-subtitle {
        margin-top: 4px;
        color: #64748b;
        font-size: .875rem;
        line-height: 1.4;
    }
    .foreign-geofence-context {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        margin-top: 8px;
        border-radius: 999px;
        padding: 5px 10px;
        background: #eff6ff;
        color: #1d4ed8;
        font-size: .75rem;
        font-weight: 900;
        max-width: 100%;
    }
    .foreign-geofence-context span {
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .foreign-geofence-actions {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 10px;
        flex-wrap: wrap;
    }
    .foreign-geofence-period {
        min-width: 220px;
        height: 42px;
        border: 1px solid #dbe7f5;
        border-radius: 14px;
        padding: 8px 38px 8px 14px;
        color: #0f172a;
        background-color: #fff;
        box-shadow: 0 10px 24px rgba(15, 23, 42, .05);
        font-size: .875rem;
        font-weight: 700;
    }
    .foreign-geofence-action {
        min-height: 40px;
        border-radius: 14px;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 16px;
        font-size: .875rem;
        font-weight: 800;
        transition: transform .24s ease, box-shadow .24s ease, border-color .24s ease, background .24s ease;
    }
    .foreign-geofence-action:hover {
        transform: translateY(-1px);
        box-shadow: 0 14px 26px rgba(37, 99, 235, .14);
    }
    select.foreign-geofence-action {
        display: block;
        width: auto;
        min-width: 128px;
    }
    .dashboard-page:not(.dashboard-layout-editing) .foreign-geofence-action.dashboard-visibility-toggle,
    .dashboard-page:not(.dashboard-layout-editing) .foreign-geofence-action.dashboard-drag-handle {
        display: none;
    }
    .foreign-geofence-grid {
        display: grid;
        grid-template-columns: minmax(0, 1.04fr) minmax(360px, .96fr);
        gap: 16px;
        align-items: stretch;
    }
    .foreign-geofence-card,
    .foreign-geofence-kpi,
    .foreign-geofence-table-card {
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        background: #fff;
        box-shadow: 0 18px 42px rgba(15, 23, 42, .06);
        transition: transform .24s ease, box-shadow .24s ease, border-color .24s ease;
    }
    .foreign-geofence-card:hover,
    .foreign-geofence-kpi:hover,
    .foreign-geofence-table-card:hover {
        transform: translateY(-2px);
        border-color: #cbdaf0;
        box-shadow: 0 24px 54px rgba(15, 23, 42, .1);
    }
    .foreign-geofence-card {
        height: 400px;
        min-height: 0;
        padding: 18px 20px;
        overflow: hidden;
    }
    .foreign-geofence-card-title {
        margin: 0 0 12px;
        color: #0f172a;
        font-size: 1rem;
        font-weight: 800;
    }
    .foreign-geofence-donut-layout {
        display: grid;
        grid-template-columns: minmax(270px, 300px) minmax(220px, 1fr);
        gap: 16px;
        align-items: center;
        min-width: 0;
        height: 100%;
    }
    .foreign-geofence-donut-wrap {
        position: relative;
        width: min(100%, 300px);
        aspect-ratio: 1 / 1;
        margin: 0 auto;
    }
    .foreign-geofence-donut-wrap::before {
        content: "";
        position: absolute;
        inset: 0;
        border-radius: 999px;
        background: #e2e8f0;
        opacity: .75;
        -webkit-mask: radial-gradient(circle, transparent 0 52%, #000 53%);
        mask: radial-gradient(circle, transparent 0 52%, #000 53%);
    }
    .foreign-geofence-donut-wrap canvas {
        position: relative;
        z-index: 1;
        width: 100% !important;
        height: 100% !important;
        cursor: pointer;
    }
    .foreign-geofence-center {
        position: absolute;
        inset: 50% auto auto 50%;
        transform: translate(-50%, -50%);
        display: grid;
        place-items: center;
        text-align: center;
        pointer-events: none;
        color: #0f172a;
        z-index: 2;
    }
    .foreign-geofence-center strong {
        display: block;
        font-size: clamp(2.1rem, 3.2vw, 2.75rem);
        line-height: 1;
        font-weight: 900;
    }
    .foreign-geofence-center span {
        margin-top: 6px;
        color: #64748b;
        font-weight: 800;
        font-size: .875rem;
    }
    .foreign-geofence-legend {
        display: grid;
        gap: 6px;
        align-content: start;
        max-height: 300px;
        min-width: 0;
        overflow-y: auto;
        padding-right: 4px;
    }
    .foreign-geofence-legend-row {
        width: 100%;
        border: 0;
        border-radius: 10px;
        background: #f8fafc;
        display: grid;
        grid-template-columns: 14px minmax(0, 1fr) auto auto;
        gap: 10px;
        align-items: center;
        min-height: 42px;
        padding: 7px 10px;
        color: #0f172a;
        text-align: left;
        transition: background .24s ease, transform .24s ease, box-shadow .24s ease;
    }
    .foreign-geofence-legend-row:hover,
    .foreign-geofence-legend-row:focus-visible {
        background: #eff6ff;
        transform: translateX(2px);
        box-shadow: inset 3px 0 0 #2563eb;
    }
    .foreign-geofence-dot {
        width: 11px;
        height: 11px;
        border-radius: 999px;
        display: inline-block;
    }
    .foreign-geofence-legend-name {
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        font-size: .8125rem;
        line-height: 1.25;
        font-weight: 800;
    }
    .foreign-geofence-legend-count {
        color: #0f172a;
        font-size: .8125rem;
        font-weight: 900;
    }
    .foreign-geofence-legend-percent {
        min-width: 40px;
        color: #64748b;
        text-align: right;
        font-size: .75rem;
        font-weight: 800;
    }
    .foreign-geofence-stat-wrap {
        max-height: 305px;
        overflow-y: auto;
        padding-right: 4px;
    }
    .foreign-geofence-stat-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0 7px;
    }
    .foreign-geofence-stat-table thead th {
        padding: 0 12px 4px;
        color: #64748b;
        font-size: .6875rem;
        text-transform: uppercase;
        font-weight: 900;
    }
    .foreign-geofence-stat-row {
        cursor: pointer;
        transition: transform .22s ease, box-shadow .22s ease;
    }
    .foreign-geofence-stat-row td {
        background: #f8fafc;
        padding: 10px 12px;
        font-size: .8125rem;
        line-height: 1.25;
        vertical-align: middle;
    }
    .foreign-geofence-stat-row td:first-child {
        border-radius: 14px 0 0 14px;
    }
    .foreign-geofence-stat-row td:last-child {
        border-radius: 0 14px 14px 0;
    }
    .foreign-geofence-stat-row:hover {
        transform: translateY(-1px);
        box-shadow: 0 14px 28px rgba(15, 23, 42, .08);
    }
    .foreign-geofence-progress {
        height: 7px;
        border-radius: 999px;
        overflow: hidden;
        background: #e2e8f0;
        margin-top: 7px;
    }
    .foreign-geofence-progress span {
        display: block;
        height: 100%;
        border-radius: inherit;
        background: linear-gradient(90deg, #2563eb, #14b8a6);
    }
    .foreign-geofence-show-all {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        color: #2563eb;
        font-size: .8125rem;
        font-weight: 900;
        text-decoration: none;
    }
    .foreign-geofence-kpi-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 12px;
        margin-top: 12px;
    }
    .foreign-geofence-kpi {
        padding: 14px 18px;
        min-height: 86px;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
    }
    .foreign-geofence-kpi-label {
        color: #64748b;
        font-weight: 800;
        font-size: .78rem;
    }
    .foreign-geofence-kpi-value {
        color: #0f172a;
        font-size: clamp(1.75rem, 2.3vw, 1.875rem);
        line-height: 1;
        font-weight: 900;
        overflow-wrap: anywhere;
    }
    .foreign-geofence-kpi-note {
        color: #94a3b8;
        font-size: .75rem;
        font-weight: 700;
    }
    .foreign-geofence-table-card {
        margin-top: 12px;
        padding: 14px 16px;
        overflow: hidden;
    }
    .foreign-geofence-table-toolbar {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 12px;
    }
    .foreign-geofence-table-title {
        margin: 0;
        color: #0f172a;
        font-size: 1.05rem;
        font-weight: 900;
    }
    .foreign-geofence-table-meta {
        color: #64748b;
        font-size: .8125rem;
        margin-top: 4px;
    }
    .foreign-geofence-table-actions {
        display: flex;
        gap: 8px;
        align-items: center;
        flex-wrap: wrap;
        justify-content: flex-end;
    }
    .foreign-geofence-search {
        position: relative;
        min-width: 240px;
    }
    .foreign-geofence-search input {
        min-height: 40px;
        border-radius: 14px;
        padding-left: 38px;
        font-size: .8125rem;
        border-color: #dbe7f5;
        box-shadow: 0 10px 24px rgba(15, 23, 42, .05);
    }
    .foreign-geofence-search i {
        position: absolute;
        left: 14px;
        top: 50%;
        transform: translateY(-50%);
        color: #94a3b8;
    }
    .foreign-geofence-table-wrap {
        overflow: auto;
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        max-height: 640px;
    }
    .foreign-geofence-detail-table {
        min-width: 1265px;
        table-layout: fixed;
        width: 100%;
        margin: 0;
    }
    .foreign-geofence-detail-table thead th {
        position: sticky;
        top: 0;
        z-index: 2;
        background: #f8fafc;
        color: #475569;
        border-bottom: 1px solid #e2e8f0;
        padding: 10px 12px;
        font-size: .6875rem;
        line-height: 1.2;
        text-transform: uppercase;
        font-weight: 900;
    }
    .foreign-geofence-detail-table tbody td {
        padding: 10px 12px;
        border-color: #edf2f7;
        color: #1e293b;
        font-size: .8125rem;
        line-height: 1.35;
        vertical-align: middle;
    }
    .foreign-geofence-detail-table tbody tr {
        height: 52px;
        transition: background .22s ease, transform .22s ease;
    }
    .foreign-geofence-detail-table tbody tr:hover {
        background: #f8fbff;
    }
    .foreign-geofence-equipment {
        color: #0f172a;
        font-weight: 900;
    }
    .foreign-geofence-muted {
        color: #94a3b8;
    }
    .foreign-geofence-clamp {
        max-width: 190px;
        overflow: hidden;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        line-clamp: 2;
    }
    .foreign-geofence-duration {
        display: inline-flex;
        align-items: center;
        border-radius: 999px;
        padding: 5px 8px;
        font-size: .75rem;
        font-weight: 900;
        white-space: nowrap;
    }
    .foreign-geofence-duration--soft {
        background: #f1f5f9;
        color: #475569;
    }
    .foreign-geofence-duration--warning {
        background: #fef3c7;
        color: #92400e;
    }
    .foreign-geofence-duration--orange {
        background: #ffedd5;
        color: #c2410c;
    }
    .foreign-geofence-duration--danger {
        background: #fee2e2;
        color: #b91c1c;
    }
    .foreign-geofence-status {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        border-radius: 999px;
        padding: 5px 8px;
        font-size: .75rem;
        font-weight: 900;
        white-space: nowrap;
    }
    .foreign-geofence-status::before {
        content: "";
        width: 7px;
        height: 7px;
        border-radius: 999px;
        background: currentColor;
    }
    .foreign-geofence-loading {
        color: #64748b;
        padding: 28px !important;
        text-align: center;
    }
    .foreign-geofence-empty {
        border-radius: 12px;
        background: #f8fafc;
        color: #64748b;
        padding: 14px;
        font-size: .8125rem;
        font-weight: 800;
        text-align: center;
    }
    .foreign-geofence-pagination {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 8px;
        margin-top: 10px;
        color: #64748b;
        font-size: .8125rem;
        font-weight: 800;
    }
    .foreign-geofence-pagination .btn {
        min-width: 34px;
        height: 34px;
        padding: 6px 10px;
        border-radius: 10px;
        font-size: .8125rem;
        font-weight: 800;
    }
    .foreign-geofence-page-size {
        min-width: 82px;
        height: 40px;
        border-radius: 14px;
        font-size: .8125rem;
        font-weight: 800;
        border-color: #dbe7f5;
        box-shadow: 0 10px 24px rgba(15, 23, 42, .05);
    }
    @keyframes foreignGeofenceFadeUp {
        from {
            opacity: 0;
            transform: translateY(10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    .foreign-geofence-shell,
    .foreign-geofence-card,
    .foreign-geofence-kpi,
    .foreign-geofence-table-card {
        animation: foreignGeofenceFadeUp .32s ease both;
    }
    @media (max-width: 1199.98px) {
        .foreign-geofence-grid,
        .foreign-geofence-donut-layout {
            grid-template-columns: minmax(0, 1fr);
        }
        .foreign-geofence-card {
            height: auto;
            min-height: auto;
            min-width: 0;
        }
        .foreign-geofence-donut-wrap {
            width: min(100%, 280px);
            max-width: 100%;
        }
    }
    @media (max-width: 991.98px) {
        .foreign-geofence-header,
        .foreign-geofence-table-toolbar {
            align-items: stretch;
            flex-direction: column;
        }
        .foreign-geofence-actions,
        .foreign-geofence-table-actions {
            justify-content: flex-start;
        }
        .foreign-geofence-kpi-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }
    @media (min-width: 1200px) and (max-width: 1399.98px) {
        .foreign-geofence-card {
            height: 390px;
        }
        .foreign-geofence-donut-layout {
            grid-template-columns: minmax(250px, 270px) minmax(210px, 1fr);
        }
        .foreign-geofence-donut-wrap {
            width: min(100%, 270px);
        }
    }
    @media (max-width: 575.98px) {
        .foreign-geofence-card,
        .foreign-geofence-table-card {
            padding: 16px;
        }
        .foreign-geofence-actions,
        .foreign-geofence-table-actions,
        .foreign-geofence-period,
        .foreign-geofence-search,
        .foreign-geofence-page-size {
            width: 100%;
        }
        .foreign-geofence-action,
        .foreign-geofence-search input {
            width: 100%;
        }
        .foreign-geofence-kpi-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        .foreign-geofence-donut-wrap {
            width: min(100%, 230px);
            max-width: 100%;
            justify-self: center;
        }
        .foreign-geofence-legend-row {
            grid-template-columns: 14px minmax(0, 1fr) auto;
        }
        .foreign-geofence-legend-percent {
            grid-column: 2 / 4;
            text-align: left;
        }
    }

    /* Enterprise visual refresh only: widget order, bindings and handlers stay unchanged. */
    .dashboard-page {
        animation: dashboardEnter .18s ease-out;
    }
    @keyframes dashboardEnter {
        from {
            opacity: 0;
            transform: scale(.998);
        }
        to {
            opacity: 1;
            transform: scale(1);
        }
    }
    #dashboardFilterForm.panel {
        border-radius: 16px;
        padding: 18px !important;
        box-shadow: 0 18px 44px rgba(15, 23, 42, .055);
    }
    #dashboardFilterForm .form-label {
        color: var(--fleet-muted);
        font-size: .72rem;
        font-weight: 700;
        letter-spacing: 0;
        margin-bottom: .4rem;
    }
    #dashboardFilterForm .form-control,
    #dashboardFilterForm .form-select {
        min-height: 42px;
        border-color: var(--fleet-line);
        background-color: var(--fleet-card);
        color: var(--fleet-ink);
        box-shadow: none;
        transition: border-color .16s ease, box-shadow .16s ease, background .16s ease;
    }
    #dashboardFilterForm .form-control:focus,
    #dashboardFilterForm .form-select:focus {
        border-color: rgba(37, 99, 235, .5);
        box-shadow: 0 0 0 3px rgba(37, 99, 235, .12);
    }
    .dashboard-period-button {
        min-height: 34px;
        border-radius: 10px;
        font-weight: 700;
        padding-inline: 14px;
    }
    .metric-card {
        position: relative;
        overflow: hidden;
        border-radius: 16px;
        min-height: 120px;
    }
    .metric-card::after {
        content: "";
        position: absolute;
        inset: auto 14px 12px 14px;
        height: 26px;
        border-radius: 999px;
        background: linear-gradient(90deg, rgba(37, 99, 235, .14), rgba(34, 197, 94, .08), rgba(14, 165, 233, .14));
        opacity: .32;
        pointer-events: none;
    }
    .metric-card .d-flex {
        position: relative;
        z-index: 1;
    }
    .metric-icon {
        width: 58px;
        height: 58px;
        border-radius: 16px;
        box-shadow: inset 0 0 0 1px rgba(255, 255, 255, .52);
    }
    .metric-title {
        color: var(--fleet-muted);
        font-size: .74rem;
        font-weight: 750;
        letter-spacing: 0;
    }
    .metric-value {
        color: var(--fleet-ink);
        font-size: 1.7rem;
        letter-spacing: 0;
    }
    .dashboard-card,
    .dashboard-average-type-card,
    .foreign-geofence-card,
    .foreign-geofence-kpi,
    .foreign-geofence-table-card {
        border-radius: 16px !important;
        border-color: var(--fleet-line) !important;
        background: var(--fleet-card) !important;
        box-shadow: 0 18px 44px rgba(15, 23, 42, .055) !important;
    }
    .dashboard-card:hover,
    .dashboard-average-type-card:hover,
    .foreign-geofence-card:hover,
    .foreign-geofence-kpi:hover,
    .foreign-geofence-table-card:hover {
        box-shadow: 0 22px 54px rgba(15, 23, 42, .075) !important;
    }
    .dashboard-card-title-text,
    .foreign-geofence-card-title,
    .foreign-geofence-table-title {
        color: var(--fleet-ink);
        font-weight: 800;
        letter-spacing: 0;
    }
    .dashboard-card-body {
        min-height: 0;
    }
    .dashboard-export-button,
    .dashboard-visibility-toggle,
    .dashboard-drag-handle,
    .foreign-geofence-action {
        border-radius: 10px !important;
        transition: color .15s ease, background .15s ease, border-color .15s ease, transform .15s ease;
    }
    .dashboard-export-button:hover,
    .dashboard-visibility-toggle:hover,
    .dashboard-drag-handle:hover,
    .foreign-geofence-action:hover {
        transform: translateY(-1px);
    }
    .chart-box--donut,
    .dashboard-work-status-chart,
    .foreign-geofence-donut-wrap {
        filter: drop-shadow(0 14px 26px rgba(15, 23, 42, .06));
    }
    .dashboard-scroll-table,
    .dashboard-drilldown-table-wrapper,
    .foreign-geofence-table-wrap,
    .foreign-geofence-stat-wrap {
        border: 1px solid var(--fleet-line);
        border-radius: 14px;
        background: var(--fleet-card);
    }
    .dashboard-scroll-table thead th,
    .dashboard-drilldown-table thead th,
    .foreign-geofence-detail-table thead th,
    .foreign-geofence-stat-table thead th,
    .table thead th {
        color: var(--fleet-muted);
        background: var(--fleet-card-soft);
        font-size: .68rem;
        font-weight: 800;
        letter-spacing: .04em;
        text-transform: uppercase;
        border-color: var(--fleet-line);
    }
    .dashboard-scroll-table tbody td,
    .dashboard-drilldown-table tbody td,
    .foreign-geofence-detail-table tbody td,
    .foreign-geofence-stat-table tbody td,
    .table tbody td {
        border-color: var(--fleet-line);
    }
    .dashboard-scroll-table tbody tr,
    .dashboard-drilldown-table tbody tr,
    .foreign-geofence-detail-table tbody tr,
    .foreign-geofence-stat-row,
    .table tbody tr {
        transition: background .15s ease, box-shadow .15s ease;
    }
    .dashboard-scroll-table tbody tr:hover,
    .dashboard-drilldown-table tbody tr:hover,
    .foreign-geofence-detail-table tbody tr:hover,
    .foreign-geofence-stat-row:hover,
    .table tbody tr:hover,
    .dashboard-drilldown-trigger:hover {
        background: var(--fleet-hover) !important;
    }
    .dashboard-average-info,
    .dashboard-average-type-cell,
    .dashboard-average-kpi,
    .dashboard-average-day-card,
    .foreign-geofence-empty,
    .dashboard-empty {
        border-color: var(--fleet-line);
        background: var(--fleet-card-soft);
        color: var(--fleet-muted);
    }
    .dashboard-average-type-row {
        border-color: var(--fleet-line);
        background: var(--fleet-card);
        box-shadow: 0 10px 24px rgba(15, 23, 42, .035);
    }
    .dashboard-average-type-row:hover {
        transform: translateY(-1px);
        box-shadow: 0 16px 34px rgba(15, 23, 42, .055);
    }
    .dashboard-average-type-track,
    .dashboard-average-bar {
        background: color-mix(in srgb, var(--fleet-muted) 12%, transparent);
    }
    .dashboard-average-type-value,
    .dashboard-average-value,
    .foreign-geofence-kpi-value,
    .foreign-geofence-center strong {
        color: var(--fleet-ink);
        letter-spacing: 0;
    }
    .foreign-geofence-shell {
        border-radius: 18px;
        background:
            radial-gradient(circle at top left, rgba(37, 99, 235, .075), transparent 28%),
            var(--fleet-card-soft);
    }
    .foreign-geofence-header {
        padding-inline: 4px;
    }
    .foreign-geofence-title {
        letter-spacing: 0;
    }
    .foreign-geofence-legend-row {
        border-radius: 12px;
    }
    .foreign-geofence-progress {
        background: color-mix(in srgb, var(--fleet-muted) 12%, transparent);
    }
    .dashboard-loading-card,
    .modal-content {
        border-radius: 16px;
        border-color: var(--fleet-line);
        background: var(--fleet-card);
        box-shadow: 0 28px 80px rgba(15, 23, 42, .18);
    }
    .badge,
    .btn,
    .form-control,
    .form-select {
        letter-spacing: 0;
    }
    [data-theme="dark"] #dashboardFilterForm .form-control,
    [data-theme="dark"] #dashboardFilterForm .form-select,
    [data-theme="dark"] .dashboard-scroll-table,
    [data-theme="dark"] .dashboard-drilldown-table-wrapper,
    [data-theme="dark"] .foreign-geofence-table-wrap,
    [data-theme="dark"] .foreign-geofence-stat-wrap {
        background: var(--fleet-card);
        border-color: var(--fleet-line);
    }
    [data-theme="dark"] .metric-card::after {
        opacity: .18;
    }
    [data-theme="dark"] .dashboard-card,
    [data-theme="dark"] .dashboard-average-type-card,
    [data-theme="dark"] .foreign-geofence-card,
    [data-theme="dark"] .foreign-geofence-kpi,
    [data-theme="dark"] .foreign-geofence-table-card,
    [data-theme="dark"] .modal-content {
        box-shadow: 0 18px 46px rgba(0, 0, 0, .22) !important;
    }
    [data-theme="dark"] .dashboard-scroll-table thead th,
    [data-theme="dark"] .dashboard-drilldown-table thead th,
    [data-theme="dark"] .foreign-geofence-detail-table thead th,
    [data-theme="dark"] .foreign-geofence-stat-table thead th,
    [data-theme="dark"] .table thead th {
        background: var(--fleet-card-soft);
    }
</style>
@endpush

@section('content')
    <div
        class="dashboard-page"
        data-dashboard-layout-editable="{{ $canManageDashboardLayout ? '1' : '0' }}"
        data-dashboard-layout-update-url="{{ route('dashboard.layout.update') }}"
        data-dashboard-layout-reset-url="{{ route('dashboard.layout.destroy') }}"
        data-dashboard-drilldown-url="{{ route('dashboard.drilldown.units') }}"
        data-dashboard-drilldown-export-url="{{ route('dashboard.drilldown.units.export') }}"
        data-dashboard-date-from="{{ $filters['from'] }}"
        data-dashboard-date-to="{{ $filters['to'] }}"
        data-dashboard-project-id="{{ $filters['project_id'] ?? '' }}"
        data-dashboard-equipment-type-id="{{ $filters['equipment_type_id'] ?? '' }}"
        data-dashboard-ownership="{{ $filters['ownership_type'] === $nwc ? 'nwc' : ($filters['ownership_type'] === $icare ? 'icare' : 'all') }}"
    >
        <div class="dashboard-loading-overlay" id="dashboardLoadingOverlay" aria-hidden="true">
            <div class="dashboard-loading-card" role="status" aria-live="polite">
                <div class="d-flex align-items-center gap-3 mb-4">
                    <div class="dashboard-loading-icon">
                        <i class="bi bi-cloud-arrow-down"></i>
                    </div>
                    <div class="min-w-0">
                        <div class="fw-bold fs-5">{{ __('app.loading_title') }}</div>
                        <div class="small text-secondary" id="dashboardLoadingText">{{ __('app.loading_filters') }}</div>
                    </div>
                    <div class="dashboard-loading-percent ms-auto" id="dashboardLoadingPercent">0%</div>
                </div>
                <div class="dashboard-loading-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="dashboardLoadingProgress">
                    <div class="dashboard-loading-bar-value" id="dashboardLoadingProgressBar"></div>
                </div>
                <div class="small text-secondary mt-3">{{ __('app.loading_note') }}</div>
            </div>
        </div>

        <form method="GET" action="{{ $selectedProject ? route('projects.dashboard', $selectedProject) : route('dashboard') }}" class="panel p-3 mb-4" id="dashboardFilterForm">
            <input type="hidden" name="period" id="dashboardPeriodInput" value="{{ $selectedPeriod }}">
            <div class="row g-3 align-items-end">
                @unless($selectedProject)
                    <div class="col-12 col-xl-3 col-lg-4">
                        <label class="form-label">{{ __('app.project') }}</label>
                        <select name="project_id" class="form-select">
                            <option value="">{{ __('app.all_projects') }}</option>
                            @foreach ($projects as $project)
                                <option value="{{ $project->id }}" @selected((string) $filters['project_id'] === (string) $project->id)>{{ $project->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endunless
                <div class="col-6 col-lg-2">
                    <label class="form-label">{{ __('app.from') }}</label>
                    <input type="date" name="date_from" value="{{ $filters['from'] }}" class="form-control" id="dashboardDateFrom">
                </div>
                <div class="col-6 col-lg-2">
                    <label class="form-label">{{ __('app.to') }}</label>
                    <input type="date" name="date_to" value="{{ $filters['to'] }}" class="form-control" id="dashboardDateTo">
                </div>
                <div class="col-12 col-lg-2">
                    <label class="form-label">{{ __('app.type') }}</label>
                    <select name="equipment_type_id" class="form-select">
                        <option value="">{{ __('app.all_types') }}</option>
                        @foreach ($equipmentTypeOptions as $type)
                            <option value="{{ $type->id }}" @selected((string) $filters['equipment_type_id'] === (string) $type->id)>{{ $type->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-lg-2">
                    <label class="form-label">{{ __('app.ownership') }}</label>
                    <select name="ownership_type" class="form-select">
                        <option value="">{{ __('app.ownership_all') }}</option>
                        <option value="{{ $nwc }}" @selected($filters['ownership_type'] === $nwc)>{{ __('app.ownership_nwc') }}</option>
                        <option value="{{ $icare }}" @selected($filters['ownership_type'] === $icare)>{{ __('app.ownership_icare') }}</option>
                    </select>
                </div>
                <div class="col-12 col-lg-auto">
                    <button class="btn btn-primary btn-icon" id="dashboardFilterButton">
                        <i class="bi bi-funnel"></i><span>{{ __('app.filter') }}</span>
                    </button>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2 mt-3" aria-label="{{ __('app.period') }}">
                @foreach ($periodPresets as $key => $preset)
                    <button
                        type="button"
                        class="btn btn-sm {{ $selectedPeriod === $key ? 'btn-primary' : 'btn-outline-secondary' }} dashboard-period-button"
                        data-period="{{ $key }}"
                        data-from="{{ $preset['from'] }}"
                        data-to="{{ $preset['to'] }}"
                    >
                        {{ $preset['label'] }}
                    </button>
                @endforeach
            </div>
        </form>

        <div class="row g-3 mb-4">
            @foreach ($kpis as $kpi)
                <div class="col-12 col-md-6 col-xxl">
                    <section class="metric-card p-3 h-100">
                        <div class="d-flex align-items-center gap-3">
                            <div class="metric-icon" style="background: {{ $kpi['tone'] }}; color: {{ $kpi['color'] }};">
                                <i class="bi {{ $kpi['icon'] }}"></i>
                            </div>
                            <div class="min-w-0">
                                <div class="metric-title">{{ $kpi['label'] }}</div>
                                <div class="metric-value">{{ $kpi['value'] }}</div>
                                <div class="small text-secondary">
                                    <span class="{{ $kpi['change'] >= 0 ? 'change-up' : 'change-down' }}">
                                        <i class="bi {{ $kpi['change'] >= 0 ? 'bi-arrow-up' : 'bi-arrow-down' }}"></i>
                                        {{ abs($kpi['change']) }}%
                                    </span>
                                    {{ __('app.previous_period') }}
                                </div>
                            </div>
                        </div>
                    </section>
                </div>
            @endforeach
        </div>

        <div class="dashboard-layout-actions d-flex flex-wrap align-items-center justify-content-end gap-2 mb-2">
            <div class="dashboard-layout-status small me-auto" id="dashboardLayoutStatus" aria-live="polite"></div>
            <a href="{{ $exportUrl('overview') }}" class="btn btn-outline-secondary btn-sm btn-icon" title="Excel" aria-label="Excel">
                <i class="bi bi-download"></i><span>Excel</span>
            </a>
            @if ($canManageDashboardLayout)
                <button type="button" class="btn btn-outline-primary btn-sm btn-icon" id="editDashboardLayout">
                    <i class="bi bi-layout-three-columns"></i><span>Düzülüşü dəyiş</span>
                </button>
                <button type="button" class="btn btn-primary btn-sm btn-icon d-none" id="saveDashboardLayout">
                    <i class="bi bi-check2"></i><span>Yadda saxla</span>
                </button>
                <button type="button" class="btn btn-outline-secondary btn-sm btn-icon d-none" id="cancelDashboardLayout">
                    <i class="bi bi-x-lg"></i><span>Ləğv et</span>
                </button>
            <button type="button" class="btn btn-outline-secondary btn-sm dashboard-reset-order" id="resetDashboardLayout" title="Sıralamanı sıfırla" aria-label="Sıralamanı sıfırla">
                <i class="bi bi-arrow-counterclockwise"></i>
            </button>
            @endif
        </div>

        <div class="row g-3 dashboard-grid" id="dashboardGrid">
            @php
                $widgetLayout = $dashboardWidgetLayoutFor('ownership-share', 'col-12 col-lg-6 col-xxl-4', 4);
            @endphp
            <div class="{{ $widgetLayout['class'] }} dashboard-widget{{ $dashboardWidgetVisibilityClassFor('ownership-share') }}" data-dashboard-widget="ownership-share" data-widget-key="ownership-share" data-widget-width="{{ $widgetLayout['width'] }}" data-widget-order="{{ $widgetLayout['order'] }}" data-widget-visible="{{ $dashboardWidgetVisibleFor('ownership-share') ? '1' : '0' }}" style="order: {{ $widgetLayout['order'] }}" draggable="false">
                <section class="panel p-3 dashboard-card dashboard-card--compact d-flex flex-column">
                    <x-dashboard-card-header
                        :title="$dashboardWidgetTitleFor('ownership-share', __('app.ownership_share'))"
                        :export-url="$ownershipExportUrl()"
                        :export-items="[
                            ['label' => 'Bütün siyahı', 'url' => $ownershipExportUrl()],
                            ['label' => 'Yalnız NWC', 'url' => $ownershipExportUrl('nwc')],
                            ['label' => 'Yalnız İCARƏ', 'url' => $ownershipExportUrl('icare')],
                        ]"
                    />
                    <div class="dashboard-card-body flex-grow-1">
                        @if ($totalOwnershipCount > 0)
                            <div class="dashboard-donut-layout h-100">
                                <div class="chart-box chart-box--donut"><canvas id="ownershipDonut"></canvas></div>
                                <div class="vstack gap-3">
                                    @foreach ($ownershipShare as $row)
                                        @php
                                            $code = $row['label'];
                                            $percent = $totalOwnershipCount > 0 ? round(($row['count'] / $totalOwnershipCount) * 100, 1) : 0;
                                            $dotColor = $code === $nwc ? '#24b35b' : '#1f6feb';
                                        @endphp
                                        <div
                                            class="dashboard-share-row dashboard-drilldown-trigger"
                                            role="button"
                                            tabindex="0"
                                            data-drilldown-title="{{ $ownershipLabelFor($code) }} texnikaları"
                                            data-drilldown-ownership="{{ $code === $nwc ? 'nwc' : 'icare' }}"
                                        >
                                            <span class="dashboard-color-dot" style="background: {{ $dotColor }}"></span>
                                            <span class="fw-semibold">{{ $ownershipLabelFor($code) }}</span>
                                            <span>{{ $row['count'] }} / {{ $percent }}%</span>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @else
                            <div class="dashboard-empty">{{ __('app.no_data') }}</div>
                        @endif
                    </div>
                </section>
            </div>

            @php
                $widgetLayout = $dashboardWidgetLayoutFor('equipment-types-nwc', 'col-12 col-lg-6 col-xxl-4', 4);
            @endphp
            <div class="{{ $widgetLayout['class'] }} dashboard-widget{{ $dashboardWidgetVisibilityClassFor('equipment-types-nwc') }}" data-dashboard-widget="equipment-types-nwc" data-widget-key="equipment-types-nwc" data-widget-width="{{ $widgetLayout['width'] }}" data-widget-order="{{ $widgetLayout['order'] }}" data-widget-visible="{{ $dashboardWidgetVisibleFor('equipment-types-nwc') ? '1' : '0' }}" style="order: {{ $widgetLayout['order'] }}" draggable="false">
                <section class="panel p-3 dashboard-card dashboard-card--compact d-flex flex-column">
                    <x-dashboard-card-header :title="$dashboardWidgetTitleFor('equipment-types-nwc', __('app.equipment_type_distribution').': '.__('app.ownership_nwc'))" :export-url="$exportUrl('equipment-types-nwc')" />
                    @include('dashboard.partials.type-distribution-card', [
                        'chartId' => 'typeDonutNwc',
                        'rows' => $typeNwc,
                        'topRows' => $typeNwcTop,
                        'hasMore' => $typeNwcHasMore,
                        'expandId' => 'types-nwc',
                        'ownership' => 'nwc',
                        'ownershipLabel' => __('app.ownership_nwc'),
                    ])
                </section>
            </div>

            @php
                $widgetLayout = $dashboardWidgetLayoutFor('equipment-types-icare', 'col-12 col-lg-6 col-xxl-4', 4);
            @endphp
            <div class="{{ $widgetLayout['class'] }} dashboard-widget{{ $dashboardWidgetVisibilityClassFor('equipment-types-icare') }}" data-dashboard-widget="equipment-types-icare" data-widget-key="equipment-types-icare" data-widget-width="{{ $widgetLayout['width'] }}" data-widget-order="{{ $widgetLayout['order'] }}" data-widget-visible="{{ $dashboardWidgetVisibleFor('equipment-types-icare') ? '1' : '0' }}" style="order: {{ $widgetLayout['order'] }}" draggable="false">
                <section class="panel p-3 dashboard-card dashboard-card--compact d-flex flex-column">
                    <x-dashboard-card-header :title="$dashboardWidgetTitleFor('equipment-types-icare', __('app.equipment_type_distribution').': '.__('app.ownership_icare'))" :export-url="$exportUrl('equipment-types-icare')" />
                    @include('dashboard.partials.type-distribution-card', [
                        'chartId' => 'typeDonutIcare',
                        'rows' => $typeIcare,
                        'topRows' => $typeIcareTop,
                        'hasMore' => $typeIcareHasMore,
                        'expandId' => 'types-icare',
                        'ownership' => 'icare',
                        'ownershipLabel' => __('app.ownership_icare'),
                    ])
                </section>
            </div>

            @php
                $widgetLayout = $dashboardWidgetLayoutFor('project-work-categories-nwc', 'col-12 col-xl-6', 6);
            @endphp
            <div class="{{ $widgetLayout['class'] }} dashboard-widget{{ $dashboardWidgetVisibilityClassFor('project-work-categories-nwc') }}" data-dashboard-widget="project-work-categories-nwc" data-widget-key="project-work-categories-nwc" data-widget-width="{{ $widgetLayout['width'] }}" data-widget-order="{{ $widgetLayout['order'] }}" data-widget-visible="{{ $dashboardWidgetVisibleFor('project-work-categories-nwc') ? '1' : '0' }}" style="order: {{ $widgetLayout['order'] }}" draggable="false">
                @include('dashboard.partials.project-engine-hours-status-card', [
                    'chartId' => 'projectWorkCategoriesNwc',
                    'ownershipCode' => $nwc,
                    'ownershipLabel' => __('app.ownership_nwc'),
                    'summary' => $projectWorkCategorySummaryNwc,
                    'categoryLabels' => $actualWorkCategoryLabels,
                    'categoryRanges' => $actualWorkCategoryRanges,
                    'categoryColors' => $actualWorkCategoryColors,
                    'exportUrl' => $exportUrl('actual-work-hours-nwc'),
                    'filters' => $filters,
                    'title' => $dashboardWidgetTitleFor('project-work-categories-nwc', 'Project üzrə: '.__('app.ownership_nwc')),
                ])
            </div>

            @php
                $widgetLayout = $dashboardWidgetLayoutFor('project-work-categories-icare', 'col-12 col-xl-6', 6);
            @endphp
            <div class="{{ $widgetLayout['class'] }} dashboard-widget{{ $dashboardWidgetVisibilityClassFor('project-work-categories-icare') }}" data-dashboard-widget="project-work-categories-icare" data-widget-key="project-work-categories-icare" data-widget-width="{{ $widgetLayout['width'] }}" data-widget-order="{{ $widgetLayout['order'] }}" data-widget-visible="{{ $dashboardWidgetVisibleFor('project-work-categories-icare') ? '1' : '0' }}" style="order: {{ $widgetLayout['order'] }}" draggable="false">
                @include('dashboard.partials.project-engine-hours-status-card', [
                    'chartId' => 'projectWorkCategoriesIcare',
                    'ownershipCode' => $icare,
                    'ownershipLabel' => __('app.ownership_icare'),
                    'summary' => $projectWorkCategorySummaryIcare,
                    'categoryLabels' => $actualWorkCategoryLabels,
                    'categoryRanges' => $actualWorkCategoryRanges,
                    'categoryColors' => $actualWorkCategoryColors,
                    'exportUrl' => $exportUrl('actual-work-hours-icare'),
                    'filters' => $filters,
                    'title' => $dashboardWidgetTitleFor('project-work-categories-icare', 'Project üzrə: '.__('app.ownership_icare')),
                ])
            </div>

            @php
                $widgetLayout = $dashboardWidgetLayoutFor('average-engine-hours', 'col-12 col-xl-6', 6);
            @endphp
            <div class="{{ $widgetLayout['class'] }} dashboard-widget{{ $dashboardWidgetVisibilityClassFor('average-engine-hours') }}" data-dashboard-widget="average-engine-hours" data-widget-key="average-engine-hours" data-widget-width="{{ $widgetLayout['width'] }}" data-widget-order="{{ $widgetLayout['order'] }}" data-widget-visible="{{ $dashboardWidgetVisibleFor('average-engine-hours') ? '1' : '0' }}" style="order: {{ $widgetLayout['order'] }}" draggable="false">
                @include('dashboard.partials.daily-average-dashboard-card', [
                    'metric' => 'engine_hours',
                    'dashboard' => $dailyAverageDashboards['engine_hours'] ?? [],
                    'title' => $dashboardWidgetTitleFor('average-engine-hours', 'Orta motosaat göstəricisi'),
                    'subtitle' => 'Hər gün üzrə orta motosaat (saat)',
                    'exportUrl' => $exportUrl('average-engine-hours'),
                    'filters' => $filters,
                    'selectedProject' => $selectedProject,
                ])
            </div>

            @php
                $widgetLayout = $dashboardWidgetLayoutFor('average-mileage', 'col-12 col-xl-6', 6);
            @endphp
            <div class="{{ $widgetLayout['class'] }} dashboard-widget{{ $dashboardWidgetVisibilityClassFor('average-mileage') }}" data-dashboard-widget="average-mileage" data-widget-key="average-mileage" data-widget-width="{{ $widgetLayout['width'] }}" data-widget-order="{{ $widgetLayout['order'] }}" data-widget-visible="{{ $dashboardWidgetVisibleFor('average-mileage') ? '1' : '0' }}" style="order: {{ $widgetLayout['order'] }}" draggable="false">
                @include('dashboard.partials.daily-average-dashboard-card', [
                    'metric' => 'mileage',
                    'dashboard' => $dailyAverageDashboards['mileage'] ?? [],
                    'title' => $dashboardWidgetTitleFor('average-mileage', 'Orta yürüş göstəricisi'),
                    'subtitle' => 'Hər gün üzrə orta yürüş (km)',
                    'exportUrl' => $exportUrl('average-mileage'),
                    'filters' => $filters,
                    'selectedProject' => $selectedProject,
                ])
            </div>

            @php
                $widgetLayout = $dashboardWidgetLayoutFor('least-working', 'col-12 col-xl-6', 6);
            @endphp
            <div class="{{ $widgetLayout['class'] }} dashboard-widget{{ $dashboardWidgetVisibilityClassFor('least-working') }}" data-dashboard-widget="least-working" data-widget-key="least-working" data-widget-width="{{ $widgetLayout['width'] }}" data-widget-order="{{ $widgetLayout['order'] }}" data-widget-visible="{{ $dashboardWidgetVisibleFor('least-working') ? '1' : '0' }}" style="order: {{ $widgetLayout['order'] }}" draggable="false">
                <section class="panel p-3 dashboard-card">
                    <x-dashboard-card-header :title="$dashboardWidgetTitleFor('least-working', __('app.least_working'))" :export-url="$exportUrl('least-working')" />
                    @include('dashboard.partials.ranking-table', ['rows' => $data['leastWorking'], 'ranking' => 'least'])
                </section>
            </div>

            @php
                $widgetLayout = $dashboardWidgetLayoutFor('most-working', 'col-12 col-xl-6', 6);
            @endphp
            <div class="{{ $widgetLayout['class'] }} dashboard-widget{{ $dashboardWidgetVisibilityClassFor('most-working') }}" data-dashboard-widget="most-working" data-widget-key="most-working" data-widget-width="{{ $widgetLayout['width'] }}" data-widget-order="{{ $widgetLayout['order'] }}" data-widget-visible="{{ $dashboardWidgetVisibleFor('most-working') ? '1' : '0' }}" style="order: {{ $widgetLayout['order'] }}" draggable="false">
                <section class="panel p-3 dashboard-card">
                    <x-dashboard-card-header :title="$dashboardWidgetTitleFor('most-working', __('app.most_working'))" :export-url="$exportUrl('most-working')" />
                    @include('dashboard.partials.ranking-table', ['rows' => $data['mostWorking'], 'ranking' => 'most'])
                </section>
            </div>

            @php
                $widgetLayout = $dashboardWidgetLayoutFor('geofence-analysis', 'col-12', 12);
                $geofenceAnalysisTitle = $dashboardWidgetTitleFor('geofence-analysis', __('app.geofence_analysis'));
            @endphp
            <div class="{{ $widgetLayout['class'] }} dashboard-widget{{ $dashboardWidgetVisibilityClassFor('geofence-analysis') }}" data-dashboard-widget="geofence-analysis" data-widget-key="geofence-analysis" data-widget-width="{{ $widgetLayout['width'] }}" data-widget-order="{{ $widgetLayout['order'] }}" data-widget-visible="{{ $dashboardWidgetVisibleFor('geofence-analysis') ? '1' : '0' }}" style="order: {{ $widgetLayout['order'] }}" draggable="false">
                <section class="panel dashboard-card foreign-geofence-shell">
                    <div class="foreign-geofence-header">
                        <div class="min-w-0">
                            <h2 class="foreign-geofence-title dashboard-card-title-text">{{ $geofenceAnalysisTitle }}</h2>
                            <input type="text" class="form-control form-control-sm dashboard-title-input mt-1 d-none" value="{{ $geofenceAnalysisTitle }}" maxlength="120" aria-label="Dashboard başlığı">
                            <div class="foreign-geofence-subtitle">Öz layihəsinin geozonasından kənarda olan texnikalar</div>
                            <div class="foreign-geofence-context" title="{{ $geofenceHomeProjectLabel }}">
                                <i class="bi bi-house-check"></i>
                                <span>Ev layihəsi: {{ $geofenceHomeProjectLabel }}</span>
                            </div>
                        </div>
                        <div class="foreign-geofence-actions">
                            <select class="form-select form-select-sm foreign-geofence-period" id="foreignGeofencePeriodSelect" aria-label="{{ __('app.period') }}">
                                @foreach ($periodPresets as $key => $preset)
                                    <option value="{{ $key }}" data-from="{{ $preset['from'] }}" data-to="{{ $preset['to'] }}" @selected($selectedPeriod === $key)>
                                        {{ $preset['label'] }} · {{ $preset['from'] }} - {{ $preset['to'] }}
                                    </option>
                                @endforeach
                            </select>
                            <button type="button" class="btn btn-outline-primary foreign-geofence-action" id="foreignGeofenceRefresh">
                                <i class="bi bi-arrow-clockwise"></i><span>Refresh</span>
                            </button>
                            <a href="{{ $exportUrl('geofence-analysis') }}" class="btn btn-primary foreign-geofence-action">
                                <i class="bi bi-download"></i><span>Excel</span>
                            </a>
                            <button type="button" class="btn btn-sm dashboard-visibility-toggle foreign-geofence-action" title="Bloku gizlət" aria-label="Bloku gizlət">
                                <i class="bi bi-eye-slash"></i>
                            </button>
                            <button type="button" class="btn btn-sm dashboard-drag-handle foreign-geofence-action" title="Bloku daşı" aria-label="Bloku daşı">
                                <i class="bi bi-grip-vertical"></i>
                            </button>
                        </div>
                    </div>

                    <div class="foreign-geofence-grid">
                        <div class="foreign-geofence-card">
                            <div class="foreign-geofence-donut-layout">
                                <div class="foreign-geofence-donut-wrap">
                                    <canvas id="geofenceViolationsDonut"></canvas>
                                    <div class="foreign-geofence-center">
                                        <div>
                                            <strong>{{ number_format($geofenceViolationTotal) }}</strong>
                                            <span>Ümumi texnika</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="foreign-geofence-legend" aria-label="Cari geozona üzrə bölgü">
                                    @if ($geofenceViolationRows->isNotEmpty())
                                        @foreach ($geofenceViolationRows as $row)
                                            @php
                                                $count = (int) ($row['count'] ?? 0);
                                                $percent = $geofenceViolationTotal > 0 ? round(($count / $geofenceViolationTotal) * 100) : 0;
                                                $color = $geofenceViolationPalette[$loop->index % count($geofenceViolationPalette)];
                                            @endphp
                                            <button
                                                type="button"
                                                class="foreign-geofence-legend-row dashboard-drilldown-trigger"
                                                data-drilldown-title="{{ $row['label'] ?? $row['project'] }} - Geozonadan çıxma halları"
                                                data-drilldown-geofence-violation="1"
                                                data-drilldown-current-geozone-project-id="{{ $row['project_id'] }}"
                                                data-drilldown-current-geozone-id="{{ $row['geofence_id'] }}"
                                                data-drilldown-current-geozone-key="{{ $row['sector_key'] }}"
                                                title="{{ $row['label'] ?? $row['project'] }}"
                                            >
                                                <span class="foreign-geofence-dot" style="background: {{ $color }}"></span>
                                                <span class="foreign-geofence-legend-name">{{ $row['label'] ?? $row['project'] }}</span>
                                                <span class="foreign-geofence-legend-count">{{ number_format($count) }}</span>
                                                <span class="foreign-geofence-legend-percent">{{ $percent }}%</span>
                                            </button>
                                        @endforeach
                                    @else
                                        <div class="foreign-geofence-empty">Seçilmiş layihə üzrə məlumat tapılmadı</div>
                                    @endif
                                </div>
                            </div>
                        </div>
                        <div class="foreign-geofence-card">
                            <h3 class="foreign-geofence-card-title">Cari geozona / layihə üzrə statistika</h3>
                            <div class="table-responsive foreign-geofence-stat-wrap">
                                <table class="foreign-geofence-stat-table">
                                    <thead>
                                        <tr>
                                            <th>Layihə</th>
                                            <th class="text-end">Texnika sayı</th>
                                            <th class="text-end">Faiz</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse ($geofenceViolationRows as $row)
                                            @php
                                                $count = (int) ($row['count'] ?? 0);
                                                $percent = $geofenceViolationTotal > 0 ? round(($count / $geofenceViolationTotal) * 100) : 0;
                                                $barWidth = min(100, max(3, round(($count / $geofenceViolationMaxCount) * 100)));
                                            @endphp
                                            <tr
                                                class="foreign-geofence-stat-row dashboard-drilldown-trigger"
                                                role="button"
                                                tabindex="0"
                                                data-drilldown-title="{{ $row['label'] ?? $row['project'] }} - Geozonadan çıxma halları"
                                                data-drilldown-geofence-violation="1"
                                                data-drilldown-current-geozone-project-id="{{ $row['project_id'] }}"
                                                data-drilldown-current-geozone-id="{{ $row['geofence_id'] }}"
                                                data-drilldown-current-geozone-key="{{ $row['sector_key'] }}"
                                                title="{{ $row['label'] ?? $row['project'] }}"
                                            >
                                                <td>
                                                    <div class="fw-bold text-truncate" title="{{ $row['label'] ?? $row['project'] }}">{{ $row['label'] ?? $row['project'] }}</div>
                                                    <div class="foreign-geofence-progress"><span style="width: {{ $barWidth }}%"></span></div>
                                                </td>
                                                <td class="text-end fw-bold">{{ number_format($count) }}</td>
                                                <td class="text-end text-secondary fw-bold">{{ $percent }}%</td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="3" class="foreign-geofence-empty">Seçilmiş layihə üzrə məlumat tapılmadı</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                            @if ($geofenceViolationTotal > 0)
                                <button
                                    type="button"
                                    class="btn btn-link foreign-geofence-show-all mt-2 dashboard-drilldown-trigger"
                                    data-drilldown-title="Geozonadan çıxma halları"
                                    data-drilldown-geofence-violation="1"
                                >
                                    Hamısını göstər <i class="bi bi-arrow-right"></i>
                                </button>
                            @endif
                        </div>
                    </div>

                    <div class="foreign-geofence-kpi-grid">
                        <div class="foreign-geofence-kpi">
                            <div class="foreign-geofence-kpi-label">Ümumi pozuntu</div>
                            <div class="foreign-geofence-kpi-value">{{ number_format($geofenceViolationTotal) }}</div>
                            <div class="foreign-geofence-kpi-note">Dashboard seçimi</div>
                        </div>
                        <div class="foreign-geofence-kpi">
                            <div class="foreign-geofence-kpi-label">3 saatdan çox</div>
                            <div class="foreign-geofence-kpi-value">{{ number_format($geofenceViolationTotal) }}</div>
                            <div class="foreign-geofence-kpi-note">Minimum müddət filtri</div>
                        </div>
                        <div class="foreign-geofence-kpi">
                            <div class="foreign-geofence-kpi-label">Aktiv layihələr</div>
                            <div class="foreign-geofence-kpi-value">{{ number_format($geofenceViolationActiveProjects) }}</div>
                            <div class="foreign-geofence-kpi-note">Cari xarici geozonalar</div>
                        </div>
                        <div class="foreign-geofence-kpi">
                            <div class="foreign-geofence-kpi-label">Ən böyük geozona</div>
                            <div class="foreign-geofence-kpi-value">{{ number_format((int) ($geofenceViolationTopRow['count'] ?? 0)) }}</div>
                            <div class="foreign-geofence-kpi-note">{{ $geofenceViolationTopRow['label'] ?? __('app.no_data') }}</div>
                        </div>
                    </div>

                    <div class="foreign-geofence-table-card">
                        <div class="foreign-geofence-table-toolbar">
                            <div class="min-w-0">
                                <h3 class="foreign-geofence-table-title">Geozonadan çıxma halları{{ $filters['project_id'] ? ' - '.$geofenceHomeProjectLabel : '' }}</h3>
                                <div class="foreign-geofence-table-meta" id="foreignGeofenceTableMeta">Məlumatlar yüklənir...</div>
                            </div>
                            <div class="foreign-geofence-table-actions">
                                <div class="foreign-geofence-search">
                                    <i class="bi bi-search"></i>
                                    <input type="search" class="form-control" id="foreignGeofenceSearch" placeholder="Axtarış...">
                                </div>
                                <select class="form-select foreign-geofence-action" id="foreignGeofenceOwnershipFilter" aria-label="{{ __('app.ownership') }}">
                                    <option value="all">Hamısı</option>
                                    <option value="nwc">NWC</option>
                                    <option value="icare">İcarə</option>
                                </select>
                                <select class="form-select foreign-geofence-page-size" id="foreignGeofencePageSize" aria-label="Sətir sayı">
                                    <option value="20" selected>20</option>
                                    <option value="50">50</option>
                                    <option value="100">100</option>
                                </select>
                                <a href="{{ $exportUrl('geofence-analysis') }}" class="btn btn-outline-primary foreign-geofence-action" id="foreignGeofenceTableExport">
                                    <i class="bi bi-download"></i><span>Eksport</span>
                                </a>
                            </div>
                        </div>
                        <div class="foreign-geofence-table-wrap">
                            <table class="table foreign-geofence-detail-table align-middle">
                                <colgroup>
                                    <col style="width: 100px;">
                                    <col style="width: 110px;">
                                    <col style="width: 110px;">
                                    <col style="width: 80px;">
                                    <col style="width: 170px;">
                                    <col style="width: 170px;">
                                    <col style="width: 180px;">
                                    <col style="width: 125px;">
                                    <col style="width: 120px;">
                                    <col style="width: 100px;">
                                </colgroup>
                                <thead>
                                    <tr>
                                        <th>Texnika</th>
                                        <th>Qeydiyyat nişanı</th>
                                        <th>Texnika növü</th>
                                        <th>Ownership</th>
                                        <th>Ev layihəsi</th>
                                        <th>Cari layihə</th>
                                        <th>Cari geozona</th>
                                        <th>Giriş vaxtı</th>
                                        <th>Qalma müddəti</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody id="foreignGeofenceRows">
                                    <tr><td colspan="10" class="foreign-geofence-loading">Məlumatlar yüklənir...</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="foreign-geofence-pagination" aria-label="Geozonadan çıxma halları səhifələmə">
                            <button type="button" class="btn btn-outline-secondary" id="foreignGeofencePrev">Əvvəlki</button>
                            <span id="foreignGeofencePageInfo">1 / 1</span>
                            <button type="button" class="btn btn-outline-secondary" id="foreignGeofenceNext">Növbəti</button>
                        </div>
                    </div>
                </section>
            </div>

            @php
                $widgetLayout = $dashboardWidgetLayoutFor('utilization-trend', 'col-12 col-xl-5', 5);
            @endphp
            <div class="{{ $widgetLayout['class'] }} dashboard-widget{{ $dashboardWidgetVisibilityClassFor('utilization-trend') }}" data-dashboard-widget="utilization-trend" data-widget-key="utilization-trend" data-widget-width="{{ $widgetLayout['width'] }}" data-widget-order="{{ $widgetLayout['order'] }}" data-widget-visible="{{ $dashboardWidgetVisibleFor('utilization-trend') ? '1' : '0' }}" style="order: {{ $widgetLayout['order'] }}" draggable="false">
                <section class="panel p-3 dashboard-card">
                    <x-dashboard-card-header :title="$dashboardWidgetTitleFor('utilization-trend', __('app.utilization_trend'))" :export-url="$exportUrl('utilization-trend')" />
                    @if ($utilizationTrendByOwnership['has_data'] ?? false)
                        <div class="chart-box"><canvas id="utilizationLine"></canvas></div>
                    @else
                        <div class="dashboard-empty">{{ __('app.no_data') }}</div>
                    @endif
                </section>
            </div>

            @php
                $widgetLayout = $dashboardWidgetLayoutFor('project-comparison', 'col-12', 12);
            @endphp
            <div class="{{ $widgetLayout['class'] }} dashboard-widget{{ $dashboardWidgetVisibilityClassFor('project-comparison') }}" data-dashboard-widget="project-comparison" data-widget-key="project-comparison" data-widget-width="{{ $widgetLayout['width'] }}" data-widget-order="{{ $widgetLayout['order'] }}" data-widget-visible="{{ $dashboardWidgetVisibleFor('project-comparison') ? '1' : '0' }}" style="order: {{ $widgetLayout['order'] }}" draggable="false">
                <section class="panel p-3 dashboard-card">
                    <x-dashboard-card-header :title="$dashboardWidgetTitleFor('project-comparison', __('app.work_hours_by_ownership'))" :export-url="$exportUrl('project-comparison')" />
                    @if ($projectComparisonRows->isNotEmpty())
                        <div class="row g-3 align-items-start">
                            <div class="col-lg-7">
                                <div class="dashboard-chart-scroll">
                                    <div style="height: {{ $projectComparisonChartHeight }}px; min-width: 680px;">
                                        <canvas id="projectComparison"></canvas>
                                    </div>
                                </div>
                            </div>
                            <div class="col-lg-5">
                                <div class="dashboard-scroll-table" data-expandable="project-comparison">
                                    <table class="table table-sm align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>{{ __('app.project') }}</th>
                                                <th class="text-end">NWC</th>
                                                <th class="text-end">{{ __('app.ownership_icare') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($projectComparisonRows as $row)
                                                <tr
                                                    class="{{ $loop->iteration > 10 ? 'expandable-extra d-none' : '' }} dashboard-drilldown-trigger"
                                                    role="button"
                                                    tabindex="0"
                                                    data-drilldown-title="{{ $row['name'] }} - Bütün texnika"
                                                    data-drilldown-project-id="{{ $row['id'] }}"
                                                    data-drilldown-ownership="all"
                                                >
                                                    <td class="fw-semibold">{{ $row['name'] }}</td>
                                                    <td
                                                        class="text-end dashboard-drilldown-trigger"
                                                        role="button"
                                                        tabindex="0"
                                                        data-drilldown-title="{{ $row['name'] }} - NWC"
                                                        data-drilldown-project-id="{{ $row['id'] }}"
                                                        data-drilldown-ownership="nwc"
                                                    >{{ number_format($row[$nwc], 0) }}</td>
                                                    <td
                                                        class="text-end dashboard-drilldown-trigger"
                                                        role="button"
                                                        tabindex="0"
                                                        data-drilldown-title="{{ $row['name'] }} - {{ __('app.ownership_icare') }}"
                                                        data-drilldown-project-id="{{ $row['id'] }}"
                                                        data-drilldown-ownership="icare"
                                                    >{{ number_format($row[$icare], 0) }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                                @if ($projectComparisonHasMore)
                                    <button type="button" class="btn btn-link dashboard-expand-toggle mt-2" data-expand-toggle="project-comparison" data-show-label="Hamısını göstər" data-hide-label="Gizlət">Hamısını göstər</button>
                                @endif
                            </div>
                        </div>
                    @else
                        <div class="dashboard-empty">{{ __('app.no_data') }}</div>
                    @endif
                </section>
            </div>
        </div>
    </div>

    <div class="modal fade" id="dashboardDrilldownModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title" id="dashboardDrilldownTitle">Texnika siyahısı</h5>
                        <div class="small text-secondary" id="dashboardDrilldownFilters"></div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Bağla"></button>
                </div>
                <div class="modal-body">
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                        <div class="btn-group btn-group-sm" role="group" aria-label="Mənsubiyyət">
                            <button type="button" class="btn btn-outline-primary dashboard-drilldown-filter" data-filter-name="ownership" data-filter-value="all">Hamısı</button>
                            <button type="button" class="btn btn-outline-primary dashboard-drilldown-filter" data-filter-name="ownership" data-filter-value="nwc">NWC</button>
                            <button type="button" class="btn btn-outline-primary dashboard-drilldown-filter" data-filter-name="ownership" data-filter-value="icare">İCARƏ</button>
                        </div>
                        <div class="btn-group btn-group-sm" role="group" aria-label="Məlumat statusu">
                            <button type="button" class="btn btn-outline-secondary dashboard-drilldown-filter" data-filter-name="data_status" data-filter-value="all">Hamısı</button>
                            <button type="button" class="btn btn-outline-secondary dashboard-drilldown-filter" data-filter-name="data_status" data-filter-value="available">Məlumat var</button>
                            <button type="button" class="btn btn-outline-secondary dashboard-drilldown-filter" data-filter-name="data_status" data-filter-value="missing">Məlumat yoxdur</button>
                        </div>
                        <button type="button" class="btn btn-outline-primary btn-sm ms-auto" id="dashboardDrilldownFilterToggle">
                            <i class="bi bi-funnel"></i> Filtrlər
                        </button>
                        <select class="form-select form-select-sm d-none" id="dashboardDrilldownGroupMode" aria-label="Qruplaşdırma" style="max-width: 190px;">
                            <option value="details">Gündəlik detallar</option>
                            <option value="day">Gün üzrə</option>
                            <option value="unit">Texnika üzrə</option>
                        </select>
                        <input type="search" class="form-control form-control-sm" id="dashboardDrilldownSearch" placeholder="Axtarış..." style="max-width: 260px;">
                    </div>
                    <div class="dashboard-drilldown-filter-panel d-none" id="dashboardDrilldownFilterPanel">
                        <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                            <strong>Əlavə filtrlər</strong>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="dashboardDrilldownFilterClose">Bağla</button>
                        </div>
                        <div class="row g-2">
                            <div class="col-6 col-lg-3">
                                <label class="form-label" for="dashboardDrilldownDateFrom">Tarixdən</label>
                                <input type="date" class="form-control form-control-sm dashboard-drilldown-control" id="dashboardDrilldownDateFrom" data-filter-name="date_from">
                            </div>
                            <div class="col-6 col-lg-3">
                                <label class="form-label" for="dashboardDrilldownDateTo">Tarixədək</label>
                                <input type="date" class="form-control form-control-sm dashboard-drilldown-control" id="dashboardDrilldownDateTo" data-filter-name="date_to">
                            </div>
                            <div class="col-6 col-lg-3">
                                <label class="form-label" for="dashboardDrilldownOwnershipSelect">Mənsubiyyət</label>
                                <select class="form-select form-select-sm dashboard-drilldown-control" id="dashboardDrilldownOwnershipSelect" data-filter-name="ownership">
                                    <option value="all">Hamısı</option>
                                    <option value="nwc">NWC</option>
                                    <option value="icare">İCARƏ</option>
                                </select>
                            </div>
                            <div class="col-6 col-lg-3">
                                <label class="form-label" for="dashboardDrilldownDataStatusSelect">Məlumat statusu</label>
                                <select class="form-select form-select-sm dashboard-drilldown-control" id="dashboardDrilldownDataStatusSelect" data-filter-name="data_status">
                                    <option value="all">Hamısı</option>
                                    <option value="available">Məlumat var</option>
                                    <option value="missing">Məlumat yoxdur</option>
                                </select>
                            </div>
                            <div class="col-12 col-lg-4 dashboard-efficiency-filter-group">
                                <label class="form-label" for="dashboardDrilldownProjects">Layihə</label>
                                <select class="form-select form-select-sm dashboard-drilldown-control" id="dashboardDrilldownProjects" data-filter-name="project_ids" multiple size="4">
                                    @foreach ($projects as $project)
                                        <option value="{{ $project->id }}">{{ $project->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-12 col-lg-4">
                                <label class="form-label" for="dashboardDrilldownVehicleTypes">Texnika növü</label>
                                <select class="form-select form-select-sm dashboard-drilldown-control" id="dashboardDrilldownVehicleTypes" data-filter-name="vehicle_types" multiple size="4">
                                    @foreach ($efficiencyVehicleTypes as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-12 col-lg-4">
                                <div class="row g-2">
                                    <div class="col-6">
                                        <label class="form-label" for="dashboardDrilldownDayStatus">Gündüz statusu</label>
                                        <select class="form-select form-select-sm dashboard-drilldown-control" id="dashboardDrilldownDayStatus" data-filter-name="day_status">
                                            <option value="">Hamısı</option>
                                            <option value="less_than_1_hour">1 saatdan az işləyən</option>
                                            <option value="less_than_7_hours">7 saatdan az işləyən</option>
                                            <option value="between_7_and_10_hours">7-10 saat işləyən</option>
                                            <option value="over_10_hours">{{ __('app.worked_over_10_hours') }}</option>
                                        </select>
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label" for="dashboardDrilldownOvertime">Overtime</label>
                                        <select class="form-select form-select-sm dashboard-drilldown-control" id="dashboardDrilldownOvertime" data-filter-name="has_overtime">
                                            <option value="all">Hamısı</option>
                                            <option value="yes">Var</option>
                                            <option value="no">Yoxdur</option>
                                        </select>
                                    </div>
                                    <div class="col-4">
                                        <label class="form-label" for="dashboardDrilldownDayMin">Gündüz min</label>
                                        <input type="number" min="0" step="0.1" class="form-control form-control-sm dashboard-drilldown-control" id="dashboardDrilldownDayMin" data-filter-name="day_hours_min">
                                    </div>
                                    <div class="col-4">
                                        <label class="form-label" for="dashboardDrilldownOvertimeMin">Overtime min</label>
                                        <input type="number" min="0" step="0.1" class="form-control form-control-sm dashboard-drilldown-control" id="dashboardDrilldownOvertimeMin" data-filter-name="overtime_hours_min">
                                    </div>
                                    <div class="col-4">
                                        <label class="form-label" for="dashboardDrilldownTotalMin">Ümumi min</label>
                                        <input type="number" min="0" step="0.1" class="form-control form-control-sm dashboard-drilldown-control" id="dashboardDrilldownTotalMin" data-filter-name="total_hours_min">
                                    </div>
                                    <div class="col-4">
                                        <label class="form-label" for="dashboardDrilldownDayMax">Gündüz max</label>
                                        <input type="number" min="0" step="0.1" class="form-control form-control-sm dashboard-drilldown-control" id="dashboardDrilldownDayMax" data-filter-name="day_hours_max">
                                    </div>
                                    <div class="col-4">
                                        <label class="form-label" for="dashboardDrilldownOvertimeMax">Overtime max</label>
                                        <input type="number" min="0" step="0.1" class="form-control form-control-sm dashboard-drilldown-control" id="dashboardDrilldownOvertimeMax" data-filter-name="overtime_hours_max">
                                    </div>
                                    <div class="col-4">
                                        <label class="form-label" for="dashboardDrilldownTotalMax">Ümumi max</label>
                                        <input type="number" min="0" step="0.1" class="form-control form-control-sm dashboard-drilldown-control" id="dashboardDrilldownTotalMax" data-filter-name="total_hours_max">
                                    </div>
                                </div>
                            </div>
                            <div class="col-12 col-lg-4">
                                <label class="form-label" for="dashboardDrilldownUnitName">Texnikanın adı</label>
                                <input type="search" class="form-control form-control-sm dashboard-drilldown-control" id="dashboardDrilldownUnitName" data-filter-name="unit_name">
                            </div>
                            <div class="col-6 col-lg-4">
                                <label class="form-label" for="dashboardDrilldownRegistration">Qeydiyyat nişanı</label>
                                <input type="search" class="form-control form-control-sm dashboard-drilldown-control" id="dashboardDrilldownRegistration" data-filter-name="registration_number">
                            </div>
                            <div class="col-6 col-lg-4">
                                <label class="form-label" for="dashboardDrilldownWialonId">Wialon ID</label>
                                <input type="search" class="form-control form-control-sm dashboard-drilldown-control" id="dashboardDrilldownWialonId" data-filter-name="wialon_id">
                            </div>
                        </div>
                        <div class="d-flex flex-wrap justify-content-end gap-2 mt-3">
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="dashboardDrilldownClearFilters">Təmizlə</button>
                            <button type="button" class="btn btn-sm btn-primary" id="dashboardDrilldownApplyFilters">Filtrləri tətbiq et</button>
                        </div>
                    </div>
                    <div class="dashboard-drilldown-chips" id="dashboardDrilldownChips"></div>
                    <div class="dashboard-drilldown-status small text-secondary mb-2" id="dashboardDrilldownStatus">Məlumatlar yüklənir...</div>
                    <div class="dashboard-drilldown-formula d-none" id="dashboardDrilldownFormula"></div>
                    <div class="dashboard-drilldown-table-wrapper border rounded">
                        <table class="table table-sm align-middle mb-0 dashboard-drilldown-table">
                            <thead>
                                <tr id="dashboardDrilldownHeader">
                                    <th>#</th>
                                    <th>Texnikanın adı</th>
                                    <th>Texnika növü</th>
                                    <th>Mənsubiyyət</th>
                                    <th>Layihə</th>
                                    <th>Wialon ID</th>
                                </tr>
                            </thead>
                            <tbody id="dashboardDrilldownRows"></tbody>
                        </table>
                    </div>
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-3">
                        <div class="small text-secondary" id="dashboardDrilldownPageInfo"></div>
                        <div class="d-flex align-items-center gap-2">
                            <select class="form-select form-select-sm" id="dashboardDrilldownPageSize" style="width: auto;">
                                <option value="20">20</option>
                                <option value="50" selected>50</option>
                                <option value="100">100</option>
                            </select>
                            <div class="btn-group btn-group-sm">
                                <button type="button" class="btn btn-outline-secondary" id="dashboardDrilldownPrev">Əvvəlki</button>
                                <button type="button" class="btn btn-outline-secondary" id="dashboardDrilldownNext">Növbəti</button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <a href="#" class="btn btn-outline-secondary btn-sm" id="dashboardDrilldownExport">
                        <i class="bi bi-download"></i> Excel
                    </a>
                    <button type="button" class="btn btn-outline-primary btn-sm d-none" id="dashboardDrilldownRetry">Yenidən cəhd et</button>
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Bağla</button>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
const ownershipColor = { NWC: '#24b35b', ICARE: '#1f6feb' };
const typePalette = ['#1f6feb', '#24b35b', '#f6ad00', '#8b5cf6', '#0ea5b7', '#94a3b8', '#f97316', '#14b8a6', '#6366f1', '#ef4444'];
const workCategoryColors = {
    less_than_1_hour: '#1f6feb',
    less_than_7_hours: '#f97316',
    between_7_and_10_hours: '#24b35b',
    over_10_hours: '#8b5cf6',
    overtime: '#ef4444',
};
const ownershipShareLabels = @json($ownershipShare->pluck('label')->map($ownershipLabelFor)->values());
const ownershipShareCounts = @json($ownershipShare->pluck('count')->values());
const ownershipShareTotal = {{ (int) $totalOwnershipCount }};
const typeNwcLabels = @json($typeNwcTop->pluck('name')->values());
const typeNwcTotals = @json($typeNwcTop->pluck('total')->values());
const typeNwcIds = @json($typeNwcTop->pluck('id')->values());
const typeNwcTotal = {{ (int) $typeNwc->sum('total') }};
const typeIcareLabels = @json($typeIcareTop->pluck('name')->values());
const typeIcareTotals = @json($typeIcareTop->pluck('total')->values());
const typeIcareIds = @json($typeIcareTop->pluck('id')->values());
const typeIcareTotal = {{ (int) $typeIcare->sum('total') }};
const workCategoryKeys = @json($actualWorkCategoryLabels->keys()->values());
const workCategoryLabels = @json($actualWorkCategoryLabels->values());
const workCategoryColorValues = workCategoryKeys.map(key => workCategoryColors[key]);
const projectWorkCategoryNwcCounts = @json($actualWorkCategoryLabels->keys()->map(fn (string $key) => (int) ($projectWorkCategorySummaryNwc[$key] ?? 0))->values());
const projectWorkCategoryIcareCounts = @json($actualWorkCategoryLabels->keys()->map(fn (string $key) => (int) ($projectWorkCategorySummaryIcare[$key] ?? 0))->values());
const workCategoryDonutKeys = workCategoryKeys.filter(key => key !== 'overtime');
const workCategoryDonutIndexes = workCategoryDonutKeys.map(key => workCategoryKeys.indexOf(key));
const workCategoryDonutLabels = workCategoryDonutIndexes.map(index => workCategoryLabels[index]);
const workCategoryDonutColorValues = workCategoryDonutKeys.map(key => workCategoryColors[key]);
const projectWorkCategoryNwcDonutCounts = workCategoryDonutIndexes.map(index => projectWorkCategoryNwcCounts[index] || 0);
const projectWorkCategoryIcareDonutCounts = workCategoryDonutIndexes.map(index => projectWorkCategoryIcareCounts[index] || 0);
const utilizationTrend = @json($utilizationTrendByOwnership);
const projectComparisonLabels = @json($projectComparisonTop->pluck('name')->values());
const projectComparisonIds = @json($projectComparisonTop->pluck('id')->values());
const projectComparisonNwc = @json($projectComparisonTop->pluck($nwc)->values());
const projectComparisonIcare = @json($projectComparisonTop->pluck($icare)->values());
const dailyAverageDashboards = @json($dailyAverageDashboards);
const geofenceViolationLabels = @json($geofenceViolations['labels'] ?? []);
const geofenceViolationCounts = @json($geofenceViolations['counts'] ?? []);
const geofenceViolationProjectIds = @json($geofenceViolations['project_ids'] ?? []);
const geofenceViolationGeofenceIds = @json($geofenceViolations['geofence_ids'] ?? []);
const geofenceViolationSectorKeys = @json($geofenceViolations['sector_keys'] ?? []);
const geofenceViolationTotal = {{ (int) ($geofenceViolations['total'] ?? 0) }};
const geofenceViolationPalette = @json($geofenceViolationPalette);
const dashboardPage = document.querySelector('.dashboard-page');
const dashboardGrid = document.getElementById('dashboardGrid');
const dashboardResetButton = document.getElementById('resetDashboardLayout');
const dashboardEditButton = document.getElementById('editDashboardLayout');
const dashboardSaveButton = document.getElementById('saveDashboardLayout');
const dashboardCancelButton = document.getElementById('cancelDashboardLayout');
const dashboardLayoutStatus = document.getElementById('dashboardLayoutStatus');
const dashboardLayoutEditable = dashboardPage?.dataset.dashboardLayoutEditable === '1';
const dashboardLayoutUpdateUrl = dashboardPage?.dataset.dashboardLayoutUpdateUrl || '';
const dashboardLayoutResetUrl = dashboardPage?.dataset.dashboardLayoutResetUrl || '';
const dashboardFilterForm = document.getElementById('dashboardFilterForm');
const dashboardFilterButton = document.getElementById('dashboardFilterButton');
const dashboardLoadingOverlay = document.getElementById('dashboardLoadingOverlay');
const dashboardLoadingPercent = document.getElementById('dashboardLoadingPercent');
const dashboardLoadingText = document.getElementById('dashboardLoadingText');
const dashboardLoadingProgress = document.getElementById('dashboardLoadingProgress');
const dashboardLoadingProgressBar = document.getElementById('dashboardLoadingProgressBar');
const dashboardPeriodInput = document.getElementById('dashboardPeriodInput');
const dashboardDateFrom = document.getElementById('dashboardDateFrom');
const dashboardDateTo = document.getElementById('dashboardDateTo');
const dashboardLoadingMessages = {
    filters: @json(__('app.loading_filters')),
    projectData: @json(__('app.loading_project_data')),
    wialon: @json(__('app.loading_wialon')),
    dashboard: @json(__('app.loading_dashboard')),
};
const labels = {
    noData: @json(__('app.no_data')),
    nwc: @json(__('app.ownership_nwc')),
    icare: @json(__('app.ownership_icare')),
    hours: @json(__('app.hours')),
    utilization: @json(__('app.utilization')),
};
const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, character => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;',
}[character]));
const hasChartData = values => Array.isArray(values) && values.some(value => Number(value) > 0);
const truncateLabel = value => String(value ?? '').length > 28 ? `${String(value).slice(0, 27)}…` : String(value ?? '');
let draggedWidget = null;
let dragOverWidget = null;
let dashboardLoadingTimer = null;

const commonTooltip = {
    callbacks: {
        label: context => {
            const value = Number(context.parsed?.x ?? context.parsed ?? context.raw ?? 0);
            return `${context.dataset.label || context.label}: ${value.toLocaleString(undefined, { maximumFractionDigits: 1 })}`;
        }
    }
};

const doughnutOptions = {
    responsive: true,
    maintainAspectRatio: false,
    cutout: '64%',
    layout: { padding: 2 },
    plugins: {
        legend: {
            position: 'bottom',
            labels: { boxWidth: 14, boxHeight: 8, usePointStyle: false, font: { size: 11 } }
        },
        tooltip: commonTooltip
    }
};

const getChartColor = color => {
    if (Array.isArray(color)) {
        return color[0] || '#ffffff';
    }

    return color || '#ffffff';
};

const getContrastingTextColor = color => {
    const hex = getChartColor(color).trim();
    const normalized = hex.startsWith('#') ? hex.slice(1) : hex;

    if (!/^[0-9a-f]{6}$/i.test(normalized)) {
        return '#ffffff';
    }

    const red = parseInt(normalized.slice(0, 2), 16);
    const green = parseInt(normalized.slice(2, 4), 16);
    const blue = parseInt(normalized.slice(4, 6), 16);
    const luminance = (red * 299 + green * 587 + blue * 114) / 1000;

    return luminance > 150 ? '#0f1f3a' : '#ffffff';
};

if (window.ChartDataLabels) {
    Chart.register(ChartDataLabels);
    Chart.defaults.plugins.datalabels = {
        ...(Chart.defaults.plugins.datalabels || {}),
        display: false,
    };
}

const donutDataLabelsOptions = {
    display: false,
};

const formatDonutCenterTotal = total => Number(total || 0).toLocaleString('az-AZ');

const createDoughnutChart = (id, chartLabels, values, colors, settings = {}) => {
    const canvas = document.getElementById(id);

    if (!canvas || !hasChartData(values)) {
        return null;
    }

    const showLegend = settings.showLegend ?? true;
    const total = Number(settings.total ?? values.reduce((sum, value) => sum + Number(value || 0), 0));
    const centerTotalPlugin = {
        id: `${id}CenterTotal`,
        afterDraw: chart => {
            if (!settings.showCenterTotal) {
                return;
            }

            const { ctx, chartArea } = chart;

            if (!chartArea) {
                return;
            }

            ctx.save();
            ctx.font = '700 24px Arial, sans-serif';
            ctx.fillStyle = '#0f1f3a';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText(formatDonutCenterTotal(total), (chartArea.left + chartArea.right) / 2, (chartArea.top + chartArea.bottom) / 2);
            ctx.restore();
        },
    };
    const options = {
        ...doughnutOptions,
        cutout: settings.cutout || doughnutOptions.cutout,
        onClick: (event, elements, chart) => {
            const selected = elements?.[0];

            if (selected && settings.drilldownItems?.[selected.index]) {
                openDashboardDrilldown(settings.drilldownItems[selected.index]);
                return;
            }

            if (settings.centerDrilldown && chart) {
                const { left, right, top, bottom } = chart.chartArea;
                const x = event.x - ((left + right) / 2);
                const y = event.y - ((top + bottom) / 2);
                const distance = Math.sqrt((x * x) + (y * y));
                const innerRadius = chart.getDatasetMeta(0)?.data?.[0]?.innerRadius || 0;

                if (distance <= innerRadius) {
                    openDashboardDrilldown(settings.centerDrilldown);
                }
            }
        },
        plugins: {
            ...doughnutOptions.plugins,
            legend: {
                ...doughnutOptions.plugins.legend,
                display: showLegend,
            },
            datalabels: donutDataLabelsOptions,
        },
    };

    return new Chart(canvas, {
        type: 'doughnut',
        data: { labels: chartLabels, datasets: [{ data: values, backgroundColor: colors, borderWidth: 2, borderColor: '#fff', hoverOffset: settings.hoverOffset ?? 0 }] },
        options,
        plugins: settings.showCenterTotal ? [centerTotalPlugin] : [],
    });
};

const createHorizontalOwnershipChart = (id, chartLabels, nwcValues, icareValues, unit = '') => {
    const canvas = document.getElementById(id);

    if (!canvas || (!hasChartData(nwcValues) && !hasChartData(icareValues))) {
        return null;
    }

    const shortLabels = chartLabels.map(truncateLabel);

    return new Chart(canvas, {
        type: 'bar',
        data: {
            labels: shortLabels,
            datasets: [
                { label: labels.nwc, data: nwcValues, backgroundColor: ownershipColor.NWC, borderRadius: 4 },
                { label: labels.icare, data: icareValues, backgroundColor: ownershipColor.ICARE, borderRadius: 4 },
            ],
            originalLabels: chartLabels,
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            onClick: (event, elements) => {
                const selected = elements?.[0];

                if (!selected) {
                    return;
                }

                const projectId = projectComparisonIds[selected.index];
                const ownership = selected.datasetIndex === 0 ? 'nwc' : 'icare';

                if (projectId) {
                    openDashboardDrilldown({
                        title: `${chartLabels[selected.index]} - ${ownership === 'nwc' ? labels.nwc : labels.icare}`,
                        project_id: projectId,
                        ownership,
                    });
                }
            },
            scales: {
                x: { beginAtZero: true, ticks: { precision: 0 } },
                y: { ticks: { autoSkip: false, font: { size: 11 } } }
            },
            plugins: {
                legend: { position: 'bottom' },
                tooltip: {
                    callbacks: {
                        title: items => chartLabels[items[0]?.dataIndex] || '',
                        label: context => `${context.dataset.label}: ${Number(context.raw || 0).toLocaleString(undefined, { maximumFractionDigits: 1 })}${unit ? ` ${unit}` : ''}`,
                    }
                }
            }
        }
    });
};


const createProjectWorkCategoryChart = (id, values, settings = {}) => {
    const canvas = document.getElementById(id);
    const total = values.reduce((sum, value) => sum + Number(value || 0), 0);
    const chartLabels = settings.labels || workCategoryLabels;
    const chartColors = settings.colors || workCategoryColorValues;
    const displayValues = total > 0 ? values : [1];
    const displayLabels = total > 0 ? chartLabels : ['Məlumat yoxdur'];
    const displayColors = total > 0 ? chartColors : ['#e5edf7'];

    if (!canvas) {
        return null;
    }

    return new Chart(canvas, {
        type: 'doughnut',
        data: {
            labels: displayLabels,
            datasets: [{
                data: displayValues,
                backgroundColor: displayColors,
                borderColor: '#fff',
                borderWidth: 2,
                hoverOffset: total > 0 ? 4 : 0,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            onClick: (event, elements) => {
                const selected = elements?.[0];

                if (total > 0 && selected && settings.drilldownItems?.[selected.index]) {
                    openDashboardDrilldown(settings.drilldownItems[selected.index]);
                }
            },
            cutout: '58%',
            radius: '94%',
            plugins: {
                legend: { display: false },
                tooltip: {
                    enabled: total > 0,
                    callbacks: {
                        label: context => {
                            const value = Number(context.raw || 0);
                            const percent = total > 0 ? (value / total) * 100 : 0;

                            return `${context.label}: ${value.toLocaleString()} (${percent.toFixed(1)}%)`;
                        },
                    }
                },
                datalabels: { display: false },
            },
        },
        plugins: [],
    });
};

const setDashboardLoadingProgress = value => {
    const progress = Math.max(0, Math.min(99, Math.round(value)));

    if (dashboardLoadingPercent) {
        dashboardLoadingPercent.textContent = `${progress}%`;
    }

    if (dashboardLoadingProgressBar) {
        dashboardLoadingProgressBar.style.width = `${progress}%`;
    }

    if (dashboardLoadingProgress) {
        dashboardLoadingProgress.setAttribute('aria-valuenow', String(progress));
    }
};

const dashboardLoadingMessage = progress => {
    if (progress < 28) {
        return dashboardLoadingMessages.filters;
    }

    if (progress < 58) {
        return dashboardLoadingMessages.projectData;
    }

    if (progress < 86) {
        return dashboardLoadingMessages.wialon;
    }

    return dashboardLoadingMessages.dashboard;
};

const showDashboardLoading = () => {
    if (!dashboardLoadingOverlay) {
        return;
    }

    let progress = 3;
    const startedAt = Date.now();

    dashboardLoadingOverlay.classList.add('is-active');
    dashboardLoadingOverlay.setAttribute('aria-hidden', 'false');
    document.body.classList.add('dashboard-is-loading');
    setDashboardLoadingProgress(progress);

    window.clearInterval(dashboardLoadingTimer);
    dashboardLoadingTimer = window.setInterval(() => {
        const elapsedSeconds = (Date.now() - startedAt) / 1000;
        const target = elapsedSeconds < 8
            ? Math.min(55, 8 + elapsedSeconds * 6)
            : elapsedSeconds < 45
                ? Math.min(84, 55 + (elapsedSeconds - 8) * .8)
                : Math.min(96, 84 + Math.log10(elapsedSeconds - 43) * 8);

        progress += Math.max(.35, (target - progress) * .18);
        progress = Math.min(progress, 96);

        setDashboardLoadingProgress(progress);

        if (dashboardLoadingText) {
            dashboardLoadingText.textContent = dashboardLoadingMessage(progress);
        }
    }, 450);
};

const hideDashboardLoading = () => {
    window.clearInterval(dashboardLoadingTimer);
    dashboardLoadingTimer = null;

    if (dashboardLoadingOverlay) {
        dashboardLoadingOverlay.classList.remove('is-active');
        dashboardLoadingOverlay.setAttribute('aria-hidden', 'true');
    }

    document.body.classList.remove('dashboard-is-loading');

    if (dashboardFilterButton) {
        dashboardFilterButton.disabled = false;
    }
};

dashboardFilterForm?.addEventListener('submit', event => {
    if (dashboardFilterForm.dataset.loading === '1') {
        event.preventDefault();
        return;
    }

    dashboardFilterForm.dataset.loading = '1';

    if (dashboardFilterButton) {
        dashboardFilterButton.disabled = true;
    }

    showDashboardLoading();
});

document.querySelectorAll('.dashboard-period-button').forEach(button => {
    button.addEventListener('click', () => {
        if (dashboardDateFrom) {
            dashboardDateFrom.value = button.dataset.from || dashboardDateFrom.value;
        }

        if (dashboardDateTo) {
            dashboardDateTo.value = button.dataset.to || dashboardDateTo.value;
        }

        if (dashboardPeriodInput) {
            dashboardPeriodInput.value = button.dataset.period || 'custom';
        }

        dashboardFilterForm?.requestSubmit();
    });
});

[dashboardDateFrom, dashboardDateTo].forEach(input => {
    input?.addEventListener('change', () => {
        if (dashboardPeriodInput) {
            dashboardPeriodInput.value = 'custom';
        }
    });
});

window.addEventListener('pageshow', () => {
    if (dashboardFilterForm) {
        dashboardFilterForm.dataset.loading = '0';
    }

    hideDashboardLoading();
});

document.querySelectorAll('[data-expand-toggle]').forEach(button => {
    button.addEventListener('click', () => {
        const targetId = button.dataset.expandToggle;
        const container = document.querySelector(`[data-expandable="${targetId}"]`);

        if (!container) {
            return;
        }

        const expanded = container.dataset.expanded === '1';
        container.dataset.expanded = expanded ? '0' : '1';
        container.classList.toggle('is-expanded', !expanded);
        container.querySelectorAll('.expandable-extra').forEach(row => row.classList.toggle('d-none', expanded));
        button.textContent = expanded ? button.dataset.showLabel : button.dataset.hideLabel;
        requestAnimationFrame(refreshDashboardVisuals);
    });
});

const dashboardWidgets = () => dashboardGrid
    ? Array.from(dashboardGrid.children).filter(child => child.classList.contains('dashboard-widget'))
    : [];

const disableDashboardDragging = () => {
    dashboardWidgets().forEach(widget => {
        widget.draggable = false;
    });
};

const refreshDashboardVisuals = () => {
    window.dispatchEvent(new Event('resize'));
};

const setDashboardLayoutStatus = (message, tone = 'muted') => {
    if (!dashboardLayoutStatus) {
        return;
    }

    dashboardLayoutStatus.textContent = message || '';
    dashboardLayoutStatus.classList.toggle('text-danger', tone === 'danger');
    dashboardLayoutStatus.classList.toggle('text-success', tone === 'success');
};

const sortWidgetsByServerOrder = () => {
    if (!dashboardGrid) {
        return;
    }

    dashboardWidgets()
        .sort((a, b) => Number(a.dataset.widgetOrder || 999) - Number(b.dataset.widgetOrder || 999))
        .forEach(widget => dashboardGrid.appendChild(widget));
};

const setWidgetVisibility = (widget, visible) => {
    widget.dataset.widgetVisible = visible ? '1' : '0';
    widget.classList.toggle('dashboard-widget-hidden', !visible);

    const toggle = widget.querySelector('.dashboard-visibility-toggle');
    const icon = toggle?.querySelector('i');
    const title = visible ? 'Bloku gizlət' : 'Bloku göstər';

    if (toggle) {
        toggle.setAttribute('title', title);
        toggle.setAttribute('aria-label', title);
    }

    if (icon) {
        icon.className = visible ? 'bi bi-eye-slash' : 'bi bi-eye';
    }
};

const refreshWidgetVisibilityControls = () => {
    dashboardWidgets().forEach(widget => setWidgetVisibility(widget, widget.dataset.widgetVisible !== '0'));
};

const collectDashboardLayoutPayload = () => dashboardWidgets().map((widget, index) => {
    const titleInput = widget.querySelector('.dashboard-title-input');
    const title = titleInput ? (titleInput.value.trim() || titleInput.defaultValue || '') : '';

    return {
        key: widget.dataset.widgetKey || widget.dataset.dashboardWidget,
        order: (index + 1) * 10,
        width: Number(widget.dataset.widgetWidth || 12),
        title,
        visible: widget.dataset.widgetVisible !== '0',
    };
});

const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

const setDashboardLayoutEditing = enabled => {
    if (!dashboardLayoutEditable || !dashboardGrid || !dashboardPage) {
        return;
    }

    dashboardPage.classList.toggle('dashboard-layout-editing', enabled);
    dashboardEditButton?.classList.toggle('d-none', enabled);
    dashboardSaveButton?.classList.toggle('d-none', !enabled);
    dashboardCancelButton?.classList.toggle('d-none', !enabled);
    dashboardResetButton?.classList.toggle('d-none', !enabled);

    dashboardWidgets().forEach(widget => {
        widget.classList.toggle('is-editable', enabled);
        widget.style.order = enabled ? '' : (widget.dataset.widgetOrder || '');
        widget.draggable = false;

        const titleInput = widget.querySelector('.dashboard-title-input');
        if (titleInput && enabled) {
            titleInput.defaultValue = titleInput.value;
        }

        if (enabled) {
            widget.dataset.widgetVisibleDefault = widget.dataset.widgetVisible || '1';
        }
    });

    setDashboardLayoutStatus(enabled ? 'Blokları tutub daşıyın, sonra yadda saxlayın.' : '');
    refreshWidgetVisibilityControls();
    refreshDashboardVisuals();
};

const persistDashboardLayout = async () => {
    if (!dashboardLayoutEditable || !dashboardLayoutUpdateUrl) {
        return;
    }

    dashboardSaveButton?.setAttribute('disabled', 'disabled');
    setDashboardLayoutStatus('Düzülüş saxlanılır...');

    try {
        const payload = collectDashboardLayoutPayload();
        const response = await fetch(dashboardLayoutUpdateUrl, {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({ widgets: payload }),
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        payload.forEach(item => {
            const widget = dashboardWidgets().find(candidate => (candidate.dataset.widgetKey || candidate.dataset.dashboardWidget) === item.key);

            if (widget) {
                widget.dataset.widgetOrder = String(item.order);
                widget.style.order = String(item.order);
                setWidgetVisibility(widget, item.visible);
                widget.dataset.widgetVisibleDefault = widget.dataset.widgetVisible;

                const titleInput = widget.querySelector('.dashboard-title-input');
                const titleText = widget.querySelector('.dashboard-card-title-text');

                if (titleInput && titleText) {
                    titleInput.value = item.title;
                    titleInput.defaultValue = item.title;
                    titleText.textContent = item.title;
                }
            }
        });

        setDashboardLayoutEditing(false);
        setDashboardLayoutStatus('Düzülüş yadda saxlanıldı.', 'success');
    } catch (error) {
        setDashboardLayoutStatus('Düzülüş saxlanmadı. Yenidən cəhd edin.', 'danger');
    } finally {
        dashboardSaveButton?.removeAttribute('disabled');
    }
};

const setDragOverWidget = widget => {
    if (dragOverWidget && dragOverWidget !== widget) {
        dragOverWidget.classList.remove('drag-over');
    }
    dragOverWidget = widget;
    if (dragOverWidget) {
        dragOverWidget.classList.add('drag-over');
    }
};

if (dashboardGrid) {
    sortWidgetsByServerOrder();
    refreshWidgetVisibilityControls();
    disableDashboardDragging();

    dashboardGrid.querySelectorAll('.dashboard-drag-handle').forEach(handle => {
        handle.addEventListener('pointerdown', () => {
            if (!dashboardLayoutEditable || !dashboardPage?.classList.contains('dashboard-layout-editing')) {
                return;
            }

            const widget = handle.closest('.dashboard-widget');
            if (widget) {
                widget.draggable = true;
            }
        });
    });

    dashboardGrid.querySelectorAll('.dashboard-visibility-toggle').forEach(toggle => {
        toggle.addEventListener('click', () => {
            if (!dashboardLayoutEditable || !dashboardPage?.classList.contains('dashboard-layout-editing')) {
                return;
            }

            const widget = toggle.closest('.dashboard-widget');

            if (!widget) {
                return;
            }

            setWidgetVisibility(widget, widget.dataset.widgetVisible === '0');
            setDashboardLayoutStatus('Dəyişiklikləri yadda saxlayın.');
            refreshDashboardVisuals();
        });
    });

    document.addEventListener('pointerup', () => {
        if (!draggedWidget) {
            disableDashboardDragging();
        }
    });

    dashboardGrid.addEventListener('dragstart', event => {
        const widget = event.target.closest('.dashboard-widget');

        if (!dashboardLayoutEditable || !dashboardPage?.classList.contains('dashboard-layout-editing') || !widget || !dashboardGrid.contains(widget) || !widget.draggable) {
            event.preventDefault();
            return;
        }

        draggedWidget = widget;
        dashboardWidgets().forEach(item => {
            if (item !== widget) {
                item.draggable = false;
            }
        });
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', widget.dataset.dashboardWidget || '');
        requestAnimationFrame(() => widget.classList.add('dragging'));
    });

    dashboardGrid.addEventListener('dragover', event => {
        if (!draggedWidget) {
            return;
        }

        const target = event.target.closest('.dashboard-widget');
        if (!target || target === draggedWidget || !dashboardGrid.contains(target)) {
            return;
        }

        event.preventDefault();
        setDragOverWidget(target);

        const gridRect = dashboardGrid.getBoundingClientRect();
        const targetRect = target.getBoundingClientRect();
        const useHorizontalPosition = targetRect.width < gridRect.width * .85
            && event.clientY >= targetRect.top
            && event.clientY <= targetRect.bottom;
        const placeBefore = useHorizontalPosition
            ? event.clientX < targetRect.left + targetRect.width / 2
            : event.clientY < targetRect.top + targetRect.height / 2;

        dashboardGrid.insertBefore(draggedWidget, placeBefore ? target : target.nextSibling);
    });

    dashboardGrid.addEventListener('drop', event => {
        if (draggedWidget) {
            event.preventDefault();
        }
    });

    dashboardGrid.addEventListener('dragend', () => {
        if (draggedWidget) {
            draggedWidget.classList.remove('dragging');
            draggedWidget = null;
            disableDashboardDragging();
            refreshDashboardVisuals();
        }
        setDragOverWidget(null);
    });
}

dashboardEditButton?.addEventListener('click', () => {
    sortWidgetsByServerOrder();
    setDashboardLayoutEditing(true);
});

dashboardCancelButton?.addEventListener('click', () => {
    dashboardWidgets().forEach(widget => {
        const titleInput = widget.querySelector('.dashboard-title-input');
        if (titleInput) {
            titleInput.value = titleInput.defaultValue;
        }

        setWidgetVisibility(widget, (widget.dataset.widgetVisibleDefault || widget.dataset.widgetVisible || '1') !== '0');
    });
    sortWidgetsByServerOrder();
    setDashboardLayoutEditing(false);
});

dashboardSaveButton?.addEventListener('click', persistDashboardLayout);

dashboardResetButton?.addEventListener('click', () => {
    if (!dashboardLayoutEditable || !dashboardLayoutResetUrl) {
        return;
    }

    if (!confirm('Standart düzülüş bərpa edilsin?')) {
        return;
    }

    dashboardResetButton.setAttribute('disabled', 'disabled');
    setDashboardLayoutStatus('Standart düzülüş bərpa edilir...');

    fetch(dashboardLayoutResetUrl, {
        method: 'DELETE',
        headers: {
            'Accept': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
    })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            window.location.reload();
        })
        .catch(() => {
            dashboardResetButton.removeAttribute('disabled');
            setDashboardLayoutStatus('Standart düzülüş bərpa edilmədi.', 'danger');
        });
});

const drilldownModalElement = document.getElementById('dashboardDrilldownModal');
const drilldownModal = drilldownModalElement && window.bootstrap ? new bootstrap.Modal(drilldownModalElement) : null;
const drilldownTitle = document.getElementById('dashboardDrilldownTitle');
const drilldownFilters = document.getElementById('dashboardDrilldownFilters');
const drilldownStatus = document.getElementById('dashboardDrilldownStatus');
const drilldownRows = document.getElementById('dashboardDrilldownRows');
const drilldownHeader = document.getElementById('dashboardDrilldownHeader');
const drilldownSearch = document.getElementById('dashboardDrilldownSearch');
const drilldownFilterToggle = document.getElementById('dashboardDrilldownFilterToggle');
const drilldownGroupMode = document.getElementById('dashboardDrilldownGroupMode');
const drilldownFilterPanel = document.getElementById('dashboardDrilldownFilterPanel');
const drilldownFilterClose = document.getElementById('dashboardDrilldownFilterClose');
const drilldownApplyFilters = document.getElementById('dashboardDrilldownApplyFilters');
const drilldownClearFilters = document.getElementById('dashboardDrilldownClearFilters');
const drilldownFilterControls = Array.from(document.querySelectorAll('.dashboard-drilldown-control'));
const drilldownEfficiencyFilterGroups = Array.from(document.querySelectorAll('.dashboard-efficiency-filter-group'));
const drilldownChips = document.getElementById('dashboardDrilldownChips');
const drilldownPageInfo = document.getElementById('dashboardDrilldownPageInfo');
const drilldownPageSize = document.getElementById('dashboardDrilldownPageSize');
const drilldownPrev = document.getElementById('dashboardDrilldownPrev');
const drilldownNext = document.getElementById('dashboardDrilldownNext');
const drilldownExport = document.getElementById('dashboardDrilldownExport');
const drilldownRetry = document.getElementById('dashboardDrilldownRetry');
const drilldownFormula = document.getElementById('dashboardDrilldownFormula');
let drilldownController = null;
let drilldownRequestId = 0;
let drilldownState = {
    title: '',
    filters: {},
    baseFilters: {},
    baseTotal: null,
    page: 1,
    meta: null,
    columns: {},
};

const baseDrilldownFilters = () => ({
    date_from: dashboardPage?.dataset.dashboardDateFrom || '',
    date_to: dashboardPage?.dataset.dashboardDateTo || '',
    project_id: dashboardPage?.dataset.dashboardProjectId || '',
    equipment_type_id: dashboardPage?.dataset.dashboardEquipmentTypeId || '',
    ownership: dashboardPage?.dataset.dashboardOwnership || 'all',
    data_status: 'all',
    per_page: 50,
});

const defaultDrilldownColumns = () => ({
    number: '#',
    name: 'Texnikanın adı',
    vehicle_type: 'Texnika növü',
    ownership: 'Mənsubiyyət',
    project: 'Layihə',
});

const drilldownSortableColumns = new Set([
    'date',
    'name',
    'registration_number',
    'vehicle_type',
    'project',
    'ownership',
    'daytime_hours',
    'overtime_hours',
    'total_hours',
    'engine_hours',
    'mileage',
    'data_status',
    'wialon_id',
]);
const drilldownFilterLabels = {
    date_from: 'Tarixdən',
    date_to: 'Tarixədək',
    ownership: 'Mənsubiyyət',
    data_status: 'Məlumat statusu',
    group_by: 'Qruplaşdırma',
    project_ids: 'Layihə',
    vehicle_types: 'Texnika növü',
    work_category: 'İş statusu',
    day_status: 'Gündüz statusu',
    has_overtime: 'Overtime',
    day_hours_min: 'Gündüz min',
    day_hours_max: 'Gündüz max',
    overtime_hours_min: 'Overtime min',
    overtime_hours_max: 'Overtime max',
    total_hours_min: 'Ümumi min',
    total_hours_max: 'Ümumi max',
    unit_name: 'Texnika',
    registration_number: 'Qeydiyyat nişanı',
    wialon_id: 'Wialon ID',
    search: 'Axtarış',
};
const drilldownValueLabels = {
    ownership: { all: 'Hamısı', nwc: 'NWC', icare: 'İCARƏ' },
    data_status: { all: 'Hamısı', available: 'Məlumat var', missing: 'Məlumat yoxdur' },
    group_by: { details: 'Gündəlik detallar', day: 'Gün üzrə', unit: 'Texnika üzrə' },
    work_category: { less_than_1_hour: @json(__('app.worked_less_than_1_hour')), less_than_7_hours: @json(__('app.worked_less_than_7_hours')), between_7_and_10_hours: @json(__('app.worked_7_to_10_hours')), over_10_hours: @json(__('app.worked_over_10_hours')), overtime: @json(__('app.worked_overtime_hours')), no_data: 'Məlumat yoxdur' },
    day_status: { less_than_1_hour: @json(__('app.worked_less_than_1_hour')), less_than_7_hours: @json(__('app.worked_less_than_7_hours')), between_7_and_10_hours: @json(__('app.worked_7_to_10_hours')), over_10_hours: @json(__('app.worked_over_10_hours')) },
    has_overtime: { all: 'Hamısı', yes: 'Var', no: 'Yoxdur' },
    vehicle_types: @json($efficiencyVehicleTypes),
};

const cleanDrilldownFilters = filters => Object.fromEntries(
    Object.entries(filters).filter(([, value]) => {
        if (Array.isArray(value)) {
            return value.length > 0;
        }

        return value !== undefined && value !== null && value !== '';
    })
);

const drilldownUrl = (baseUrl, filters) => {
    const params = new URLSearchParams();

    Object.entries(cleanDrilldownFilters(filters)).forEach(([key, value]) => {
        if (Array.isArray(value)) {
            value.forEach(item => params.append(`${key}[]`, item));
            return;
        }

        params.append(key, value);
    });

    return `${baseUrl}?${params.toString()}`;
};

const foreignGeofenceRows = document.getElementById('foreignGeofenceRows');
const foreignGeofenceTableMeta = document.getElementById('foreignGeofenceTableMeta');
const foreignGeofenceSearch = document.getElementById('foreignGeofenceSearch');
const foreignGeofenceOwnershipFilter = document.getElementById('foreignGeofenceOwnershipFilter');
const foreignGeofenceTableExport = document.getElementById('foreignGeofenceTableExport');
const foreignGeofencePeriodSelect = document.getElementById('foreignGeofencePeriodSelect');
const foreignGeofenceRefresh = document.getElementById('foreignGeofenceRefresh');
const foreignGeofencePageSize = document.getElementById('foreignGeofencePageSize');
const foreignGeofencePrev = document.getElementById('foreignGeofencePrev');
const foreignGeofenceNext = document.getElementById('foreignGeofenceNext');
const foreignGeofencePageInfo = document.getElementById('foreignGeofencePageInfo');
let foreignGeofenceController = null;
let foreignGeofenceRequestId = 0;
let foreignGeofencePage = 1;
let foreignGeofenceMeta = { current_page: 1, last_page: 1, total: 0, per_page: 20 };

if (foreignGeofenceOwnershipFilter) {
    foreignGeofenceOwnershipFilter.value = dashboardPage?.dataset.dashboardOwnership || 'all';
}

const foreignGeofenceFilters = () => ({
    ...baseDrilldownFilters(),
    geofence_violation: 1,
    ownership: foreignGeofenceOwnershipFilter?.value || dashboardPage?.dataset.dashboardOwnership || 'all',
    data_status: 'all',
    search: foreignGeofenceSearch?.value.trim() || '',
    page: foreignGeofencePage,
    per_page: Number(foreignGeofencePageSize?.value || 20),
});

const foreignGeofenceDurationMinutes = duration => {
    const value = String(duration || '');
    const match = value.match(/(\d+)\s+saat\s+(\d+)/i);

    if (!match) {
        return 0;
    }

    return Number(match[1] || 0) * 60 + Number(match[2] || 0);
};

const foreignGeofenceTone = minutes => {
    if (minutes >= 720) {
        return { className: 'foreign-geofence-duration--danger', label: '12 saat+' };
    }

    if (minutes >= 360) {
        return { className: 'foreign-geofence-duration--orange', label: '6 saat+' };
    }

    if (minutes >= 180) {
        return { className: 'foreign-geofence-duration--warning', label: '3 saat+' };
    }

    return { className: 'foreign-geofence-duration--soft', label: '< 3 saat' };
};

const appendForeignGeofenceCell = (row, text, className = '', title = '') => {
    const cell = document.createElement('td');
    const value = text || '-';

    if (className === 'foreign-geofence-clamp') {
        const content = document.createElement('span');
        content.className = className;
        content.textContent = value;
        content.title = title || value;
        cell.appendChild(content);
    } else if (className) {
        cell.className = className;
        cell.textContent = value;
    } else {
        cell.textContent = value;
    }

    cell.title = title || value;
    row.appendChild(cell);

    return cell;
};

const foreignGeofenceNormalizeMeta = (meta, rows) => {
    const perPage = Number(meta?.per_page || foreignGeofencePageSize?.value || 20);
    const total = Number(meta?.total || rows.length || 0);
    const currentPage = Number(meta?.current_page || foreignGeofencePage || 1);
    const lastPage = Number(meta?.last_page || Math.max(1, Math.ceil(total / Math.max(perPage, 1))));

    return {
        current_page: Math.min(Math.max(currentPage, 1), Math.max(lastPage, 1)),
        last_page: Math.max(lastPage, 1),
        per_page: perPage,
        total,
    };
};

const renderForeignGeofencePagination = () => {
    const currentPage = Number(foreignGeofenceMeta.current_page || 1);
    const lastPage = Number(foreignGeofenceMeta.last_page || 1);

    if (foreignGeofencePageInfo) {
        foreignGeofencePageInfo.textContent = `${currentPage} / ${lastPage}`;
    }

    if (foreignGeofencePrev) {
        foreignGeofencePrev.disabled = currentPage <= 1;
    }

    if (foreignGeofenceNext) {
        foreignGeofenceNext.disabled = currentPage >= lastPage;
    }
};

const setForeignGeofenceLoading = message => {
    if (foreignGeofenceRows) {
        foreignGeofenceRows.innerHTML = `<tr><td colspan="10" class="foreign-geofence-loading">${message}</td></tr>`;
    }

    if (foreignGeofenceTableMeta) {
        foreignGeofenceTableMeta.textContent = message;
    }
};

const renderForeignGeofenceRows = (rows, meta) => {
    if (!foreignGeofenceRows) {
        return;
    }

    foreignGeofenceRows.textContent = '';
    foreignGeofenceMeta = foreignGeofenceNormalizeMeta(meta, rows);
    foreignGeofencePage = foreignGeofenceMeta.current_page;

    if (!rows.length) {
        setForeignGeofenceLoading('Seçilmiş layihə və dövr üzrə geozonadan çıxma halı aşkarlanmadı.');
        renderForeignGeofencePagination();
        return;
    }

    rows.forEach(item => {
        const tr = document.createElement('tr');
        const minutes = foreignGeofenceDurationMinutes(item.duration);
        const tone = foreignGeofenceTone(minutes);

        appendForeignGeofenceCell(tr, item.equipment, 'foreign-geofence-equipment', item.equipment);
        appendForeignGeofenceCell(tr, item.registration_number);
        appendForeignGeofenceCell(tr, item.vehicle_type);
        appendForeignGeofenceCell(tr, item.ownership);
        appendForeignGeofenceCell(tr, item.home_project, 'foreign-geofence-clamp', item.home_project);
        appendForeignGeofenceCell(tr, item.current_project, 'foreign-geofence-clamp', item.current_project);
        appendForeignGeofenceCell(tr, item.current_geofence, 'foreign-geofence-clamp', item.current_geofence);
        appendForeignGeofenceCell(tr, item.entered_at);

        const durationCell = document.createElement('td');
        const durationBadge = document.createElement('span');
        durationBadge.className = `foreign-geofence-duration ${tone.className}`;
        durationBadge.textContent = item.duration || '-';
        durationCell.appendChild(durationBadge);
        tr.appendChild(durationCell);

        const statusCell = document.createElement('td');
        const statusBadge = document.createElement('span');
        statusBadge.className = `foreign-geofence-status ${tone.className}`;
        statusBadge.textContent = tone.label;
        statusCell.appendChild(statusBadge);
        tr.appendChild(statusCell);

        foreignGeofenceRows.appendChild(tr);
    });

    if (foreignGeofenceTableMeta) {
        foreignGeofenceTableMeta.textContent = `Cəmi: ${Number(foreignGeofenceMeta.total || rows.length).toLocaleString()} | Göstərilən: ${rows.length.toLocaleString()} | Səhifə: ${foreignGeofenceMeta.current_page}`;
    }

    renderForeignGeofencePagination();
};

const updateForeignGeofenceExport = filters => {
    if (foreignGeofenceTableExport && dashboardPage?.dataset.dashboardDrilldownExportUrl) {
        foreignGeofenceTableExport.href = drilldownUrl(dashboardPage.dataset.dashboardDrilldownExportUrl, filters);
    }
};

const loadForeignGeofenceTable = async () => {
    if (!foreignGeofenceRows || !dashboardPage?.dataset.dashboardDrilldownUrl) {
        return;
    }

    const filters = foreignGeofenceFilters();
    foreignGeofenceController?.abort();
    foreignGeofenceController = new AbortController();
    const requestId = foreignGeofenceRequestId + 1;
    foreignGeofenceRequestId = requestId;
    updateForeignGeofenceExport(filters);
    setForeignGeofenceLoading('Məlumatlar yüklənir...');

    try {
        const response = await fetch(drilldownUrl(dashboardPage.dataset.dashboardDrilldownUrl, filters), {
            headers: { 'Accept': 'application/json' },
            signal: foreignGeofenceController.signal,
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        const payload = await response.json();

        if (requestId !== foreignGeofenceRequestId) {
            return;
        }

        renderForeignGeofenceRows(payload.data || [], payload.meta || {});
    } catch (error) {
        if (error.name === 'AbortError') {
            return;
        }

        if (requestId !== foreignGeofenceRequestId) {
            return;
        }

        setForeignGeofenceLoading('Məlumatları yükləmək mümkün olmadı.');
    }
};

foreignGeofencePeriodSelect?.addEventListener('change', () => {
    const option = foreignGeofencePeriodSelect.selectedOptions?.[0];

    if (dashboardDateFrom && option?.dataset.from) {
        dashboardDateFrom.value = option.dataset.from;
    }

    if (dashboardDateTo && option?.dataset.to) {
        dashboardDateTo.value = option.dataset.to;
    }

    if (dashboardPeriodInput) {
        dashboardPeriodInput.value = foreignGeofencePeriodSelect.value || 'custom';
    }

    dashboardFilterForm?.requestSubmit();
});

foreignGeofenceRefresh?.addEventListener('click', () => {
    dashboardFilterForm?.requestSubmit();
});

let foreignGeofenceSearchTimer = null;
foreignGeofenceSearch?.addEventListener('input', () => {
    window.clearTimeout(foreignGeofenceSearchTimer);
    foreignGeofenceSearchTimer = window.setTimeout(() => {
        foreignGeofencePage = 1;
        loadForeignGeofenceTable();
    }, 300);
});

foreignGeofenceOwnershipFilter?.addEventListener('change', () => {
    foreignGeofencePage = 1;
    loadForeignGeofenceTable();
});
foreignGeofencePageSize?.addEventListener('change', () => {
    foreignGeofencePage = 1;
    loadForeignGeofenceTable();
});
foreignGeofencePrev?.addEventListener('click', () => {
    if (foreignGeofencePage <= 1) {
        return;
    }

    foreignGeofencePage -= 1;
    loadForeignGeofenceTable();
});
foreignGeofenceNext?.addEventListener('click', () => {
    const lastPage = Number(foreignGeofenceMeta.last_page || 1);

    if (foreignGeofencePage >= lastPage) {
        return;
    }

    foreignGeofencePage += 1;
    loadForeignGeofenceTable();
});
loadForeignGeofenceTable();

const setDrilldownStatus = (message, tone = 'muted') => {
    if (!drilldownStatus) {
        return;
    }

    drilldownStatus.textContent = message || '';
    drilldownStatus.classList.toggle('text-danger', tone === 'danger');
    drilldownStatus.classList.toggle('text-success', tone === 'success');
};

const renderDrilldownFormula = formula => {
    if (!drilldownFormula) {
        return;
    }

    drilldownFormula.textContent = '';

    if (!formula) {
        drilldownFormula.classList.add('d-none');
        return;
    }

    const items = [
        ['Texnika növü', formula.vehicle_type || '-'],
        [formula.total_label || 'Ümumi', formula.total_value || '-'],
        ['Texnika sayı', Number(formula.units_count || 0).toLocaleString()],
        ['Gün sayı', Number(formula.days_count || 0).toLocaleString()],
        ['Orta göstərici', formula.average_value || '-'],
        ['Məlumatsız', Number(formula.units_without_data || 0).toLocaleString()],
    ];

    items.forEach(([label, value]) => {
        const item = document.createElement('div');
        item.className = 'dashboard-drilldown-formula-item';

        const labelElement = document.createElement('span');
        labelElement.textContent = label;

        const valueElement = document.createElement('strong');
        valueElement.textContent = value;

        item.append(labelElement, valueElement);
        drilldownFormula.appendChild(item);
    });

    drilldownFormula.classList.remove('d-none');
};

const renderDrilldownFilters = filters => {
    if (!drilldownFilters) {
        return;
    }

    drilldownFilters.textContent = Object.entries(filters || {})
        .map(([label, value]) => `${label}: ${value}`)
        .join(' | ');
};

const drilldownSelectLabels = (filterName, values) => {
    const valueList = Array.isArray(values) ? values : [values];
    const control = drilldownFilterControls.find(item => item.dataset.filterName === filterName);

    if (control?.tagName === 'SELECT') {
        return Array.from(control.options)
            .filter(option => valueList.map(String).includes(String(option.value)))
            .map(option => option.textContent.trim())
            .join(', ');
    }

    return valueList
        .map(value => drilldownValueLabels[filterName]?.[value] || value)
        .join(', ');
};

const syncDrilldownFilterControls = () => {
    drilldownFilterControls.forEach(control => {
        const name = control.dataset.filterName;
        const value = drilldownState.filters[name];

        if (control.multiple) {
            const selected = Array.isArray(value) ? value.map(String) : [];
            Array.from(control.options).forEach(option => {
                option.selected = selected.includes(String(option.value));
            });
            return;
        }

        control.value = value ?? '';
    });

    if (drilldownSearch) {
        drilldownSearch.value = drilldownState.filters.search || '';
    }

    if (drilldownPageSize) {
        drilldownPageSize.value = String(drilldownState.filters.per_page || 50);
    }
};

const collectDrilldownControlFilters = () => {
    const filters = {};

    drilldownFilterControls.forEach(control => {
        const name = control.dataset.filterName;

        if (control.multiple) {
            filters[name] = Array.from(control.selectedOptions).map(option => option.value);
            return;
        }

        filters[name] = control.value;
    });

    return cleanDrilldownFilters(filters);
};

const renderDrilldownChips = () => {
    if (!drilldownChips) {
        return;
    }

    drilldownChips.textContent = '';
    const hidden = new Set(['page', 'per_page', 'sort', 'direction', 'title', 'geofence_violation', 'current_geozone_project_id', 'current_geozone_id', 'current_geozone_key', 'top_working_equipment_id', 'top_working_stat_date', 'top_working_ranking', 'metric']);
    const defaultValues = { ownership: 'all', data_status: 'all', has_overtime: 'all', group_by: 'details' };

    Object.entries(cleanDrilldownFilters(drilldownState.filters)).forEach(([name, value]) => {
        if (name === 'work_category' && drilldownState.filters.day_status === value) {
            return;
        }

        if (hidden.has(name) || String(defaultValues[name] ?? '') === String(value)) {
            return;
        }

        const label = drilldownFilterLabels[name];

        if (!label) {
            return;
        }

        const chip = document.createElement('span');
        chip.className = 'dashboard-drilldown-chip';
        chip.textContent = `${label}: ${drilldownSelectLabels(name, value)}`;

        const button = document.createElement('button');
        button.type = 'button';
        button.setAttribute('aria-label', `${label} filtrini sil`);
        button.textContent = '?';
        button.addEventListener('click', () => {
            if (name === 'day_status' && drilldownState.filters.work_category === value) {
                delete drilldownState.filters.work_category;
            }
            if (name === 'work_category' && drilldownState.filters.day_status === value) {
                delete drilldownState.filters.day_status;
            }
            delete drilldownState.filters[name];
            drilldownState.filters.page = 1;
            syncDrilldownFilterControls();
            loadDashboardDrilldown();
        });

        chip.appendChild(button);
        drilldownChips.appendChild(chip);
    });
};

const renderDrilldownRows = rows => {
    if (!drilldownRows) {
        return;
    }

    drilldownRows.textContent = '';
    const columns = Object.keys(drilldownState.columns || {});

    if (!rows.length) {
        const tr = document.createElement('tr');
        const td = document.createElement('td');
        td.colSpan = Math.max(1, columns.length);
        td.className = 'text-center text-secondary py-4';
        td.textContent = 'Seçilmiş filtrlərə uyğun məlumat tapılmadı';
        tr.appendChild(td);
        drilldownRows.appendChild(tr);
        return;
    }

    rows.forEach((row, index) => {
        const tr = document.createElement('tr');
        const rowNumber = ((drilldownState.meta?.current_page || 1) - 1) * (drilldownState.meta?.per_page || 50) + index + 1;

        columns.forEach(key => {
            const td = document.createElement('td');
            const value = key === 'number' ? rowNumber : row[key];
            td.textContent = value ?? '-';
            tr.appendChild(td);
        });

        drilldownRows.appendChild(tr);
    });
};

const renderDrilldownColumns = columns => {
    if (!drilldownHeader) {
        return;
    }

    drilldownState.columns = columns && Object.keys(columns).length ? columns : defaultDrilldownColumns();

    drilldownHeader.textContent = '';

    Object.entries(drilldownState.columns).forEach(([key, label]) => {
        const th = document.createElement('th');

        if (drilldownSortableColumns.has(key)) {
            const button = document.createElement('button');
            const isActive = drilldownState.filters.sort === key;
            button.type = 'button';
            button.className = 'dashboard-drilldown-sort';
            button.dataset.sort = key;
            button.textContent = label;

            const marker = document.createElement('span');
            marker.textContent = isActive ? (drilldownState.filters.direction === 'desc' ? '?' : '?') : '?';
            button.appendChild(marker);
            th.appendChild(button);
        } else {
            th.textContent = label;
        }

        drilldownHeader.appendChild(th);
    });
};

const updateDrilldownPagination = () => {
    const meta = drilldownState.meta || { current_page: 1, last_page: 1, total: 0 };

    if (drilldownPageInfo) {
        drilldownPageInfo.textContent = `Cəmi: ${Number(meta.total || 0).toLocaleString()} | Səhifə ${meta.current_page || 1} / ${meta.last_page || 1}`;
    }

    if (drilldownPrev) {
        drilldownPrev.disabled = Number(meta.current_page || 1) <= 1;
    }

    if (drilldownNext) {
        drilldownNext.disabled = Number(meta.current_page || 1) >= Number(meta.last_page || 1);
    }

    if (drilldownPageSize) {
        drilldownPageSize.value = String(meta.per_page || drilldownState.filters.per_page || 50);
    }
};

const updateDrilldownFilterButtons = () => {
    document.querySelectorAll('.dashboard-drilldown-filter').forEach(button => {
        const name = button.dataset.filterName;
        const value = button.dataset.filterValue;
        button.classList.toggle('active', String(drilldownState.filters[name] || 'all') === value);
    });

    syncDrilldownFilterControls();
    renderDrilldownChips();
};

const updateDrilldownExportUrl = () => {
    if (drilldownExport && dashboardPage?.dataset.dashboardDrilldownExportUrl) {
        drilldownExport.href = drilldownUrl(dashboardPage.dataset.dashboardDrilldownExportUrl, drilldownState.filters);
    }
};

const resetDashboardDrilldownState = (options = {}) => {
    const abortRequest = options.abortRequest ?? true;
    const clearTitle = options.clearTitle ?? true;

    if (abortRequest) {
        drilldownController?.abort();
        drilldownController = null;
    }

    drilldownRequestId += 1;
    drilldownState = {
        title: '',
        filters: {},
        baseFilters: {},
        baseTotal: null,
        page: 1,
        meta: null,
        columns: defaultDrilldownColumns(),
    };

    if (clearTitle && drilldownTitle) {
        drilldownTitle.textContent = 'Texnika siyahısı';
    }

    if (drilldownSearch) {
        drilldownSearch.value = '';
    }
    if (drilldownGroupMode) {
        drilldownGroupMode.value = 'details';
        drilldownGroupMode.classList.add('d-none');
    }

    if (drilldownFilters) {
        drilldownFilters.textContent = '';
    }

    setDrilldownStatus('');
    renderDrilldownFormula(null);
    renderDrilldownColumns(null);
    renderDrilldownRows([]);
    updateDrilldownPagination();
    updateDrilldownFilterButtons();
    drilldownRetry?.classList.add('d-none');

    if (drilldownExport) {
        drilldownExport.href = '#';
    }

    drilldownFilterPanel?.classList.add('d-none');
    drilldownFilterToggle?.classList.add('d-none');
    drilldownEfficiencyFilterGroups.forEach(group => group.classList.remove('d-none'));
    syncDrilldownFilterControls();
    renderDrilldownChips();
};

const loadDashboardDrilldown = async () => {
    if (!dashboardPage?.dataset.dashboardDrilldownUrl) {
        return;
    }

    drilldownController?.abort();
    drilldownController = new AbortController();
    const requestId = drilldownRequestId + 1;
    drilldownRequestId = requestId;

    setDrilldownStatus('Məlumatlar yüklənir...');
    drilldownRetry?.classList.add('d-none');
    renderDrilldownRows([]);
    drilldownModalElement?.classList.add('dashboard-drilldown-loading');
    updateDrilldownFilterButtons();
    updateDrilldownExportUrl();

    try {
        const response = await fetch(drilldownUrl(dashboardPage.dataset.dashboardDrilldownUrl, drilldownState.filters), {
            headers: { 'Accept': 'application/json' },
            signal: drilldownController.signal,
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        const payload = await response.json();

        if (requestId !== drilldownRequestId) {
            return;
        }

        drilldownState.title = payload.title || drilldownState.title || 'Texnika siyahısı';
        drilldownState.meta = payload.meta || null;
        drilldownState.baseTotal ??= payload.summary?.total ?? 0;
        renderDrilldownColumns(payload.columns || null);

        if (drilldownTitle) {
            drilldownTitle.textContent = drilldownState.title;
        }

        renderDrilldownFilters(payload.filters || {});
        renderDrilldownFormula(payload.summary?.average_formula || null);
        renderDrilldownRows(payload.data || []);
        updateDrilldownPagination();
        const filteredTotal = Number(payload.summary?.total ?? 0);
        const baseTotal = Number(drilldownState.baseTotal ?? filteredTotal);
        setDrilldownStatus((payload.data || []).length ? `Tapıldı: ${filteredTotal.toLocaleString()} / Cəmi: ${baseTotal.toLocaleString()}` : 'Seçilmiş filtrlərə uyğun məlumat tapılmadı', (payload.data || []).length ? 'success' : 'muted');
    } catch (error) {
        if (error.name === 'AbortError') {
            return;
        }

        if (requestId !== drilldownRequestId) {
            return;
        }

        drilldownState.meta = null;
        updateDrilldownPagination();
        setDrilldownStatus('Məlumatları yükləmək mümkün olmadı.', 'danger');
        drilldownRetry?.classList.remove('d-none');
    } finally {
        if (requestId === drilldownRequestId) {
            drilldownModalElement?.classList.remove('dashboard-drilldown-loading');
        }
    }
};

const openDashboardDrilldown = (filters = {}) => {
    resetDashboardDrilldownState({ abortRequest: true, clearTitle: false });
    const nextFilters = cleanDrilldownFilters(filters);

    const initialFilters = {
        ...baseDrilldownFilters(),
        ...nextFilters,
        data_status: nextFilters.data_status || 'all',
        has_overtime: nextFilters.has_overtime || 'all',
        page: 1,
        search: '',
    };
    if (!initialFilters.day_status && ['less_than_1_hour', 'less_than_7_hours', 'between_7_and_10_hours', 'over_10_hours'].includes(initialFilters.work_category)) {
        initialFilters.day_status = initialFilters.work_category;
    }
    if ((initialFilters.work_category || initialFilters.day_status) && !initialFilters.sort) {
        initialFilters.sort = 'date';
        initialFilters.direction = 'asc';
    }
    if (initialFilters.metric && !initialFilters.sort) {
        initialFilters.sort = 'date';
        initialFilters.direction = 'asc';
    }
    if (initialFilters.metric && !initialFilters.group_by) {
        initialFilters.group_by = 'details';
    }
    const isEfficiencyDrilldown = Boolean(initialFilters.work_category || initialFilters.day_status);
    const isMetricDrilldown = Boolean(initialFilters.metric);
    drilldownFilterToggle?.classList.toggle('d-none', !(isEfficiencyDrilldown || isMetricDrilldown));
    drilldownGroupMode?.classList.toggle('d-none', !isMetricDrilldown);
    if (drilldownGroupMode) {
        drilldownGroupMode.value = initialFilters.group_by || 'details';
    }
    drilldownEfficiencyFilterGroups.forEach(group => group.classList.toggle('d-none', isMetricDrilldown && !isEfficiencyDrilldown));
    drilldownState.filters = initialFilters;
    drilldownState.baseFilters = { ...initialFilters };
    drilldownState.baseTotal = null;
    drilldownState.title = nextFilters.title || '';

    delete drilldownState.filters.title;
    delete drilldownState.baseFilters.title;

    if (drilldownSearch) {
        drilldownSearch.value = '';
    }

    if (drilldownTitle) {
        drilldownTitle.textContent = drilldownState.title || 'Texnika siyahısı';
    }

    syncDrilldownFilterControls();
    renderDrilldownChips();
    drilldownModal?.show();
    loadDashboardDrilldown();
};

drilldownModalElement?.addEventListener('hidden.bs.modal', () => {
    resetDashboardDrilldownState({ abortRequest: true, clearTitle: true });
});

document.addEventListener('click', event => {
    const trigger = event.target.closest('.dashboard-drilldown-trigger');

    if (!trigger || trigger.dataset.expandToggle) {
        return;
    }

    event.preventDefault();
    event.stopPropagation();
    openDashboardDrilldown({
        title: trigger.dataset.drilldownTitle || '',
        ownership: trigger.dataset.drilldownOwnership || undefined,
        project_id: trigger.dataset.drilldownProjectId || undefined,
        equipment_type_id: trigger.dataset.drilldownEquipmentTypeId || undefined,
        vehicle_types: trigger.dataset.drilldownVehicleTypes ? [trigger.dataset.drilldownVehicleTypes] : undefined,
        work_category: trigger.dataset.drilldownWorkCategory || undefined,
        status: trigger.dataset.drilldownStatus || trigger.dataset.drilldownWorkCategory || undefined,
        date_from: trigger.dataset.drilldownDateFrom || undefined,
        date_to: trigger.dataset.drilldownDateTo || undefined,
        metric: trigger.dataset.drilldownMetric || undefined,
        sort: trigger.dataset.drilldownSort || undefined,
        data_status: trigger.dataset.drilldownDataStatus || undefined,
        top_working_equipment_id: trigger.dataset.drilldownTopEquipmentId || undefined,
        top_working_stat_date: trigger.dataset.drilldownTopStatDate || undefined,
        top_working_ranking: trigger.dataset.drilldownTopRanking || undefined,
        geofence_violation: trigger.dataset.drilldownGeofenceViolation || undefined,
        current_geozone_project_id: trigger.dataset.drilldownCurrentGeozoneProjectId || undefined,
        current_geozone_id: trigger.dataset.drilldownCurrentGeozoneId || undefined,
        current_geozone_key: trigger.dataset.drilldownCurrentGeozoneKey || undefined,
    });
});

document.addEventListener('keydown', event => {
    if (!['Enter', ' '].includes(event.key)) {
        return;
    }

    const trigger = event.target.closest('.dashboard-drilldown-trigger');

    if (!trigger || trigger.dataset.expandToggle) {
        return;
    }

    event.preventDefault();
    trigger.click();
});

document.querySelectorAll('.dashboard-drilldown-filter').forEach(button => {
    button.addEventListener('click', () => {
        drilldownState.filters[button.dataset.filterName] = button.dataset.filterValue;
        drilldownState.filters.page = 1;
        loadDashboardDrilldown();
    });
});

drilldownFilterToggle?.addEventListener('click', () => {
    drilldownFilterPanel?.classList.toggle('d-none');
});

drilldownFilterClose?.addEventListener('click', () => {
    drilldownFilterPanel?.classList.add('d-none');
});

drilldownApplyFilters?.addEventListener('click', () => {
    const controlNames = drilldownFilterControls.map(control => control.dataset.filterName);
    const panelFilters = collectDrilldownControlFilters();

    controlNames.forEach(name => {
        delete drilldownState.filters[name];
    });

    drilldownState.filters = {
        ...drilldownState.filters,
        ...panelFilters,
        page: 1,
    };

    if (drilldownState.filters.day_status) {
        drilldownState.filters.work_category = drilldownState.filters.day_status;
    } else if (['less_than_1_hour', 'less_than_7_hours', 'between_7_and_10_hours', 'over_10_hours'].includes(drilldownState.filters.work_category)) {
        delete drilldownState.filters.work_category;
    }

    loadDashboardDrilldown();
});

drilldownClearFilters?.addEventListener('click', () => {
    drilldownState.filters = { ...drilldownState.baseFilters, page: 1 };
    drilldownState.baseTotal = null;
    syncDrilldownFilterControls();
    loadDashboardDrilldown();
});

let drilldownSearchTimer = null;
drilldownSearch?.addEventListener('input', () => {
    window.clearTimeout(drilldownSearchTimer);
    drilldownSearchTimer = window.setTimeout(() => {
        drilldownState.filters.search = drilldownSearch.value.trim();
        drilldownState.filters.page = 1;
        loadDashboardDrilldown();
    }, 300);
});

drilldownPageSize?.addEventListener('change', () => {
    drilldownState.filters.per_page = Number(drilldownPageSize.value || 50);
    drilldownState.filters.page = 1;
    loadDashboardDrilldown();
});

drilldownGroupMode?.addEventListener('change', () => {
    drilldownState.filters.group_by = drilldownGroupMode.value || 'details';
    drilldownState.filters.page = 1;
    loadDashboardDrilldown();
});

drilldownPrev?.addEventListener('click', () => {
    const currentPage = Number(drilldownState.meta?.current_page || 1);
    if (currentPage > 1) {
        drilldownState.filters.page = currentPage - 1;
        loadDashboardDrilldown();
    }
});

drilldownNext?.addEventListener('click', () => {
    const currentPage = Number(drilldownState.meta?.current_page || 1);
    const lastPage = Number(drilldownState.meta?.last_page || 1);
    if (currentPage < lastPage) {
        drilldownState.filters.page = currentPage + 1;
        loadDashboardDrilldown();
    }
});

drilldownRetry?.addEventListener('click', loadDashboardDrilldown);

drilldownHeader?.addEventListener('click', event => {
    const button = event.target.closest('.dashboard-drilldown-sort');

    if (!button) {
        return;
    }

    const sort = button.dataset.sort;
    const currentSort = drilldownState.filters.sort || 'date';
    const currentDirection = drilldownState.filters.direction || 'asc';

    drilldownState.filters.sort = sort;
    drilldownState.filters.direction = currentSort === sort && currentDirection === 'asc' ? 'desc' : 'asc';
    drilldownState.filters.page = 1;
    loadDashboardDrilldown();
});

const ownershipDrilldownItems = [
    { title: `${labels.nwc} texnikaları`, ownership: 'nwc' },
    { title: `${labels.icare} texnikaları`, ownership: 'icare' },
];
const projectWorkCategoryNwcDrilldownItems = workCategoryKeys.map((key, index) => ({
    title: `${labels.nwc} - ${workCategoryLabels[index]}`,
    ownership: 'nwc',
    work_category: key,
    status: key,
}));
const projectWorkCategoryIcareDrilldownItems = workCategoryKeys.map((key, index) => ({
    title: `${labels.icare} - ${workCategoryLabels[index]}`,
    ownership: 'icare',
    work_category: key,
    status: key,
}));
const projectWorkCategoryNwcDonutDrilldownItems = workCategoryDonutKeys.map((key, index) => ({
    title: `${labels.nwc} - ${workCategoryDonutLabels[index]}`,
    ownership: 'nwc',
    work_category: key,
    status: key,
}));
const projectWorkCategoryIcareDonutDrilldownItems = workCategoryDonutKeys.map((key, index) => ({
    title: `${labels.icare} - ${workCategoryDonutLabels[index]}`,
    ownership: 'icare',
    work_category: key,
    status: key,
}));
const typeNwcDrilldownItems = typeNwcIds.map((id, index) => ({
    title: `${labels.nwc} - ${typeNwcLabels[index]}`,
    ownership: 'nwc',
    equipment_type_id: id,
}));
const typeIcareDrilldownItems = typeIcareIds.map((id, index) => ({
    title: `${labels.icare} - ${typeIcareLabels[index]}`,
    ownership: 'icare',
    equipment_type_id: id,
}));
const geofenceViolationDrilldownItems = geofenceViolationProjectIds.map((id, index) => ({
    title: `${geofenceViolationLabels[index]} - Geozonadan çıxma halları`,
    geofence_violation: 1,
    current_geozone_project_id: id,
    current_geozone_id: geofenceViolationGeofenceIds[index],
    current_geozone_key: geofenceViolationSectorKeys[index],
}));

createDoughnutChart('ownershipDonut', ownershipShareLabels, ownershipShareCounts, [ownershipColor.NWC, ownershipColor.ICARE], {
    showLegend: false,
    total: ownershipShareTotal,
    showCenterTotal: true,
    drilldownItems: ownershipDrilldownItems,
    centerDrilldown: { title: 'Bütün texnikalar', ownership: 'all' },
});
createDoughnutChart('typeDonutNwc', typeNwcLabels, typeNwcTotals, typePalette, { showLegend: false, total: typeNwcTotal, showCenterTotal: true, drilldownItems: typeNwcDrilldownItems });
createDoughnutChart('typeDonutIcare', typeIcareLabels, typeIcareTotals, typePalette, { showLegend: false, total: typeIcareTotal, showCenterTotal: true, drilldownItems: typeIcareDrilldownItems });
createDoughnutChart('geofenceViolationsDonut', geofenceViolationLabels, geofenceViolationCounts, geofenceViolationPalette, {
    showLegend: false,
    cutout: '66%',
    hoverOffset: 10,
    total: geofenceViolationTotal,
    drilldownItems: geofenceViolationDrilldownItems,
    centerDrilldown: { title: 'Geozonadan çıxma halları', geofence_violation: 1 },
});
createProjectWorkCategoryChart('projectWorkCategoriesNwc', projectWorkCategoryNwcDonutCounts, {
    labels: workCategoryDonutLabels,
    colors: workCategoryDonutColorValues,
    drilldownItems: projectWorkCategoryNwcDonutDrilldownItems,
});
createProjectWorkCategoryChart('projectWorkCategoriesIcare', projectWorkCategoryIcareDonutCounts, {
    labels: workCategoryDonutLabels,
    colors: workCategoryDonutColorValues,
    drilldownItems: projectWorkCategoryIcareDonutDrilldownItems,
});
createHorizontalOwnershipChart('projectComparison', projectComparisonLabels, projectComparisonNwc, projectComparisonIcare);
if (document.getElementById('utilizationLine') && utilizationTrend.has_data) {
    new Chart(document.getElementById('utilizationLine'), {
        type: 'line',
        data: {
            labels: utilizationTrend.labels,
            datasets: [
                { label: labels.nwc, data: utilizationTrend.series.NWC || [], borderColor: ownershipColor.NWC, backgroundColor: 'rgba(36,179,91,.12)', tension: .35, fill: false },
                { label: labels.icare, data: utilizationTrend.series.ICARE || [], borderColor: ownershipColor.ICARE, backgroundColor: 'rgba(31,111,235,.12)', tension: .35, fill: false },
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: { y: { min: 0, max: 100, ticks: { callback: value => `${value}%` } } },
            plugins: {
                legend: { position: 'bottom' },
                tooltip: {
                    callbacks: {
                        title: items => utilizationTrend.dates?.[items[0]?.dataIndex] || items[0]?.label || '',
                        label: context => `${context.dataset.label}: ${Number(context.raw || 0).toFixed(1)}%`,
                    }
                }
            }
        }
    });
}

</script>
@endpush
