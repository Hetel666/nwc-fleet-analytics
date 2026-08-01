@php
    $statuses = collect(['0_1', '1_7', '7_10', 'over_10', 'no_data']);
    $total = (int) ($summary['total'] ?? 0);
    $drilldownOwnership = $ownershipCode === 'ICARE' ? 'icare' : 'nwc';
@endphp

<section class="panel p-3 dashboard-card dashboard-work-status-card d-flex flex-column">
    <div class="dashboard-panel-header d-flex align-items-start justify-content-between gap-2 mb-3">
        <div class="min-w-0">
            <h3 class="h5 dashboard-work-status-title fw-bold mb-1 dashboard-card-title-text">{{ $title }}</h3>
            <div class="small text-secondary">18:00-07:59 intervalı üzrə Engine hours</div>
        </div>
        <a href="{{ $exportUrl }}" class="btn btn-sm dashboard-export-button" title="Excel" aria-label="Excel">
            <i class="bi bi-download"></i>
        </a>
    </div>

    <div class="dashboard-work-status-note mb-2">
        Hesablama vahidi: Texnika-növbə<br>
        İş vaxtı: 18:00-07:59<br>
        Mənbə: night report Engine hours (api)<br>
        Növbə tarixi başlanğıc gününə görə hesablanır
    </div>

    @if ($total > 0)
        <div class="dashboard-work-status-layout flex-grow-1">
            <div class="dashboard-work-status-chart"><canvas id="{{ $chartId }}"></canvas></div>
            <div class="dashboard-work-status-table">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>{{ __('app.status') }}</th><th class="text-end">Say</th></tr></thead>
                    <tbody>
                    @foreach ($statuses as $status)
                        <tr
                            class="dashboard-drilldown-trigger"
                            role="button"
                            tabindex="0"
                            data-drilldown-title="Gecə effektivliyi — {{ $categoryLabels[$status] }}"
                            data-drilldown-ownership="{{ $drilldownOwnership }}"
                            data-drilldown-mode="nighttime_efficiency_projects"
                            data-drilldown-status="{{ $status }}"
                            data-drilldown-date-from="{{ $filters['from'] }}"
                            data-drilldown-date-to="{{ $filters['to'] }}"
                            data-drilldown-project-id="{{ $filters['project_id'] }}"
                            data-drilldown-search="{{ request('nighttime_search') }}"
                            data-drilldown-endpoint-url="{{ route('api.dashboard.nighttime-efficiency.projects') }}"
                            data-drilldown-units-endpoint-url="{{ route('api.dashboard.nighttime-efficiency.units') }}"
                            data-drilldown-export-url="{{ route('api.dashboard.nighttime-efficiency.export') }}"
                            data-drilldown-export-enabled="0"
                        >
                            <td><span class="dashboard-work-status-label"><span class="dashboard-color-dot" style="background: {{ $categoryColors[$status] }}"></span><span class="dashboard-work-status-label-text">{{ $categoryLabels[$status] }}</span></span></td>
                            <td class="text-end">{{ number_format((int) ($summary[$status] ?? 0), 0, '.', ' ') }}</td>
                        </tr>
                    @endforeach
                    <tr class="dashboard-work-status-total"><td>Cəmi</td><td class="text-end">{{ number_format($total, 0, '.', ' ') }}</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    @else
        <div class="dashboard-empty flex-grow-1">{{ __('app.no_data') }}</div>
    @endif
</section>
