@php
    $metric = $metric ?? 'engine_hours';
    $unit = $dashboard['unit'] ?? ($metric === 'mileage' ? 'km' : 'saat');
    $metricLabel = $metric === 'mileage' ? 'Orta yürüş' : 'Orta motosaat';
    $subtitle = $metric === 'mileage'
        ? 'Texnika növləri üzrə bir maşına düşən orta gündəlik yürüş'
        : 'Texnika növləri üzrə bir maşına düşən orta gündəlik motosaat';
    $formulaText = $metric === 'mileage'
        ? 'Ümumi yürüş / texnika sayı / seçilmiş gün sayı'
        : 'Ümumi motosaat / texnika sayı / seçilmiş gün sayı';
    $infoText = $metric === 'mileage'
        ? 'Hazırkı biznes qaydasına görə yürüş göstəricisi yalnız Dump Truck üzrə hesablanır.'
        : 'Hesablama Bulldozer, Excavator, Loader, Backhoe Loader, Road Grader və Road Roller üzrə aparılır.';
    $icon = $metric === 'mileage' ? 'bi-signpost-split' : 'bi-clock-history';
    $tone = $metric === 'mileage' ? '#eaf2ff' : '#eaf8ef';
    $toneColor = $metric === 'mileage' ? '#1f6feb' : '#24b35b';
    $typeRows = collect($dashboard['type_rows'] ?? []);
    $maxAverage = max(0.1, (float) $typeRows
        ->flatMap(fn (array $row): array => [
            $row['nwc']['average_per_unit_per_day'] ?? null,
            $row['icare']['average_per_unit_per_day'] ?? null,
        ])
        ->filter(fn ($value): bool => $value !== null)
        ->max());
    $formatValue = function ($value) use ($unit, $metric) {
        if ($value === null) {
            return '—';
        }

        return ($metric === 'mileage'
            ? number_format((float) $value, 0, '.', ' ')
            : number_format((float) $value, 2, '.', ' ')).' '.$unit;
    };
    $ownershipSeries = [
        'nwc' => ['label' => 'NWC', 'ownership' => 'nwc', 'color' => '#24b35b'],
        'icare' => ['label' => 'İCARƏ', 'ownership' => 'icare', 'color' => '#1f6feb'],
    ];
@endphp

<section class="panel p-3 dashboard-card dashboard-average-type-card d-flex flex-column">
    <div class="dashboard-panel-header dashboard-average-insight-header d-flex flex-wrap align-items-start justify-content-between gap-3 mb-3">
        <div class="min-w-0">
            <div class="d-flex align-items-center gap-2">
                <h2 class="h5 fw-bold mb-1 dashboard-card-title-text">{{ $title }}</h2>
                <span class="dashboard-average-formula-help" title="{{ $formulaText }}">
                    <i class="bi bi-info-circle"></i>
                </span>
            </div>
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

    <div class="dashboard-average-type-panel">
        <div class="dashboard-average-type-head">
            <span>Texnika növü</span>
            <span>NWC</span>
            <span>İCARƏ</span>
        </div>

        @forelse ($typeRows as $row)
            <div class="dashboard-average-type-row">
                <div class="dashboard-average-type-name">
                    <strong>{{ $row['vehicle_type'] }}</strong>
                    <span>{{ $unit }} / texnika / gün</span>
                </div>

                @foreach ($ownershipSeries as $key => $series)
                    @php
                        $summary = $row[$key] ?? null;
                        $average = $summary['average_per_unit_per_day'] ?? null;
                        $total = $summary['total_value'] ?? 0;
                        $unitsCount = (int) ($summary['units_count'] ?? 0);
                        $daysCount = (int) ($summary['days_count'] ?? ($dashboard['days_count'] ?? 1));
                        $missingCount = (int) ($summary['units_without_data'] ?? 0);
                        $hasUnits = $unitsCount > 0;
                        $width = $average === null ? 0 : min(100, max($average > 0 ? 5 : 0, round(((float) $average / $maxAverage) * 100)));
                        $tooltip = $row['vehicle_type'].' — '.$series['label']
                            .' | Ümumi: '.$formatValue($total)
                            .' | Texnika: '.$unitsCount
                            .' | Gün: '.$daysCount
                            .' | Orta: '.$formatValue($average);
                    @endphp

                    @if ($hasUnits)
                        <button
                            type="button"
                            class="dashboard-average-type-cell dashboard-drilldown-trigger"
                            data-drilldown-title="{{ $row['vehicle_type'] }} — {{ $series['label'] }} — {{ $metricLabel }}"
                            data-drilldown-metric="{{ $metric }}"
                            data-drilldown-ownership="{{ $series['ownership'] }}"
                            data-drilldown-vehicle-types="{{ $row['type_slug'] }}"
                            data-drilldown-sort="date"
                            title="{{ $tooltip }}"
                        >
                            <span class="dashboard-average-type-track">
                                <span class="dashboard-average-type-fill" style="width: {{ $width }}%; background: {{ $series['color'] }}"></span>
                            </span>
                            <span class="dashboard-average-type-value">{{ $formatValue($average) }}</span>
                            <span class="dashboard-average-type-meta">
                                Texnika: {{ number_format($unitsCount, 0, '.', ' ') }}
                                @if ($missingCount > 0)
                                    · Məlumatsız: {{ number_format($missingCount, 0, '.', ' ') }}
                                @endif
                            </span>
                        </button>
                    @else
                        <div class="dashboard-average-type-cell dashboard-average-type-cell--empty">
                            <span class="dashboard-average-type-track"></span>
                            <span class="dashboard-average-type-value">—</span>
                            <span class="dashboard-average-type-meta">Texnika yoxdur</span>
                        </div>
                    @endif
                @endforeach
            </div>
        @empty
            <div class="dashboard-empty">{{ __('app.no_data') }}</div>
        @endforelse
    </div>
</section>
