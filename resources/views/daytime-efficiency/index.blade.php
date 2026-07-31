@extends('layouts.app')

@section('title', 'Effektivlik gündüz')
@section('page-title', 'Effektivlik gündüz')
@section('page-subtitle', 'Mənbə: Qrup report daytime (api) | 08:00–17:59 | Asia/Baku')

@php
    $summaries = $data['summaries'];
    $labels = $data['labels'];
    $colors = $data['colors'];
    $facts = $data['facts'];
    $categoryOrder = array_keys(config('daytime_efficiency.categories', []));
    $queryWithoutCategory = request()->except(['category', 'ownership_type', 'page']);
    $isRange = $filters['date_from'] !== $filters['date_to'];
@endphp

@push('styles')
<style>
    .day-efficiency-filterbar {
        display: grid;
        grid-template-columns: minmax(150px, 1.1fr) repeat(2, minmax(150px, .8fr)) minmax(170px, 1fr) minmax(170px, 1fr) auto;
        gap: 12px;
        align-items: end;
    }
    .day-efficiency-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
    .day-efficiency-card { min-width: 0; min-height: 410px; overflow: hidden; }
    .day-efficiency-content { display: grid; grid-template-columns: minmax(230px, .8fr) minmax(330px, 1.2fr); gap: 20px; align-items: center; }
    .day-efficiency-content > * { min-width: 0; }
    .day-efficiency-chart { position: relative; min-height: 270px; }
    .day-efficiency-chart canvas { width: 100% !important; height: 270px !important; }
    .day-efficiency-summary { width: 100%; table-layout: fixed; }
    .day-efficiency-summary th:last-child,
    .day-efficiency-summary td:last-child { width: 72px; }
    .day-efficiency-summary td:first-child { overflow-wrap: anywhere; }
    .day-efficiency-source {
        display: inline-flex; align-items: center; gap: 6px; padding: 5px 9px; border-radius: 6px;
        background: color-mix(in srgb, var(--fleet-blue) 10%, transparent); color: var(--fleet-blue);
        font-size: .76rem; font-weight: 700;
    }
    .day-efficiency-category { cursor: pointer; }
    .day-efficiency-category:hover td { background: color-mix(in srgb, var(--fleet-blue) 7%, transparent); }
    .day-efficiency-category.active td { background: color-mix(in srgb, var(--fleet-blue) 12%, transparent); }
    .day-efficiency-dot { display: inline-block; width: 9px; height: 9px; border-radius: 50%; margin-right: 9px; }
    .day-efficiency-total td { border-top: 2px solid var(--fleet-line); font-weight: 800; }
    .day-efficiency-report { overflow: hidden; }
    .day-efficiency-report-scroll { overflow: auto; max-height: 610px; }
    .day-efficiency-report table { min-width: 1550px; margin: 0; }
    .day-efficiency-report thead { position: sticky; top: 0; z-index: 2; background: var(--fleet-card); }
    .day-efficiency-report th { white-space: nowrap; font-size: .72rem; text-transform: uppercase; color: var(--fleet-muted); }
    .day-efficiency-report td { vertical-align: middle; font-size: .84rem; }
    .day-efficiency-hours { font-variant-numeric: tabular-nums; font-weight: 800; }
    .day-efficiency-status { display: inline-flex; padding: 3px 7px; border-radius: 5px; background: var(--fleet-bg); font-size: .72rem; font-weight: 700; }
    .day-efficiency-status--error { color: #dc3545; }
    .day-efficiency-empty { min-height: 220px; display: grid; place-items: center; color: var(--fleet-muted); }
    @media (max-width: 1500px) {
        .day-efficiency-content { grid-template-columns: 1fr; }
        .day-efficiency-chart { min-height: 230px; }
        .day-efficiency-chart canvas { height: 230px !important; }
    }
    @media (max-width: 1250px) {
        .day-efficiency-filterbar { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    }
    @media (max-width: 900px) {
        .day-efficiency-grid { grid-template-columns: 1fr; }
        .day-efficiency-filterbar { grid-template-columns: 1fr 1fr; }
    }
    @media (max-width: 560px) { .day-efficiency-filterbar { grid-template-columns: 1fr; } }
</style>
@endpush

@section('content')
<div class="container-fluid px-0">
    <form method="GET" action="{{ route('daytime-efficiency.index') }}" class="panel p-3 mb-3 day-efficiency-filterbar">
        <div>
            <label class="form-label small fw-semibold">Layihə</label>
            <select class="form-select" name="project_id">
                <option value="">Bütün layihələr</option>
                @foreach ($projects as $project)
                    <option value="{{ $project->id }}" @selected((string) ($filters['project_id'] ?? '') === (string) $project->id)>{{ $project->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="form-label small fw-semibold">Başlanğıc</label>
            <input class="form-control" type="date" name="date_from" value="{{ $filters['date_from'] }}">
        </div>
        <div>
            <label class="form-label small fw-semibold">Son</label>
            <input class="form-control" type="date" name="date_to" value="{{ $filters['date_to'] }}">
        </div>
        <div>
            <label class="form-label small fw-semibold">Texnika növü</label>
            <select class="form-select" name="equipment_type_id">
                <option value="">Bütün icazə verilən növlər</option>
                @foreach ($equipmentTypes as $type)
                    <option value="{{ $type->id }}" @selected((string) ($filters['equipment_type_id'] ?? '') === (string) $type->id)>{{ $type->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="form-label small fw-semibold">Mənsubiyyət</label>
            <select class="form-select" name="ownership_type">
                <option value="">NWC + İCARƏ</option>
                <option value="nwc" @selected(($filters['ownership_type'] ?? '') === 'nwc')>NWC</option>
                <option value="icare" @selected(($filters['ownership_type'] ?? '') === 'icare')>İCARƏ</option>
            </select>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-primary flex-grow-1" type="submit"><i data-lucide="filter"></i> Filtrlə</button>
            <a class="btn btn-outline-secondary" href="{{ route('daytime-efficiency.index') }}" title="Filtrləri sıfırla"><i data-lucide="rotate-ccw"></i></a>
        </div>
    </form>

    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <span class="day-efficiency-source"><i data-lucide="database"></i> Qrup report daytime (api)</span>
        <div class="d-flex align-items-center gap-2 small text-secondary">
            <span>Son yenilənmə: {{ $data['last_updated_at'] ? \Carbon\CarbonImmutable::parse($data['last_updated_at'])->timezone(config('daytime_efficiency.timezone'))->format('Y-m-d H:i:s') : '—' }}</span>
            <a class="btn btn-sm btn-outline-secondary" href="{{ route('daytime-efficiency.export', request()->query()) }}"><i data-lucide="download"></i> Excel</a>
        </div>
    </div>

    <div class="day-efficiency-grid mb-3">
        @foreach (['nwc' => 'NWC', 'icare' => 'İCARƏ'] as $ownership => $ownershipLabel)
            @php $summary = $summaries[$ownership]; @endphp
            <section class="panel p-3 day-efficiency-card">
                <div class="d-flex align-items-start justify-content-between gap-3 mb-3">
                    <div>
                        <h2 class="h5 fw-bold mb-1">Effektivlik gündüz: {{ $ownershipLabel }} üzrə</h2>
                        <div class="small text-secondary">{{ $isRange ? 'Texnika-gün sayı' : 'Unikal texnika' }} · Engine hours Wialon-dan birbaşa</div>
                    </div>
                    <strong>{{ number_format($summary['total'], 0, '.', ' ') }}</strong>
                </div>
                <div class="day-efficiency-content">
                    <div class="day-efficiency-chart"><canvas id="dayEfficiency{{ ucfirst($ownership) }}"></canvas></div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0 day-efficiency-summary">
                            <thead><tr><th>Status</th><th class="text-end">Say</th></tr></thead>
                            <tbody>
                                @foreach ($categoryOrder as $category)
                                    <tr class="day-efficiency-category {{ ($filters['category'] ?? '') === $category && ($filters['ownership_type'] ?? '') === $ownership ? 'active' : '' }}"
                                        data-category-url="{{ route('daytime-efficiency.index', [...$queryWithoutCategory, 'category' => $category, 'ownership_type' => $ownership]) }}">
                                        <td><span class="day-efficiency-dot" style="background: {{ $colors[$category] }}"></span>{{ $labels[$category] }}</td>
                                        <td class="text-end fw-bold">{{ number_format($summary[$category], 0, '.', ' ') }}</td>
                                    </tr>
                                @endforeach
                                <tr class="day-efficiency-total"><td>Cəmi</td><td class="text-end">{{ number_format($summary['total'], 0, '.', ' ') }}</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        @endforeach
    </div>

    <section class="panel day-efficiency-report">
        <div class="p-3 border-bottom d-flex flex-wrap align-items-end justify-content-between gap-3">
            <div>
                <h2 class="h5 fw-bold mb-1">Wialon gündüz hesabatı</h2>
                <div class="small text-secondary">
                    {{ $filters['date_from'] }} – {{ $filters['date_to'] }}
                    @if ($filters['category'] ?? null) · {{ $labels[$filters['category']] }} @endif
                </div>
            </div>
            <form method="GET" action="{{ route('daytime-efficiency.index') }}" class="d-flex gap-2">
                @foreach (request()->except(['search', 'page']) as $key => $value)
                    @if (! is_array($value))<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif
                @endforeach
                <input class="form-control" type="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Texnika axtarışı...">
                <button class="btn btn-outline-primary" title="Axtar"><i data-lucide="search"></i></button>
            </form>
        </div>

        @if ($facts->count())
            <div class="day-efficiency-report-scroll">
                <table class="table table-hover align-middle">
                    <thead><tr>
                        <th>#</th><th>Tarix</th><th>Qruplaşdırma</th><th>Model</th><th>İstehsalçı</th><th>Engine hours</th>
                        <th>Equipment Type</th><th>Vendor</th><th>Year</th><th>Idling</th><th>Mileage (adjusted)</th>
                        <th>Beginning</th><th>End</th><th>Layihə</th><th>Status</th>
                    </tr></thead>
                    <tbody>
                        @foreach ($facts as $fact)
                            <tr>
                                <td>{{ $facts->firstItem() + $loop->index }}</td>
                                <td>{{ $fact->fact_date->format('Y-m-d') }}</td>
                                <td class="fw-semibold">{{ $fact->unit_name_snapshot }}</td>
                                <td>{{ $fact->model_name ?: '—' }}</td>
                                <td>{{ $fact->manufacturer_name ?: '—' }}</td>
                                <td class="day-efficiency-hours">{{ $fact->raw_engine_hours !== null && $fact->raw_engine_hours !== '' ? $fact->raw_engine_hours : '—' }}</td>
                                <td>{{ $fact->wialon_equipment_type ?: $fact->equipment_type_canonical }}</td>
                                <td>{{ $fact->wialon_vendor ?: strtoupper($fact->ownership_type) }}</td>
                                <td>{{ $fact->year ?: '—' }}</td>
                                <td>{{ $fact->raw_idling !== null && $fact->raw_idling !== '' ? $fact->raw_idling : '—' }}</td>
                                <td>{{ $fact->raw_mileage !== null && $fact->raw_mileage !== '' ? $fact->raw_mileage : '—' }}</td>
                                <td>{{ $fact->beginning_at?->timezone(config('daytime_efficiency.timezone'))->format('Y-m-d H:i:s') ?: '—' }}</td>
                                <td>{{ $fact->end_at?->timezone(config('daytime_efficiency.timezone'))->format('Y-m-d H:i:s') ?: '—' }}</td>
                                <td>{{ $fact->project_name_snapshot ?: 'Layihəsiz' }}</td>
                                <td><span class="day-efficiency-status {{ in_array($fact->detail_status, ['parse_error', 'anomaly']) ? 'day-efficiency-status--error' : '' }}">{{ $data['detail_labels'][$fact->detail_status] ?? $fact->detail_status }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="p-3 border-top">{{ $facts->links() }}</div>
        @else
            <div class="day-efficiency-empty">Seçilmiş dövr üzrə gündüz hesabatı məlumatı yoxdur.</div>
        @endif
    </section>
</div>
@endsection

@push('scripts')
<script>
(() => {
    const labels = @json(array_values($labels));
    const colors = @json(array_values($colors));
    const categoryKeys = @json($categoryOrder);
    const summaries = @json($summaries);
    const baseQuery = @json($queryWithoutCategory);
    const routeUrl = @json(route('daytime-efficiency.index'));
    const ownershipLabels = { nwc: 'NWC', icare: 'İCARƏ' };

    const centerText = {
        id: 'dayEfficiencyCenterText',
        afterDraw(chart, args, options) {
            const meta = chart.getDatasetMeta(0);
            if (!meta.data.length) return;
            const {x, y} = meta.data[0];
            const ctx = chart.ctx;
            ctx.save();
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillStyle = getComputedStyle(document.documentElement).getPropertyValue('--fleet-ink').trim() || '#111827';
            ctx.font = '800 28px system-ui';
            ctx.fillText(options.total, x, y - 7);
            ctx.font = '600 12px system-ui';
            ctx.fillStyle = getComputedStyle(document.documentElement).getPropertyValue('--fleet-muted').trim() || '#64748b';
            ctx.fillText(options.label, x, y + 18);
            ctx.restore();
        }
    };

    const openCategory = (ownership, category) => {
        const params = new URLSearchParams(baseQuery);
        params.set('ownership_type', ownership);
        params.set('category', category);
        window.location.assign(`${routeUrl}?${params.toString()}`);
    };

    Object.entries({nwc: 'dayEfficiencyNwc', icare: 'dayEfficiencyIcare'}).forEach(([ownership, id]) => {
        const summary = summaries[ownership];
        const canvas = document.getElementById(id);
        if (!canvas) return;
        new Chart(canvas, {
            type: 'doughnut',
            data: { labels, datasets: [{ data: categoryKeys.map(key => summary[key] || 0), backgroundColor: colors, borderWidth: 2, borderColor: getComputedStyle(document.documentElement).getPropertyValue('--fleet-card').trim() || '#fff' }] },
            options: {
                responsive: true, maintainAspectRatio: false, cutout: '68%',
                plugins: { legend: { display: false }, tooltip: { callbacks: { label: context => `${context.label}: ${context.raw}` } }, dayEfficiencyCenterText: { total: summary.total, label: ownershipLabels[ownership] } },
                onClick(event, elements) { if (elements.length) openCategory(ownership, categoryKeys[elements[0].index]); }
            },
            plugins: [centerText]
        });
    });

    document.querySelectorAll('[data-category-url]').forEach(row => row.addEventListener('click', () => window.location.assign(row.dataset.categoryUrl)));
})();
</script>
@endpush
