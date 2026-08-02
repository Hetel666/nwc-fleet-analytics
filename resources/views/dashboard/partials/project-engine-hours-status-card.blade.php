@php
    $primaryCategoryKeys = collect($visibleStatuses ?? [
        '0_1',
        '1_7',
        '7_10',
        'over_10',
        'no_data',
    ])->filter(fn (string $key): bool => $categoryLabels->has($key))->values();
    $additionalCategoryKeys = collect();
    $fullTotal = (int) ($summary['total'] ?? 0);
    $total = (int) $primaryCategoryKeys->sum(fn (string $key): int => (int) ($summary[$key] ?? 0));
    $hasRows = $total + (int) $additionalCategoryKeys->sum(fn (string $key): int => (int) ($summary[$key] ?? 0)) > 0;
    $ownershipColor = $ownershipCode === 'NWC' ? '#24b35b' : '#1f6feb';
    $title = $title ?? null;
    $drilldownProjectId = $drilldownProjectId ?? ($filters['project_id'] ?? null);
    $drilldownDateFrom = $drilldownDateFrom ?? ($filters['from'] ?? null);
    $drilldownDateTo = $drilldownDateTo ?? ($filters['to'] ?? null);
    $drilldownOwnership = $ownershipCode === 'ICARE' ? 'icare' : 'nwc';
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

    @if ($hasRows)
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
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($primaryCategoryKeys as $key)
                            @php
                                $count = (int) ($summary[$key] ?? 0);
                            @endphp
                            <tr
                                class="dashboard-drilldown-trigger"
                                role="button"
                                tabindex="0"
                                data-drilldown-title="{{ ($title ?: 'Project üzrə: '.$ownershipLabel).' — '.$categoryLabels[$key] }}"
                                data-drilldown-ownership="{{ $drilldownOwnership }}"
                                data-drilldown-project-id="{{ $drilldownProjectId }}"
                                data-drilldown-view="projects"
                                data-drilldown-mode="efficiency_projects"
                                data-drilldown-work-category="{{ $key }}"
                                data-drilldown-status="{{ $key }}"
                                data-drilldown-date-from="{{ $drilldownDateFrom }}"
                                data-drilldown-date-to="{{ $drilldownDateTo }}"
                            >
                                <td>
                                    <span class="dashboard-work-status-label">
                                        <span class="dashboard-color-dot" style="background: {{ $categoryColors[$key] }}"></span>
                                        <span class="dashboard-work-status-label-text">{{ $categoryLabels[$key] }}</span>
                                    </span>
                                </td>
                                <td class="text-end">{{ number_format($count, 0, '.', ' ') }}</td>
                            </tr>
                        @endforeach
                        <tr class="dashboard-work-status-total">
                            <td>Cəmi</td>
                            <td class="text-end">{{ number_format($total, 0, '.', ' ') }}</td>
                        </tr>
                        @if ($fullTotal !== $total)
                            <tr class="dashboard-work-status-note">
                                <td colspan="2">Gosterilir: {{ number_format($total, 0, '.', ' ') }} / {{ number_format($fullTotal, 0, '.', ' ') }}</td>
                            </tr>
                        @endif
                        @foreach ($additionalCategoryKeys as $key)
                            @php
                                $count = (int) ($summary[$key] ?? 0);
                            @endphp
                            <tr
                                class="dashboard-drilldown-trigger dashboard-work-status-additional"
                                role="button"
                                tabindex="0"
                                data-drilldown-title="{{ ($title ?: 'Project üzrə: '.$ownershipLabel).' — '.$categoryLabels[$key] }}"
                                data-drilldown-ownership="{{ $drilldownOwnership }}"
                                data-drilldown-project-id="{{ $drilldownProjectId }}"
                                data-drilldown-view="projects"
                                data-drilldown-mode="efficiency_projects"
                                data-drilldown-work-category="{{ $key }}"
                                data-drilldown-status="{{ $key }}"
                                data-drilldown-date-from="{{ $drilldownDateFrom }}"
                                data-drilldown-date-to="{{ $drilldownDateTo }}"
                            >
                                <td>
                                    <span class="dashboard-work-status-label">
                                        <span class="dashboard-color-dot" style="background: {{ $categoryColors[$key] }}"></span>
                                        <span class="dashboard-work-status-label-text">{{ $categoryLabels[$key] }}</span>
                                    </span>
                                </td>
                                <td class="text-end">{{ number_format($count, 0, '.', ' ') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @else
        <div class="dashboard-empty flex-grow-1">Seçilmiş dövr üçün məlumat yoxdur</div>
    @endif
</section>
