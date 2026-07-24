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
    $projectWorkCategoryGroups = $data['projectActualWorkHourCategoriesByOwnership'] ?? [$nwc => [], $icare => []];
    $projectWorkCategoryRowsNwc = collect($projectWorkCategoryGroups[$nwc] ?? []);
    $projectWorkCategoryRowsIcare = collect($projectWorkCategoryGroups[$icare] ?? []);
    $actualWorkCategoryLabels = collect([
        'less_than_1' => __('app.worked_less_than_1_hour'),
        'from_1_to_7' => __('app.worked_less_than_7_hours'),
        'from_7_to_10' => __('app.worked_7_to_10_hours'),
        'overtime' => __('app.worked_overtime_hours'),
    ]);
    $actualWorkCategoryRanges = collect([
        'less_than_1' => '< 1 saat',
        'from_1_to_7' => '1 - 7 saat',
        'from_7_to_10' => '7 - 10 saat',
        'overtime' => '> 10 saat (Overtime)',
    ]);
    $actualWorkCategoryColors = collect([
        'less_than_1' => '#1f6feb',
        'from_1_to_7' => '#f97316',
        'from_7_to_10' => '#24b35b',
        'overtime' => '#ef4444',
    ]);
    $projectWorkCategorySummaryFor = function ($rows) use ($actualWorkCategoryLabels) {
        $rows = collect($rows);
        $summary = [];

        foreach ($actualWorkCategoryLabels->keys() as $key) {
            $summary[$key] = (int) $rows->sum($key);
        }

        $summary['total'] = array_sum(array_intersect_key($summary, array_flip($actualWorkCategoryLabels->keys()->all())));
        $summary['missing_data'] = (int) $rows->sum('missing_data');

        return collect($summary);
    };
    $projectWorkCategorySummaryNwc = $projectWorkCategorySummaryFor($projectWorkCategoryRowsNwc);
    $projectWorkCategorySummaryIcare = $projectWorkCategorySummaryFor($projectWorkCategoryRowsIcare);
    $projectComparisonRows = collect($data['projectOwnershipComparison'] ?? []);
    $projectComparisonTop = $projectComparisonRows->take(10)->values();
    $projectComparisonHasMore = $projectComparisonRows->count() > 10;
    $projectComparisonChartHeight = min(max($projectComparisonTop->count() * 34 + 80, 260), 520);
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
    $kpis = [
        ['label' => __('app.total_hours'), 'value' => number_format($overview['total_hours'], 1).' '.__('app.hours'), 'icon' => 'bi-clock', 'tone' => '#eaf2ff', 'color' => '#1f6feb', 'change' => $overview['changes']['total_hours']],
        ['label' => __('app.total_distance'), 'value' => number_format($overview['total_distance'], 1).' '.__('app.km'), 'icon' => 'bi-signpost-split', 'tone' => '#eaf8ef', 'color' => '#24b35b', 'change' => $overview['changes']['total_distance']],
        ['label' => __('app.avg_hours'), 'value' => number_format($overview['avg_hours_per_equipment'], 1).' '.__('app.hours'), 'icon' => 'bi-speedometer', 'tone' => '#f2ebff', 'color' => '#8b5cf6', 'change' => $overview['changes']['avg_hours_per_equipment']],
        ['label' => __('app.avg_distance'), 'value' => number_format($overview['avg_distance_per_equipment'], 1).' '.__('app.km'), 'icon' => 'bi-geo-alt', 'tone' => '#fff1e9', 'color' => '#f97316', 'change' => $overview['changes']['avg_distance_per_equipment']],
        ['label' => __('app.utilization'), 'value' => number_format($overview['utilization'], 1).' %', 'icon' => 'bi-graph-up-arrow', 'tone' => '#e8f8fb', 'color' => '#0ea5b7', 'change' => $overview['changes']['utilization']],
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
    .dashboard-widget {
        transition: opacity .15s ease, transform .15s ease;
    }
    .dashboard-widget.dragging {
        opacity: .48;
        transform: scale(.995);
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
    .dashboard-drag-handle:hover,
    .dashboard-drag-handle:focus,
    .dashboard-export-button:hover,
    .dashboard-export-button:focus,
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
    .map-box {
        height: 300px;
        border-radius: 8px;
        overflow: hidden;
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
    }
    @media (max-width: 767px) {
        .dashboard-donut-layout,
        .dashboard-type-layout,
        .dashboard-work-status-layout,
        .dashboard-kpi-grid {
            grid-template-columns: 1fr;
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
    }
</style>
@endpush

@section('content')
    <div class="dashboard-page">
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

        <div class="dashboard-layout-actions d-flex justify-content-end gap-2 mb-2">
            <a href="{{ $exportUrl('overview') }}" class="btn btn-outline-secondary btn-sm btn-icon" title="Excel" aria-label="Excel">
                <i class="bi bi-download"></i><span>Excel</span>
            </a>
            <button type="button" class="btn btn-outline-secondary btn-sm dashboard-reset-order" id="resetDashboardLayout" title="Sıralamanı sıfırla" aria-label="Sıralamanı sıfırla">
                <i class="bi bi-arrow-counterclockwise"></i>
            </button>
        </div>

        <div class="row g-3 dashboard-grid" id="dashboardGrid">
            <div class="col-12 col-lg-6 col-xxl-4 dashboard-widget" data-dashboard-widget="ownership-share" draggable="false">
                <section class="panel p-3 dashboard-card dashboard-card--compact d-flex flex-column">
                    <x-dashboard-card-header title="{{ __('app.ownership_share') }}" :export-url="$exportUrl('ownership-share')" />
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
                                        <div class="dashboard-share-row">
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

            <div class="col-12 col-lg-6 col-xxl-4 dashboard-widget" data-dashboard-widget="equipment-types-nwc" draggable="false">
                <section class="panel p-3 dashboard-card dashboard-card--compact d-flex flex-column">
                    <x-dashboard-card-header title="{{ __('app.equipment_type_distribution') }}: {{ __('app.ownership_nwc') }}" :export-url="$exportUrl('equipment-types-nwc')" />
                    @include('dashboard.partials.type-distribution-card', [
                        'chartId' => 'typeDonutNwc',
                        'rows' => $typeNwc,
                        'topRows' => $typeNwcTop,
                        'hasMore' => $typeNwcHasMore,
                        'expandId' => 'types-nwc',
                    ])
                </section>
            </div>

            <div class="col-12 col-lg-6 col-xxl-4 dashboard-widget" data-dashboard-widget="equipment-types-icare" draggable="false">
                <section class="panel p-3 dashboard-card dashboard-card--compact d-flex flex-column">
                    <x-dashboard-card-header title="{{ __('app.equipment_type_distribution') }}: {{ __('app.ownership_icare') }}" :export-url="$exportUrl('equipment-types-icare')" />
                    @include('dashboard.partials.type-distribution-card', [
                        'chartId' => 'typeDonutIcare',
                        'rows' => $typeIcare,
                        'topRows' => $typeIcareTop,
                        'hasMore' => $typeIcareHasMore,
                        'expandId' => 'types-icare',
                    ])
                </section>
            </div>

            <div class="col-12 col-xl-6 dashboard-widget" data-dashboard-widget="project-work-categories-nwc" draggable="false">
                @include('dashboard.partials.project-engine-hours-status-card', [
                    'chartId' => 'projectWorkCategoriesNwc',
                    'ownershipCode' => $nwc,
                    'ownershipLabel' => __('app.ownership_nwc'),
                    'summary' => $projectWorkCategorySummaryNwc,
                    'categoryLabels' => $actualWorkCategoryLabels,
                    'categoryRanges' => $actualWorkCategoryRanges,
                    'categoryColors' => $actualWorkCategoryColors,
                    'exportUrl' => $exportUrl('actual-work-hours-nwc'),
                ])
            </div>

            <div class="col-12 col-xl-6 dashboard-widget" data-dashboard-widget="project-work-categories-icare" draggable="false">
                @include('dashboard.partials.project-engine-hours-status-card', [
                    'chartId' => 'projectWorkCategoriesIcare',
                    'ownershipCode' => $icare,
                    'ownershipLabel' => __('app.ownership_icare'),
                    'summary' => $projectWorkCategorySummaryIcare,
                    'categoryLabels' => $actualWorkCategoryLabels,
                    'categoryRanges' => $actualWorkCategoryRanges,
                    'categoryColors' => $actualWorkCategoryColors,
                    'exportUrl' => $exportUrl('actual-work-hours-icare'),
                ])
            </div>

            <div class="col-12 col-xl-4 dashboard-widget" data-dashboard-widget="project-averages" draggable="false">
                <section class="panel p-3 dashboard-card dashboard-card--medium">
                    <x-dashboard-card-header title="{{ __('app.project_averages') }}: {{ __('app.ownership_nwc') }} vs {{ __('app.ownership_icare') }}" :export-url="$exportUrl('project-averages')" />
                    @if ($ownershipAverages->sum('count') > 0)
                        <div class="dashboard-kpi-grid">
                            @foreach ($ownershipAverages as $row)
                                <div class="dashboard-mini-kpi">
                                    <div class="text-secondary small fw-semibold">{{ $ownershipLabelFor($row['ownership'] ?? null) }}</div>
                                    <div class="dashboard-mini-kpi-value mt-1">{{ number_format($row['avg_hours'], 1) }}</div>
                                    <div class="small text-secondary">{{ __('app.engine_hours') }} / {{ __('app.hours') }}</div>
                                </div>
                                <div class="dashboard-mini-kpi">
                                    <div class="text-secondary small fw-semibold">{{ $ownershipLabelFor($row['ownership'] ?? null) }}</div>
                                    <div class="dashboard-mini-kpi-value mt-1">{{ number_format($row['avg_mileage'], 1) }}</div>
                                    <div class="small text-secondary">{{ __('app.mileage') }} / {{ __('app.km') }}</div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="dashboard-empty">{{ __('app.no_data') }}</div>
                    @endif
                </section>
            </div>

            <div class="col-12 col-xl-6 dashboard-widget" data-dashboard-widget="least-working" draggable="false">
                <section class="panel p-3 dashboard-card">
                    <x-dashboard-card-header title="{{ __('app.least_working') }}" :export-url="$exportUrl('least-working')" />
                    @include('dashboard.partials.ranking-table', ['rows' => $data['leastWorking']])
                </section>
            </div>

            <div class="col-12 col-xl-6 dashboard-widget" data-dashboard-widget="most-working" draggable="false">
                <section class="panel p-3 dashboard-card">
                    <x-dashboard-card-header title="{{ __('app.most_working') }}" :export-url="$exportUrl('most-working')" />
                    @include('dashboard.partials.ranking-table', ['rows' => $data['mostWorking']])
                </section>
            </div>

            <div class="col-12 col-xl-7 dashboard-widget" data-dashboard-widget="geofence-analysis" draggable="false">
                <section class="panel p-3 dashboard-card">
                    <x-dashboard-card-header title="{{ __('app.geofence_analysis') }}" :export-url="$exportUrl('geofence-analysis')" />
                    <div class="row g-3">
                        <div class="col-lg-7"><div id="fleetMap" class="map-box"></div></div>
                        <div class="col-lg-5">
                            <div class="dashboard-scroll-table">
                                <table class="table table-sm align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>Texnika</th>
                                            <th>Vendor</th>
                                            <th class="text-end">Saat</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse ($data['geofenceOutsideRows'] as $row)
                                            <tr>
                                                <td class="fw-semibold">{{ $row['grouping'] }}</td>
                                                <td>{{ $row['vendor'] }}</td>
                                                <td class="text-end">{{ number_format((float) $row['outside_hours'], 2, '.', '') }}</td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="3" class="text-secondary">{{ __('app.no_data') }}</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </section>
            </div>

            <div class="col-12 col-xl-5 dashboard-widget" data-dashboard-widget="utilization-trend" draggable="false">
                <section class="panel p-3 dashboard-card">
                    <x-dashboard-card-header title="{{ __('app.utilization_trend') }}" :export-url="$exportUrl('utilization-trend')" />
                    @if ($utilizationTrendByOwnership['has_data'] ?? false)
                        <div class="chart-box"><canvas id="utilizationLine"></canvas></div>
                    @else
                        <div class="dashboard-empty">{{ __('app.no_data') }}</div>
                    @endif
                </section>
            </div>

            <div class="col-12 dashboard-widget" data-dashboard-widget="project-comparison" draggable="false">
                <section class="panel p-3 dashboard-card">
                    <x-dashboard-card-header title="{{ __('app.work_hours_by_ownership') }}" :export-url="$exportUrl('project-comparison')" />
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
                                                <tr class="{{ $loop->iteration > 10 ? 'expandable-extra d-none' : '' }}">
                                                    <td class="fw-semibold">{{ $row['name'] }}</td>
                                                    <td class="text-end">{{ number_format($row[$nwc], 0) }}</td>
                                                    <td class="text-end">{{ number_format($row[$icare], 0) }}</td>
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
@endsection

@push('scripts')
<script>
const ownershipColor = { NWC: '#24b35b', ICARE: '#1f6feb' };
const typePalette = ['#1f6feb', '#24b35b', '#f6ad00', '#8b5cf6', '#0ea5b7', '#94a3b8', '#f97316', '#14b8a6', '#6366f1', '#ef4444'];
const workCategoryColors = {
    less_than_1: '#1f6feb',
    from_1_to_7: '#f97316',
    from_7_to_10: '#24b35b',
    overtime: '#ef4444',
};
const ownershipShareLabels = @json($ownershipShare->pluck('label')->map($ownershipLabelFor)->values());
const ownershipShareCounts = @json($ownershipShare->pluck('count')->values());
const typeNwcLabels = @json($typeNwcTop->pluck('name')->values());
const typeNwcTotals = @json($typeNwcTop->pluck('total')->values());
const typeIcareLabels = @json($typeIcareTop->pluck('name')->values());
const typeIcareTotals = @json($typeIcareTop->pluck('total')->values());
const workCategoryKeys = @json($actualWorkCategoryLabels->keys()->values());
const workCategoryLabels = @json($actualWorkCategoryLabels->values());
const workCategoryColorValues = workCategoryKeys.map(key => workCategoryColors[key]);
const projectWorkCategoryNwcCounts = @json($actualWorkCategoryLabels->keys()->map(fn (string $key) => (int) ($projectWorkCategorySummaryNwc[$key] ?? 0))->values());
const projectWorkCategoryIcareCounts = @json($actualWorkCategoryLabels->keys()->map(fn (string $key) => (int) ($projectWorkCategorySummaryIcare[$key] ?? 0))->values());
const utilizationTrend = @json($utilizationTrendByOwnership);
const projectComparisonLabels = @json($projectComparisonTop->pluck('name')->values());
const projectComparisonNwc = @json($projectComparisonTop->pluck($nwc)->values());
const projectComparisonIcare = @json($projectComparisonTop->pluck($icare)->values());
const mapData = @json($data['mapData']);
const dashboardLayoutScope = @json($selectedProject ? 'project-'.$selectedProject->id : 'dashboard');
const dashboardLayoutKey = `fleet.analytics.dashboard.order.v4.${dashboardLayoutScope}`;
const dashboardLayoutInitial = @json($dashboardLayout ?? []);
const dashboardLayoutDefault = @json($dashboardDefaultLayout ?? []);
const dashboardLayoutSaveUrl = @json(route('dashboard.layout.save'));
const dashboardCsrfToken = @json(csrf_token());
const dashboardGrid = document.getElementById('dashboardGrid');
const dashboardResetButton = document.getElementById('resetDashboardLayout');
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
let fleetMap = null;
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

const createDoughnutChart = (id, chartLabels, values, colors, settings = {}) => {
    const canvas = document.getElementById(id);

    if (!canvas || !hasChartData(values)) {
        return null;
    }

    const showLegend = settings.showLegend ?? true;
    const options = {
        ...doughnutOptions,
        plugins: {
            ...doughnutOptions.plugins,
            legend: {
                ...doughnutOptions.plugins.legend,
                display: showLegend,
            },
        },
    };

    return new Chart(canvas, {
        type: 'doughnut',
        data: { labels: chartLabels, datasets: [{ data: values, backgroundColor: colors, borderWidth: 2, borderColor: '#fff' }] },
        options
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

const workStatusCenterTextPlugin = {
    id: 'workStatusCenterText',
    afterDraw(chart, args, options) {
        if (!options?.display) {
            return;
        }

        const { ctx, chartArea } = chart;
        const centerX = (chartArea.left + chartArea.right) / 2;
        const centerY = (chartArea.top + chartArea.bottom) / 2;

        ctx.save();
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillStyle = '#64748b';
        ctx.font = '600 13px system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
        ctx.fillText(options.label || 'Cəmi', centerX, centerY - 13);
        ctx.fillStyle = '#0f1f3a';
        ctx.font = '800 26px system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
        ctx.fillText(Number(options.total || 0).toLocaleString(), centerX, centerY + 14);
        ctx.restore();
    },
};

const workStatusPercentLabelsPlugin = {
    id: 'workStatusPercentLabels',
    afterDatasetsDraw(chart) {
        const dataset = chart.data.datasets[0];
        const total = (dataset.data || []).reduce((sum, value) => sum + Number(value || 0), 0);

        if (!total) {
            return;
        }

        const meta = chart.getDatasetMeta(0);
        const { ctx } = chart;

        ctx.save();
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillStyle = '#fff';
        ctx.font = '700 13px system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';

        meta.data.forEach((arc, index) => {
            const value = Number(dataset.data[index] || 0);
            const percent = value / total;

            if (percent < .045) {
                return;
            }

            const position = arc.tooltipPosition();
            ctx.fillText(`${(percent * 100).toFixed(1)}%`, position.x, position.y);
        });

        ctx.restore();
    },
};

const createProjectWorkCategoryChart = (id, values) => {
    const canvas = document.getElementById(id);
    const total = values.reduce((sum, value) => sum + Number(value || 0), 0);

    if (!canvas || total <= 0) {
        return null;
    }

    return new Chart(canvas, {
        type: 'doughnut',
        data: {
            labels: workCategoryLabels,
            datasets: [{
                data: values,
                backgroundColor: workCategoryColorValues,
                borderColor: '#fff',
                borderWidth: 2,
                hoverOffset: 4,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '58%',
            radius: '94%',
            plugins: {
                legend: { display: false },
                workStatusCenterText: {
                    display: true,
                    label: 'Cəmi',
                    total,
                },
                tooltip: {
                    callbacks: {
                        label: context => {
                            const value = Number(context.raw || 0);
                            const percent = total > 0 ? (value / total) * 100 : 0;

                            return `${context.label}: ${value.toLocaleString()} (${percent.toFixed(1)}%)`;
                        },
                    }
                }
            },
        },
        plugins: [workStatusCenterTextPlugin, workStatusPercentLabelsPlugin],
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
    if (fleetMap) {
        setTimeout(() => fleetMap.invalidateSize(), 60);
    }
};

const readLocalDashboardOrder = () => {
    try {
        const order = JSON.parse(localStorage.getItem(dashboardLayoutKey) || '[]');
        return Array.isArray(order) ? order : [];
    } catch (error) {
        return [];
    }
};

const currentDashboardLayoutPayload = () => dashboardWidgets().map((widget, index) => ({
    key: widget.dataset.dashboardWidget,
    order: (index + 1) * 10,
    visible: true
})).filter(item => item.key);

const persistDashboardOrder = () => {
    const widgets = currentDashboardLayoutPayload();

    try {
        localStorage.setItem(dashboardLayoutKey, JSON.stringify(widgets.map(widget => widget.key)));
    } catch (error) {
        // Ignore private-mode storage errors; dragging still works for the current page.
    }

    fetch(dashboardLayoutSaveUrl, {
        method: 'POST',
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': dashboardCsrfToken,
        },
        body: JSON.stringify({ widgets }),
    }).catch(() => {
        // Local order has already been saved; server persistence is retried on the next drag.
    });
};

const applySavedDashboardOrder = () => {
    const serverOrder = Array.isArray(dashboardLayoutInitial)
        ? dashboardLayoutInitial.map(item => item.key).filter(Boolean)
        : [];
    const savedOrder = serverOrder.length > 0 ? serverOrder : readLocalDashboardOrder();
    if (!dashboardGrid || savedOrder.length === 0) {
        return;
    }

    const widgetsById = new Map(dashboardWidgets().map(widget => [widget.dataset.dashboardWidget, widget]));
    savedOrder.forEach(id => {
        const widget = widgetsById.get(id);
        if (widget) {
            dashboardGrid.appendChild(widget);
        }
    });
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
    applySavedDashboardOrder();
    disableDashboardDragging();

    dashboardGrid.querySelectorAll('.dashboard-drag-handle').forEach(handle => {
        handle.addEventListener('pointerdown', () => {
            const widget = handle.closest('.dashboard-widget');
            if (widget) {
                widget.draggable = true;
            }
        });
    });

    document.addEventListener('pointerup', () => {
        if (!draggedWidget) {
            disableDashboardDragging();
        }
    });

    dashboardGrid.addEventListener('dragstart', event => {
        const widget = event.target.closest('.dashboard-widget');

        if (!widget || !dashboardGrid.contains(widget) || !widget.draggable) {
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
            persistDashboardOrder();
            refreshDashboardVisuals();
        }
        setDragOverWidget(null);
    });
}

dashboardResetButton?.addEventListener('click', () => {
    try {
        localStorage.removeItem(dashboardLayoutKey);
    } catch (error) {
        // Nothing to clear when local storage is unavailable.
    }
    fetch(dashboardLayoutSaveUrl, {
        method: 'POST',
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': dashboardCsrfToken,
        },
        body: JSON.stringify({ widgets: dashboardLayoutDefault }),
    }).finally(() => window.location.reload());
});

createDoughnutChart('ownershipDonut', ownershipShareLabels, ownershipShareCounts, [ownershipColor.NWC, ownershipColor.ICARE], { showLegend: false });
createDoughnutChart('typeDonutNwc', typeNwcLabels, typeNwcTotals, typePalette, { showLegend: false });
createDoughnutChart('typeDonutIcare', typeIcareLabels, typeIcareTotals, typePalette, { showLegend: false });
createProjectWorkCategoryChart('projectWorkCategoriesNwc', projectWorkCategoryNwcCounts);
createProjectWorkCategoryChart('projectWorkCategoriesIcare', projectWorkCategoryIcareCounts);
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

const mapContainer = document.getElementById('fleetMap');
if (mapContainer) {
    const map = L.map('fleetMap', { scrollWheelZoom: false }).setView([40.39, 49.86], 10);
    fleetMap = map;
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap'
    }).addTo(map);

    const bounds = [];
    mapData.geofences.forEach(zone => {
        if (!zone.geometry) return;
        const layer = L.geoJSON(zone.geometry, { style: { color: '#1f6feb', weight: 2, fillOpacity: .12 } }).addTo(map);
        layer.bindPopup(escapeHtml(zone.name));
        layer.getBounds && bounds.push(layer.getBounds());
    });
    mapData.equipment.forEach(item => {
        if (!item.position || !item.position.lat || !item.position.lng) return;
        const marker = L.circleMarker([item.position.lat, item.position.lng], {
            radius: 7,
            color: item.ownership === 'ICARE' ? ownershipColor.ICARE : ownershipColor.NWC,
            fillOpacity: .9
        }).addTo(map);
        marker.bindPopup(`<strong>${escapeHtml(item.name)}</strong><br>${escapeHtml(item.type)}<br>${escapeHtml(item.project)}`);
        bounds.push(marker.getLatLng());
    });
    if (bounds.length > 0) {
        const group = L.featureGroup(bounds.map(item => item instanceof L.LatLng ? L.marker(item) : L.rectangle(item)));
        map.fitBounds(group.getBounds().pad(.18));
    }
    setTimeout(() => map.invalidateSize(), 80);
}
</script>
@endpush
