@php
    $daytimeOwnerships = [
        'nwc' => ['key' => 'nwc', 'label' => 'NWC'],
        'icare' => ['key' => 'icare', 'label' => 'İCARƏ'],
    ];
    $selectedDaytimeOwnership = mb_strtolower((string) ($filters['ownership_type'] ?? ''));
    if (in_array($selectedDaytimeOwnership, ['nwc', 'icare'], true)) {
        $daytimeOwnerships = array_intersect_key($daytimeOwnerships, [$selectedDaytimeOwnership => true]);
    }
    $daytimePalette = ['#2874e8', '#ff7a12', '#20b65a', '#0ea5e9', '#98a9bd', '#e5484d'];
    $daytimeFrom = $filters['from'] ?? $filters['date_from'] ?? '';
    $daytimeTo = $filters['to'] ?? $filters['date_to'] ?? '';
@endphp

<section class="daytime-efficiency-shell" aria-label="Effektivlik gündüz">
    @foreach ($daytimeOwnerships as $ownership)
        @php
            $summary = $daytimeEfficiencySummaries[$ownership['key']] ?? [];
            $total = (int) ($summary['total'] ?? 0);
            $cursor = 0.0;
            $segments = [];

            foreach ($daytimeEfficiencyCategoryOrder as $categoryIndex => $category) {
                $count = (int) ($summary[$category] ?? 0);

                if ($total <= 0 || $count <= 0) {
                    continue;
                }

                $end = min(100, $cursor + ($count / $total * 100));
                $segments[] = ($daytimeEfficiencyColors[$category] ?? $daytimePalette[$categoryIndex % count($daytimePalette)])
                    .' '.number_format($cursor, 3, '.', '').'% '
                    .number_format($end, 3, '.', '').'%';
                $cursor = $end;
            }

            $donutBackground = $segments === []
                ? 'conic-gradient(#d9e1ec 0% 100%)'
                : 'conic-gradient('.implode(', ', $segments).')';
            $daytimeQuery = array_filter([
                'date_from' => $daytimeFrom,
                'date_to' => $daytimeTo,
                'project_id' => $filters['project_id'] ?? null,
                'equipment_type_id' => $filters['equipment_type_id'] ?? null,
                'ownership_type' => $ownership['key'],
            ], fn ($value) => $value !== null && $value !== '');
        @endphp

        <article class="panel p-3 daytime-efficiency-card">
            <div class="daytime-efficiency-card-header">
                <div>
                    <h3>Effektivlik gündüz: {{ $ownership['label'] }} üzrə</h3>
                    <p>Unikal texnika · {{ $daytimeEfficiency['source'] ?? 'Qrup report daytime (api)' }}</p>
                </div>
                <strong>{{ number_format($total, 0, '.', ' ') }}</strong>
            </div>

            <div class="daytime-efficiency-content">
                <div class="daytime-efficiency-donut" style="background: {{ $donutBackground }}" aria-label="{{ $ownership['label'] }} üzrə gündüz effektivliyi">
                    <div class="daytime-efficiency-donut-center">
                        <strong>{{ number_format($total, 0, '.', ' ') }}</strong>
                        <span>{{ $ownership['label'] }}</span>
                    </div>
                </div>

                <table class="table table-sm daytime-efficiency-summary">
                    <thead><tr><th>Status</th><th>Say</th></tr></thead>
                    <tbody>
                        @foreach ($daytimeEfficiencyCategoryOrder as $categoryIndex => $category)
                            <tr>
                                <td><span class="daytime-efficiency-dot" style="background: {{ $daytimeEfficiencyColors[$category] ?? $daytimePalette[$categoryIndex % count($daytimePalette)] }}"></span>{{ $daytimeEfficiencyLabels[$category] ?? $category }}</td>
                                <td>{{ number_format((int) ($summary[$category] ?? 0), 0, '.', ' ') }}</td>
                            </tr>
                        @endforeach
                        <tr class="daytime-efficiency-total"><td>Cəmi</td><td>{{ number_format($total, 0, '.', ' ') }}</td></tr>
                    </tbody>
                </table>
            </div>

            <div class="mt-2 text-end">
                <a class="small" href="{{ route('daytime-efficiency.index', $daytimeQuery) }}">Ətraflı baxış <span aria-hidden="true">→</span></a>
            </div>
        </article>
    @endforeach
</section>
