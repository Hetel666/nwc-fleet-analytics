@php
    $categoryKeys = $categoryLabels->keys();
    $total = (int) ($summary['total'] ?? 0);
    $missingData = (int) ($summary['missing_data'] ?? 0);
    $ownershipColor = $ownershipCode === 'NWC' ? '#24b35b' : '#1f6feb';
    $title = $title ?? null;
@endphp

<section class="panel p-3 dashboard-card dashboard-work-status-card d-flex flex-column">
    <div class="dashboard-panel-header d-flex align-items-start justify-content-between gap-2 mb-3">
        <div class="min-w-0">
            <h2 class="h5 dashboard-work-status-title fw-bold mb-0 dashboard-card-title-text">
                @if ($title)
                    {{ $title }}
                @else
                    Project üzrə:
                    <span style="color: {{ $ownershipColor }}">{{ $ownershipLabel }}</span>
                @endif
            </h2>
            <input type="text" class="form-control form-control-sm dashboard-title-input mt-1 d-none" value="{{ $title ?: 'Project üzrə: '.$ownershipLabel }}" maxlength="120" aria-label="Dashboard başlığı">
        </div>
        <div class="d-flex align-items-center gap-1 flex-shrink-0">
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

    @if ($total > 0)
        <div class="dashboard-work-status-layout flex-grow-1">
            <div class="dashboard-work-status-chart">
                <canvas id="{{ $chartId }}"></canvas>
            </div>
            <div class="dashboard-work-status-table">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>{{ __('app.status') }}</th>
                            <th class="text-end">Say</th>
                            <th class="text-end">Faiz</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($categoryKeys as $key)
                            @php
                                $count = (int) ($summary[$key] ?? 0);
                                $percent = $total > 0 ? round(($count / $total) * 100, 1) : 0;
                                $drilldownOwnership = $ownershipCode === 'ICARE' ? 'icare' : 'nwc';
                            @endphp
                            <tr
                                class="dashboard-drilldown-trigger"
                                role="button"
                                tabindex="0"
                                data-drilldown-title="{{ ($title ?: 'Project üzrə: '.$ownershipLabel).' — '.$categoryLabels[$key] }}"
                                data-drilldown-ownership="{{ $drilldownOwnership }}"
                                data-drilldown-work-category="{{ $key }}"
                            >
                                <td>
                                    <span class="dashboard-work-status-label">
                                        <span class="dashboard-color-dot" style="background: {{ $categoryColors[$key] }}"></span>
                                        <span class="dashboard-work-status-label-text">{{ $categoryLabels[$key] }}</span>
                                    </span>
                                </td>
                                <td class="text-end">{{ number_format($count, 0, '.', ' ') }}</td>
                                <td class="text-end">{{ number_format($percent, 1, '.', ' ') }}%</td>
                            </tr>
                        @endforeach
                        <tr class="dashboard-work-status-total">
                            <td>Cəmi</td>
                            <td class="text-end">{{ number_format($total, 0, '.', ' ') }}</td>
                            <td class="text-end">100%</td>
                        </tr>
                    </tbody>
                </table>
                @if ($missingData > 0)
                    <div class="small text-secondary mt-2">1 saatdan az işləyən kateqoriyasına məlumatı olmayan {{ number_format($missingData, 0, '.', ' ') }} texnika daxildir.</div>
                @endif
            </div>
        </div>
        <div class="dashboard-work-status-note small pt-3 mt-3 d-flex align-items-center gap-2">
            <i class="bi bi-info-circle"></i>
            <span>Hesablamalar Asia/Baku vaxtına əsasən aparılır. Göstəricilər texnika-gün qeydləri üzrə hesablanıb; overtime gündüz statusu ilə üst-üstə düşə bilər.</span>
        </div>
        <div class="dashboard-work-status-legend mt-3">
            @foreach ($categoryKeys as $key)
                <div class="dashboard-work-status-legend-item">
                    <span class="dashboard-color-dot mt-1" style="background: {{ $categoryColors[$key] }}"></span>
                    <span>
                        {{ $categoryLabels[$key] }}
                        <small>({{ $categoryRanges[$key] }})</small>
                    </span>
                </div>
            @endforeach
        </div>
    @else
        <div class="dashboard-empty flex-grow-1">{{ __('app.no_data') }}</div>
        @if ($missingData > 0)
            <div class="small text-secondary mt-2">1 saatdan az işləyən kateqoriyasına məlumatı olmayan {{ number_format($missingData, 0, '.', ' ') }} texnika daxildir.</div>
        @endif
    @endif
</section>
