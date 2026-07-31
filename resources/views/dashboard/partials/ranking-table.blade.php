<div class="dashboard-scroll-table">
    @php
        $showDate = collect($rows)->contains(fn ($row) => (bool) ($row['show_date'] ?? false));
        $ranking = $ranking ?? 'least';
    @endphp
    <table class="table table-sm align-middle mb-0">
        <thead>
            <tr>
                <th>#</th>
                @if ($showDate)
                    <th>Tarix</th>
                @endif
                <th>{{ __('app.equipment') }}</th>
                <th>Mənsubiyyət</th>
                <th>{{ __('app.type') }}</th>
                <th>{{ __('app.project') }}</th>
                <th class="text-end">Faktiki {{ __('app.hours') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr class="dashboard-drilldown-trigger"
                    role="button"
                    tabindex="0"
                    data-drilldown-title="{{ ($row['name'] ?? '') }} - {{ $row['date'] ?? '' }}"
                    data-drilldown-top-ranking="{{ $ranking }}"
                    data-drilldown-top-equipment-id="{{ $row['id'] ?? '' }}"
                    data-drilldown-top-stat-date="{{ $row['date'] ?? '' }}">
                    <td>{{ $loop->iteration }}</td>
                    @if ($showDate)
                        <td>{{ $row['date'] ?? '' }}</td>
                    @endif
                    <td>
                        <div class="fw-semibold">{{ $row['name'] }}</div>
                    </td>
                    <td>{{ $row['ownership_label'] ?? (($row['ownership'] ?? '') === 'ICARE' ? __('app.ownership_icare') : __('app.ownership_nwc')) }}</td>
                    <td>{{ $row['type'] }}</td>
                    <td title="{{ $row['project'] ?? '' }}">{{ $row['project'] ?? 'Layihəsiz' }}</td>
                    <td class="text-end">{{ $row['hours'] }}</td>
                </tr>
            @empty
                <tr><td colspan="{{ $showDate ? 7 : 6 }}" class="text-secondary">{{ __('app.no_data') }}</td></tr>
            @endforelse
        </tbody>
    </table>
    <div class="small text-secondary px-2 py-2">Hesablama yalnız Dump Truck, Bulldozer, Excavator, Loader, Backhoe Loader, Road Grader və Road Roller üzrə aparılır.</div>
</div>
