@php
    $metric = $metric ?? 'engine_hours';
    $unit = $dashboard['unit'] ?? ($metric === 'mileage' ? 'km' : 'saat');
    $chartId = $chartId ?? 'dailyAverageChart';
    $metricLabel = $metric === 'mileage' ? 'Orta yürüş' : 'Orta motosaat';
    $infoText = $metric === 'mileage'
        ? 'Hesablama yalnız Dump Truck texnikaları üçün aparılır.'
        : 'Hesablama yalnız Excavator, Road Grader, Loader, Backhoe Loader və Road Roller texnikaları üzrə aparılır.';
    $icon = $metric === 'mileage' ? 'bi-signpost-split' : 'bi-clock-history';
    $tone = $metric === 'mileage' ? '#eaf2ff' : '#eaf8ef';
    $toneColor = $metric === 'mileage' ? '#1f6feb' : '#24b35b';
    $kpis = collect($dashboard['kpis'] ?? []);
    $tableRows = collect($dashboard['table_rows'] ?? []);
    $dayCards = collect($dashboard['day_cards'] ?? []);
    $formatValue = function ($value) use ($unit, $metric) {
        if ($value === null) {
            return '—';
        }

        return ($metric === 'mileage' ? number_format((float) $value, 0, '.', ' ') : number_format((float) $value, 1, '.', ' ')).' '.$unit;
    };
@endphp

<section class="panel p-3 dashboard-card dashboard-average-insight-card d-flex flex-column">
    <div class="dashboard-panel-header dashboard-average-insight-header d-flex flex-wrap align-items-start justify-content-between gap-3 mb-3">
        <div class="min-w-0">
            <h2 class="h5 fw-bold mb-1 dashboard-card-title-text">{{ $title }}</h2>
            <input type="text" class="form-control form-control-sm dashboard-title-input mt-1 d-none" value="{{ $title }}" maxlength="120" aria-label="Dashboard başlığı">
            <div class="small text-secondary">{{ $subtitle }}</div>
        </div>
        <div class="d-flex flex-wrap align-items-center gap-2 flex-shrink-0">
            <span class="dashboard-average-filter-pill"><i class="bi bi-calendar3"></i>{{ $filters['from'] }} - {{ $filters['to'] }}</span>
            <span class="dashboard-average-filter-pill"><i class="bi bi-filter"></i>{{ $selectedProject?->name ?? 'Hamısı' }}</span>
            <span class="dashboard-average-filter-pill">{{ $filters['ownership_type'] ? ($filters['ownership_type'] === \App\Models\Equipment::OWNERSHIP_NWC ? 'NWC' : 'İCARƏ') : 'Hamısı' }}</span>
            <a href="{{ $exportUrl }}" class="btn btn-sm dashboard-export-button" title="Excel" aria-label="Excel">
                <i class="bi bi-download"></i>
            </a>
            <button type="button" class="btn btn-sm dashboard-visibility-toggle" title="Bloku gizlət" aria-label="Bloku gizlət">
                <i class="bi bi-eye-slash"></i>
            </button>
            <button type="button" class="btn btn-sm dashboard-drag-handle" title="Bloku daşı" aria-label="Bloku daşı">
                <i class="bi bi-grip-vertical"></i>
            </button>
        </div>
    </div>

    <div class="dashboard-average-info mb-3">
        <span class="dashboard-average-info-icon" style="background: {{ $tone }}; color: {{ $toneColor }};"><i class="bi {{ $icon }}"></i></span>
        <span>{{ $infoText }}</span>
    </div>

    <div class="dashboard-average-main mb-3">
        <div class="dashboard-chart-mode-tabs mb-3" data-chart-mode-target="{{ $chartId }}">
            <button type="button" class="btn btn-sm btn-primary" data-chart-mode="line">Line</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-chart-mode="bar">Bar</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-chart-mode="area">Area</button>
        </div>
        <div class="dashboard-average-chart dashboard-average-chart--large">
            <canvas id="{{ $chartId }}"></canvas>
        </div>
    </div>

    <div class="dashboard-average-kpis mb-3">
        @foreach ([\App\Models\Equipment::OWNERSHIP_NWC => 'NWC', \App\Models\Equipment::OWNERSHIP_ICARE => 'İCARƏ', 'TOTAL' => 'Ümumi'] as $key => $label)
            @php
                $row = $kpis->get($key, ['average' => null, 'valid_units_count' => 0, 'missing_units_count' => 0]);
                $color = $key === \App\Models\Equipment::OWNERSHIP_ICARE ? '#1f6feb' : ($key === 'TOTAL' ? '#8b5cf6' : '#24b35b');
            @endphp
            <div class="dashboard-average-kpi">
                <span class="dashboard-average-kpi-dot" style="background: {{ $color }}"></span>
                <div class="min-w-0">
                    <div class="dashboard-average-kpi-label">{{ $label }}</div>
                    <div class="dashboard-average-kpi-value" style="color: {{ $color }}">{{ $formatValue($row['average'] ?? null) }}</div>
                </div>
                <div class="dashboard-average-kpi-meta">
                    <span>Texnika</span>
                    <strong>{{ number_format((int) ($row['valid_units_count'] ?? 0), 0, '.', ' ') }}</strong>
                    @if ((int) ($row['missing_units_count'] ?? 0) > 0)
                        <small>{{ number_format((int) ($row['missing_units_count'] ?? 0), 0, '.', ' ') }} məlumatsız</small>
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    <div class="dashboard-average-daily-table mb-3">
        <div class="fw-bold mb-2">Cədvəl (günlük orta)</div>
        <div class="dashboard-scroll-table">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>Tarix</th>
                        <th class="text-end">NWC</th>
                        <th class="text-end">İCARƏ</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($tableRows as $row)
                        <tr
                            class="dashboard-drilldown-trigger"
                            role="button"
                            tabindex="0"
                            data-drilldown-title="{{ $metricLabel }} — {{ $row['label'] }}"
                            data-drilldown-date-from="{{ $row['date'] }}"
                            data-drilldown-date-to="{{ $row['date'] }}"
                            data-drilldown-metric="{{ $metric }}"
                        >
                            <td>{{ $row['label'] }}</td>
                            <td class="text-end">{{ $formatValue($row['nwc']) }}</td>
                            <td class="text-end">{{ $formatValue($row['icare']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="text-secondary">{{ __('app.no_data') }}</td></tr>
                    @endforelse
                    <tr class="fw-bold">
                        <td>Orta</td>
                        <td class="text-end">{{ $formatValue(($kpis->get(\App\Models\Equipment::OWNERSHIP_NWC, [])['average'] ?? null)) }}</td>
                        <td class="text-end">{{ $formatValue(($kpis->get(\App\Models\Equipment::OWNERSHIP_ICARE, [])['average'] ?? null)) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="dashboard-average-day-strip">
        <div class="small fw-bold mb-2">KPI kartları (günlük)</div>
        <div class="dashboard-average-day-cards">
            @foreach ($dayCards as $row)
                <button
                    type="button"
                    class="dashboard-average-day-card dashboard-drilldown-trigger"
                    data-drilldown-title="{{ $metricLabel }} — {{ $row['label'] }}"
                    data-drilldown-date-from="{{ $row['date'] }}"
                    data-drilldown-date-to="{{ $row['date'] }}"
                    data-drilldown-metric="{{ $metric }}"
                >
                    <span class="dashboard-average-day-date">{{ $row['label'] }}</span>
                    <span><strong>NWC</strong> {{ $formatValue($row['nwc']) }}</span>
                    <span><strong>İCARƏ</strong> {{ $formatValue($row['icare']) }}</span>
                    <i class="bi {{ $row['trend'] === 'up' ? 'bi-graph-up-arrow text-success' : ($row['trend'] === 'down' ? 'bi-graph-down-arrow text-danger' : 'bi-dash-lg text-secondary') }}"></i>
                </button>
            @endforeach
        </div>
    </div>
</section>
