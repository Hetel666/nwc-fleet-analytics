@php
    $statuses = collect($visibleStatuses ?? ['critical_low', 'low', 'normal'])
        ->filter(fn (string $status): bool => $categoryLabels->has($status))
        ->values();
    $fullTotal = (int) ($summary['total'] ?? 0);
    $total = (int) $statuses->sum(fn (string $status): int => (int) ($summary[$status] ?? 0));
    $drilldownOwnership = $ownershipCode === 'ICARE' ? 'icare' : 'nwc';
    $month = (string) ($summary['month'] ?? \Illuminate\Support\Carbon::parse($filters['from'])->format('Y-m'));
    $period = $summary['period'] ?? ['from' => $filters['from'], 'to' => $filters['to']];
    $completeness = $summary['completeness'] ?? ['is_complete' => true, 'message' => null];
    $parkEfficiencyPercent = (float) ($summary['efficiency_percent'] ?? 0);
    $statusPercentages = [];
    $remainingPercent = 100.0;
    $lastStatusIndex = max(0, $statuses->count() - 1);

    foreach ($statuses as $index => $status) {
        if ($total <= 0) {
            $statusPercentages[$status] = 0.0;

            continue;
        }

        if ($index === $lastStatusIndex) {
            $statusPercentages[$status] = max(0.0, round($remainingPercent, 1));

            continue;
        }

        $statusPercentages[$status] = round(((int) ($summary[$status] ?? 0)) * 100 / $total, 1);
        $remainingPercent -= $statusPercentages[$status];
    }
@endphp

<section class="panel p-3 dashboard-card dashboard-work-status-card dashboard-monthly-efficiency-card d-flex flex-column">
    <div class="dashboard-panel-header d-flex align-items-start justify-content-between gap-2 mb-3">
        <div class="min-w-0">
            <h3 class="h5 dashboard-work-status-title fw-bold mb-1 dashboard-card-title-text">{{ $title }}</h3>
            <div class="small text-secondary">{{ \Illuminate\Support\Carbon::parse($period['from'])->translatedFormat('F Y') }} - {{ $ownershipLabel }} üzrə park effektivliyi</div>
        </div>
            <button type="button" class="btn btn-sm dashboard-personal-hide-toggle" title="Hide" aria-label="Hide">
                <i class="bi bi-eye-slash"></i>
            </button>
        <a href="{{ $exportUrl }}" class="btn btn-sm dashboard-export-button" title="Excel" aria-label="Excel">
            <i class="bi bi-download"></i>
        </a>
    </div>

    @if (! ($completeness['is_complete'] ?? true))
        <div class="alert alert-warning py-2 px-3 small mb-3">
            {{ $completeness['message'] ?? 'Seçilmiş ay üzrə məlumatlar tam sinxronlaşdırılmayıb.' }}
        </div>
    @endif

    @if ($total > 0)
        <div class="dashboard-monthly-layout flex-grow-1">
            <div class="dashboard-monthly-chart">
                <canvas id="{{ $chartId }}"></canvas>
                <div class="dashboard-monthly-center">
                    <strong>{{ number_format($parkEfficiencyPercent, 2, '.', ' ') }}%</strong>
                    <span>EFFEKTİVLİK</span>
                </div>
            </div>
            <div class="dashboard-work-status-table">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>{{ __('app.status') }}</th><th class="text-end">%</th><th class="text-end">Say</th></tr></thead>
                    <tbody>
                    @foreach ($statuses as $status)
                        <tr
                            class="dashboard-drilldown-trigger"
                            role="button"
                            tabindex="0"
                            data-drilldown-title="{{ $ownershipLabel }} üzrə — {{ $categoryLabels[$status] }}"
                            data-drilldown-ownership="{{ $drilldownOwnership }}"
                            data-drilldown-mode="monthly_efficiency_objects"
                            data-drilldown-status="{{ $status }}"
                            data-drilldown-month="{{ $month }}"
                            data-drilldown-date-from="{{ $period['from'] }}"
                            data-drilldown-date-to="{{ $period['to'] }}"
                            data-drilldown-project-id="{{ $filters['project_id'] }}"
                            data-drilldown-endpoint-url="{{ route('api.dashboard.monthly-efficiency.objects') }}"
                            data-drilldown-units-endpoint-url="{{ route('api.dashboard.monthly-efficiency.object-geofences') }}"
                            data-drilldown-days-endpoint-url="{{ route('api.dashboard.monthly-efficiency.object-geofence-days') }}"
                            data-drilldown-export-url="{{ route('api.dashboard.monthly-efficiency.export') }}"
                            data-drilldown-export-enabled="0"
                        >
                            <td><span class="dashboard-work-status-label"><span class="dashboard-color-dot" style="background: {{ $categoryColors[$status] }}"></span><span class="dashboard-work-status-label-text">{{ $categoryLabels[$status] }}</span></span></td>
                            <td class="text-end">{{ number_format((float) ($statusPercentages[$status] ?? 0), 1, '.', ' ') }}%</td>
                            <td class="text-end">{{ number_format((int) ($summary[$status] ?? 0), 0, '.', ' ') }}</td>
                        </tr>
                    @endforeach
                    <tr class="dashboard-work-status-total"><td>Cəmi</td><td class="text-end">100%</td><td class="text-end">{{ number_format($total, 0, '.', ' ') }}</td></tr>
                    @if ($fullTotal !== $total)
                    <tr class="dashboard-work-status-note"><td colspan="3">Göstərilir: {{ number_format($total, 0, '.', ' ') }} / {{ number_format($fullTotal, 0, '.', ' ') }}</td></tr>
                    @endif
                    </tbody>
                </table>
            </div>
        </div>
        <div class="small text-secondary text-end mt-2">Ümumi vahidlər: {{ number_format($total, 0, '.', ' ') }}</div>
    @else
        <div class="dashboard-empty flex-grow-1">Seçilmiş ay üçün aylıq effektivlik məlumatı yoxdur</div>
    @endif
</section>
