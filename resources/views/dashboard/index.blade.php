@extends('layouts.app')

@section('title', __('app.dashboard').' | '.__('app.app_name'))
@section('page-title', $selectedProject ? $selectedProject->name : __('app.dashboard'))
@section('page-subtitle', __('app.period').': '.$filters['from'].' - '.$filters['to'])

@php
    $overview = $data['overview'];
    $ownership = collect($overview['ownership']);
    $typeLabels = collect($data['equipmentTypes'])->pluck('name');
    $typeTotals = collect($data['equipmentTypes'])->pluck('total');
    $typeHours = collect($data['equipmentTypes'])->pluck('hours');
    $projectLabels = collect($data['projects'])->pluck('name');
    $projectHours = collect($data['projects'])->pluck('hours');
    $projectUtilization = collect($data['projects'])->pluck('utilization');
    $ownershipLabels = $ownership->pluck('label');
    $ownershipHours = $ownership->pluck('hours');
    $totalOwnershipHours = max(1, (float) $ownership->sum('hours'));
    $kpis = [
        ['label' => __('app.total_hours'), 'value' => number_format($overview['total_hours'], 1).' '.__('app.hours'), 'icon' => 'bi-clock', 'tone' => '#eaf2ff', 'color' => '#1f6feb', 'change' => $overview['changes']['total_hours']],
        ['label' => __('app.total_distance'), 'value' => number_format($overview['total_distance'], 1).' '.__('app.km'), 'icon' => 'bi-signpost-split', 'tone' => '#eaf8ef', 'color' => '#24b35b', 'change' => $overview['changes']['total_distance']],
        ['label' => __('app.avg_hours'), 'value' => number_format($overview['avg_hours_per_equipment'], 1).' '.__('app.hours'), 'icon' => 'bi-speedometer', 'tone' => '#f2ebff', 'color' => '#8b5cf6', 'change' => $overview['changes']['avg_hours_per_equipment']],
        ['label' => __('app.avg_distance'), 'value' => number_format($overview['avg_distance_per_equipment'], 1).' '.__('app.km'), 'icon' => 'bi-geo-alt', 'tone' => '#fff1e9', 'color' => '#f97316', 'change' => $overview['changes']['avg_distance_per_equipment']],
        ['label' => __('app.utilization'), 'value' => number_format($overview['utilization'], 1).' %', 'icon' => 'bi-graph-up-arrow', 'tone' => '#e8f8fb', 'color' => '#0ea5b7', 'change' => $overview['changes']['utilization']],
    ];
@endphp

@section('content')
    <form method="GET" action="{{ $selectedProject ? route('projects.dashboard', $selectedProject) : route('dashboard') }}" class="panel p-3 mb-4">
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
                <input type="date" name="from" value="{{ $filters['from'] }}" class="form-control">
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label">{{ __('app.to') }}</label>
                <input type="date" name="to" value="{{ $filters['to'] }}" class="form-control">
            </div>
            <div class="col-12 col-lg-auto">
                <button class="btn btn-primary btn-icon">
                    <i class="bi bi-funnel"></i><span>{{ __('app.filter') }}</span>
                </button>
            </div>
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

    <div class="row g-4">
        <div class="col-12 col-xl-5">
            <section class="panel p-3 h-100">
                <h2 class="h6 fw-bold mb-3">1) {{ __('app.work_hours_by_ownership') }}</h2>
                <div class="chart-box"><canvas id="ownershipBar"></canvas></div>
            </section>
        </div>

        <div class="col-12 col-xl-4">
            <section class="panel p-3 h-100">
                <h2 class="h6 fw-bold mb-3">2) {{ __('app.equipment_type_distribution') }}</h2>
                <div class="row g-3 align-items-center">
                    <div class="col-md-5"><div class="chart-box"><canvas id="typeDonut"></canvas></div></div>
                    <div class="col-md-7">
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>{{ __('app.type') }}</th>
                                        <th class="text-end">Say</th>
                                        <th class="text-end">{{ __('app.hours') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($data['equipmentTypes'] as $type)
                                        <tr>
                                            <td>{{ $type['name'] }}</td>
                                            <td class="text-end">{{ $type['total'] }}</td>
                                            <td class="text-end">{{ $type['hours'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <div class="col-12 col-xl-3">
            <section class="panel p-3 h-100">
                <h2 class="h6 fw-bold mb-3">3) {{ __('app.project_averages') }}</h2>
                <div class="vstack gap-3">
                    <div class="p-3 rounded border">
                        <div class="text-secondary small fw-semibold">{{ __('app.avg_hours') }}</div>
                        <div class="fs-3 fw-bold">{{ number_format($overview['avg_hours_per_equipment'], 1) }} <span class="fs-6">{{ __('app.hours') }}</span></div>
                        <span class="{{ $overview['changes']['avg_hours_per_equipment'] >= 0 ? 'change-up' : 'change-down' }}">
                            {{ $overview['changes']['avg_hours_per_equipment'] }}%
                        </span>
                    </div>
                    <div class="p-3 rounded border">
                        <div class="text-secondary small fw-semibold">{{ __('app.avg_distance') }}</div>
                        <div class="fs-3 fw-bold">{{ number_format($overview['avg_distance_per_equipment'], 1) }} <span class="fs-6">{{ __('app.km') }}</span></div>
                        <span class="{{ $overview['changes']['avg_distance_per_equipment'] >= 0 ? 'change-up' : 'change-down' }}">
                            {{ $overview['changes']['avg_distance_per_equipment'] }}%
                        </span>
                    </div>
                </div>
            </section>
        </div>

        <div class="col-12 col-xl-4">
            <section class="panel p-3 h-100">
                <h2 class="h6 fw-bold mb-3">4) {{ __('app.least_working') }}</h2>
                @include('dashboard.partials.ranking-table', ['rows' => $data['leastWorking']])
            </section>
        </div>

        <div class="col-12 col-xl-4">
            <section class="panel p-3 h-100">
                <h2 class="h6 fw-bold mb-3">5) {{ __('app.most_working') }}</h2>
                @include('dashboard.partials.ranking-table', ['rows' => $data['mostWorking']])
            </section>
        </div>

        <div class="col-12 col-xl-4">
            <section class="panel p-3 h-100">
                <h2 class="h6 fw-bold mb-3">6) {{ __('app.ownership_share') }}</h2>
                <div class="row align-items-center g-3">
                    <div class="col-md-7"><div class="chart-box"><canvas id="ownershipDonut"></canvas></div></div>
                    <div class="col-md-5">
                        <div class="vstack gap-2">
                            @foreach ($ownership as $row)
                                <div class="d-flex justify-content-between gap-2">
                                    <span class="fw-semibold">{{ $row['label'] }}</span>
                                    <span>{{ round(($row['hours'] / $totalOwnershipHours) * 100, 1) }}%</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <div class="col-12 col-xl-7">
            <section class="panel p-3 h-100">
                <h2 class="h6 fw-bold mb-3">7) {{ __('app.geofence_analysis') }}</h2>
                <div class="row g-3">
                    <div class="col-lg-7"><div id="fleetMap" class="map-box"></div></div>
                    <div class="col-lg-5">
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>{{ __('app.equipment') }}</th>
                                        <th>{{ __('app.status') }}</th>
                                        <th class="text-end">Dəq.</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($data['geofenceEvents'] as $event)
                                        <tr>
                                            <td>
                                                <div class="fw-semibold">{{ $event->equipment?->name }}</div>
                                                <div class="small text-secondary">{{ $event->project?->name }}</div>
                                            </td>
                                            <td><span class="badge text-bg-{{ $event->status === 'returned' ? 'success' : 'warning' }}">{{ __('app.'.$event->status) }}</span></td>
                                            <td class="text-end">{{ $event->outside_minutes }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="3" class="text-secondary">Məlumat yoxdur</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <div class="col-12 col-xl-5">
            <section class="panel p-3 h-100">
                <h2 class="h6 fw-bold mb-3">8) {{ __('app.utilization_trend') }}</h2>
                <div class="chart-box"><canvas id="utilizationLine"></canvas></div>
            </section>
        </div>

        <div class="col-12">
            <section class="panel p-3">
                <h2 class="h6 fw-bold mb-3">{{ __('app.projects') }}</h2>
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
const typeLabels = @json($typeLabels);
const typeTotals = @json($typeTotals);
const projectLabels = @json($projectLabels);
const projectHours = @json($projectHours);
const projectUtilization = @json($projectUtilization);
const utilizationTrend = @json($data['utilizationTrend']);
const mapData = @json($data['mapData']);

new Chart(document.getElementById('ownershipBar'), {
    type: 'bar',
    data: {
        labels: ownershipLabels,
        datasets: [{ label: '{{ __('app.hours') }}', data: ownershipHours, backgroundColor: ['#1f6feb', '#24b35b'] }]
    },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
});

new Chart(document.getElementById('typeDonut'), {
    type: 'doughnut',
    data: { labels: typeLabels, datasets: [{ data: typeTotals, backgroundColor: chartColors }] },
    options: { responsive: true, maintainAspectRatio: false, cutout: '62%' }
});

new Chart(document.getElementById('ownershipDonut'), {
    type: 'doughnut',
    data: { labels: ownershipLabels, datasets: [{ data: ownershipHours, backgroundColor: ['#1f6feb', '#24b35b'] }] },
    options: { responsive: true, maintainAspectRatio: false, cutout: '64%' }
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
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap'
}).addTo(map);

const bounds = [];
mapData.geofences.forEach(zone => {
    if (!zone.geometry) return;
    const layer = L.geoJSON(zone.geometry, { style: { color: '#1f6feb', weight: 2, fillOpacity: .12 } }).addTo(map);
    layer.bindPopup(zone.name);
    layer.getBounds && bounds.push(layer.getBounds());
});
mapData.equipment.forEach(item => {
    if (!item.position || !item.position.lat || !item.position.lng) return;
    const marker = L.circleMarker([item.position.lat, item.position.lng], {
        radius: 7,
        color: item.ownership === 'ICARE' ? '#24b35b' : '#1f6feb',
        fillOpacity: .9
    }).addTo(map);
    marker.bindPopup(`<strong>${item.name}</strong><br>${item.type || ''}<br>${item.project || ''}`);
    bounds.push(marker.getLatLng());
});
if (bounds.length > 0) {
    const group = L.featureGroup(bounds.map(item => item instanceof L.LatLng ? L.marker(item) : L.rectangle(item)));
    map.fitBounds(group.getBounds().pad(.18));
}
</script>
@endpush
