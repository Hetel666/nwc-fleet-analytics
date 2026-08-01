@extends(($dashboardTabFragment ?? false) ? 'dashboard.partials.fragment-layout' : 'layouts.app')

@section('title', __('app.dashboard').' | '.__('app.app_name'))
@section('page-title', $selectedProject ? $selectedProject->name : __('app.dashboard'))
@section('page-subtitle', __('app.period').': '.$filters['from'].' - '.$filters['to'])

@php
    $nwc = \App\Models\Equipment::OWNERSHIP_NWC;
    $icare = \App\Models\Equipment::OWNERSHIP_ICARE;
    $overview = $data['overview'] ?? [
        'ownership_share' => [],
        'total_hours' => 0,
        'total_distance' => 0,
        'avg_hours_per_equipment' => 0,
        'avg_distance_per_equipment' => 0,
        'utilization' => 0,
        'changes' => [
            'total_hours' => 0,
            'total_distance' => 0,
            'avg_hours_per_equipment' => 0,
            'avg_distance_per_equipment' => 0,
            'utilization' => 0,
        ],
    ];
    $ownershipLabelFor = fn (?string $value): string => $value === $icare ? __('app.ownership_icare') : __('app.ownership_nwc');
    $typeGroups = $data['equipmentTypesByOwnership'] ?? [$nwc => [], $icare => []];
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
        '0_1' => '0 - 1 saat arası işləyən',
        '1_7' => '1 - 7 saat arası işləyən',
        '7_10' => '7 - 10 saat arası işləyən',
        'over_10' => '10 saatdan artıq işləyən',
        'no_data' => 'İşləməyən / Məlumatı olmayan',
    ]);
    $actualWorkCategoryRanges = collect([
        '0_1' => '0 - 1 saat',
        '1_7' => '1 - 7 saat',
        '7_10' => '7 - 10 saat',
        'over_10' => '> 10 saat',
        'no_data' => '-',
    ]);
    $actualWorkCategoryColors = collect([
        '0_1' => '#1f6feb',
        '1_7' => '#f97316',
        '7_10' => '#24b35b',
        'over_10' => '#8b5cf6',
        'no_data' => '#94a3b8',
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

        $summary['total'] = (int) $rows->sum('total');
        $summary['missing_data'] = (int) $rows->sum('missing_data');
        $summary['overtime_denominator'] = (int) $rows->sum('overtime_denominator');
        $summary['overtime_unknown'] = (int) $rows->sum('overtime_unknown');

        return collect($summary);
    };
    $projectWorkCategorySummaryNwc = $projectWorkCategorySummaryFor($projectWorkCategoryRowsNwc);
    $projectWorkCategorySummaryIcare = $projectWorkCategorySummaryFor($projectWorkCategoryRowsIcare);
    $daytimeEfficiencyGroups = $data['daytimeEfficiencyByOwnership'] ?? [$nwc => [], $icare => []];
    $daytimeEfficiencySummaryNwc = collect($daytimeEfficiencyGroups[$nwc] ?? [])->merge(['total' => (int) ($daytimeEfficiencyGroups[$nwc]['total'] ?? 0)]);
    $daytimeEfficiencySummaryIcare = collect($daytimeEfficiencyGroups[$icare] ?? [])->merge(['total' => (int) ($daytimeEfficiencyGroups[$icare]['total'] ?? 0)]);
    $nighttimeEfficiencyGroups = $data['nighttimeEfficiencyByOwnership'] ?? [$nwc => [], $icare => []];
    $nighttimeEfficiencySummaryNwc = collect($nighttimeEfficiencyGroups[$nwc] ?? [])->merge(['total' => (int) ($nighttimeEfficiencyGroups[$nwc]['total'] ?? 0)]);
    $nighttimeEfficiencySummaryIcare = collect($nighttimeEfficiencyGroups[$icare] ?? [])->merge(['total' => (int) ($nighttimeEfficiencyGroups[$icare]['total'] ?? 0)]);
    $projectComparisonRows = collect($data['projectOwnershipComparison'] ?? []);
    $projectComparisonTotals = [
        $nwc => (float) $projectComparisonRows->sum($nwc),
        $icare => (float) $projectComparisonRows->sum($icare),
    ];
    $projectComparisonTotals['total'] = $projectComparisonTotals[$nwc] + $projectComparisonTotals[$icare];
    $projectComparisonTop = $projectComparisonRows->take(10)->values();
    $projectComparisonHasMore = $projectComparisonRows->count() > 10;
    $projectComparisonChartHeight = min(max($projectComparisonTop->count() * 34 + 80, 260), 520);
    $geofenceViolations = $data['geofenceViolations'] ?? ['labels' => [], 'counts' => [], 'project_ids' => [], 'geofence_ids' => [], 'sector_keys' => [], 'total' => 0, 'rows' => []];
    $geofenceViolationRows = collect($geofenceViolations['rows'] ?? [])->sortByDesc('count')->values();
    $geofenceViolationTotal = (int) ($geofenceViolations['total'] ?? 0);
    $geofenceViolationPalette = ['#2563EB', '#22C55E', '#F59E0B', '#8B5CF6', '#14B8A6', '#EF4444', '#64748B', '#0EA5E9', '#A855F7', '#F97316'];
    $geofenceHomeProjectLabel = $selectedProject?->name ?? ($filters['project_id'] ? 'ID '.$filters['project_id'] : __('app.all_projects'));
    $geofenceReportWidget = $geofenceViolationDashboardWidget ?? [
        'distribution' => collect(),
        'kpis' => [
            'total_violations' => 0,
            'active_violations' => 0,
            'active_projects' => 0,
            'longest_duration_seconds' => 0,
        ],
        'latest_report_at' => null,
    ];
    $geofenceReportDistribution = collect($geofenceReportWidget['distribution'] ?? []);
    $geofenceReportTotal = (int) data_get($geofenceReportWidget, 'kpis.total_violations', 0);
    $geofenceReportCursor = 0.0;
    $geofenceReportSegments = [];

    foreach ($geofenceReportDistribution as $segmentIndex => $segment) {
        $segmentEnd = $segmentIndex === $geofenceReportDistribution->count() - 1
            ? 100.0
            : min(100.0, $geofenceReportCursor + (float) $segment['share']);
        $geofenceReportSegments[] = sprintf(
            '%s %.4f%% %.4f%%',
            $segment['color'],
            $geofenceReportCursor,
            $segmentEnd
        );
        $geofenceReportCursor = $segmentEnd;
    }

    $geofenceReportDonutBackground = $geofenceReportSegments === []
        ? 'conic-gradient(#E5E7EB 0% 100%)'
        : 'conic-gradient('.implode(', ', $geofenceReportSegments).')';
    $utilizationTrendByOwnership = $data['utilizationTrendByOwnership'] ?? ['labels' => [], 'series' => [$nwc => [], $icare => []], 'has_data' => false];
    $latestPublishedReportDate = $latestPublishedReportDate
        ?? ($data['latestPublishedReportDate'] ?? null)
        ?? ($data['overview']['latestPublishedReportDate'] ?? null);
    $today = \Illuminate\Support\Carbon::today(config('app.timezone'));
    $reportThrough = $latestPublishedReportDate
        ? \Illuminate\Support\Carbon::parse($latestPublishedReportDate, config('app.timezone'))->startOfDay()
        : $today;
    $periodPresets = [
        'today' => ['label' => __('app.period_latest_completed'), 'from' => $reportThrough->toDateString(), 'to' => $reportThrough->toDateString()],
        'yesterday' => ['label' => __('app.period_yesterday'), 'from' => $reportThrough->copy()->subDay()->toDateString(), 'to' => $reportThrough->copy()->subDay()->toDateString()],
        'last_7_days' => ['label' => __('app.period_last_7_days'), 'from' => $reportThrough->copy()->subDays(6)->toDateString(), 'to' => $reportThrough->toDateString()],
        'this_month' => ['label' => __('app.period_this_month'), 'from' => $reportThrough->copy()->startOfMonth()->toDateString(), 'to' => $reportThrough->toDateString()],
        'last_month' => ['label' => __('app.period_last_month'), 'from' => $reportThrough->copy()->subMonthNoOverflow()->startOfMonth()->toDateString(), 'to' => $reportThrough->copy()->subMonthNoOverflow()->endOfMonth()->toDateString()],
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
    $defaultOwnershipExportType = match ($filters['ownership_type']) {
        $nwc => 'nwc',
        $icare => 'icare',
        default => null,
    };
    $ownershipExportUrl = function (?string $type = null) use ($filters, $defaultOwnershipExportType): string {
        return route('dashboard.ownership.export', array_filter([
            'project_id' => $filters['project_id'],
            'equipment_type_id' => $filters['equipment_type_id'],
            'type' => $type ?? $defaultOwnershipExportType,
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
    $dashboardWidgetDefaultTitles = [
        'ownership-share' => __('app.ownership_share'),
        'equipment-types-nwc' => __('app.equipment_type_distribution').': '.__('app.ownership_nwc'),
        'equipment-types-icare' => __('app.equipment_type_distribution').': '.__('app.ownership_icare'),
        'project-work-categories-nwc' => 'Project üzrə: '.__('app.ownership_nwc'),
        'project-work-categories-icare' => 'Project üzrə: '.__('app.ownership_icare'),
        'average-engine-hours' => 'Orta motosaat göstəricisi',
        'average-mileage' => 'Orta yürüş göstəricisi',
        'least-working' => __('app.least_working'),
        'most-working' => __('app.most_working'),
        'geofence-analysis' => __('app.geofence_analysis'),
        'geofence-violations-report' => __('app.geofence_violations'),
        'utilization-trend' => __('app.utilization_trend'),
        'project-comparison' => __('app.work_hours_by_ownership'),
    ];
    $dashboardTabs = $dashboardTabs ?? config('dashboard.tabs', []);
    $selectedDashboardTab = $selectedDashboardTab ?? (string) config('dashboard.default_tab', 'overview');
    $dashboardChartData = [
        'ownershipShareLabels' => $ownershipShare->pluck('label')->map($ownershipLabelFor)->values()->all(),
        'ownershipShareCounts' => $ownershipShare->pluck('count')->values()->all(),
        'ownershipShareTotal' => $totalOwnershipCount,
        'typeNwcLabels' => $typeNwcTop->pluck('name')->values()->all(),
        'typeNwcTotals' => $typeNwcTop->pluck('total')->values()->all(),
        'typeNwcIds' => $typeNwcTop->pluck('id')->values()->all(),
        'typeNwcTotal' => (int) $typeNwc->sum('total'),
        'typeIcareLabels' => $typeIcareTop->pluck('name')->values()->all(),
        'typeIcareTotals' => $typeIcareTop->pluck('total')->values()->all(),
        'typeIcareIds' => $typeIcareTop->pluck('id')->values()->all(),
        'typeIcareTotal' => (int) $typeIcare->sum('total'),
        'projectWorkCategoryNwcCounts' => $actualWorkCategoryLabels->keys()->map(fn (string $key): int => (int) ($projectWorkCategorySummaryNwc[$key] ?? 0))->values()->all(),
        'projectWorkCategoryIcareCounts' => $actualWorkCategoryLabels->keys()->map(fn (string $key): int => (int) ($projectWorkCategorySummaryIcare[$key] ?? 0))->values()->all(),
        'daytimeEfficiencyNwcCounts' => $actualWorkCategoryLabels->keys()->map(fn (string $key): int => (int) ($daytimeEfficiencySummaryNwc[$key] ?? 0))->values()->all(),
        'daytimeEfficiencyIcareCounts' => $actualWorkCategoryLabels->keys()->map(fn (string $key): int => (int) ($daytimeEfficiencySummaryIcare[$key] ?? 0))->values()->all(),
        'nighttimeEfficiencyNwcCounts' => $actualWorkCategoryLabels->keys()->map(fn (string $key): int => (int) ($nighttimeEfficiencySummaryNwc[$key] ?? 0))->values()->all(),
        'nighttimeEfficiencyIcareCounts' => $actualWorkCategoryLabels->keys()->map(fn (string $key): int => (int) ($nighttimeEfficiencySummaryIcare[$key] ?? 0))->values()->all(),
        'utilizationTrend' => $utilizationTrendByOwnership,
        'projectComparisonLabels' => $projectComparisonTop->pluck('name')->values()->all(),
        'projectComparisonIds' => $projectComparisonTop->pluck('id')->values()->all(),
        'projectComparisonNwc' => $projectComparisonTop->pluck($nwc)->values()->all(),
        'projectComparisonIcare' => $projectComparisonTop->pluck($icare)->values()->all(),
        'geofenceViolationLabels' => $geofenceViolations['labels'] ?? [],
        'geofenceViolationCounts' => $geofenceViolations['counts'] ?? [],
        'geofenceViolationProjectIds' => $geofenceViolations['project_ids'] ?? [],
        'geofenceViolationGeofenceIds' => $geofenceViolations['geofence_ids'] ?? [],
        'geofenceViolationSectorKeys' => $geofenceViolations['sector_keys'] ?? [],
        'geofenceViolationTotal' => (int) ($geofenceViolations['total'] ?? 0),
    ];
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
    .dashboard-drilldown-trigger,
    .dashboard-project-type-row {
        cursor: pointer;
    }
    .dashboard-drilldown-trigger:hover,
    .dashboard-project-type-row:hover {
        background: #f4f8ff;
    }
    .dashboard-drilldown-trigger:focus-visible,
    .dashboard-project-type-row:focus-visible {
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
        background: var(--fleet-card-soft);
    }
    .dashboard-drilldown-table.dashboard-project-type-table {
        width: 100%;
        table-layout: fixed;
    }
    .dashboard-project-type-table .dashboard-project-type-name {
        width: auto;
        min-width: 220px;
    }
    .dashboard-project-type-table col.dashboard-project-type-number {
        width: 88px;
    }
    .dashboard-project-type-table th.dashboard-project-type-number,
    .dashboard-project-type-table td.dashboard-project-type-number {
        padding-right: 8px;
        padding-left: 8px;
        text-align: center !important;
        white-space: nowrap;
        font-variant-numeric: tabular-nums;
        font-feature-settings: "tnum" 1;
    }
    .dashboard-project-type-table .dashboard-project-type-total {
        border-left: 1px solid var(--fleet-line);
        background: var(--fleet-card-soft);
        font-weight: 800;
    }
    @media (max-width: 575.98px) {
        .dashboard-project-type-table col.dashboard-project-type-number {
            width: 72px;
        }
    }
    .dashboard-drilldown-status {
        min-height: 24px;
    }
    .dashboard-drilldown-filter-panel {
        border: 1px solid var(--fleet-line);
        border-radius: 14px;
        background: var(--fleet-card-soft);
        box-shadow: 0 18px 45px rgba(15, 31, 58, .08);
        padding: 14px;
        margin-bottom: 12px;
    }
    .dashboard-drilldown-filter-panel .form-label {
        font-size: 11px;
        font-weight: 700;
        color: var(--fleet-muted);
        margin-bottom: 4px;
    }
    .dashboard-drilldown-chips {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        margin-bottom: 10px;
    }
    .dashboard-drilldown-chip {
        border: 1px solid color-mix(in srgb, var(--fleet-blue) 28%, var(--fleet-line));
        background: color-mix(in srgb, var(--fleet-blue) 10%, var(--fleet-card));
        color: var(--fleet-blue);
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
        background: var(--fleet-hover);
        border-color: var(--fleet-line);
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
    .dashboard-project-comparison-table {
        width: 100%;
        table-layout: fixed;
    }
    .dashboard-project-comparison-table col:first-child {
        width: auto;
    }
    .dashboard-project-comparison-table col:not(:first-child) {
        width: 72px;
    }
    .dashboard-project-comparison-table th:not(:first-child),
    .dashboard-project-comparison-table td:not(:first-child) {
        font-variant-numeric: tabular-nums;
        white-space: nowrap;
    }
    .dashboard-project-comparison-table th:last-child,
    .dashboard-project-comparison-table td:last-child {
        font-weight: 800;
    }
    .dashboard-project-comparison-total td {
        background: color-mix(in srgb, var(--fleet-blue) 8%, var(--fleet-card));
        font-weight: 800;
        border-bottom-width: 2px;
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
        border-bottom: 2px solid var(--fleet-line);
    }
    .dashboard-work-status-total + .dashboard-work-status-additional td {
        padding-top: 1rem;
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
        background: var(--fleet-card);
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
        border: 1px dashed var(--fleet-line);
        border-radius: 8px;
        display: grid;
        place-items: center;
        color: var(--fleet-muted);
        background: var(--fleet-card-soft);
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
        background: var(--fleet-card);
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
        background: var(--fleet-card-soft);
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
        background: var(--fleet-card);
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
        background: var(--fleet-card-soft);
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
        background: var(--fleet-card);
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
        background: var(--fleet-card);
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
        background: var(--fleet-card);
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
        background: color-mix(in srgb, var(--fleet-blue) 12%, var(--fleet-card));
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
        background: var(--fleet-card);
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
        background: var(--fleet-card-soft);
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
        background: var(--fleet-hover);
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
        background: color-mix(in srgb, var(--fleet-bg) 82%, transparent);
        backdrop-filter: blur(10px);
    }
    .dashboard-loading-overlay.is-active {
        display: flex;
    }
    .dashboard-loading-card {
        width: min(460px, 100%);
        border: 1px solid var(--fleet-line);
        border-radius: 8px;
        background: var(--fleet-card);
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
    .geofence-paired-widget .foreign-geofence-shell {
        height: 100%;
        display: flex;
        flex-direction: column;
    }
    .geofence-paired-widget .foreign-geofence-header {
        flex-direction: column;
        align-items: stretch;
    }
    .geofence-paired-widget .foreign-geofence-actions {
        justify-content: flex-start;
    }
    .geofence-paired-widget .foreign-geofence-card {
        height: auto;
        min-height: 0;
    }
    .geofence-paired-widget .foreign-geofence-donut-layout {
        grid-template-columns: minmax(0, 1fr);
        align-items: start;
        height: auto;
    }
    .geofence-paired-widget .foreign-geofence-donut-wrap {
        width: min(100%, 260px);
    }
    .geofence-paired-widget .foreign-geofence-legend {
        max-height: 230px;
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
        color: var(--fleet-ink);
        font-size: clamp(1.25rem, 1.65vw, 1.75rem);
        font-weight: 800;
        line-height: 1.2;
    }
    .foreign-geofence-subtitle {
        margin-top: 4px;
        color: var(--fleet-muted);
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
        background: color-mix(in srgb, var(--fleet-blue) 12%, var(--fleet-card));
        color: var(--fleet-blue);
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
        border: 1px solid var(--fleet-line);
        border-radius: 14px;
        padding: 8px 38px 8px 14px;
        color: var(--fleet-ink);
        background-color: var(--fleet-card);
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
    .foreign-geofence-card {
        border: 1px solid var(--fleet-line);
        border-radius: 16px;
        background: var(--fleet-card);
        box-shadow: 0 18px 42px rgba(15, 23, 42, .06);
        transition: transform .24s ease, box-shadow .24s ease, border-color .24s ease;
    }
    .foreign-geofence-card:hover {
        transform: translateY(-2px);
        border-color: color-mix(in srgb, var(--fleet-blue) 24%, var(--fleet-line));
        box-shadow: 0 24px 54px rgba(15, 23, 42, .1);
    }
    .foreign-geofence-card {
        height: 400px;
        min-height: 0;
        padding: 18px 20px;
        overflow: hidden;
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
    .geofence-report-donut {
        position: relative;
        z-index: 1;
        width: 100%;
        height: 100%;
        border-radius: 50%;
        background: var(--geofence-report-donut-background);
        box-shadow: 0 12px 30px rgba(15, 23, 42, .08);
    }
    .geofence-report-donut-link {
        display: block;
        padding: 0;
        border: 0;
        background: transparent;
        color: inherit;
        font: inherit;
        text-decoration: none;
        cursor: pointer;
        border-radius: 50%;
        transition: transform .15s ease, filter .15s ease;
    }
    .geofence-report-donut-link:hover,
    .geofence-report-donut-link:focus-visible {
        color: inherit;
        transform: translateY(-2px);
        filter: brightness(.99);
    }
    .geofence-report-donut-link:focus-visible {
        outline: 3px solid rgba(37, 99, 235, .3);
        outline-offset: 4px;
    }
    .geofence-report-donut::after {
        content: "";
        position: absolute;
        inset: 22%;
        border-radius: 50%;
        background: var(--fleet-card);
        box-shadow: inset 0 0 0 1px var(--fleet-line);
    }
    .geofence-report-legend-wrap {
        min-width: 0;
    }
    .foreign-geofence-center {
        position: absolute;
        inset: 50% auto auto 50%;
        transform: translate(-50%, -50%);
        display: grid;
        place-items: center;
        text-align: center;
        pointer-events: none;
        color: var(--fleet-ink);
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
        color: var(--fleet-muted);
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
        background: var(--fleet-card-soft);
        display: grid;
        grid-template-columns: 14px minmax(0, 1fr) auto auto;
        gap: 10px;
        align-items: center;
        min-height: 42px;
        padding: 7px 10px;
        color: var(--fleet-ink);
        text-align: left;
        transition: background .24s ease, transform .24s ease, box-shadow .24s ease;
    }
    .foreign-geofence-legend-row:hover,
    .foreign-geofence-legend-row:focus-visible {
        background: var(--fleet-hover);
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
        color: var(--fleet-ink);
        font-size: .8125rem;
        font-weight: 900;
    }
    .foreign-geofence-legend-percent {
        min-width: 40px;
        color: var(--fleet-muted);
        text-align: right;
        font-size: .75rem;
        font-weight: 800;
    }
    .foreign-geofence-empty {
        border-radius: 12px;
        background: var(--fleet-card-soft);
        color: var(--fleet-muted);
        padding: 14px;
        font-size: .8125rem;
        font-weight: 800;
        text-align: center;
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
    .foreign-geofence-card {
        animation: foreignGeofenceFadeUp .32s ease both;
    }
    @media (max-width: 1199.98px) {
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
        .foreign-geofence-header {
            align-items: stretch;
            flex-direction: column;
        }
        .foreign-geofence-actions {
            justify-content: flex-start;
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
        .foreign-geofence-card {
            padding: 16px;
        }
        .foreign-geofence-actions,
        .foreign-geofence-period {
            width: 100%;
        }
        .foreign-geofence-action {
            width: 100%;
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
    .foreign-geofence-card {
        border-radius: 16px !important;
        border-color: var(--fleet-line) !important;
        background: var(--fleet-card) !important;
        box-shadow: 0 18px 44px rgba(15, 23, 42, .055) !important;
    }
    .dashboard-card:hover,
    .dashboard-average-type-card:hover,
    .foreign-geofence-card:hover {
        box-shadow: 0 22px 54px rgba(15, 23, 42, .075) !important;
    }
    .dashboard-card-title-text {
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
    .dashboard-drilldown-table-wrapper {
        border: 1px solid var(--fleet-line);
        border-radius: 14px;
        background: var(--fleet-card);
    }
    .dashboard-scroll-table thead th,
    .dashboard-drilldown-table thead th,
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
    .table tbody td {
        border-color: var(--fleet-line);
    }
    .dashboard-scroll-table tbody tr,
    .dashboard-drilldown-table tbody tr,
    .table tbody tr {
        transition: background .15s ease, box-shadow .15s ease;
    }
    .dashboard-scroll-table tbody tr:hover,
    .dashboard-drilldown-table tbody tr:hover,
    .table tbody tr:hover,
    .dashboard-drilldown-trigger:hover,
    .dashboard-project-type-row:hover {
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
    [data-theme="dark"] .dashboard-drilldown-table-wrapper {
        background: var(--fleet-card);
        border-color: var(--fleet-line);
    }
    [data-theme="dark"] .metric-card::after {
        opacity: .18;
    }
    [data-theme="dark"] .dashboard-card,
    [data-theme="dark"] .dashboard-average-type-card,
    [data-theme="dark"] .foreign-geofence-card,
    [data-theme="dark"] .modal-content {
        box-shadow: 0 18px 46px rgba(0, 0, 0, .22) !important;
    }
    [data-theme="dark"] .dashboard-scroll-table thead th,
    [data-theme="dark"] .dashboard-drilldown-table thead th,
    [data-theme="dark"] .table thead th {
        background: var(--fleet-card-soft);
    }
    .dashboard-active-filters {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 8px;
        margin: -8px 0 18px;
    }
    .dashboard-active-filters-label,
    .dashboard-filter-chip {
        min-height: 32px;
        display: inline-flex;
        align-items: center;
        gap: 7px;
        border-radius: 8px;
        font-size: .78rem;
        font-weight: 700;
    }
    .dashboard-active-filters-label {
        color: var(--fleet-muted);
    }
    .dashboard-active-filters-label .lucide,
    .dashboard-filter-chip .lucide {
        width: 14px;
        height: 14px;
    }

    .dashboard-efficiency-subnav {
        position: sticky;
        top: 76px;
        z-index: 20;
        display: flex;
        align-items: center;
        gap: .35rem;
        margin-bottom: 1rem;
        overflow-x: auto;
        padding: .45rem;
        border: 1px solid var(--fleet-line);
        border-radius: 8px;
        background: color-mix(in srgb, var(--fleet-card) 94%, transparent);
        box-shadow: 0 8px 20px rgba(15, 23, 42, .06);
        backdrop-filter: blur(10px);
        scrollbar-width: thin;
    }

    .dashboard-efficiency-subnav .btn {
        flex: 0 0 auto;
        white-space: nowrap;
    }

    .dashboard-efficiency-section-heading,
    .dashboard-efficiency-shift-section {
        scroll-margin-top: 142px;
    }

    .dashboard-efficiency-section-heading {
        padding-top: .35rem;
    }

    .dashboard-efficiency-section-heading h2,
    .dashboard-efficiency-shift-section h2 {
        letter-spacing: 0;
    }

    .dashboard-efficiency-section-meta {
        display: flex;
        flex-wrap: wrap;
        gap: .25rem .9rem;
        color: var(--fleet-muted);
        font-size: .82rem;
    }

    .dashboard-efficiency-section-meta span {
        display: inline-flex;
        align-items: center;
        gap: .3rem;
    }

    #efficiency-general { order: 100 !important; }
    [data-widget-key="project-work-categories-nwc"] { order: 110 !important; }
    [data-widget-key="project-work-categories-icare"] { order: 111 !important; }
    #efficiency-daytime { order: 200 !important; }
    #efficiency-nighttime { order: 300 !important; }
    #efficiency-averages { order: 400 !important; }
    [data-widget-key="average-engine-hours"] { order: 410 !important; }
    [data-widget-key="average-mileage"] { order: 411 !important; }
    #efficiency-top20 { order: 500 !important; }
    [data-widget-key="least-working"] { order: 510 !important; }
    [data-widget-key="most-working"] { order: 511 !important; }

    .dashboard-ranking-card {
        display: flex;
        flex-direction: column;
        width: 100%;
        min-height: 420px;
    }

    .dashboard-ranking-card .dashboard-panel-header {
        min-height: 32px;
    }

    .dashboard-ranking-card .dashboard-ranking-table {
        flex: 1 1 auto;
        height: 350px;
        max-height: 350px;
        overscroll-behavior: contain;
    }

    .dashboard-efficiency-pair-widget,
    .dashboard-top-widget,
    .dashboard-efficiency-shift-section .row > [class*="col-"] {
        display: flex;
    }

    .dashboard-efficiency-pair-widget > .dashboard-card,
    .dashboard-top-widget > .dashboard-card,
    .dashboard-efficiency-shift-section .row > [class*="col-"] > .dashboard-card {
        width: 100%;
        height: 100%;
    }
    .dashboard-filter-chip {
        padding: 5px 9px;
        border: 1px solid color-mix(in srgb, var(--fleet-blue) 28%, var(--fleet-line));
        color: var(--fleet-blue);
        background: color-mix(in srgb, var(--fleet-blue) 8%, var(--fleet-card));
        text-decoration: none;
    }
    .dashboard-filter-chip:hover,
    .dashboard-filter-chip:focus-visible {
        border-color: var(--fleet-blue);
        color: var(--fleet-blue);
        background: color-mix(in srgb, var(--fleet-blue) 13%, var(--fleet-card));
    }
    .dashboard-design-backdrop {
        position: fixed;
        inset: 0;
        z-index: 1080;
        background: rgba(15, 23, 42, .42);
        backdrop-filter: blur(2px);
    }
    .dashboard-design-drawer {
        position: fixed;
        inset: 0 0 0 auto;
        z-index: 1090;
        width: min(520px, 100vw);
        display: grid;
        grid-template-rows: auto minmax(0, 1fr) auto auto;
        color: var(--fleet-ink);
        background: var(--fleet-card);
        border-left: 1px solid var(--fleet-line);
        box-shadow: -24px 0 60px rgba(15, 23, 42, .2);
    }
    .dashboard-design-header,
    .dashboard-design-footer {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 18px 20px;
        border-color: var(--fleet-line);
    }
    .dashboard-design-header {
        justify-content: space-between;
        border-bottom: 1px solid var(--fleet-line);
    }
    .dashboard-design-footer {
        border-top: 1px solid var(--fleet-line);
    }
    .dashboard-design-footer .btn {
        display: inline-flex;
        align-items: center;
        gap: 7px;
    }
    .dashboard-design-footer .lucide,
    .dashboard-design-icon-button .lucide {
        width: 17px;
        height: 17px;
    }
    .dashboard-design-icon-button {
        width: 36px;
        height: 36px;
        display: grid;
        place-items: center;
        border: 1px solid var(--fleet-line);
        border-radius: 8px;
        color: var(--fleet-muted);
        background: var(--fleet-card);
    }
    .dashboard-design-form {
        overflow-y: auto;
        padding: 20px;
    }
    .dashboard-design-section {
        border: 0;
        padding: 0;
        margin: 0 0 26px;
    }
    .dashboard-design-section legend {
        float: none;
        width: auto;
        margin-bottom: 12px;
        color: var(--fleet-ink);
        font-size: .82rem;
        font-weight: 800;
    }
    .dashboard-layout-options {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
    }
    .dashboard-layout-option {
        position: relative;
        display: grid;
        grid-template-rows: 88px auto;
        gap: 9px;
        min-width: 0;
        padding: 10px;
        border: 1px solid var(--fleet-line);
        border-radius: 8px;
        background: var(--fleet-card);
        cursor: pointer;
        transition: border-color .15s ease, box-shadow .15s ease, background .15s ease;
    }
    .dashboard-layout-option:has(input:checked) {
        border-color: var(--fleet-blue);
        box-shadow: 0 0 0 2px color-mix(in srgb, var(--fleet-blue) 18%, transparent);
        background: color-mix(in srgb, var(--fleet-blue) 4%, var(--fleet-card));
    }
    .dashboard-layout-option > input {
        position: absolute;
        opacity: 0;
        pointer-events: none;
    }
    .dashboard-layout-copy {
        display: grid;
        gap: 2px;
        min-width: 0;
    }
    .dashboard-layout-copy strong {
        font-size: .8rem;
    }
    .dashboard-layout-copy small {
        color: var(--fleet-muted);
        font-size: .68rem;
        line-height: 1.35;
    }
    .dashboard-layout-selected {
        position: absolute;
        top: 7px;
        right: 7px;
        display: none;
        padding: 2px 6px;
        border-radius: 6px;
        color: #fff;
        background: var(--fleet-blue);
        font-size: .62rem;
        font-weight: 800;
    }
    .dashboard-layout-option:has(input:checked) .dashboard-layout-selected {
        display: inline-flex;
    }
    .dashboard-layout-preview {
        position: relative;
        display: block;
        overflow: hidden;
        border: 1px solid color-mix(in srgb, var(--fleet-line) 86%, transparent);
        border-radius: 6px;
        background: var(--fleet-card-soft);
    }
    .dashboard-layout-preview span,
    .dashboard-layout-preview i {
        position: absolute;
        display: block;
        border-radius: 2px;
        background: color-mix(in srgb, var(--fleet-muted) 16%, var(--fleet-card));
    }
    .preview-sidebar { inset: 0 auto 0 0; width: 14%; background: color-mix(in srgb, var(--fleet-blue) 32%, var(--fleet-card)) !important; }
    .preview-filter { inset: 8px 7px auto 19%; height: 13px; }
    .preview-kpis { inset: 27px 7px auto 19%; height: 14px; background: transparent !important; }
    .preview-kpis i { top: 0; bottom: 0; width: 29%; }
    .preview-kpis i:nth-child(2) { left: 35%; }
    .preview-kpis i:nth-child(3) { right: 0; }
    .preview-donut { left: 19%; bottom: 8px; width: 29px; height: 29px; border-radius: 50% !important; border: 7px solid color-mix(in srgb, var(--fleet-blue) 55%, var(--fleet-card)); background: transparent !important; }
    .preview-table { inset: auto 7px 8px 47%; height: 29px; }
    .preview-table i { left: 4px; right: 4px; height: 3px; background: var(--fleet-card) !important; }
    .preview-table i:nth-child(1) { top: 5px; } .preview-table i:nth-child(2) { top: 13px; } .preview-table i:nth-child(3) { top: 21px; }
    .dashboard-layout-preview--compact .preview-filter { height: 8px; }
    .dashboard-layout-preview--compact .preview-kpis { top: 21px; height: 10px; }
    .dashboard-layout-preview--compact .preview-donut,
    .dashboard-layout-preview--compact .preview-table { bottom: 6px; height: 43px; }
    .dashboard-layout-preview--compact .preview-donut { width: 43px; }
    .dashboard-layout-preview--card_grid .preview-filter { height: 8px; }
    .dashboard-layout-preview--card_grid .preview-donut { left: 19%; bottom: 6px; width: 37%; height: 43px; border-radius: 3px !important; border-width: 0; background: color-mix(in srgb, var(--fleet-blue) 22%, var(--fleet-card)) !important; }
    .dashboard-layout-preview--card_grid .preview-table { left: 59%; height: 43px; }
    .dashboard-layout-preview--side_filters .preview-sidebar { width: 27%; background: color-mix(in srgb, var(--fleet-muted) 17%, var(--fleet-card)) !important; }
    .dashboard-layout-preview--side_filters .preview-filter { inset: 8px auto 8px 5%; width: 17%; height: auto; background: color-mix(in srgb, var(--fleet-blue) 22%, var(--fleet-card)) !important; }
    .dashboard-layout-preview--side_filters .preview-kpis { left: 32%; }
    .dashboard-layout-preview--side_filters .preview-donut { left: 32%; }
    .dashboard-layout-preview--side_filters .preview-table { left: 58%; }
    .dashboard-layout-preview--dark_analytics { background: #111827; }
    .dashboard-layout-preview--dark_analytics span:not(.preview-kpis),
    .dashboard-layout-preview--dark_analytics i { background-color: #334155; }
    .dashboard-preference-field {
        display: grid;
        grid-template-columns: minmax(145px, 1fr) minmax(190px, 1.35fr);
        align-items: center;
        gap: 14px;
        padding: 11px 0;
        border-bottom: 1px solid var(--fleet-line);
    }
    .dashboard-preference-field > span {
        font-size: .78rem;
        font-weight: 700;
    }
    .dashboard-segmented-control {
        display: grid;
        grid-auto-flow: column;
        grid-auto-columns: 1fr;
        padding: 3px;
        border: 1px solid var(--fleet-line);
        border-radius: 8px;
        background: var(--fleet-card-soft);
    }
    .dashboard-segmented-control label { cursor: pointer; }
    .dashboard-segmented-control input { position: absolute; opacity: 0; }
    .dashboard-segmented-control span {
        min-height: 30px;
        display: grid;
        place-items: center;
        border-radius: 6px;
        color: var(--fleet-muted);
        font-size: .7rem;
        font-weight: 800;
    }
    .dashboard-segmented-control input:checked + span {
        color: var(--fleet-blue);
        background: var(--fleet-card);
        box-shadow: 0 1px 4px rgba(15, 23, 42, .1);
    }
    .dashboard-design-status {
        min-height: 28px;
        padding: 6px 20px;
        color: var(--fleet-muted);
    }
    body.dashboard-design-open { overflow: hidden; }
    .dashboard-page[data-dashboard-density="compact"] { --dashboard-density-padding: 12px; }
    .dashboard-page[data-dashboard-density="dense"] { --dashboard-density-padding: 9px; }
    .dashboard-page[data-dashboard-density="compact"] #dashboardFilterForm,
    .dashboard-page[data-dashboard-density="compact"] .dashboard-card,
    .dashboard-page[data-dashboard-density="compact"] .metric-card { padding: 12px !important; }
    .dashboard-page[data-dashboard-density="dense"] #dashboardFilterForm,
    .dashboard-page[data-dashboard-density="dense"] .dashboard-card,
    .dashboard-page[data-dashboard-density="dense"] .metric-card { padding: 9px !important; }
    .dashboard-page[data-dashboard-density="compact"] #dashboardGrid { --bs-gutter-x: .75rem; --bs-gutter-y: .75rem; }
    .dashboard-page[data-dashboard-density="dense"] #dashboardGrid { --bs-gutter-x: .5rem; --bs-gutter-y: .5rem; }
    .dashboard-page[data-dashboard-table-density="compact"] .table > :not(caption) > * > * { padding-block: .3rem; }
    .dashboard-page[data-dashboard-table-density="dense"] .table > :not(caption) > * > * { padding-block: .2rem; font-size: .74rem; }
    .dashboard-page .table-responsive thead th,
    .dashboard-page .dashboard-scroll-table thead th {
        position: sticky;
        top: 0;
        z-index: 2;
        background: var(--fleet-card-soft);
    }
    .dashboard-page[data-dashboard-kpi-size="small"] .metric-card { min-height: 96px; }
    .dashboard-page[data-dashboard-kpi-size="small"] .metric-icon { width: 44px; height: 44px; font-size: 1.25rem; }
    .dashboard-page[data-dashboard-kpi-size="small"] .metric-value { font-size: 1.35rem; }
    .dashboard-page[data-dashboard-kpi-size="large"] .metric-card { min-height: 146px; }
    .dashboard-page[data-dashboard-kpi-size="large"] .metric-icon { width: 66px; height: 66px; font-size: 1.85rem; }
    .dashboard-page[data-dashboard-kpi-size="large"] .metric-value { font-size: 2rem; }
    .dashboard-page[data-dashboard-legend-position="bottom"] .dashboard-donut-layout,
    .dashboard-page[data-dashboard-legend-position="bottom"] .dashboard-work-status-layout,
    .dashboard-page[data-dashboard-legend-position="bottom"] .foreign-geofence-donut-layout {
        grid-template-columns: 1fr;
    }
    .dashboard-page[data-dashboard-legend-position="hidden"] .dashboard-donut-layout > :last-child,
    .dashboard-page[data-dashboard-legend-position="hidden"] .dashboard-work-status-table,
    .dashboard-page[data-dashboard-legend-position="hidden"] .dashboard-work-status-legend,
    .dashboard-page[data-dashboard-legend-position="hidden"] .foreign-geofence-legend {
        display: none !important;
    }
    .dashboard-page[data-dashboard-layout-variant="dark_analytics"] .dashboard-card,
    .dashboard-page[data-dashboard-layout-variant="dark_analytics"] .metric-card,
    .dashboard-page[data-dashboard-layout-variant="dark_analytics"] #dashboardFilterForm {
        border-color: color-mix(in srgb, var(--fleet-blue) 20%, var(--fleet-line)) !important;
        box-shadow: 0 12px 30px rgba(0, 0, 0, .18) !important;
    }
    @media (min-width: 1200px) {
        #dashboardGrid[data-dashboard-active-tab="efficiency"] > .dashboard-widget {
            width: 50% !important;
        }

        .dashboard-page[data-dashboard-layout-variant="compact"] #dashboardGrid > .dashboard-widget {
            width: 33.333333%;
        }
        .dashboard-page[data-dashboard-layout-variant="card_grid"] #dashboardGrid > .dashboard-widget {
            width: 50%;
        }
        .dashboard-page[data-dashboard-layout-variant="side_filters"] {
            display: grid;
            grid-template-columns: 260px minmax(0, 1fr);
            column-gap: 18px;
            align-items: start;
        }
        .dashboard-page[data-dashboard-layout-variant="side_filters"] > :not(#dashboardFilterForm):not(.dashboard-loading-overlay):not(.dashboard-design-backdrop):not(.dashboard-design-drawer) {
            grid-column: 2;
        }
        .dashboard-page[data-dashboard-layout-variant="side_filters"] > #dashboardFilterForm {
            grid-column: 1;
            grid-row: 1 / span 8;
            position: sticky;
            top: 88px;
            margin-bottom: 0 !important;
        }
        .dashboard-page[data-dashboard-layout-variant="side_filters"] #dashboardFilterForm .row > * {
            width: 100%;
        }
        .dashboard-page[data-dashboard-layout-variant="side_filters"] #dashboardFilterForm .dashboard-period-button {
            flex: 1 1 100%;
        }
    }
    @media (max-width: 1199.98px) {
        .dashboard-page {
            overflow-x: clip;
        }

        .dashboard-scroll-table {
            contain: inline-size;
            max-width: 100%;
        }
    }
    @media (min-width: 1200px) and (max-width: 1399.98px) {
        .dashboard-page[data-dashboard-layout-variant="side_filters"] #dashboardGrid[data-dashboard-active-tab="efficiency"] > .dashboard-widget,
        .dashboard-page[data-dashboard-layout-variant="side_filters"] .dashboard-efficiency-shift-section .row > [class*="col-"] {
            width: 100% !important;
        }
    }
    @media (max-width: 1199.98px) and (min-width: 768px) {
        .dashboard-page[data-dashboard-layout-variant="compact"] #dashboardGrid > .dashboard-widget,
        .dashboard-page[data-dashboard-layout-variant="card_grid"] #dashboardGrid > .dashboard-widget {
            width: 50%;
        }
    }
    @media (max-width: 767.98px) {
        .dashboard-efficiency-subnav {
            top: 64px;
        }

        .dashboard-efficiency-section-heading,
        .dashboard-efficiency-shift-section {
            scroll-margin-top: 128px;
        }

        .dashboard-ranking-card {
            min-height: 390px;
        }

        .dashboard-ranking-card .dashboard-ranking-table {
            height: 320px;
            max-height: 320px;
        }

        .dashboard-design-drawer {
            width: 100vw;
            border-left: 0;
        }
        .dashboard-design-form { padding: 16px; }
        .dashboard-design-header,
        .dashboard-design-footer { padding: 14px 16px; }
        .dashboard-design-footer { flex-wrap: wrap; }
        .dashboard-design-footer > .btn { width: 100%; justify-content: center; }
        .dashboard-design-footer > .d-flex { width: 100%; margin-left: 0 !important; }
        .dashboard-design-footer > .d-flex .btn { flex: 1 1 0; }
        .dashboard-layout-options { grid-template-columns: 1fr; }
        .dashboard-preference-field { grid-template-columns: 1fr; gap: 7px; }
        .dashboard-page #dashboardGrid > .dashboard-widget { width: 100% !important; }
        .dashboard-active-filters { margin-top: 0; }
    }
</style>
@endpush

@section('content')
    <div
        class="dashboard-page"
        data-dashboard-layout-editable="{{ $canManageDashboardLayout ? '1' : '0' }}"
        data-dashboard-layout-update-url="{{ route('dashboard.layout.update') }}"
        data-dashboard-layout-reset-url="{{ route('dashboard.layout.destroy') }}"
        data-dashboard-tab-url-template="{{ route('dashboard.tabs.show', ['tab' => '__TAB__']) }}"
        data-dashboard-drilldown-url="{{ route('dashboard.drilldown.units') }}"
        data-dashboard-drilldown-export-url="{{ route('dashboard.drilldown.units.export') }}"
        data-geofence-violations-drilldown-url="{{ route('dashboard.geofence-violations.drilldown') }}"
        data-dashboard-date-from="{{ $filters['from'] }}"
        data-dashboard-date-to="{{ $filters['to'] }}"
        data-dashboard-today="{{ $today->toDateString() }}"
        data-dashboard-project-id="{{ $filters['project_id'] ?? '' }}"
        data-dashboard-equipment-type-id="{{ $filters['equipment_type_id'] ?? '' }}"
        data-dashboard-ownership="{{ $filters['ownership_type'] === $nwc ? 'nwc' : ($filters['ownership_type'] === $icare ? 'icare' : 'all') }}"
        data-dashboard-saved-layout="{{ json_encode($dashboardLayout ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}"
        data-dashboard-default-titles="{{ json_encode($dashboardWidgetDefaultTitles, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}"
        data-dashboard-layout-revision="{{ (int) ($dashboardLayoutRevision ?? 0) }}"
        data-dashboard-preferences='@json($dashboardPreferences)'
        data-dashboard-preferences-update-url="{{ route('api.user.dashboard-preferences.update') }}"
        data-dashboard-preferences-reset-url="{{ route('api.user.dashboard-preferences.destroy') }}"
        data-dashboard-layout-variant="{{ $dashboardPreferences['layout'] }}"
        data-dashboard-density="{{ $dashboardPreferences['density'] }}"
        data-dashboard-table-density="{{ $dashboardPreferences['table_density'] }}"
        data-dashboard-legend-position="{{ $dashboardPreferences['donut_legend_position'] }}"
        data-dashboard-kpi-size="{{ $dashboardPreferences['kpi_size'] }}"
    >
        @unless ($dashboardTabFragment ?? false)
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
            <input type="hidden" name="tab" id="dashboardSelectedTabInput" value="{{ $selectedDashboardTab }}">
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

        @php
            $filterQueryWithout = fn (array $keys): array => collect(request()->query())->except($keys)->all();
            $activeOwnershipLabel = $filters['ownership_type'] === $nwc
                ? __('app.ownership_nwc')
                : ($filters['ownership_type'] === $icare ? __('app.ownership_icare') : null);
        @endphp
        @if ($selectedProject || $filters['equipment_type_id'] || $activeOwnershipLabel || $selectedPeriod === 'custom')
            <div class="dashboard-active-filters" aria-label="Aktiv filtrlər">
                <span class="dashboard-active-filters-label"><i data-lucide="list-filter"></i> Aktiv filtrlər</span>
                @if ($selectedProject)
                    <a class="dashboard-filter-chip" href="{{ route('dashboard', $filterQueryWithout(['project_id'])) }}">Layihə: {{ $selectedProject->name }} <i data-lucide="x"></i></a>
                @endif
                @if ($filters['equipment_type_id'])
                    @php
                        $selectedEquipmentType = $equipmentTypeOptions->firstWhere('id', $filters['equipment_type_id']);
                    @endphp
                    <a class="dashboard-filter-chip" href="{{ route('dashboard', $filterQueryWithout(['equipment_type_id'])) }}">Növ: {{ $selectedEquipmentType?->name ?? $filters['equipment_type_id'] }} <i data-lucide="x"></i></a>
                @endif
                @if ($activeOwnershipLabel)
                    <a class="dashboard-filter-chip" href="{{ route('dashboard', $filterQueryWithout(['ownership_type'])) }}">Sahiblik: {{ $activeOwnershipLabel }} <i data-lucide="x"></i></a>
                @endif
                @if ($selectedPeriod === 'custom')
                    <a class="dashboard-filter-chip" href="{{ route('dashboard', $filterQueryWithout(['period', 'date_from', 'date_to'])) }}">Dövr: {{ $filters['from'] }} – {{ $filters['to'] }} <i data-lucide="x"></i></a>
                @endif
            </div>
        @endif

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

        <nav class="dashboard-tabs mb-3" aria-label="{{ __('app.dashboard_sections') }}">
            <div class="nav nav-tabs" role="tablist">
                @foreach ($dashboardTabs as $tabKey => $tab)
                    @php
                        $isSelectedDashboardTab = $selectedDashboardTab === $tabKey;
                    @endphp
                    <button
                        type="button"
                        class="nav-link{{ $isSelectedDashboardTab ? ' active' : '' }}"
                        id="dashboardTab{{ \Illuminate\Support\Str::studly($tabKey) }}"
                        role="tab"
                        aria-selected="{{ $isSelectedDashboardTab ? 'true' : 'false' }}"
                        aria-controls="dashboardGrid"
                        tabindex="{{ $isSelectedDashboardTab ? '0' : '-1' }}"
                        data-dashboard-tab="{{ $tabKey }}"
                    >
                        {{ __($tab['label_key']) }}
                    </button>
                @endforeach
            </div>
            <span class="visually-hidden" id="dashboardGeozonesDescription">Öz layihəsinin geozonasından kənarda olan texnikalar</span>
        </nav>

        @if ($latestPublishedReportDate)
            <div class="alert alert-info py-2 px-3 mb-3" role="status" aria-live="polite" data-dashboard-freshness>
                <i class="bi bi-database-check me-1" aria-hidden="true"></i>
                {{ __('app.data_updated_through', ['date' => $latestPublishedReportDate]) }}
            </div>
        @endif

        <div class="dashboard-layout-actions d-flex flex-wrap align-items-center justify-content-end gap-2 mb-2">
            <div class="dashboard-layout-status small me-auto" id="dashboardLayoutStatus" aria-live="polite"></div>
            @if ($canManageDashboardLayout)
                <form method="POST" action="{{ route('settings.sync-units') }}" data-dashboard-object-sync-form>
                    @csrf
                    <button
                        type="submit"
                        class="btn btn-outline-primary btn-sm btn-icon"
                        data-dashboard-object-sync-button
                        title="Wialon layihə qruplarından obyekt siyahısını yenilə"
                    >
                        <i class="bi bi-arrow-repeat"></i><span>Obyekt siyahısını yenilə</span>
                    </button>
                </form>
            @endif
            <a href="{{ $exportUrl('overview') }}" class="btn btn-outline-secondary btn-sm btn-icon" title="Excel" aria-label="Excel">
                <i class="bi bi-download"></i><span>Excel</span>
            </a>
            <button type="button" class="btn btn-outline-primary btn-sm btn-icon" id="openDashboardDesign">
                <i data-lucide="sliders-horizontal"></i><span>Düzülüşü dəyiş</span>
            </button>
            @if ($canManageDashboardLayout)
                <button type="button" class="btn btn-outline-primary btn-sm btn-icon" id="editDashboardLayout">
                    <i class="bi bi-layout-three-columns"></i><span>Kartları düzənlə</span>
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
        @include('dashboard.partials.design-preferences')
        @endunless

        <nav class="dashboard-efficiency-subnav" id="dashboardEfficiencySubnav" aria-label="Effektivlik bölmələri" @hidden($selectedDashboardTab !== 'efficiency')>
            <button type="button" class="btn btn-sm btn-primary" data-efficiency-section="general" aria-pressed="true">Ümumi</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-efficiency-section="daytime" aria-pressed="false">Gündüz</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-efficiency-section="nighttime" aria-pressed="false">Gecə</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-efficiency-section="averages" aria-pressed="false">Orta göstəricilər</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-efficiency-section="top20" aria-pressed="false">Top 20</button>
        </nav>

        <div
            class="row g-3 dashboard-grid"
            id="dashboardGrid"
            role="tabpanel"
            aria-labelledby="dashboardTab{{ \Illuminate\Support\Str::studly($selectedDashboardTab) }}"
            aria-live="polite"
            aria-busy="false"
            data-dashboard-active-tab="{{ $selectedDashboardTab }}"
            data-dashboard-chart-data="{{ json_encode($dashboardChartData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}"
        >
            @if ($selectedDashboardTab === 'overview')
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
                                            class="dashboard-share-row{{ $row['count'] > 0 ? ' dashboard-drilldown-trigger' : '' }}"
                                            @if ($row['count'] > 0)
                                                role="button"
                                                tabindex="0"
                                                data-drilldown-title="{{ $ownershipLabelFor($code) }} texnikaları"
                                                data-drilldown-ownership="{{ $code === $nwc ? 'nwc' : 'icare' }}"
                                                data-drilldown-ownership-scope="project_groups"
                                            @else
                                                aria-disabled="true"
                                            @endif
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
            @endif

            @if ($selectedDashboardTab === 'efficiency')
            <section
                id="efficiency-general"
                class="col-12 dashboard-efficiency-section-heading"
                data-efficiency-group="general"
                aria-labelledby="efficiency-general-title"
            >
                <h2 class="h4 fw-bold mb-1" id="efficiency-general-title">Ümumi effektivlik</h2>
                <div class="dashboard-efficiency-section-meta">
                    <span><i class="bi bi-database"></i>Mənbə: Qrup report Engine hours (api)</span>
                    <span><i class="bi bi-calculator"></i>Hesablama vahidi: Texnika-gün</span>
                </div>
            </section>

            @php
                $widgetLayout = $dashboardWidgetLayoutFor('project-work-categories-nwc', 'col-12 col-md-6', 6);
            @endphp
            <div class="{{ $widgetLayout['class'] }} dashboard-widget dashboard-efficiency-pair-widget{{ $dashboardWidgetVisibilityClassFor('project-work-categories-nwc') }}" data-dashboard-widget="project-work-categories-nwc" data-widget-key="project-work-categories-nwc" data-efficiency-group="general" data-widget-width="{{ $widgetLayout['width'] }}" data-widget-order="{{ $widgetLayout['order'] }}" data-widget-visible="{{ $dashboardWidgetVisibleFor('project-work-categories-nwc') ? '1' : '0' }}" style="order: 110" draggable="false">
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
                    'title' => $dashboardWidgetTitleFor('project-work-categories-nwc', 'Effektivlik: NWC üzrə'),
                ])
            </div>

            @php
                $widgetLayout = $dashboardWidgetLayoutFor('project-work-categories-icare', 'col-12 col-md-6', 6);
            @endphp
            <div class="{{ $widgetLayout['class'] }} dashboard-widget dashboard-efficiency-pair-widget{{ $dashboardWidgetVisibilityClassFor('project-work-categories-icare') }}" data-dashboard-widget="project-work-categories-icare" data-widget-key="project-work-categories-icare" data-efficiency-group="general" data-widget-width="{{ $widgetLayout['width'] }}" data-widget-order="{{ $widgetLayout['order'] }}" data-widget-visible="{{ $dashboardWidgetVisibleFor('project-work-categories-icare') ? '1' : '0' }}" style="order: 111" draggable="false">
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
                    'title' => $dashboardWidgetTitleFor('project-work-categories-icare', 'Effektivlik: İcarə üzrə'),
                ])
            </div>

            <section id="efficiency-daytime" class="col-12 mt-4 dashboard-efficiency-shift-section" data-efficiency-group="daytime" style="order: 200" aria-labelledby="daytime-efficiency-title">
                <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-3">
                    <div>
                        <h2 class="h4 fw-bold mb-1" id="daytime-efficiency-title">Gündüz növbəsi üzrə effektivlik</h2>
                        <div class="dashboard-efficiency-section-meta">
                            <span><i class="bi bi-clock"></i>08:00–17:59 intervalı üzrə Engine hours</span>
                            <span><i class="bi bi-database"></i>Mənbə: day report Engine hours (api)</span>
                            <span><i class="bi bi-calculator"></i>Hesablama vahidi: Texnika-gün</span>
                        </div>
                    </div>
                    <form method="GET" action="{{ route('dashboard') }}" class="d-flex align-items-end gap-2">
                        <input type="hidden" name="tab" value="efficiency">
                        <input type="hidden" name="date_from" value="{{ $filters['from'] }}">
                        <input type="hidden" name="date_to" value="{{ $filters['to'] }}">
                        @if ($filters['project_id'])<input type="hidden" name="project_id" value="{{ $filters['project_id'] }}">@endif
                        @if ($filters['equipment_type_id'])<input type="hidden" name="equipment_type_id" value="{{ $filters['equipment_type_id'] }}">@endif
                        @if ($filters['ownership_type'])<input type="hidden" name="ownership_type" value="{{ $filters['ownership_type'] }}">@endif
                        <div>
                            <label class="form-label small mb-1" for="daytime-efficiency-search">Axtarış</label>
                            <input id="daytime-efficiency-search" type="search" name="daytime_search" class="form-control form-control-sm" value="{{ request('daytime_search') }}" maxlength="120">
                        </div>
                        <button class="btn btn-sm btn-outline-primary" type="submit" title="Axtar"><i class="bi bi-search"></i></button>
                    </form>
                </div>
                <div class="row g-3">
                    <div class="col-12 col-md-6 d-flex">
                        @include('dashboard.partials.daytime-efficiency-card', [
                            'chartId' => 'daytimeEfficiencyNwc',
                            'ownershipCode' => $nwc,
                            'summary' => $daytimeEfficiencySummaryNwc,
                            'categoryLabels' => $actualWorkCategoryLabels,
                            'categoryColors' => $actualWorkCategoryColors,
                            'filters' => $filters,
                            'title' => 'Effektivlik gündüz: NWC üzrə',
                            'exportUrl' => route('api.dashboard.daytime-efficiency.export', array_filter([
                                'date_from' => $filters['from'], 'date_to' => $filters['to'], 'ownership' => 'nwc',
                                'project_id' => $filters['project_id'], 'equipment_type_id' => $filters['equipment_type_id'],
                                'search' => request('daytime_search'),
                            ], fn ($value) => $value !== null && $value !== '')),
                        ])
                    </div>
                    <div class="col-12 col-md-6 d-flex">
                        @include('dashboard.partials.daytime-efficiency-card', [
                            'chartId' => 'daytimeEfficiencyIcare',
                            'ownershipCode' => $icare,
                            'summary' => $daytimeEfficiencySummaryIcare,
                            'categoryLabels' => $actualWorkCategoryLabels,
                            'categoryColors' => $actualWorkCategoryColors,
                            'filters' => $filters,
                            'title' => 'Effektivlik gündüz: İcarə üzrə',
                            'exportUrl' => route('api.dashboard.daytime-efficiency.export', array_filter([
                                'date_from' => $filters['from'], 'date_to' => $filters['to'], 'ownership' => 'icare',
                                'project_id' => $filters['project_id'], 'equipment_type_id' => $filters['equipment_type_id'],
                                'search' => request('daytime_search'),
                            ], fn ($value) => $value !== null && $value !== '')),
                        ])
                    </div>
                </div>
            </section>

            <section id="efficiency-nighttime" class="col-12 mt-4 dashboard-efficiency-shift-section" data-efficiency-group="nighttime" style="order: 300" aria-labelledby="nighttime-efficiency-title">
                <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-3">
                    <div>
                        <h2 class="h4 fw-bold mb-1" id="nighttime-efficiency-title">Gecə növbəsi üzrə effektivlik</h2>
                        <div class="dashboard-efficiency-section-meta">
                            <span title="31.07 18:00-01.08 07:59 növbəsi 31.07 tarixinə aiddir"><i class="bi bi-moon-stars"></i>18:00–07:59 intervalı üzrə Engine hours</span>
                            <span><i class="bi bi-database"></i>Mənbə: night report Engine hours (api)</span>
                            <span><i class="bi bi-calculator"></i>Hesablama vahidi: Texnika-növbə</span>
                        </div>
                        <div class="small text-secondary mt-1">Növbə tarixi başlanğıc gününə görə hesablanır</div>
                    </div>
                    <form method="GET" action="{{ route('dashboard') }}" class="d-flex align-items-end gap-2">
                        <input type="hidden" name="tab" value="efficiency">
                        <input type="hidden" name="date_from" value="{{ $filters['from'] }}">
                        <input type="hidden" name="date_to" value="{{ $filters['to'] }}">
                        @if ($filters['project_id'])<input type="hidden" name="project_id" value="{{ $filters['project_id'] }}">@endif
                        @if ($filters['equipment_type_id'])<input type="hidden" name="equipment_type_id" value="{{ $filters['equipment_type_id'] }}">@endif
                        @if ($filters['ownership_type'])<input type="hidden" name="ownership_type" value="{{ $filters['ownership_type'] }}">@endif
                        <div>
                            <label class="form-label small mb-1" for="nighttime-efficiency-search">Axtarış</label>
                            <input id="nighttime-efficiency-search" type="search" name="nighttime_search" class="form-control form-control-sm" value="{{ request('nighttime_search') }}" maxlength="120">
                        </div>
                        <button class="btn btn-sm btn-outline-primary" type="submit" title="Axtar"><i class="bi bi-search"></i></button>
                    </form>
                </div>
                <div class="row g-3">
                    <div class="col-12 col-md-6 d-flex">
                        @include('dashboard.partials.nighttime-efficiency-card', [
                            'chartId' => 'nighttimeEfficiencyNwc',
                            'ownershipCode' => $nwc,
                            'summary' => $nighttimeEfficiencySummaryNwc,
                            'categoryLabels' => $actualWorkCategoryLabels,
                            'categoryColors' => $actualWorkCategoryColors,
                            'filters' => $filters,
                            'title' => 'Effektivlik gecə: NWC üzrə',
                            'exportUrl' => route('api.dashboard.nighttime-efficiency.export', array_filter([
                                'date_from' => $filters['from'], 'date_to' => $filters['to'], 'ownership' => 'nwc',
                                'project_id' => $filters['project_id'], 'equipment_type_id' => $filters['equipment_type_id'],
                                'search' => request('nighttime_search'),
                            ], fn ($value) => $value !== null && $value !== '')),
                        ])
                    </div>
                    <div class="col-12 col-md-6 d-flex">
                        @include('dashboard.partials.nighttime-efficiency-card', [
                            'chartId' => 'nighttimeEfficiencyIcare',
                            'ownershipCode' => $icare,
                            'summary' => $nighttimeEfficiencySummaryIcare,
                            'categoryLabels' => $actualWorkCategoryLabels,
                            'categoryColors' => $actualWorkCategoryColors,
                            'filters' => $filters,
                            'title' => 'Effektivlik gecə: İcarə üzrə',
                            'exportUrl' => route('api.dashboard.nighttime-efficiency.export', array_filter([
                                'date_from' => $filters['from'], 'date_to' => $filters['to'], 'ownership' => 'icare',
                                'project_id' => $filters['project_id'], 'equipment_type_id' => $filters['equipment_type_id'],
                                'search' => request('nighttime_search'),
                            ], fn ($value) => $value !== null && $value !== '')),
                        ])
                    </div>
                </div>
            </section>

            <section id="efficiency-averages" class="col-12 mt-4 dashboard-efficiency-section-heading" data-efficiency-group="averages" style="order: 400" aria-labelledby="efficiency-averages-title">
                <h2 class="h4 fw-bold mb-1" id="efficiency-averages-title">Orta göstəricilər</h2>
            </section>

            @php
                $widgetLayout = $dashboardWidgetLayoutFor('average-engine-hours', 'col-12 col-md-6', 6);
            @endphp
            <div class="{{ $widgetLayout['class'] }} dashboard-widget dashboard-efficiency-pair-widget{{ $dashboardWidgetVisibilityClassFor('average-engine-hours') }}" data-dashboard-widget="average-engine-hours" data-widget-key="average-engine-hours" data-efficiency-group="averages" data-widget-width="{{ $widgetLayout['width'] }}" data-widget-order="{{ $widgetLayout['order'] }}" data-widget-visible="{{ $dashboardWidgetVisibleFor('average-engine-hours') ? '1' : '0' }}" style="order: 410" draggable="false">
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
                $widgetLayout = $dashboardWidgetLayoutFor('average-mileage', 'col-12 col-md-6', 6);
            @endphp
            <div class="{{ $widgetLayout['class'] }} dashboard-widget dashboard-efficiency-pair-widget{{ $dashboardWidgetVisibilityClassFor('average-mileage') }}" data-dashboard-widget="average-mileage" data-widget-key="average-mileage" data-efficiency-group="averages" data-widget-width="{{ $widgetLayout['width'] }}" data-widget-order="{{ $widgetLayout['order'] }}" data-widget-visible="{{ $dashboardWidgetVisibleFor('average-mileage') ? '1' : '0' }}" style="order: 411" draggable="false">
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

            <section id="efficiency-top20" class="col-12 mt-4 dashboard-efficiency-section-heading" data-efficiency-group="top20" style="order: 500" aria-labelledby="efficiency-top20-title">
                <h2 class="h4 fw-bold mb-1" id="efficiency-top20-title">Top göstəricilər</h2>
            </section>

            @php
                $widgetLayout = $dashboardWidgetLayoutFor('least-working', 'col-12 col-md-6', 6);
            @endphp
            <div class="{{ $widgetLayout['class'] }} dashboard-widget dashboard-top-widget{{ $dashboardWidgetVisibilityClassFor('least-working') }}" data-dashboard-widget="least-working" data-widget-key="least-working" data-efficiency-group="top20" data-widget-width="{{ $widgetLayout['width'] }}" data-widget-order="{{ $widgetLayout['order'] }}" data-widget-visible="{{ $dashboardWidgetVisibleFor('least-working') ? '1' : '0' }}" style="order: 510" draggable="false">
                <section class="panel p-3 dashboard-card dashboard-ranking-card">
                    <x-dashboard-card-header :title="$dashboardWidgetTitleFor('least-working', __('app.least_working'))" :export-url="$exportUrl('least-working')" />
                    @include('dashboard.partials.ranking-table', ['rows' => $data['leastWorking'] ?? [], 'ranking' => 'least'])
                </section>
            </div>

            @php
                $widgetLayout = $dashboardWidgetLayoutFor('most-working', 'col-12 col-md-6', 6);
            @endphp
            <div class="{{ $widgetLayout['class'] }} dashboard-widget dashboard-top-widget{{ $dashboardWidgetVisibilityClassFor('most-working') }}" data-dashboard-widget="most-working" data-widget-key="most-working" data-efficiency-group="top20" data-widget-width="{{ $widgetLayout['width'] }}" data-widget-order="{{ $widgetLayout['order'] }}" data-widget-visible="{{ $dashboardWidgetVisibleFor('most-working') ? '1' : '0' }}" style="order: 511" draggable="false">
                <section class="panel p-3 dashboard-card dashboard-ranking-card">
                    <x-dashboard-card-header :title="$dashboardWidgetTitleFor('most-working', __('app.most_working'))" :export-url="$exportUrl('most-working')" />
                    @include('dashboard.partials.ranking-table', ['rows' => $data['mostWorking'] ?? [], 'ranking' => 'most'])
                </section>
            </div>
            @endif

            @if ($selectedDashboardTab === 'geozones')
            @php
                $widgetLayout = $dashboardWidgetLayoutFor('geofence-analysis', 'col-12 col-xl-6', 6);
                $geofenceAnalysisTitle = $dashboardWidgetTitleFor('geofence-analysis', __('app.geofence_analysis'));
            @endphp
            <div class="{{ $widgetLayout['class'] }} dashboard-widget geofence-paired-widget{{ $dashboardWidgetVisibilityClassFor('geofence-analysis') }}" data-dashboard-widget="geofence-analysis" data-widget-key="geofence-analysis" data-widget-width="{{ $widgetLayout['width'] }}" data-widget-order="{{ $widgetLayout['order'] }}" data-widget-visible="{{ $dashboardWidgetVisibleFor('geofence-analysis') ? '1' : '0' }}" style="order: {{ $widgetLayout['order'] }}" draggable="false">
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
                            <button type="button" class="btn btn-sm dashboard-visibility-toggle foreign-geofence-action" title="Bloku gizlət" aria-label="Bloku gizlət">
                                <i class="bi bi-eye-slash"></i>
                            </button>
                            <button type="button" class="btn btn-sm dashboard-drag-handle foreign-geofence-action" title="Bloku daşı" aria-label="Bloku daşı">
                                <i class="bi bi-grip-vertical"></i>
                            </button>
                        </div>
                    </div>

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
                                            data-drilldown-title="{{ $row['label'] ?? $row['project'] }} - Geofence Transferləri"
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

                </section>
            </div>

            @php
                $widgetLayout = $dashboardWidgetLayoutFor('geofence-violations-report', 'col-12 col-xl-6', 6);
                $geofenceReportTitle = $dashboardWidgetTitleFor('geofence-violations-report', __('app.geofence_violations'));
            @endphp
            <div class="{{ $widgetLayout['class'] }} dashboard-widget geofence-paired-widget{{ $dashboardWidgetVisibilityClassFor('geofence-violations-report') }}" data-dashboard-widget="geofence-violations-report" data-widget-key="geofence-violations-report" data-widget-width="{{ $widgetLayout['width'] }}" data-widget-order="{{ $widgetLayout['order'] }}" data-widget-visible="{{ $dashboardWidgetVisibleFor('geofence-violations-report') ? '1' : '0' }}" style="order: {{ $widgetLayout['order'] }}" draggable="false">
                <section class="panel dashboard-card foreign-geofence-shell" aria-labelledby="geofenceReportTitle">
                    <div class="foreign-geofence-header">
                        <div class="min-w-0">
                            <h2 class="foreign-geofence-title dashboard-card-title-text" id="geofenceReportTitle">{{ $geofenceReportTitle }}</h2>
                            <input type="text" class="form-control form-control-sm dashboard-title-input mt-1 d-none" value="{{ $geofenceReportTitle }}" maxlength="120" aria-label="Dashboard başlığı">
                            <div class="foreign-geofence-subtitle">Bütün layihə geozonalarından kənarda fasiləsiz 3 saatdan çox qalan texnika</div>
                            <div class="foreign-geofence-context">
                                <i class="bi bi-shield-check"></i>
                                <span>Mənbə: Geofence Pozuntuları api</span>
                            </div>
                        </div>
                        <div class="foreign-geofence-actions">
                            <button type="button" class="btn btn-sm dashboard-visibility-toggle foreign-geofence-action" title="Bloku gizlət" aria-label="Bloku gizlət">
                                <i class="bi bi-eye-slash"></i>
                            </button>
                            <button type="button" class="btn btn-sm dashboard-drag-handle foreign-geofence-action" title="Bloku daşı" aria-label="Bloku daşı">
                                <i class="bi bi-grip-vertical"></i>
                            </button>
                        </div>
                    </div>

                    <div class="foreign-geofence-card">
                        <div class="foreign-geofence-donut-layout">
                            <button
                                type="button"
                                class="foreign-geofence-donut-wrap geofence-report-donut-link"
                                data-geofence-violations-list-link
                                data-geofence-violations-drilldown
                                aria-label="Geofence Pozuntuları siyahısını aç"
                                title="Geofence Pozuntuları siyahısını aç"
                            >
                                <div class="geofence-report-donut" style="--geofence-report-donut-background: {{ $geofenceReportDonutBackground }};"></div>
                                <div class="foreign-geofence-center">
                                    <div>
                                        <strong>{{ number_format($geofenceReportTotal) }}</strong>
                                        <span>Ümumi pozuntu</span>
                                    </div>
                                </div>
                            </button>
                            <div class="geofence-report-legend-wrap">
                                <div class="foreign-geofence-legend" aria-label="Geofence pozuntularının layihə üzrə bölgüsü">
                                    @forelse ($geofenceReportDistribution as $segment)
                                        <button
                                            type="button"
                                            class="foreign-geofence-legend-row"
                                            title="{{ $segment['label'] }}"
                                            data-geofence-violations-drilldown
                                            data-geofence-violations-project-id="{{ $segment['project_id'] }}"
                                        >
                                            <span class="foreign-geofence-dot" style="background: {{ $segment['color'] }}"></span>
                                            <span class="foreign-geofence-legend-name">{{ $segment['label'] }}</span>
                                            <span class="foreign-geofence-legend-count">{{ number_format($segment['count']) }}</span>
                                            <span class="foreign-geofence-legend-percent">{{ number_format($segment['percentage'], 1) }}%</span>
                                        </button>
                                    @empty
                                        <div class="foreign-geofence-empty">Seçilmiş dövr üçün pozuntu məlumatı yoxdur</div>
                                    @endforelse
                                </div>
                            </div>
                        </div>
                    </div>
                </section>
            </div>

            @endif

            @if ($selectedDashboardTab === 'overview')
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
                                <div
                                    class="dashboard-scroll-table is-expanded"
                                    id="dashboardExpandableProjectComparison"
                                    data-expandable="project-comparison"
                                    data-expanded="1"
                                >
                                    <table class="table table-sm align-middle mb-0 dashboard-project-comparison-table">
                                        <colgroup>
                                            <col>
                                            <col>
                                            <col>
                                            <col>
                                        </colgroup>
                                        <thead>
                                            <tr>
                                                <th>{{ __('app.project') }}</th>
                                                <th class="text-end">NWC</th>
                                                <th class="text-end">{{ __('app.ownership_icare') }}</th>
                                                <th class="text-end">Cəmi</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr
                                                class="dashboard-project-comparison-total"
                                                data-project-comparison-total-nwc="{{ (int) round($projectComparisonTotals[$nwc]) }}"
                                                data-project-comparison-total-icare="{{ (int) round($projectComparisonTotals[$icare]) }}"
                                                data-project-comparison-total="{{ (int) round($projectComparisonTotals['total']) }}"
                                            >
                                                <td>Cəmi</td>
                                                <td class="text-end">{{ number_format($projectComparisonTotals[$nwc], 0) }}</td>
                                                <td class="text-end">{{ number_format($projectComparisonTotals[$icare], 0) }}</td>
                                                <td class="text-end">{{ number_format($projectComparisonTotals['total'], 0) }}</td>
                                            </tr>
                                            @foreach ($projectComparisonRows as $row)
                                                <tr class="{{ $loop->iteration > 10 ? 'expandable-extra' : '' }}">
                                                    <td>
                                                        <button
                                                            type="button"
                                                            class="btn btn-link p-0 fw-semibold text-start dashboard-drilldown-trigger"
                                                            data-drilldown-title="{{ $row['name'] }} - Texnika növü üzrə"
                                                            data-drilldown-project-id="{{ $row['id'] }}"
                                                            data-drilldown-ownership-scope="project_groups"
                                                            data-drilldown-view="equipment_types"
                                                            data-drilldown-mode="project_types"
                                                        >{{ $row['name'] }}</button>
                                                    </td>
                                                    <td class="text-end">{{ number_format($row[$nwc], 0) }}</td>
                                                    <td class="text-end">{{ number_format($row[$icare], 0) }}</td>
                                                    <td class="text-end">{{ number_format($row['total'], 0) }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                                @if ($projectComparisonHasMore)
                                    <button
                                        type="button"
                                        class="btn btn-link dashboard-expand-toggle mt-2"
                                        data-expand-toggle="project-comparison"
                                        data-show-label="Hamısını göstər"
                                        data-hide-label="Gizlət"
                                        aria-expanded="true"
                                        aria-controls="dashboardExpandableProjectComparison"
                                    >Gizlət</button>
                                @endif
                            </div>
                        </div>
                    @else
                        <div class="dashboard-empty">{{ __('app.no_data') }}</div>
                    @endif
                </section>
            </div>
            @endif
        </div>
    </div>

    @unless ($dashboardTabFragment ?? false)
    <div class="modal fade" id="dashboardDrilldownModal" tabindex="-1" aria-hidden="true" aria-labelledby="dashboardDrilldownTitle" aria-describedby="dashboardDrilldownStatus">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <div class="d-flex align-items-start gap-2 min-w-0">
                        <button type="button" class="btn btn-sm btn-outline-secondary d-none flex-shrink-0" id="dashboardDrilldownBack" title="Texnika növlərinə qayıt" aria-label="Texnika növlərinə qayıt">
                            <i class="bi bi-arrow-left"></i>
                        </button>
                        <div class="min-w-0">
                            <h5 class="modal-title" id="dashboardDrilldownTitle">Texnika siyahısı</h5>
                            <div class="small text-secondary" id="dashboardDrilldownFilters"></div>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Bağla"></button>
                </div>
                <div class="modal-body" id="dashboardDrilldownBody" aria-busy="false">
                    <div class="nav nav-tabs mb-3" role="tablist" aria-label="{{ __('app.equipment_details') }}">
                        <button type="button" class="nav-link active" id="dashboardDrilldownTabData" role="tab" aria-selected="true" aria-controls="dashboardDrilldownDataControls dashboardDrilldownTable dashboardDrilldownPagination" data-drilldown-tab-target="data">{{ __('app.modal_tab_data') }}</button>
                        <button type="button" class="nav-link" id="dashboardDrilldownTabSummary" role="tab" aria-selected="false" aria-controls="dashboardDrilldownChips dashboardDrilldownStatus dashboardDrilldownFormula" data-drilldown-tab-target="summary" tabindex="-1">{{ __('app.modal_tab_summary') }}</button>
                        <button type="button" class="nav-link" id="dashboardDrilldownTabFilters" role="tab" aria-selected="false" aria-controls="dashboardDrilldownFilterPanel" data-drilldown-tab-target="filters" tabindex="-1">{{ __('app.modal_tab_filters') }}</button>
                    </div>
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-3" id="dashboardDrilldownDataControls" data-drilldown-tab-section="data">
                        <div class="btn-group btn-group-sm" role="group" aria-label="Mənsubiyyət">
                            <button type="button" class="btn btn-outline-primary dashboard-drilldown-filter" data-filter-name="ownership" data-filter-value="all" aria-pressed="false">Hamısı</button>
                            <button type="button" class="btn btn-outline-primary dashboard-drilldown-filter" data-filter-name="ownership" data-filter-value="nwc" aria-pressed="false">NWC</button>
                            <button type="button" class="btn btn-outline-primary dashboard-drilldown-filter" data-filter-name="ownership" data-filter-value="icare" aria-pressed="false">İCARƏ</button>
                        </div>
                        <div class="btn-group btn-group-sm" id="dashboardDrilldownDataStatusGroup" role="group" aria-label="Məlumat statusu">
                            <button type="button" class="btn btn-outline-secondary dashboard-drilldown-filter" data-filter-name="data_status" data-filter-value="all" aria-pressed="false">Hamısı</button>
                            <button type="button" class="btn btn-outline-secondary dashboard-drilldown-filter" data-filter-name="data_status" data-filter-value="available" aria-pressed="false">Məlumat var</button>
                            <button type="button" class="btn btn-outline-secondary dashboard-drilldown-filter" data-filter-name="data_status" data-filter-value="missing" aria-pressed="false">Məlumat yoxdur</button>
                        </div>
                        <button type="button" class="btn btn-outline-primary btn-sm ms-auto" id="dashboardDrilldownFilterToggle" aria-expanded="false" aria-controls="dashboardDrilldownFilterPanel">
                            <i class="bi bi-funnel"></i> Filtrlər
                        </button>
                        <select class="form-select form-select-sm d-none" id="dashboardDrilldownGroupMode" aria-label="Qruplaşdırma" style="max-width: 190px;">
                            <option value="details">Gündəlik detallar</option>
                            <option value="day">Gün üzrə</option>
                            <option value="unit">Texnika üzrə</option>
                        </select>
                        <label class="visually-hidden" for="dashboardDrilldownSearch">{{ __('app.search_equipment') }}</label>
                        <input type="search" class="form-control form-control-sm" id="dashboardDrilldownSearch" placeholder="Axtarış..." aria-label="{{ __('app.search_equipment') }}" style="max-width: 260px;">
                    </div>
                    <div class="dashboard-drilldown-filter-panel d-none" id="dashboardDrilldownFilterPanel" role="tabpanel" aria-labelledby="dashboardDrilldownTabFilters" data-drilldown-tab-section="filters">
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
                            <div class="col-6 col-lg-3 dashboard-legacy-shift-filter-group">
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
                            <div class="col-12 col-lg-4 dashboard-legacy-shift-filter-group">
                                <div class="row g-2">
                                    <div class="col-6">
                                        <label class="form-label" for="dashboardDrilldownDayStatus">Gündüz statusu</label>
                                        <select class="form-select form-select-sm dashboard-drilldown-control" id="dashboardDrilldownDayStatus" data-filter-name="day_status">
                                            <option value="">Hamısı</option>
                                            <option value="less_than_1_hour">{{ __('app.worked_less_than_1_hour') }}</option>
                                            <option value="less_than_7_hours">{{ __('app.worked_less_than_7_hours') }}</option>
                                            <option value="between_7_and_10_hours">{{ __('app.worked_7_to_10_hours') }}</option>
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
                                    <div class="col-12">
                                        <label class="form-label" for="dashboardDrilldownDurationFormat">Müddət formatı</label>
                                        <select class="form-select form-select-sm dashboard-drilldown-control" id="dashboardDrilldownDurationFormat" data-filter-name="duration_format">
                                            <option value="decimal_hours">saat (yüzdə bir dəqiqliklə)</option>
                                            <option value="hours_hms">saat:dəqiqə:saniyə</option>
                                            <option value="days_hms">gün saat:dəqiqə:saniyə</option>
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
                            <div class="col-6 col-lg-4 dashboard-legacy-shift-filter-group">
                                <label class="form-label" for="dashboardDrilldownRegistration">Qeydiyyat nişanı</label>
                                <input type="search" class="form-control form-control-sm dashboard-drilldown-control" id="dashboardDrilldownRegistration" data-filter-name="registration_number">
                            </div>
                            <div class="col-6 col-lg-4 dashboard-legacy-shift-filter-group">
                                <label class="form-label" for="dashboardDrilldownWialonId">Wialon ID</label>
                                <input type="search" class="form-control form-control-sm dashboard-drilldown-control" id="dashboardDrilldownWialonId" data-filter-name="wialon_id">
                            </div>
                        </div>
                        <div class="d-flex flex-wrap justify-content-end gap-2 mt-3">
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="dashboardDrilldownClearFilters">Təmizlə</button>
                            <button type="button" class="btn btn-sm btn-primary" id="dashboardDrilldownApplyFilters">Filtrləri tətbiq et</button>
                        </div>
                    </div>
                    <div class="dashboard-drilldown-chips d-none" id="dashboardDrilldownChips" role="tabpanel" aria-labelledby="dashboardDrilldownTabSummary" data-drilldown-tab-section="summary"></div>
                    <div class="dashboard-drilldown-status small text-secondary mb-2 d-none" id="dashboardDrilldownStatus" aria-live="polite" data-drilldown-tab-section="summary">Məlumatlar yüklənir...</div>
                    <div class="dashboard-drilldown-formula d-none" id="dashboardDrilldownFormula" data-drilldown-tab-section="summary"></div>
                    <div class="dashboard-drilldown-table-wrapper border rounded" id="dashboardDrilldownTable" role="tabpanel" aria-labelledby="dashboardDrilldownTabData" data-drilldown-tab-section="data">
                        <table class="table table-sm align-middle mb-0 dashboard-drilldown-table">
                            <colgroup id="dashboardDrilldownColgroup"></colgroup>
                            <caption class="visually-hidden">{{ __('app.equipment_details') }}</caption>
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
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-3" id="dashboardDrilldownPagination" data-drilldown-tab-section="data">
                        <div class="small text-secondary" id="dashboardDrilldownPageInfo" aria-live="polite"></div>
                        <div class="d-flex align-items-center gap-2">
                            <select class="form-select form-select-sm" id="dashboardDrilldownPageSize" aria-label="{{ __('app.pagination') }}" style="width: auto;">
                                <option value="20" selected>20</option>
                                <option value="50">50</option>
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
    @endunless
@endsection

@push('scripts')
<script>
const ownershipColor = { NWC: '#24b35b', ICARE: '#1f6feb' };
const typePalette = ['#1f6feb', '#24b35b', '#f6ad00', '#8b5cf6', '#0ea5b7', '#94a3b8', '#f97316', '#14b8a6', '#6366f1', '#ef4444'];
const workCategoryColors = {
    '0_1': '#1f6feb',
    '1_7': '#f97316',
    '7_10': '#24b35b',
    over_10: '#8b5cf6',
    no_data: '#94a3b8',
};
const workCategoryKeys = @json($actualWorkCategoryLabels->keys()->values());
const workCategoryLabels = @json($actualWorkCategoryLabels->values());
const workCategoryColorValues = workCategoryKeys.map(key => workCategoryColors[key]);
const workCategoryDonutKeys = [
    '0_1',
    '1_7',
    '7_10',
    'over_10',
    'no_data',
];
const workCategoryDonutIndexes = workCategoryDonutKeys.map(key => workCategoryKeys.indexOf(key));
const workCategoryDonutLabels = workCategoryDonutIndexes.map(index => workCategoryLabels[index]);
const workCategoryDonutColorValues = workCategoryDonutKeys.map(key => workCategoryColors[key]);
const geofenceViolationPalette = @json($geofenceViolationPalette);
let ownershipShareLabels = [];
let ownershipShareCounts = [];
let ownershipShareTotal = 0;
let typeNwcLabels = [];
let typeNwcTotals = [];
let typeNwcIds = [];
let typeNwcTotal = 0;
let typeIcareLabels = [];
let typeIcareTotals = [];
let typeIcareIds = [];
let typeIcareTotal = 0;
let projectWorkCategoryNwcCounts = [];
let projectWorkCategoryIcareCounts = [];
let projectWorkCategoryNwcDonutCounts = [];
let projectWorkCategoryIcareDonutCounts = [];
let daytimeEfficiencyNwcCounts = [];
let daytimeEfficiencyIcareCounts = [];
let nighttimeEfficiencyNwcCounts = [];
let nighttimeEfficiencyIcareCounts = [];
let utilizationTrend = { labels: [], dates: [], series: {}, has_data: false };
let projectComparisonLabels = [];
let projectComparisonIds = [];
let projectComparisonNwc = [];
let projectComparisonIcare = [];
let geofenceViolationLabels = [];
let geofenceViolationCounts = [];
let geofenceViolationProjectIds = [];
let geofenceViolationGeofenceIds = [];
let geofenceViolationSectorKeys = [];
let geofenceViolationTotal = 0;

const applyDashboardChartData = data => {
    ownershipShareLabels = data?.ownershipShareLabels || [];
    ownershipShareCounts = data?.ownershipShareCounts || [];
    ownershipShareTotal = Number(data?.ownershipShareTotal || 0);
    typeNwcLabels = data?.typeNwcLabels || [];
    typeNwcTotals = data?.typeNwcTotals || [];
    typeNwcIds = data?.typeNwcIds || [];
    typeNwcTotal = Number(data?.typeNwcTotal || 0);
    typeIcareLabels = data?.typeIcareLabels || [];
    typeIcareTotals = data?.typeIcareTotals || [];
    typeIcareIds = data?.typeIcareIds || [];
    typeIcareTotal = Number(data?.typeIcareTotal || 0);
    projectWorkCategoryNwcCounts = data?.projectWorkCategoryNwcCounts || [];
    projectWorkCategoryIcareCounts = data?.projectWorkCategoryIcareCounts || [];
    projectWorkCategoryNwcDonutCounts = workCategoryDonutIndexes.map(index => projectWorkCategoryNwcCounts[index] || 0);
    projectWorkCategoryIcareDonutCounts = workCategoryDonutIndexes.map(index => projectWorkCategoryIcareCounts[index] || 0);
    daytimeEfficiencyNwcCounts = data?.daytimeEfficiencyNwcCounts || [];
    daytimeEfficiencyIcareCounts = data?.daytimeEfficiencyIcareCounts || [];
    nighttimeEfficiencyNwcCounts = data?.nighttimeEfficiencyNwcCounts || [];
    nighttimeEfficiencyIcareCounts = data?.nighttimeEfficiencyIcareCounts || [];
    utilizationTrend = data?.utilizationTrend || { labels: [], dates: [], series: {}, has_data: false };
    projectComparisonLabels = data?.projectComparisonLabels || [];
    projectComparisonIds = data?.projectComparisonIds || [];
    projectComparisonNwc = data?.projectComparisonNwc || [];
    projectComparisonIcare = data?.projectComparisonIcare || [];
    geofenceViolationLabels = data?.geofenceViolationLabels || [];
    geofenceViolationCounts = data?.geofenceViolationCounts || [];
    geofenceViolationProjectIds = data?.geofenceViolationProjectIds || [];
    geofenceViolationGeofenceIds = data?.geofenceViolationGeofenceIds || [];
    geofenceViolationSectorKeys = data?.geofenceViolationSectorKeys || [];
    geofenceViolationTotal = Number(data?.geofenceViolationTotal || 0);
};

applyDashboardChartData(@json($dashboardChartData));
const dashboardPage = document.querySelector('.dashboard-page');
const dashboardGrid = document.getElementById('dashboardGrid');
const dashboardPreferenceDefaults = {
    layout: 'standard',
    theme: 'system',
    density: 'comfortable',
    sidebar_state: 'expanded',
    donut_legend_position: 'right',
    table_density: 'comfortable',
    kpi_size: 'medium',
};
const dashboardDesignDrawer = document.getElementById('dashboardDesignDrawer');
const dashboardDesignBackdrop = document.getElementById('dashboardDesignBackdrop');
const dashboardDesignForm = document.getElementById('dashboardDesignForm');
const dashboardDesignStatus = document.getElementById('dashboardDesignStatus');
let savedDashboardPreferences = {
    ...dashboardPreferenceDefaults,
    ...JSON.parse(dashboardPage?.dataset.dashboardPreferences || '{}'),
};
function setActiveEfficiencyNavigation(activeSection = 'general') {
    document.querySelectorAll('[data-efficiency-section]').forEach(button => {
        const active = button.dataset.efficiencySection === activeSection;
        button.classList.toggle('btn-primary', active);
        button.classList.toggle('btn-outline-secondary', !active);
        button.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
}

const applyDashboardPreferences = preferences => {
    const resolved = { ...dashboardPreferenceDefaults, ...preferences };

    if (dashboardPage) {
        dashboardPage.dataset.dashboardLayoutVariant = resolved.layout;
        dashboardPage.dataset.dashboardDensity = resolved.density;
        dashboardPage.dataset.dashboardTableDensity = resolved.table_density;
        dashboardPage.dataset.dashboardLegendPosition = resolved.donut_legend_position;
        dashboardPage.dataset.dashboardKpiSize = resolved.kpi_size;
    }

    document.documentElement.dataset.sidebarState = resolved.sidebar_state;
    window.applyFleetThemePreference?.(resolved.theme);
    window.setTimeout(() => window.dispatchEvent(new Event('resize')), 0);

    return resolved;
};

const fillDashboardPreferenceForm = preferences => {
    if (!dashboardDesignForm) {
        return;
    }

    Object.entries(preferences).forEach(([name, value]) => {
        const controls = dashboardDesignForm.elements.namedItem(name);
        if (!controls) {
            return;
        }

        if (typeof RadioNodeList !== 'undefined' && controls instanceof RadioNodeList) {
            controls.value = value;
        } else {
            controls.value = value;
        }
    });
};

const readDashboardPreferenceForm = () => dashboardDesignForm
    ? { ...dashboardPreferenceDefaults, ...Object.fromEntries(new FormData(dashboardDesignForm).entries()) }
    : { ...savedDashboardPreferences };

const setDashboardDesignOpen = open => {
    if (!dashboardDesignDrawer || !dashboardDesignBackdrop) {
        return;
    }

    dashboardDesignDrawer.hidden = !open;
    dashboardDesignBackdrop.hidden = !open;
    document.body.classList.toggle('dashboard-design-open', open);
    if (open) {
        dashboardDesignDrawer.querySelector('button')?.focus();
    }
};

const requestDashboardPreferences = async (method, url, payload = null) => {
    const response = await fetch(url, {
        method,
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
        },
        body: payload === null ? null : JSON.stringify(payload),
    });

    if (!response.ok) {
        throw new Error(`Dashboard preferences request failed with ${response.status}.`);
    }

    return response.json();
};

document.getElementById('openDashboardDesign')?.addEventListener('click', () => {
    savedDashboardPreferences = {
        ...savedDashboardPreferences,
        ...window.fleetDashboardPreferences,
    };
    fillDashboardPreferenceForm(savedDashboardPreferences);
    if (dashboardDesignStatus) {
        dashboardDesignStatus.textContent = '';
    }
    setDashboardDesignOpen(true);
    window.lucide?.createIcons();
});

dashboardDesignForm?.addEventListener('change', event => {
    if (event.target?.name === 'layout' && event.target.value === 'dark_analytics') {
        dashboardDesignForm.elements.namedItem('theme').value = 'dark';
    }
    applyDashboardPreferences(readDashboardPreferenceForm());
});

const cancelDashboardPreferencePreview = () => {
    fillDashboardPreferenceForm(savedDashboardPreferences);
    applyDashboardPreferences(savedDashboardPreferences);
    setDashboardDesignOpen(false);
};

document.getElementById('dashboardDesignClose')?.addEventListener('click', cancelDashboardPreferencePreview);
document.getElementById('dashboardDesignCancel')?.addEventListener('click', cancelDashboardPreferencePreview);
dashboardDesignBackdrop?.addEventListener('click', cancelDashboardPreferencePreview);

document.getElementById('dashboardDesignApply')?.addEventListener('click', async event => {
    const button = event.currentTarget;
    const draft = readDashboardPreferenceForm();
    button.disabled = true;
    if (dashboardDesignStatus) {
        dashboardDesignStatus.textContent = 'Yadda saxlanılır…';
    }

    try {
        const saved = await requestDashboardPreferences(
            'PUT',
            dashboardPage.dataset.dashboardPreferencesUpdateUrl,
            draft,
        );
        savedDashboardPreferences = { ...dashboardPreferenceDefaults, ...saved };
        window.fleetDashboardPreferences = { ...savedDashboardPreferences };
        applyDashboardPreferences(savedDashboardPreferences);
        setDashboardDesignOpen(false);
    } catch (error) {
        applyDashboardPreferences(savedDashboardPreferences);
        fillDashboardPreferenceForm(savedDashboardPreferences);
        if (dashboardDesignStatus) {
            dashboardDesignStatus.textContent = 'Dizayn saxlanılmadı. Əvvəlki görünüş bərpa edildi.';
        }
    } finally {
        button.disabled = false;
    }
});

document.getElementById('dashboardDesignReset')?.addEventListener('click', async event => {
    const button = event.currentTarget;
    button.disabled = true;

    try {
        const defaults = await requestDashboardPreferences(
            'DELETE',
            dashboardPage.dataset.dashboardPreferencesResetUrl,
        );
        savedDashboardPreferences = { ...dashboardPreferenceDefaults, ...defaults };
        window.fleetDashboardPreferences = { ...savedDashboardPreferences };
        fillDashboardPreferenceForm(savedDashboardPreferences);
        applyDashboardPreferences(savedDashboardPreferences);
        if (dashboardDesignStatus) {
            dashboardDesignStatus.textContent = 'Standart görünüş bərpa edildi.';
        }
    } catch (error) {
        if (dashboardDesignStatus) {
            dashboardDesignStatus.textContent = 'Standart görünüş bərpa edilmədi.';
        }
    } finally {
        button.disabled = false;
    }
});

document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && dashboardDesignDrawer && !dashboardDesignDrawer.hidden) {
        cancelDashboardPreferencePreview();
    }
});

document.getElementById('dashboardEfficiencySubnav')?.addEventListener('click', event => {
    const button = event.target.closest('[data-efficiency-section]');
    if (!button) {
        return;
    }

    const section = button.dataset.efficiencySection;
    const target = document.getElementById(`efficiency-${section}`);
    if (!target) {
        return;
    }

    setActiveEfficiencyNavigation(section);
    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
});

applyDashboardPreferences(savedDashboardPreferences);
const dashboardTabButtons = Array.from(document.querySelectorAll('[data-dashboard-tab]'));
const dashboardTabUrlTemplate = dashboardPage?.dataset.dashboardTabUrlTemplate || '';
const dashboardSelectedTabInput = document.getElementById('dashboardSelectedTabInput');
const dashboardResetButton = document.getElementById('resetDashboardLayout');
const dashboardEditButton = document.getElementById('editDashboardLayout');
const dashboardSaveButton = document.getElementById('saveDashboardLayout');
const dashboardCancelButton = document.getElementById('cancelDashboardLayout');
const dashboardLayoutStatus = document.getElementById('dashboardLayoutStatus');
const dashboardObjectSyncForm = document.querySelector('[data-dashboard-object-sync-form]');
const dashboardObjectSyncButton = document.querySelector('[data-dashboard-object-sync-button]');
const dashboardLayoutEditable = dashboardPage?.dataset.dashboardLayoutEditable === '1';
const dashboardLayoutUpdateUrl = dashboardPage?.dataset.dashboardLayoutUpdateUrl || '';
const dashboardLayoutResetUrl = dashboardPage?.dataset.dashboardLayoutResetUrl || '';
const parseDashboardDatasetJson = (value, fallback) => {
    try {
        return JSON.parse(value || '');
    } catch (error) {
        return fallback;
    }
};
const dashboardSavedLayout = new Map(parseDashboardDatasetJson(dashboardPage?.dataset.dashboardSavedLayout, [])
    .filter(item => item && typeof item === 'object' && item.key)
    .map(item => [String(item.key), item]));
const dashboardDefaultTitles = parseDashboardDatasetJson(dashboardPage?.dataset.dashboardDefaultTitles, {});
let dashboardLayoutRevision = Number(dashboardPage?.dataset.dashboardLayoutRevision || 0);
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

dashboardObjectSyncForm?.addEventListener('submit', () => {
    if (!dashboardObjectSyncButton) {
        return;
    }

    dashboardObjectSyncButton.disabled = true;
    dashboardObjectSyncButton.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span><span>Yenilənir...</span>';
});
const labels = {
    noData: @json(__('app.no_data')),
    nwc: @json(__('app.ownership_nwc')),
    icare: @json(__('app.ownership_icare')),
    hours: @json(__('app.hours')),
    utilization: @json(__('app.utilization')),
};
const dashboardChartTheme = () => {
    const styles = getComputedStyle(document.documentElement);

    return {
        text: styles.getPropertyValue('--fleet-chart-text').trim() || '#334155',
        grid: styles.getPropertyValue('--fleet-chart-grid').trim() || 'rgba(100, 116, 139, .18)',
        border: styles.getPropertyValue('--fleet-chart-border').trim() || '#ffffff',
    };
};
const configureDashboardChartTheme = () => {
    const theme = dashboardChartTheme();
    Chart.defaults.color = theme.text;
    Chart.defaults.borderColor = theme.grid;
};
const refreshDashboardChartTheme = () => {
    Object.values(Chart.instances || {}).forEach(chart => chart.destroy());
    configureDashboardChartTheme();
    initializeDashboardCharts();
};
window.addEventListener('fleet:theme-change', refreshDashboardChartTheme);
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
let dashboardTabController = null;
let dashboardTabRequestId = 0;

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
            ctx.fillStyle = dashboardChartTheme().text;
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
        data: { labels: chartLabels, datasets: [{ data: values, backgroundColor: colors, borderWidth: 2, borderColor: dashboardChartTheme().border, hoverOffset: settings.hoverOffset ?? 0 }] },
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
                if (projectId) {
                    openDashboardDrilldown({
                        title: `${chartLabels[selected.index]} - Texnika növü üzrə`,
                        project_id: projectId,
                        ownership_scope: 'project_groups',
                        view: 'equipment_types',
                        drilldown_mode: 'project_types',
                        export_enabled: false,
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
                borderColor: dashboardChartTheme().border,
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
                            return `${context.label}: ${value.toLocaleString()}`;
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

document.addEventListener('click', event => {
    const button = event.target.closest('[data-expand-toggle]');

    if (!button) {
        return;
    }

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
    button.setAttribute('aria-expanded', expanded ? 'false' : 'true');
    button.setAttribute('aria-controls', `dashboardExpandable${targetId.replace(/[^a-z0-9_-]/gi, '')}`);
    container.id ||= `dashboardExpandable${targetId.replace(/[^a-z0-9_-]/gi, '')}`;
    requestAnimationFrame(refreshDashboardVisuals);
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
    if (!dashboardGrid || dashboardGrid.dataset.dashboardActiveTab === 'efficiency') {
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
    const key = widget.dataset.widgetKey || widget.dataset.dashboardWidget;
    const enteredTitle = titleInput?.value.trim() || '';
    const savedTitle = String(dashboardSavedLayout.get(key)?.title || '').trim();
    const displayedTitle = String(titleInput?.defaultValue || '').trim();
    const title = enteredTitle === displayedTitle
        ? (savedTitle || null)
        : (enteredTitle || null);

    return {
        key,
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
    dashboardTabButtons.forEach(button => {
        button.disabled = enabled;
    });

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
            body: JSON.stringify({
                revision: dashboardLayoutRevision,
                tab: dashboardGrid?.dataset.dashboardActiveTab || @json($selectedDashboardTab),
                widgets: payload,
            }),
        });

        if (!response.ok) {
            const error = new Error(`HTTP ${response.status}`);
            error.status = response.status;
            throw error;
        }

        const responsePayload = await response.json();
        dashboardLayoutRevision = Number(responsePayload.revision ?? dashboardLayoutRevision);

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
                    const effectiveTitle = item.title || dashboardDefaultTitles[item.key] || titleText.textContent;
                    titleInput.value = effectiveTitle;
                    titleInput.defaultValue = effectiveTitle;
                    titleText.textContent = effectiveTitle;
                    dashboardSavedLayout.set(item.key, { ...(dashboardSavedLayout.get(item.key) || {}), title: item.title });
                }
            }
        });

        setDashboardLayoutEditing(false);
        setDashboardLayoutStatus('Düzülüş yadda saxlanıldı.', 'success');
    } catch (error) {
        setDashboardLayoutStatus(
            error.status === 409
                ? 'Düzülüş başqa administrator tərəfindən dəyişdirilib. Səhifəni yeniləyin.'
                : 'Düzülüş saxlanmadı. Yenidən cəhd edin.',
            'danger'
        );
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

const bindDashboardWidgetControls = () => {
    if (!dashboardGrid) {
        return;
    }

    dashboardGrid.querySelectorAll('.dashboard-drag-handle:not([data-dashboard-bound])').forEach(handle => {
        handle.dataset.dashboardBound = '1';
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

    dashboardGrid.querySelectorAll('.dashboard-visibility-toggle:not([data-dashboard-bound])').forEach(toggle => {
        toggle.dataset.dashboardBound = '1';
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
};

if (dashboardGrid) {
    sortWidgetsByServerOrder();
    refreshWidgetVisibilityControls();
    disableDashboardDragging();
    bindDashboardWidgetControls();

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

const setActiveDashboardTab = tab => {
    dashboardTabButtons.forEach(button => {
        const selected = button.dataset.dashboardTab === tab;
        button.classList.toggle('active', selected);
        button.setAttribute('aria-selected', selected ? 'true' : 'false');
        button.tabIndex = selected ? 0 : -1;
    });

    if (dashboardGrid) {
        dashboardGrid.dataset.dashboardActiveTab = tab;
        dashboardGrid.setAttribute('aria-labelledby', `dashboardTab${tab.charAt(0).toUpperCase()}${tab.slice(1)}`);
    }

    if (dashboardSelectedTabInput) {
        dashboardSelectedTabInput.value = tab;
    }

    const efficiencySubnav = document.getElementById('dashboardEfficiencySubnav');
    if (efficiencySubnav) {
        efficiencySubnav.hidden = tab !== 'efficiency';
    }
    if (tab === 'efficiency') {
        setActiveEfficiencyNavigation();
    }
};

const dashboardTabRequestUrl = tab => {
    const path = dashboardTabUrlTemplate.replace('__TAB__', encodeURIComponent(tab));
    const url = new URL(path, window.location.origin);
    const globalFilters = {
        date_from: dashboardPage?.dataset.dashboardDateFrom || '',
        date_to: dashboardPage?.dataset.dashboardDateTo || '',
        project_id: dashboardPage?.dataset.dashboardProjectId || '',
        equipment_type_id: dashboardPage?.dataset.dashboardEquipmentTypeId || '',
        ownership_type: dashboardPage?.dataset.dashboardOwnership === 'all' ? '' : (dashboardPage?.dataset.dashboardOwnership || ''),
    };

    Object.entries(globalFilters).forEach(([key, value]) => {
        if (value !== '') {
            url.searchParams.set(key, value);
        }
    });

    return url;
};

const replaceDashboardTabWidgets = remoteGrid => {
    const widgets = Array.from(remoteGrid.children)
        .filter(node => node.classList?.contains('dashboard-widget') || node.dataset?.efficiencyGroup);
    let chartData = {};

    try {
        chartData = JSON.parse(remoteGrid.dataset.dashboardChartData || '{}');
    } catch (error) {
        chartData = {};
    }

    dashboardGrid.querySelectorAll('canvas').forEach(canvas => {
        window.Chart?.getChart?.(canvas)?.destroy();
    });
    dashboardGrid.replaceChildren(...widgets);
    dashboardGrid.dataset.dashboardChartData = remoteGrid.dataset.dashboardChartData || '{}';
    applyDashboardChartData(chartData);
    sortWidgetsByServerOrder();
    refreshWidgetVisibilityControls();
    disableDashboardDragging();
    bindDashboardWidgetControls();
    initializeDashboardCharts();
    setActiveEfficiencyNavigation();

};

const renderDashboardTabError = (tab, button) => {
    document.getElementById('dashboardTabLoadError')?.remove();
    const alert = document.createElement('div');
    alert.id = 'dashboardTabLoadError';
    alert.className = 'alert alert-danger';
    alert.setAttribute('role', 'alert');
    alert.textContent = @json(__('app.tab_load_failed'));

    const retry = document.createElement('button');
    retry.type = 'button';
    retry.className = 'btn btn-sm btn-outline-danger ms-2';
    retry.textContent = @json(__('app.tab_load_retry'));
    retry.addEventListener('click', () => loadDashboardTab(tab, button));
    alert.appendChild(retry);
    dashboardGrid.before(alert);
};

const loadDashboardTab = async (tab, button, { updateHistory = true } = {}) => {
    if (!dashboardGrid || !dashboardTabUrlTemplate || !dashboardTabButtons.some(item => item.dataset.dashboardTab === tab)) {
        return;
    }

    dashboardTabController?.abort();
    dashboardTabController = new AbortController();
    const requestId = ++dashboardTabRequestId;
    document.getElementById('dashboardTabLoadError')?.remove();
    if (dashboardGrid.contains(document.activeElement)) {
        dashboardTabButtons.find(item => item.getAttribute('aria-selected') === 'true')?.focus({ preventScroll: true });
    }
    dashboardGrid.setAttribute('aria-busy', 'true');
    dashboardGrid.inert = true;
    setDashboardLayoutStatus(@json(__('app.loading_tab')));

    try {
        const response = await fetch(dashboardTabRequestUrl(tab), {
            headers: { 'Accept': 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
            signal: dashboardTabController.signal,
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        const remoteDocument = new DOMParser().parseFromString(await response.text(), 'text/html');
        const remoteGrid = remoteDocument.getElementById('dashboardGrid');

        if (!remoteGrid || requestId !== dashboardTabRequestId) {
            throw new Error('Dashboard tab fragment is invalid.');
        }

        replaceDashboardTabWidgets(remoteGrid);
        document.getElementById('dashboardTabLoadError')?.remove();
        setActiveDashboardTab(tab);
        setDashboardLayoutStatus('');

        if (updateHistory) {
            const locationUrl = new URL(window.location.href);
            locationUrl.searchParams.set('tab', tab);
            window.history.pushState({ dashboardTab: tab }, '', locationUrl);
        }

        button?.focus({ preventScroll: true });
    } catch (error) {
        if (error.name !== 'AbortError' && requestId === dashboardTabRequestId) {
            renderDashboardTabError(tab, button);
            setDashboardLayoutStatus(@json(__('app.tab_load_failed')), 'danger');
        }
    } finally {
        if (requestId === dashboardTabRequestId) {
            dashboardGrid.setAttribute('aria-busy', 'false');
            dashboardGrid.inert = false;
        }
    }
};

dashboardTabButtons.forEach((button, index) => {
    button.addEventListener('click', () => {
        if (button.getAttribute('aria-selected') !== 'true') {
            loadDashboardTab(button.dataset.dashboardTab, button);
        }
    });

    button.addEventListener('keydown', event => {
        let nextIndex = null;

        if (event.key === 'ArrowRight') nextIndex = (index + 1) % dashboardTabButtons.length;
        if (event.key === 'ArrowLeft') nextIndex = (index - 1 + dashboardTabButtons.length) % dashboardTabButtons.length;
        if (event.key === 'Home') nextIndex = 0;
        if (event.key === 'End') nextIndex = dashboardTabButtons.length - 1;

        if (nextIndex !== null) {
            event.preventDefault();
            dashboardTabButtons[nextIndex].focus();
        }
    });
});

window.addEventListener('popstate', () => {
    const tab = new URL(window.location.href).searchParams.get('tab') || @json(config('dashboard.default_tab', 'overview'));
    const button = dashboardTabButtons.find(item => item.dataset.dashboardTab === tab);

    if (button && dashboardGrid?.dataset.dashboardActiveTab !== tab) {
        loadDashboardTab(tab, button, { updateHistory: false });
    }
});

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
const drilldownBody = document.getElementById('dashboardDrilldownBody');
const drilldownTabButtons = Array.from(document.querySelectorAll('[data-drilldown-tab-target]'));
const drilldownTabSections = Array.from(document.querySelectorAll('[data-drilldown-tab-section]'));
const drilldownTitle = document.getElementById('dashboardDrilldownTitle');
const drilldownFilters = document.getElementById('dashboardDrilldownFilters');
const drilldownBack = document.getElementById('dashboardDrilldownBack');
const drilldownStatus = document.getElementById('dashboardDrilldownStatus');
const drilldownRows = document.getElementById('dashboardDrilldownRows');
const drilldownHeader = document.getElementById('dashboardDrilldownHeader');
const drilldownTable = drilldownHeader?.closest('table');
const drilldownColgroup = document.getElementById('dashboardDrilldownColgroup');
const drilldownSearch = document.getElementById('dashboardDrilldownSearch');
const drilldownFilterToggle = document.getElementById('dashboardDrilldownFilterToggle');
const drilldownFilterTab = document.getElementById('dashboardDrilldownTabFilters');
const drilldownDataStatusGroup = document.getElementById('dashboardDrilldownDataStatusGroup');
const drilldownGroupMode = document.getElementById('dashboardDrilldownGroupMode');
const drilldownFilterPanel = document.getElementById('dashboardDrilldownFilterPanel');
const drilldownFilterClose = document.getElementById('dashboardDrilldownFilterClose');
const drilldownApplyFilters = document.getElementById('dashboardDrilldownApplyFilters');
const drilldownClearFilters = document.getElementById('dashboardDrilldownClearFilters');
const drilldownFilterControls = Array.from(document.querySelectorAll('.dashboard-drilldown-control'));
const drilldownEfficiencyFilterGroups = Array.from(document.querySelectorAll('.dashboard-efficiency-filter-group'));
const drilldownLegacyShiftFilterGroups = Array.from(document.querySelectorAll('.dashboard-legacy-shift-filter-group'));
const drilldownChips = document.getElementById('dashboardDrilldownChips');
const drilldownPageInfo = document.getElementById('dashboardDrilldownPageInfo');
const drilldownPageSize = document.getElementById('dashboardDrilldownPageSize');
const drilldownPrev = document.getElementById('dashboardDrilldownPrev');
const drilldownNext = document.getElementById('dashboardDrilldownNext');
const drilldownExport = document.getElementById('dashboardDrilldownExport');
const drilldownRetry = document.getElementById('dashboardDrilldownRetry');
const drilldownFormula = document.getElementById('dashboardDrilldownFormula');
const efficiencyDurationFormatKey = 'efficiency_duration_format';
const efficiencyDurationFormats = new Set(['days_hms', 'hours_hms', 'decimal_hours']);

const readEfficiencyDurationFormat = () => {
    try {
        const value = window.localStorage?.getItem(efficiencyDurationFormatKey);

        return efficiencyDurationFormats.has(value) ? value : 'decimal_hours';
    } catch (error) {
        return 'decimal_hours';
    }
};

const saveEfficiencyDurationFormat = value => {
    if (!efficiencyDurationFormats.has(value)) {
        return;
    }

    try {
        window.localStorage?.setItem(efficiencyDurationFormatKey, value);
    } catch (error) {
        // localStorage can be unavailable in private or restricted browser modes.
    }
};

let drilldownController = null;
let drilldownRequestId = 0;
let drilldownReturnFocus = null;
let drilldownState = {
    title: '',
    filters: {},
    baseFilters: {},
    baseTotal: null,
    initialized: false,
    page: 1,
    meta: null,
    columns: {},
    endpointUrl: '',
    unitsEndpointUrl: '',
    exportUrl: '',
    exportEnabled: true,
    mode: 'fleet',
    parent: null,
};

const setDrilldownControlsDisabled = disabled => {
    [
        ...document.querySelectorAll('.dashboard-drilldown-filter'),
        ...drilldownFilterControls,
        drilldownSearch,
        drilldownFilterToggle,
        drilldownGroupMode,
        drilldownApplyFilters,
        drilldownClearFilters,
        drilldownPageSize,
        drilldownPrev,
        drilldownNext,
    ].filter(Boolean).forEach(control => {
        control.disabled = disabled;
    });
};

const activateDrilldownTab = (tab, { focus = false } = {}) => {
    drilldownTabButtons.forEach(button => {
        const selected = button.dataset.drilldownTabTarget === tab;
        button.classList.toggle('active', selected);
        button.setAttribute('aria-selected', selected ? 'true' : 'false');
        button.tabIndex = selected ? 0 : -1;

        if (selected && focus) {
            button.focus();
        }
    });

    drilldownTabSections.forEach(section => {
        const selected = section.dataset.drilldownTabSection === tab;
        section.hidden = !selected;

        if (section.id === 'dashboardDrilldownFilterPanel') {
            section.classList.toggle('d-none', !selected);
        } else if (section.id !== 'dashboardDrilldownFormula' || selected) {
            section.classList.toggle('d-none', !selected);
        }
    });

    drilldownFilterToggle?.setAttribute('aria-expanded', tab === 'filters' ? 'true' : 'false');
};

drilldownTabButtons.forEach((button, index) => {
    button.addEventListener('click', () => activateDrilldownTab(button.dataset.drilldownTabTarget));
    button.addEventListener('keydown', event => {
        let nextIndex = null;

        if (event.key === 'ArrowRight') nextIndex = (index + 1) % drilldownTabButtons.length;
        if (event.key === 'ArrowLeft') nextIndex = (index - 1 + drilldownTabButtons.length) % drilldownTabButtons.length;
        if (event.key === 'Home') nextIndex = 0;
        if (event.key === 'End') nextIndex = drilldownTabButtons.length - 1;

        if (nextIndex !== null) {
            event.preventDefault();
            activateDrilldownTab(drilldownTabButtons[nextIndex].dataset.drilldownTabTarget, { focus: true });
        }
    });
});

const baseDrilldownFilters = () => ({
    date_from: dashboardPage?.dataset.dashboardDateFrom || '',
    date_to: dashboardPage?.dataset.dashboardDateTo || '',
    project_id: dashboardPage?.dataset.dashboardProjectId || '',
    equipment_type_id: dashboardPage?.dataset.dashboardEquipmentTypeId || '',
    ownership: dashboardPage?.dataset.dashboardOwnership || 'all',
    data_status: 'all',
    duration_format: readEfficiencyDurationFormat(),
    per_page: 20,
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
    duration_format: 'Müddət formatı',
    unit_name: 'Texnika',
    registration_number: 'Qeydiyyat nişanı',
    wialon_id: 'Wialon ID',
    search: 'Axtarış',
};
const drilldownValueLabels = {
    ownership: { all: 'Hamısı', nwc: 'NWC', icare: 'İCARƏ' },
    data_status: { all: 'Hamısı', available: 'Məlumat var', missing: 'Məlumat yoxdur' },
    group_by: { details: 'Gündəlik detallar', day: 'Gün üzrə', unit: 'Texnika üzrə' },
    work_category: { less_than_1_hour: @json(__('app.worked_less_than_1_hour')), less_than_7_hours: @json(__('app.worked_less_than_7_hours')), between_7_and_10_hours: @json(__('app.worked_7_to_10_hours')), night_shift_only: @json(__('app.worked_night_shift_only')), over_10_hours: @json(__('app.worked_over_10_hours')), overtime: @json(__('app.worked_overtime_hours')), no_data: @json(__('app.equipment_without_data')) },
    day_status: { less_than_1_hour: @json(__('app.worked_less_than_1_hour')), less_than_7_hours: @json(__('app.worked_less_than_7_hours')), between_7_and_10_hours: @json(__('app.worked_7_to_10_hours')), night_shift_only: @json(__('app.worked_night_shift_only')), over_10_hours: @json(__('app.worked_over_10_hours')) },
    has_overtime: { all: 'Hamısı', yes: 'Var', no: 'Yoxdur' },
    duration_format: { decimal_hours: 'saat (yüzdə bir dəqiqliklə)', hours_hms: 'saat:dəqiqə:saniyə', days_hms: 'gün saat:dəqiqə:saniyə' },
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
        ['Məlumatlı texnika-gün', Number(formula.valid_unit_days || 0).toLocaleString()],
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
        drilldownPageSize.value = String(drilldownState.filters.per_page || 20);
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

    if (filters.duration_format) {
        saveEfficiencyDurationFormat(filters.duration_format);
    }

    return cleanDrilldownFilters(filters);
};

const renderDrilldownChips = () => {
    if (!drilldownChips) {
        return;
    }

    drilldownChips.textContent = '';
    const hidden = new Set(['page', 'per_page', 'sort', 'direction', 'title', 'view', 'date_from', 'date_to', 'ownership', 'geofence_violation', 'current_geozone_project_id', 'current_geozone_id', 'current_geozone_key', 'top_working_equipment_id', 'top_working_stat_date', 'top_working_ranking', 'metric']);
    const defaultValues = { ownership: 'all', data_status: 'all', has_overtime: 'all', group_by: 'details', duration_format: 'decimal_hours' };

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
        button.textContent = '×';
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
        const rowNumber = ((drilldownState.meta?.current_page || 1) - 1) * (drilldownState.meta?.per_page || 20) + index + 1;

        if (drilldownState.mode === 'project_types' && row.equipment_type_id) {
            tr.className = 'dashboard-project-type-row';
            tr.setAttribute('role', 'button');
            tr.tabIndex = 0;
            tr.dataset.equipmentTypeId = row.equipment_type_id;
            tr.dataset.equipmentTypeName = row.vehicle_type || '';
            tr.title = `${row.vehicle_type || 'Texnika növü'} siyahısını aç`;
        }

        if (['efficiency_projects', 'daytime_efficiency_projects', 'nighttime_efficiency_projects'].includes(drilldownState.mode) && row.project_id) {
            tr.className = 'dashboard-project-type-row';
            tr.setAttribute('role', 'button');
            tr.tabIndex = 0;
            tr.dataset.projectId = row.project_id;
            tr.dataset.projectName = row.project || '';
            tr.title = `${row.project || 'Layihə'} texnika siyahısını aç`;
        }

        columns.forEach(key => {
            const td = document.createElement('td');
            const formattedDurationKey = {
                daytime_hours: 'daytime_formatted',
                overtime_hours: 'overtime_formatted',
                total_hours: 'total_formatted',
            }[key];
            const value = key === 'number'
                ? rowNumber
                : (formattedDurationKey && row[formattedDurationKey] !== undefined ? row[formattedDurationKey] : row[key]);
            const isSummaryNumber = ['project_types', 'efficiency_projects', 'daytime_efficiency_projects', 'nighttime_efficiency_projects'].includes(drilldownState.mode)
                && ['nwc_count', 'icare_count', 'count'].includes(key);
            const isSummaryName = (drilldownState.mode === 'project_types' && key === 'vehicle_type')
                || (['efficiency_projects', 'daytime_efficiency_projects', 'nighttime_efficiency_projects'].includes(drilldownState.mode) && key === 'project');

            td.textContent = isSummaryNumber && Number(value) === 0 ? '–' : (value ?? '-');
            td.classList.toggle('dashboard-project-type-name', isSummaryName);
            td.classList.toggle('dashboard-project-type-number', isSummaryNumber);
            td.classList.toggle('dashboard-project-type-total', ['project_types', 'efficiency_projects', 'daytime_efficiency_projects', 'nighttime_efficiency_projects'].includes(drilldownState.mode) && key === 'count');
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
    if (drilldownColgroup) {
        drilldownColgroup.textContent = '';

        if (['project_types', 'efficiency_projects', 'daytime_efficiency_projects', 'nighttime_efficiency_projects'].includes(drilldownState.mode)) {
            Object.keys(drilldownState.columns).forEach(key => {
                const col = document.createElement('col');
                col.classList.toggle('dashboard-project-type-name', key === 'vehicle_type' || key === 'project');
                col.classList.toggle('dashboard-project-type-number', ['nwc_count', 'icare_count', 'count'].includes(key));
                drilldownColgroup.appendChild(col);
            });
        }
    }

    Object.entries(drilldownState.columns).forEach(([key, label]) => {
        const th = document.createElement('th');
        const isSummaryNumber = ['project_types', 'efficiency_projects', 'daytime_efficiency_projects', 'nighttime_efficiency_projects'].includes(drilldownState.mode)
            && ['nwc_count', 'icare_count', 'count'].includes(key);
        const isSummaryName = (drilldownState.mode === 'project_types' && key === 'vehicle_type')
            || (['efficiency_projects', 'daytime_efficiency_projects', 'nighttime_efficiency_projects'].includes(drilldownState.mode) && key === 'project');

        th.classList.toggle('dashboard-project-type-name', isSummaryName);
        th.classList.toggle('dashboard-project-type-number', isSummaryNumber);
        th.classList.toggle('dashboard-project-type-total', ['project_types', 'efficiency_projects', 'daytime_efficiency_projects', 'nighttime_efficiency_projects'].includes(drilldownState.mode) && key === 'count');

        if (drilldownSortableColumns.has(key) && !['efficiency_projects', 'daytime_efficiency_projects', 'nighttime_efficiency_projects'].includes(drilldownState.mode)) {
            const button = document.createElement('button');
            const isActive = drilldownState.filters.sort === key;
            const direction = drilldownState.filters.direction === 'desc' ? 'descending' : 'ascending';
            th.setAttribute('aria-sort', isActive ? direction : 'none');
            button.type = 'button';
            button.className = 'dashboard-drilldown-sort';
            button.dataset.sort = key;
            button.setAttribute('aria-label', `${label}: sırala`);
            button.textContent = label;

            const marker = document.createElement('span');
            marker.textContent = isActive ? (drilldownState.filters.direction === 'desc' ? '↓' : '↑') : '↕';
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
        drilldownPageSize.value = String(meta.per_page || drilldownState.filters.per_page || 20);
    }
};

const updateDrilldownFilterButtons = () => {
    document.querySelectorAll('.dashboard-drilldown-filter').forEach(button => {
        const name = button.dataset.filterName;
        const value = button.dataset.filterValue;
        const selected = String(drilldownState.filters[name] || 'all') === value;
        button.classList.toggle('active', selected);
        button.setAttribute('aria-pressed', selected ? 'true' : 'false');
    });

    syncDrilldownFilterControls();
    renderDrilldownChips();
};

const updateDrilldownExportUrl = () => {
    if (!drilldownExport) {
        return;
    }

    drilldownExport.classList.toggle('d-none', !drilldownState.exportEnabled);

    const exportUrl = drilldownState.exportUrl || dashboardPage?.dataset.dashboardDrilldownExportUrl;

    if (drilldownState.exportEnabled && exportUrl) {
        drilldownExport.href = drilldownUrl(exportUrl, drilldownState.filters);
    } else {
        drilldownExport.href = '#';
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
        initialized: false,
        page: 1,
        meta: null,
        columns: defaultDrilldownColumns(),
        endpointUrl: dashboardPage?.dataset.dashboardDrilldownUrl || '',
        unitsEndpointUrl: '',
        exportUrl: '',
        exportEnabled: true,
        mode: 'fleet',
        parent: null,
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
        drilldownExport.classList.remove('d-none');
    }

    drilldownFilterPanel?.classList.add('d-none');
    drilldownFilterToggle?.classList.add('d-none');
    drilldownFilterTab?.classList.remove('d-none');
    drilldownDataStatusGroup?.classList.remove('d-none');
    drilldownBack?.classList.add('d-none');
    drilldownEfficiencyFilterGroups.forEach(group => group.classList.remove('d-none'));
    drilldownLegacyShiftFilterGroups.forEach(group => group.classList.remove('d-none'));
    syncDrilldownFilterControls();
    renderDrilldownChips();
};

const loadDashboardDrilldown = async () => {
    const endpointUrl = drilldownState.endpointUrl || dashboardPage?.dataset.dashboardDrilldownUrl;

    if (!endpointUrl) {
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
    drilldownBody?.setAttribute('aria-busy', 'true');
    updateDrilldownFilterButtons();
    updateDrilldownExportUrl();

    try {
        const response = await fetch(drilldownUrl(endpointUrl, drilldownState.filters), {
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
        if (!drilldownState.initialized) {
            drilldownState.baseTotal = payload.summary?.total ?? 0;
            drilldownState.initialized = true;
            setDrilldownControlsDisabled(false);
        }
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
            drilldownBody?.setAttribute('aria-busy', 'false');
        }
    }
};

const configureDrilldownMode = (mode, filters = {}) => {
    const isEfficiencyDrilldown = Boolean(filters.work_category || filters.day_status);
    const isMetricDrilldown = Boolean(filters.metric);
    const isRestrictedMode = ['geofence_violations', 'project_types', 'efficiency_projects', 'daytime_efficiency_projects', 'nighttime_efficiency_projects'].includes(mode);

    drilldownTable?.classList.toggle('dashboard-project-type-table', ['project_types', 'efficiency_projects', 'daytime_efficiency_projects', 'nighttime_efficiency_projects'].includes(mode));
    drilldownFilterToggle?.classList.toggle('d-none', isRestrictedMode || !(isEfficiencyDrilldown || isMetricDrilldown));
    drilldownFilterTab?.classList.toggle('d-none', isRestrictedMode);
    drilldownDataStatusGroup?.classList.toggle('d-none', isRestrictedMode);
    drilldownGroupMode?.classList.toggle('d-none', !isMetricDrilldown);
    drilldownEfficiencyFilterGroups.forEach(group => group.classList.toggle('d-none', isMetricDrilldown && !isEfficiencyDrilldown));
    drilldownLegacyShiftFilterGroups.forEach(group => group.classList.toggle('d-none', isEfficiencyDrilldown));

    if (drilldownGroupMode) {
        drilldownGroupMode.value = filters.group_by || 'details';
    }
};

const openDashboardDrilldown = (filters = {}) => {
    drilldownReturnFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    resetDashboardDrilldownState({ abortRequest: true, clearTitle: false });
    const nextFilters = cleanDrilldownFilters(filters);
    const endpointUrl = nextFilters.endpoint_url || dashboardPage?.dataset.dashboardDrilldownUrl || '';
    const unitsEndpointUrl = nextFilters.units_endpoint_url || '';
    const exportUrl = nextFilters.export_url || '';
    const mode = nextFilters.drilldown_mode
        || (nextFilters.view === 'equipment_types' ? 'project_types' : (nextFilters.view === 'projects' ? 'efficiency_projects' : 'fleet'));
    const exportEnabled = nextFilters.export_enabled !== false && !['project_types', 'efficiency_projects', 'daytime_efficiency_projects', 'nighttime_efficiency_projects'].includes(mode);

    delete nextFilters.endpoint_url;
    delete nextFilters.units_endpoint_url;
    delete nextFilters.export_url;
    delete nextFilters.export_enabled;
    delete nextFilters.drilldown_mode;

    const initialFilters = {
        ...baseDrilldownFilters(),
        ...nextFilters,
        data_status: nextFilters.data_status || 'all',
        has_overtime: nextFilters.has_overtime || 'all',
        page: 1,
        search: nextFilters.search || '',
    };
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
    drilldownState.filters = initialFilters;
    drilldownState.baseFilters = { ...initialFilters };
    drilldownState.baseTotal = null;
    drilldownState.initialized = false;
    drilldownState.title = nextFilters.title || '';
    drilldownState.endpointUrl = endpointUrl;
    drilldownState.unitsEndpointUrl = unitsEndpointUrl;
    drilldownState.exportUrl = exportUrl;
    drilldownState.exportEnabled = exportEnabled;
    drilldownState.mode = mode;
    drilldownState.parent = null;

    delete drilldownState.filters.title;
    delete drilldownState.baseFilters.title;
    configureDrilldownMode(mode, initialFilters);

    if (drilldownSearch) {
        drilldownSearch.value = initialFilters.search;
    }

    if (drilldownTitle) {
        drilldownTitle.textContent = drilldownState.title || 'Texnika siyahısı';
    }

    syncDrilldownFilterControls();
    renderDrilldownChips();
    updateDrilldownExportUrl();
    activateDrilldownTab('data');
    setDrilldownControlsDisabled(true);
    drilldownModal?.show();
    loadDashboardDrilldown();
};

const openSummaryUnits = trigger => {
    const isProjectTypeSummary = drilldownState.mode === 'project_types' && trigger?.dataset.equipmentTypeId;
    const isEfficiencyProjectSummary = drilldownState.mode === 'efficiency_projects' && trigger?.dataset.projectId;
    const isDaytimeEfficiencyProjectSummary = drilldownState.mode === 'daytime_efficiency_projects' && trigger?.dataset.projectId;
    const isNighttimeEfficiencyProjectSummary = drilldownState.mode === 'nighttime_efficiency_projects' && trigger?.dataset.projectId;

    if (!isProjectTypeSummary && !isEfficiencyProjectSummary && !isDaytimeEfficiencyProjectSummary && !isNighttimeEfficiencyProjectSummary) {
        return;
    }

    const parent = {
        title: drilldownState.title,
        filters: { ...drilldownState.filters },
        baseFilters: { ...drilldownState.baseFilters },
        endpointUrl: drilldownState.endpointUrl,
        unitsEndpointUrl: drilldownState.unitsEndpointUrl,
        exportUrl: drilldownState.exportUrl,
        exportEnabled: drilldownState.exportEnabled,
        mode: drilldownState.mode,
    };
    const nextFilters = {
        ...drilldownState.filters,
        view: 'units',
        page: 1,
        search: drilldownState.filters.search || '',
        sort: (isEfficiencyProjectSummary || isDaytimeEfficiencyProjectSummary || isNighttimeEfficiencyProjectSummary) ? 'date' : 'name',
        direction: 'asc',
    };
    if (isProjectTypeSummary) {
        nextFilters.equipment_type_id = trigger.dataset.equipmentTypeId;
    }
    if (isEfficiencyProjectSummary || isDaytimeEfficiencyProjectSummary || isNighttimeEfficiencyProjectSummary) {
        nextFilters.project_id = trigger.dataset.projectId;
    }

    drilldownController?.abort();
    drilldownState.filters = nextFilters;
    drilldownState.baseFilters = { ...nextFilters };
    drilldownState.baseTotal = null;
    drilldownState.initialized = false;
    drilldownState.meta = null;
    drilldownState.columns = defaultDrilldownColumns();
    drilldownState.title = `${parent.title} - ${
        (isEfficiencyProjectSummary || isDaytimeEfficiencyProjectSummary || isNighttimeEfficiencyProjectSummary)
            ? (trigger.dataset.projectName || 'Layihə')
            : (trigger.dataset.equipmentTypeName || 'Texnika növü')
    }`;
    drilldownState.exportEnabled = true;
    if (isDaytimeEfficiencyProjectSummary || isNighttimeEfficiencyProjectSummary) {
        drilldownState.endpointUrl = drilldownState.unitsEndpointUrl;
    }
    drilldownState.mode = isNighttimeEfficiencyProjectSummary
        ? 'nighttime_efficiency_units'
        : (isDaytimeEfficiencyProjectSummary ? 'daytime_efficiency_units' : 'fleet');
    drilldownState.parent = parent;
    drilldownBack?.classList.remove('d-none');
    if (drilldownSearch) {
        drilldownSearch.value = nextFilters.search;
    }
    configureDrilldownMode(drilldownState.mode, nextFilters);
    renderDrilldownColumns(null);
    renderDrilldownRows([]);
    syncDrilldownFilterControls();
    renderDrilldownChips();
    updateDrilldownExportUrl();
    setDrilldownControlsDisabled(true);
    loadDashboardDrilldown();
};

const restoreDrilldownSummary = () => {
    const parent = drilldownState.parent;

    if (!parent) {
        return;
    }

    drilldownController?.abort();
    drilldownState.filters = { ...parent.filters, page: 1, search: '' };
    drilldownState.baseFilters = { ...parent.baseFilters };
    drilldownState.baseTotal = null;
    drilldownState.initialized = false;
    drilldownState.meta = null;
    drilldownState.title = parent.title;
    drilldownState.endpointUrl = parent.endpointUrl;
    drilldownState.unitsEndpointUrl = parent.unitsEndpointUrl;
    drilldownState.exportUrl = parent.exportUrl;
    drilldownState.exportEnabled = parent.exportEnabled;
    drilldownState.mode = parent.mode;
    drilldownState.parent = null;
    drilldownBack?.classList.add('d-none');
    if (drilldownSearch) {
        drilldownSearch.value = drilldownState.filters.search || '';
    }
    configureDrilldownMode(parent.mode, drilldownState.filters);
    renderDrilldownColumns(null);
    renderDrilldownRows([]);
    syncDrilldownFilterControls();
    renderDrilldownChips();
    updateDrilldownExportUrl();
    setDrilldownControlsDisabled(true);
    loadDashboardDrilldown();
};

const openGeofenceViolationsDrilldown = trigger => {
    openDashboardDrilldown({
        title: 'Geofence Pozuntuları',
        endpoint_url: dashboardPage?.dataset.geofenceViolationsDrilldownUrl || '',
        export_enabled: false,
        drilldown_mode: 'geofence_violations',
        project_id: trigger?.dataset.geofenceViolationsProjectId || undefined,
    });
};

drilldownModalElement?.addEventListener('hidden.bs.modal', () => {
    resetDashboardDrilldownState({ abortRequest: true, clearTitle: true });

    if (drilldownReturnFocus?.isConnected) {
        drilldownReturnFocus.focus({ preventScroll: true });
    } else {
        document.querySelector('[data-dashboard-tab].active')?.focus?.({ preventScroll: true });
    }

    drilldownReturnFocus = null;
});

document.addEventListener('click', event => {
    const geofenceViolationsTrigger = event.target.closest('[data-geofence-violations-drilldown]');

    if (geofenceViolationsTrigger) {
        event.preventDefault();
        event.stopPropagation();
        openGeofenceViolationsDrilldown(geofenceViolationsTrigger);
        return;
    }

    const trigger = event.target.closest('.dashboard-drilldown-trigger');

    if (!trigger || trigger.dataset.expandToggle) {
        return;
    }

    event.preventDefault();
    event.stopPropagation();
    openDashboardDrilldown({
        title: trigger.dataset.drilldownTitle || '',
        ownership: trigger.dataset.drilldownOwnership || undefined,
        ownership_scope: trigger.dataset.drilldownOwnershipScope || undefined,
        view: trigger.dataset.drilldownView || undefined,
        drilldown_mode: trigger.dataset.drilldownMode || undefined,
        project_id: trigger.dataset.drilldownProjectId || undefined,
        equipment_type_id: trigger.dataset.drilldownEquipmentTypeId || undefined,
        vehicle_types: trigger.dataset.drilldownVehicleTypes ? [trigger.dataset.drilldownVehicleTypes] : undefined,
        work_category: trigger.dataset.drilldownWorkCategory || undefined,
        status: trigger.dataset.drilldownStatus || trigger.dataset.drilldownWorkCategory || undefined,
        search: trigger.dataset.drilldownSearch || undefined,
        date_from: trigger.dataset.drilldownDateFrom || undefined,
        date_to: trigger.dataset.drilldownDateTo || undefined,
        metric: trigger.dataset.drilldownMetric || undefined,
        sort: trigger.dataset.drilldownSort || undefined,
        data_status: trigger.dataset.drilldownDataStatus || undefined,
        top_working_equipment_id: trigger.dataset.drilldownTopEquipmentId || undefined,
        top_working_stat_date: trigger.dataset.drilldownTopStatDate || undefined,
        top_working_ranking: trigger.dataset.drilldownTopRanking || undefined,
        geofence_violation: trigger.dataset.drilldownGeofenceViolation || undefined,
        endpoint_url: trigger.dataset.drilldownEndpointUrl || undefined,
        units_endpoint_url: trigger.dataset.drilldownUnitsEndpointUrl || undefined,
        export_url: trigger.dataset.drilldownExportUrl || undefined,
        export_enabled: trigger.dataset.drilldownExportEnabled === '0' ? false : undefined,
        current_geozone_project_id: trigger.dataset.drilldownCurrentGeozoneProjectId || undefined,
        current_geozone_id: trigger.dataset.drilldownCurrentGeozoneId || undefined,
        current_geozone_key: trigger.dataset.drilldownCurrentGeozoneKey || undefined,
    });
});

drilldownRows?.addEventListener('click', event => {
    const trigger = event.target.closest('.dashboard-project-type-row');

    if (trigger) {
        openSummaryUnits(trigger);
    }
});

drilldownRows?.addEventListener('keydown', event => {
    if (!['Enter', ' '].includes(event.key)) {
        return;
    }

    const trigger = event.target.closest('.dashboard-project-type-row');

    if (trigger) {
        event.preventDefault();
        openSummaryUnits(trigger);
    }
});

drilldownBack?.addEventListener('click', restoreDrilldownSummary);

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
    activateDrilldownTab('filters', { focus: true });
});

drilldownFilterClose?.addEventListener('click', () => {
    activateDrilldownTab('data', { focus: true });
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

    activateDrilldownTab('data');
    loadDashboardDrilldown();
});

drilldownClearFilters?.addEventListener('click', () => {
    drilldownState.filters = { ...drilldownState.baseFilters, duration_format: readEfficiencyDurationFormat(), page: 1 };
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
    drilldownState.filters.per_page = Number(drilldownPageSize.value || 20);
    drilldownState.filters.page = 1;
    loadDashboardDrilldown();
});

drilldownGroupMode?.addEventListener('change', () => {
    drilldownState.filters.group_by = drilldownGroupMode.value || 'details';
    drilldownState.filters.page = 1;
    loadDashboardDrilldown();
});

drilldownFilterControls
    .filter(control => control.dataset.filterName === 'duration_format')
    .forEach(control => {
        control.addEventListener('change', () => {
            const value = efficiencyDurationFormats.has(control.value) ? control.value : 'decimal_hours';
            saveEfficiencyDurationFormat(value);
            drilldownState.filters.duration_format = value;
            drilldownState.filters.page = 1;
            loadDashboardDrilldown();
        });
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
    { title: `${labels.nwc} texnikaları`, ownership: 'nwc', ownership_scope: 'project_groups' },
    { title: `${labels.icare} texnikaları`, ownership: 'icare', ownership_scope: 'project_groups' },
];
const projectWorkCategoryNwcDrilldownItems = workCategoryKeys.map((key, index) => ({
    title: `${labels.nwc} - ${workCategoryLabels[index]}`,
    ownership: 'nwc',
    view: 'projects',
    drilldown_mode: 'efficiency_projects',
    work_category: key,
    status: key,
}));
const projectWorkCategoryIcareDrilldownItems = workCategoryKeys.map((key, index) => ({
    title: `${labels.icare} - ${workCategoryLabels[index]}`,
    ownership: 'icare',
    view: 'projects',
    drilldown_mode: 'efficiency_projects',
    work_category: key,
    status: key,
}));
const projectWorkCategoryNwcDonutDrilldownItems = workCategoryDonutKeys.map((key, index) => ({
    title: `${labels.nwc} - ${workCategoryDonutLabels[index]}`,
    ownership: 'nwc',
    view: 'projects',
    drilldown_mode: 'efficiency_projects',
    work_category: key,
    status: key,
}));
const projectWorkCategoryIcareDonutDrilldownItems = workCategoryDonutKeys.map((key, index) => ({
    title: `${labels.icare} - ${workCategoryDonutLabels[index]}`,
    ownership: 'icare',
    view: 'projects',
    drilldown_mode: 'efficiency_projects',
    work_category: key,
    status: key,
}));
const daytimeEfficiencyEndpoints = {
    projects: @json(route('api.dashboard.daytime-efficiency.projects')),
    units: @json(route('api.dashboard.daytime-efficiency.units')),
    export: @json(route('api.dashboard.daytime-efficiency.export')),
};
const daytimeEfficiencySearch = @json(request('daytime_search', ''));
const daytimeEfficiencyNwcDrilldownItems = workCategoryDonutKeys.map((key, index) => ({
    title: `Effektivlik gunduz: ${labels.nwc} - ${workCategoryDonutLabels[index]}`,
    ownership: 'nwc',
    view: 'projects',
    drilldown_mode: 'daytime_efficiency_projects',
    status: key,
    search: daytimeEfficiencySearch,
    endpoint_url: daytimeEfficiencyEndpoints.projects,
    units_endpoint_url: daytimeEfficiencyEndpoints.units,
    export_url: daytimeEfficiencyEndpoints.export,
    export_enabled: false,
}));
const daytimeEfficiencyIcareDrilldownItems = workCategoryDonutKeys.map((key, index) => ({
    title: `Effektivlik gunduz: ${labels.icare} - ${workCategoryDonutLabels[index]}`,
    ownership: 'icare',
    view: 'projects',
    drilldown_mode: 'daytime_efficiency_projects',
    status: key,
    search: daytimeEfficiencySearch,
    endpoint_url: daytimeEfficiencyEndpoints.projects,
    units_endpoint_url: daytimeEfficiencyEndpoints.units,
    export_url: daytimeEfficiencyEndpoints.export,
    export_enabled: false,
}));
const nighttimeEfficiencyEndpoints = {
    projects: @json(route('api.dashboard.nighttime-efficiency.projects')),
    units: @json(route('api.dashboard.nighttime-efficiency.units')),
    export: @json(route('api.dashboard.nighttime-efficiency.export')),
};
const nighttimeEfficiencySearch = @json(request('nighttime_search', ''));
const nighttimeEfficiencyNwcDrilldownItems = workCategoryDonutKeys.map((key, index) => ({
    title: `Effektivlik gecə: ${labels.nwc} - ${workCategoryDonutLabels[index]}`,
    ownership: 'nwc',
    view: 'projects',
    drilldown_mode: 'nighttime_efficiency_projects',
    status: key,
    search: nighttimeEfficiencySearch,
    endpoint_url: nighttimeEfficiencyEndpoints.projects,
    units_endpoint_url: nighttimeEfficiencyEndpoints.units,
    export_url: nighttimeEfficiencyEndpoints.export,
    export_enabled: false,
}));
const nighttimeEfficiencyIcareDrilldownItems = workCategoryDonutKeys.map((key, index) => ({
    title: `Effektivlik gecə: ${labels.icare} - ${workCategoryDonutLabels[index]}`,
    ownership: 'icare',
    view: 'projects',
    drilldown_mode: 'nighttime_efficiency_projects',
    status: key,
    search: nighttimeEfficiencySearch,
    endpoint_url: nighttimeEfficiencyEndpoints.projects,
    units_endpoint_url: nighttimeEfficiencyEndpoints.units,
    export_url: nighttimeEfficiencyEndpoints.export,
    export_enabled: false,
}));
const typeNwcDrilldownItems = () => typeNwcIds.map((id, index) => ({
    title: `${labels.nwc} - ${typeNwcLabels[index]}`,
    ownership: 'nwc',
    equipment_type_id: id,
    ownership_scope: 'project_groups',
}));
const typeIcareDrilldownItems = () => typeIcareIds.map((id, index) => ({
    title: `${labels.icare} - ${typeIcareLabels[index]}`,
    ownership: 'icare',
    equipment_type_id: id,
    ownership_scope: 'project_groups',
}));
const geofenceViolationDrilldownItems = () => geofenceViolationProjectIds.map((id, index) => ({
    title: `${geofenceViolationLabels[index]} - Geofence Transferləri`,
    geofence_violation: 1,
    current_geozone_project_id: id,
    current_geozone_id: geofenceViolationGeofenceIds[index],
    current_geozone_key: geofenceViolationSectorKeys[index],
}));

const initializeDashboardCharts = () => {
    createDoughnutChart('ownershipDonut', ownershipShareLabels, ownershipShareCounts, [ownershipColor.NWC, ownershipColor.ICARE], {
        showLegend: false,
        total: ownershipShareTotal,
        showCenterTotal: true,
        drilldownItems: ownershipDrilldownItems,
        centerDrilldown: { title: 'Bütün texnikalar', ownership_scope: 'project_groups' },
    });
    createDoughnutChart('typeDonutNwc', typeNwcLabels, typeNwcTotals, typePalette, { showLegend: false, total: typeNwcTotal, showCenterTotal: true, drilldownItems: typeNwcDrilldownItems() });
    createDoughnutChart('typeDonutIcare', typeIcareLabels, typeIcareTotals, typePalette, { showLegend: false, total: typeIcareTotal, showCenterTotal: true, drilldownItems: typeIcareDrilldownItems() });
    createDoughnutChart('geofenceViolationsDonut', geofenceViolationLabels, geofenceViolationCounts, geofenceViolationPalette, {
        showLegend: false,
        cutout: '66%',
        hoverOffset: 10,
        total: geofenceViolationTotal,
        drilldownItems: geofenceViolationDrilldownItems(),
        centerDrilldown: { title: 'Geofence Transferləri', geofence_violation: 1 },
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
    createProjectWorkCategoryChart('daytimeEfficiencyNwc', daytimeEfficiencyNwcCounts, {
        labels: workCategoryDonutLabels,
        colors: workCategoryDonutColorValues,
        drilldownItems: daytimeEfficiencyNwcDrilldownItems,
    });
    createProjectWorkCategoryChart('daytimeEfficiencyIcare', daytimeEfficiencyIcareCounts, {
        labels: workCategoryDonutLabels,
        colors: workCategoryDonutColorValues,
        drilldownItems: daytimeEfficiencyIcareDrilldownItems,
    });
    createProjectWorkCategoryChart('nighttimeEfficiencyNwc', nighttimeEfficiencyNwcCounts, {
        labels: workCategoryDonutLabels,
        colors: workCategoryDonutColorValues,
        drilldownItems: nighttimeEfficiencyNwcDrilldownItems,
    });
    createProjectWorkCategoryChart('nighttimeEfficiencyIcare', nighttimeEfficiencyIcareCounts, {
        labels: workCategoryDonutLabels,
        colors: workCategoryDonutColorValues,
        drilldownItems: nighttimeEfficiencyIcareDrilldownItems,
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
};

configureDashboardChartTheme();
initializeDashboardCharts();

</script>
@endpush
