@extends('layouts.app')

@section('title', __('app.dashboard').' | '.__('app.app_name'))
@section('page-title', $selectedProject ? $selectedProject->name : __('app.dashboard'))
@section('page-subtitle', __('app.period').': '.$filters['from'].' - '.$filters['to'])

@php
    $overview = $data['overview'];
    $ownership = collect($overview['ownership']);
    $ownershipShare = collect($overview['ownership_share']);
    $ownershipLabelFor = fn (?string $value): string => $value === 'ICARE' ? __('app.ownership_icare') : __('app.ownership_nwc');
    $typeGroups = $data['equipmentTypesByOwnership'];
    $typeNwc = collect($typeGroups['NWC'] ?? []);
    $typeIcare = collect($typeGroups['ICARE'] ?? []);
    $typeNwcLabels = $typeNwc->pluck('name');
    $typeNwcTotals = $typeNwc->pluck('total');
    $typeIcareLabels = $typeIcare->pluck('name');
    $typeIcareTotals = $typeIcare->pluck('total');
    $ownershipAverages = collect($data['averageMetricsByOwnership'] ?? []);
    $projectLabels = collect($data['projects'])->pluck('name');
    $projectHours = collect($data['projects'])->pluck('hours');
    $projectUtilization = collect($data['projects'])->pluck('utilization');
    $ownershipLabels = $ownership->pluck('label')->map($ownershipLabelFor);
    $ownershipHours = $ownership->pluck('hours');
    $ownershipShareLabels = $ownershipShare->pluck('label')->map($ownershipLabelFor);
    $ownershipShareCounts = $ownershipShare->pluck('count');
    $totalOwnershipCount = max(1, (int) $ownershipShare->sum('count'));
    $actualWorkLabels = [
        'less_than_1' => __('app.less_than_1_hour'),
        'from_1_to_7' => __('app.from_1_to_7_hours'),
        'from_7_to_10' => __('app.from_7_to_10_hours'),
        'overtime' => __('app.overtime_hours'),
    ];
    $actualWorkCategories = $data['actualWorkHourCategories'];
    $actualWorkKeys = array_keys($actualWorkLabels);
    $actualWorkNwc = collect($actualWorkKeys)->map(fn ($key) => $actualWorkCategories['NWC'][$key] ?? 0);
    $actualWorkIcare = collect($actualWorkKeys)->map(fn ($key) => $actualWorkCategories['ICARE'][$key] ?? 0);
    $actualWorkNwcTotal = (int) $actualWorkNwc->sum();
    $actualWorkIcareTotal = (int) $actualWorkIcare->sum();
    $actualWorkModeText = $filters['from'] === $filters['to']
        ? __('app.actual_work_single_day_mode')
        : __('app.actual_work_average_daily_mode');
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
</style>
@endpush

@section('content')
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
                <div class="col-12 col-lg-3">
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
                    <option value="NWC" @selected($filters['ownership_type'] === 'NWC')>{{ __('app.ownership_nwc') }}</option>
                    <option value="ICARE" @selected($filters['ownership_type'] === 'ICARE')>{{ __('app.ownership_icare') }}</option>
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
            <div class="col-12 col-md-6 col-xl">
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

    <div class="row g-4 dashboard-grid" id="dashboardGrid">
        <div class="col-12 col-xl-5 dashboard-widget" data-dashboard-widget="work-hours" draggable="false">
            <section class="panel p-3 h-100">
                <div class="dashboard-panel-header d-flex align-items-start justify-content-between gap-2 mb-3">
                    <h2 class="h6 fw-bold mb-0">{{ __('app.work_hours_by_ownership') }}</h2>
                    <div class="d-flex align-items-center gap-1">
                        <a href="{{ $exportUrl('work-hours') }}" class="btn btn-sm dashboard-export-button" title="Excel" aria-label="Excel">
                            <i class="bi bi-download"></i>
                        </a>
                        <button type="button" class="btn btn-sm dashboard-drag-handle" title="Bloku daşı" aria-label="Bloku daşı">
                            <i class="bi bi-grip-vertical"></i>
                        </button>
                    </div>
                </div>
                <div class="chart-box"><canvas id="ownershipBar"></canvas></div>
            </section>
        </div>

        <div class="col-12 col-xl-7 dashboard-widget" data-dashboard-widget="equipment-types" draggable="false">
            <div class="row g-4 h-100">
                <div class="col-12 col-lg-6">
                    <section class="panel p-3 h-100">
                        <div class="dashboard-panel-header d-flex align-items-start justify-content-between gap-2 mb-3">
                            <h2 class="h6 fw-bold mb-0">{{ __('app.equipment_type_distribution') }}: {{ __('app.ownership_nwc') }}</h2>
                            <div class="d-flex align-items-center gap-1">
                                <a href="{{ $exportUrl('equipment-types-nwc') }}" class="btn btn-sm dashboard-export-button" title="Excel" aria-label="Excel">
                                    <i class="bi bi-download"></i>
                                </a>
                                <button type="button" class="btn btn-sm dashboard-drag-handle" title="Bloku daşı" aria-label="Bloku daşı">
                                    <i class="bi bi-grip-vertical"></i>
                                </button>
                            </div>
                        </div>
                        <div class="row g-3 align-items-center">
                            <div class="col-md-5"><div class="chart-box"><canvas id="typeDonutNwc"></canvas></div></div>
                            <div class="col-md-7">
                                <div class="table-responsive">
                                    <table class="table table-sm align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>Növ</th>
                                                <th class="text-end">Say</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @forelse ($typeNwc as $type)
                                                <tr>
                                                    <td>{{ $type['name'] }}</td>
                                                    <td class="text-end">{{ $type['total'] }}</td>
                                                </tr>
                                            @empty
                                                <tr><td colspan="2" class="text-secondary">{{ __('app.no_data') }}</td></tr>
                                            @endforelse
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </section>
                </div>

                <div class="col-12 col-lg-6">
                    <section class="panel p-3 h-100">
                        <div class="dashboard-panel-header d-flex align-items-start justify-content-between gap-2 mb-3">
                            <h2 class="h6 fw-bold mb-0">{{ __('app.equipment_type_distribution') }}: {{ __('app.ownership_icare') }}</h2>
                            <div class="d-flex align-items-center gap-1">
                                <a href="{{ $exportUrl('equipment-types-icare') }}" class="btn btn-sm dashboard-export-button" title="Excel" aria-label="Excel">
                                    <i class="bi bi-download"></i>
                                </a>
                                <button type="button" class="btn btn-sm dashboard-drag-handle" title="Bloku daşı" aria-label="Bloku daşı">
                                    <i class="bi bi-grip-vertical"></i>
                                </button>
                            </div>
                        </div>
                        <div class="row g-3 align-items-center">
                            <div class="col-md-5"><div class="chart-box"><canvas id="typeDonutIcare"></canvas></div></div>
                            <div class="col-md-7">
                                <div class="table-responsive">
                                    <table class="table table-sm align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>Növ</th>
                                                <th class="text-end">Say</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @forelse ($typeIcare as $type)
                                                <tr>
                                                    <td>{{ $type['name'] }}</td>
                                                    <td class="text-end">{{ $type['total'] }}</td>
                                                </tr>
                                            @empty
                                                <tr><td colspan="2" class="text-secondary">{{ __('app.no_data') }}</td></tr>
                                            @endforelse
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </section>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-4 dashboard-widget" data-dashboard-widget="project-averages" draggable="false">
            <section class="panel p-3 h-100">
                <div class="dashboard-panel-header d-flex align-items-start justify-content-between gap-2 mb-3">
                    <h2 class="h6 fw-bold mb-0">{{ __('app.project_averages') }}: {{ __('app.ownership_nwc') }} vs {{ __('app.ownership_icare') }}</h2>
                    <div class="d-flex align-items-center gap-1">
                        <a href="{{ $exportUrl('project-averages') }}" class="btn btn-sm dashboard-export-button" title="Excel" aria-label="Excel">
                            <i class="bi bi-download"></i>
                        </a>
                        <button type="button" class="btn btn-sm dashboard-drag-handle" title="Bloku daşı" aria-label="Bloku daşı">
                            <i class="bi bi-grip-vertical"></i>
                        </button>
                    </div>
                </div>
                <div class="vstack gap-3">
                    <div class="p-3 rounded border">
                        <div class="text-secondary small fw-semibold mb-2">{{ __('app.engine_hours') }}</div>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Tip</th>
                                        <th class="text-end">Say</th>
                                        <th class="text-end">{{ __('app.avg_engine_hours') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($ownershipAverages as $row)
                                        <tr>
                                            <td>{{ $ownershipLabelFor($row['ownership'] ?? null) }}</td>
                                            <td class="text-end">{{ $row['count'] }}</td>
                                            <td class="text-end fw-semibold">{{ number_format($row['avg_hours'], 1) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="p-3 rounded border">
                        <div class="text-secondary small fw-semibold mb-2">{{ __('app.mileage') }}</div>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Tip</th>
                                        <th class="text-end">Say</th>
                                        <th class="text-end">{{ __('app.avg_mileage') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($ownershipAverages as $row)
                                        <tr>
                                            <td>{{ $ownershipLabelFor($row['ownership'] ?? null) }}</td>
                                            <td class="text-end">{{ $row['count'] }}</td>
                                            <td class="text-end fw-semibold">{{ number_format($row['avg_mileage'], 1) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <div class="col-12 col-xl-4 dashboard-widget" data-dashboard-widget="least-working" draggable="false">
            <section class="panel p-3 h-100">
                <div class="dashboard-panel-header d-flex align-items-start justify-content-between gap-2 mb-3">
                    <h2 class="h6 fw-bold mb-0">{{ __('app.least_working') }}</h2>
                    <div class="d-flex align-items-center gap-1">
                        <a href="{{ $exportUrl('least-working') }}" class="btn btn-sm dashboard-export-button" title="Excel" aria-label="Excel">
                            <i class="bi bi-download"></i>
                        </a>
                        <button type="button" class="btn btn-sm dashboard-drag-handle" title="Bloku daşı" aria-label="Bloku daşı">
                            <i class="bi bi-grip-vertical"></i>
                        </button>
                    </div>
                </div>
                @include('dashboard.partials.ranking-table', ['rows' => $data['leastWorking']])
            </section>
        </div>

        <div class="col-12 col-xl-4 dashboard-widget" data-dashboard-widget="most-working" draggable="false">
            <section class="panel p-3 h-100">
                <div class="dashboard-panel-header d-flex align-items-start justify-content-between gap-2 mb-3">
                    <h2 class="h6 fw-bold mb-0">{{ __('app.most_working') }}</h2>
                    <div class="d-flex align-items-center gap-1">
                        <a href="{{ $exportUrl('most-working') }}" class="btn btn-sm dashboard-export-button" title="Excel" aria-label="Excel">
                            <i class="bi bi-download"></i>
                        </a>
                        <button type="button" class="btn btn-sm dashboard-drag-handle" title="Bloku daşı" aria-label="Bloku daşı">
                            <i class="bi bi-grip-vertical"></i>
                        </button>
                    </div>
                </div>
                @include('dashboard.partials.ranking-table', ['rows' => $data['mostWorking']])
            </section>
        </div>

        <div class="col-12 col-xl-4 dashboard-widget" data-dashboard-widget="ownership-share" draggable="false">
            <section class="panel p-3 h-100">
                <div class="dashboard-panel-header d-flex align-items-start justify-content-between gap-2 mb-3">
                    <h2 class="h6 fw-bold mb-0">{{ __('app.ownership_share') }}</h2>
                    <div class="d-flex align-items-center gap-1">
                        <a href="{{ $exportUrl('ownership-share') }}" class="btn btn-sm dashboard-export-button" title="Excel" aria-label="Excel">
                            <i class="bi bi-download"></i>
                        </a>
                        <button type="button" class="btn btn-sm dashboard-drag-handle" title="Bloku daşı" aria-label="Bloku daşı">
                            <i class="bi bi-grip-vertical"></i>
                        </button>
                    </div>
                </div>
                <div class="row align-items-center g-3">
                    <div class="col-md-7"><div class="chart-box"><canvas id="ownershipDonut"></canvas></div></div>
                    <div class="col-md-5">
                        <div class="vstack gap-2">
                            @foreach ($ownershipShare as $row)
                                <div class="d-flex justify-content-between gap-2">
                                    <span class="fw-semibold">{{ $ownershipLabelFor($row['label']) }}</span>
                                    <span>{{ $row['count'] }} / {{ round(($row['count'] / $totalOwnershipCount) * 100, 1) }}%</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <div class="col-12 col-xl-7 dashboard-widget" data-dashboard-widget="geofence-analysis" draggable="false">
            <section class="panel p-3 h-100">
                <div class="dashboard-panel-header d-flex align-items-start justify-content-between gap-2 mb-3">
                    <h2 class="h6 fw-bold mb-0">{{ __('app.geofence_analysis') }}</h2>
                    <div class="d-flex align-items-center gap-1">
                        <a href="{{ $exportUrl('geofence-analysis') }}" class="btn btn-sm dashboard-export-button" title="Excel" aria-label="Excel">
                            <i class="bi bi-download"></i>
                        </a>
                        <button type="button" class="btn btn-sm dashboard-drag-handle" title="Bloku daşı" aria-label="Bloku daşı">
                            <i class="bi bi-grip-vertical"></i>
                        </button>
                    </div>
                </div>
                <div class="row g-3">
                    <div class="col-lg-7"><div id="fleetMap" class="map-box"></div></div>
                    <div class="col-lg-5">
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Grouping</th>
                                        <th>Vendor</th>
                                        <th class="text-end">outside the geofence hours</th>
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
            <section class="panel p-3 h-100">
                <div class="dashboard-panel-header d-flex align-items-start justify-content-between gap-2 mb-3">
                    <h2 class="h6 fw-bold mb-0">{{ __('app.utilization_trend') }}</h2>
                    <div class="d-flex align-items-center gap-1">
                        <a href="{{ $exportUrl('utilization-trend') }}" class="btn btn-sm dashboard-export-button" title="Excel" aria-label="Excel">
                            <i class="bi bi-download"></i>
                        </a>
                        <button type="button" class="btn btn-sm dashboard-drag-handle" title="Bloku daşı" aria-label="Bloku daşı">
                            <i class="bi bi-grip-vertical"></i>
                        </button>
                    </div>
                </div>
                <div class="chart-box"><canvas id="utilizationLine"></canvas></div>
            </section>
        </div>

        <div class="col-12 dashboard-widget" data-dashboard-widget="actual-work-hours" draggable="false">
            <section class="panel p-3">
                <div class="dashboard-panel-header d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3">
                    <div>
                        <h2 class="h6 fw-bold mb-1">{{ __('app.actual_work_hours_title') }}</h2>
                        <div class="small text-secondary">{{ $actualWorkModeText }}</div>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <div class="small text-secondary">{{ $filters['from'] }} - {{ $filters['to'] }}</div>
                        <a href="{{ $exportUrl('actual-work-hours') }}" class="btn btn-sm dashboard-export-button" title="Excel" aria-label="Excel">
                            <i class="bi bi-download"></i>
                        </a>
                        <button type="button" class="btn btn-sm dashboard-drag-handle" title="Bloku daşı" aria-label="Bloku daşı">
                            <i class="bi bi-grip-vertical"></i>
                        </button>
                    </div>
                </div>

                <div class="row g-4">
                    <div class="col-12 col-xl-6">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <h3 class="h6 fw-semibold mb-0">NWC</h3>
                            <span class="small text-secondary">{{ $actualWorkNwcTotal }} ədəd</span>
                        </div>
                        <div class="row g-3 align-items-center">
                            <div class="col-md-6">
                                <div class="chart-box"><canvas id="actualWorkNwc"></canvas></div>
                            </div>
                            <div class="col-md-6">
                                <div class="table-responsive">
                                    <table class="table table-sm align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>Status</th>
                                                <th class="text-end">Say</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($actualWorkLabels as $key => $label)
                                                <tr>
                                                    <td>{{ $label }}</td>
                                                    <td class="text-end">{{ $actualWorkCategories['NWC'][$key] ?? 0 }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-xl-6">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <h3 class="h6 fw-semibold mb-0">{{ __('app.ownership_icare') }}</h3>
                            <span class="small text-secondary">{{ $actualWorkIcareTotal }} ədəd</span>
                        </div>
                        <div class="row g-3 align-items-center">
                            <div class="col-md-6">
                                <div class="chart-box"><canvas id="actualWorkIcare"></canvas></div>
                            </div>
                            <div class="col-md-6">
                                <div class="table-responsive">
                                    <table class="table table-sm align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>Status</th>
                                                <th class="text-end">Say</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($actualWorkLabels as $key => $label)
                                                <tr>
                                                    <td>{{ $label }}</td>
                                                    <td class="text-end">{{ $actualWorkCategories['ICARE'][$key] ?? 0 }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <div class="col-12 dashboard-widget" data-dashboard-widget="project-comparison" draggable="false">
            <section class="panel p-3">
                <div class="dashboard-panel-header d-flex align-items-start justify-content-between gap-2 mb-3">
                    <h2 class="h6 fw-bold mb-0">{{ __('app.projects') }}</h2>
                    <div class="d-flex align-items-center gap-1">
                        <a href="{{ $exportUrl('project-comparison') }}" class="btn btn-sm dashboard-export-button" title="Excel" aria-label="Excel">
                            <i class="bi bi-download"></i>
                        </a>
                        <button type="button" class="btn btn-sm dashboard-drag-handle" title="Bloku daşı" aria-label="Bloku daşı">
                            <i class="bi bi-grip-vertical"></i>
                        </button>
                    </div>
                </div>
                <div class="chart-box"><canvas id="projectComparison"></canvas></div>
            </section>
        </div>
    </div>
@endsection

@push('scripts')
<script>
const chartColors = ['#1f6feb', '#24b35b', '#f6ad00', '#8b5cf6', '#0ea5b7', '#94a3b8', '#f97316'];
const ownershipLabels = @json($ownershipLabels);
const ownershipHours = @json($ownershipHours);
const ownershipShareLabels = @json($ownershipShareLabels);
const ownershipShareCounts = @json($ownershipShareCounts);
const typeNwcLabels = @json($typeNwcLabels);
const typeNwcTotals = @json($typeNwcTotals);
const typeIcareLabels = @json($typeIcareLabels);
const typeIcareTotals = @json($typeIcareTotals);
const projectLabels = @json($projectLabels);
const projectHours = @json($projectHours);
const projectUtilization = @json($projectUtilization);
const utilizationTrend = @json($data['utilizationTrend']);
const mapData = @json($data['mapData']);
const actualWorkLabels = @json(array_values($actualWorkLabels));
const actualWorkNwc = @json($actualWorkNwc);
const actualWorkIcare = @json($actualWorkIcare);
const actualWorkColors = ['#94a3b8', '#1f6feb', '#24b35b', '#ef4444'];
const dashboardLayoutScope = @json($selectedProject ? 'project-'.$selectedProject->id : 'dashboard');
const dashboardLayoutKey = `fleet.analytics.dashboard.order.${dashboardLayoutScope}`;
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
const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, character => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;',
}[character]));
let fleetMap = null;
let draggedWidget = null;
let dragOverWidget = null;
let dashboardLoadingTimer = null;

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

const readDashboardOrder = () => {
    try {
        const order = JSON.parse(localStorage.getItem(dashboardLayoutKey) || '[]');
        return Array.isArray(order) ? order : [];
    } catch (error) {
        return [];
    }
};

const saveDashboardOrder = () => {
    try {
        const order = dashboardWidgets().map(widget => widget.dataset.dashboardWidget).filter(Boolean);
        localStorage.setItem(dashboardLayoutKey, JSON.stringify(order));
    } catch (error) {
        // Ignore private-mode storage errors; dragging still works for the current page.
    }
};

const applySavedDashboardOrder = () => {
    const savedOrder = readDashboardOrder();
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
            saveDashboardOrder();
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
    window.location.reload();
});

new Chart(document.getElementById('ownershipBar'), {
    type: 'bar',
    data: {
        labels: ownershipLabels,
        datasets: [{ label: '{{ __('app.hours') }}', data: ownershipHours, backgroundColor: ['#1f6feb', '#24b35b'] }]
    },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
});

new Chart(document.getElementById('typeDonutNwc'), {
    type: 'doughnut',
    data: { labels: typeNwcLabels, datasets: [{ data: typeNwcTotals, backgroundColor: chartColors }] },
    options: { responsive: true, maintainAspectRatio: false, cutout: '62%' }
});

new Chart(document.getElementById('typeDonutIcare'), {
    type: 'doughnut',
    data: { labels: typeIcareLabels, datasets: [{ data: typeIcareTotals, backgroundColor: chartColors }] },
    options: { responsive: true, maintainAspectRatio: false, cutout: '62%' }
});

new Chart(document.getElementById('ownershipDonut'), {
    type: 'doughnut',
    data: { labels: ownershipShareLabels, datasets: [{ data: ownershipShareCounts, backgroundColor: ['#1f6feb', '#24b35b'] }] },
    options: { responsive: true, maintainAspectRatio: false, cutout: '64%' }
});

new Chart(document.getElementById('actualWorkNwc'), {
    type: 'doughnut',
    data: { labels: actualWorkLabels, datasets: [{ data: actualWorkNwc, backgroundColor: actualWorkColors }] },
    options: { responsive: true, maintainAspectRatio: false, cutout: '62%', plugins: { legend: { position: 'right' } } }
});

new Chart(document.getElementById('actualWorkIcare'), {
    type: 'doughnut',
    data: { labels: actualWorkLabels, datasets: [{ data: actualWorkIcare, backgroundColor: actualWorkColors }] },
    options: { responsive: true, maintainAspectRatio: false, cutout: '62%', plugins: { legend: { position: 'right' } } }
});

new Chart(document.getElementById('utilizationLine'), {
    type: 'line',
    data: {
        labels: utilizationTrend.labels,
        datasets: [{ label: '{{ __('app.utilization') }} %', data: utilizationTrend.data, borderColor: '#1f6feb', backgroundColor: 'rgba(31,111,235,.12)', tension: .35, fill: true }]
    },
    options: { responsive: true, maintainAspectRatio: false, scales: { y: { min: 0, max: 100 } } }
});

new Chart(document.getElementById('projectComparison'), {
    data: {
        labels: projectLabels,
        datasets: [
            { type: 'bar', label: '{{ __('app.hours') }}', data: projectHours, backgroundColor: '#1f6feb' },
            { type: 'line', label: '{{ __('app.utilization') }} %', data: projectUtilization, borderColor: '#24b35b', yAxisID: 'percent', tension: .35 }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: { percent: { position: 'right', min: 0, max: 100, grid: { drawOnChartArea: false } } }
    }
});

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
        color: item.ownership === 'ICARE' ? '#24b35b' : '#1f6feb',
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
</script>
@endpush
