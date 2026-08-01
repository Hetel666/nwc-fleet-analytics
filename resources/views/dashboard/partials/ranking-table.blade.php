<div class="dashboard-scroll-table dashboard-ranking-table top20-table-wrapper">
    @php
        $showDate = collect($rows)->contains(fn ($row) => (bool) ($row['show_date'] ?? false));
        $ranking = $ranking ?? 'least';
    @endphp
    <table class="table table-sm align-middle mb-0 top20-table{{ $showDate ? ' top20-table--with-date' : '' }}">
        <colgroup>
            @if ($showDate)
                <col class="top20-col-rank">
                <col class="top20-col-date">
                <col class="top20-col-equipment">
                <col class="top20-col-ownership">
                <col class="top20-col-type">
                <col class="top20-col-project">
                <col class="top20-col-hours">
            @else
                <col class="top20-col-rank">
                <col class="top20-col-equipment">
                <col class="top20-col-ownership">
                <col class="top20-col-type">
                <col class="top20-col-project">
                <col class="top20-col-hours">
            @endif
        </colgroup>
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
                @php
                    $ownershipLabel = $row['ownership_label'] ?? (($row['ownership'] ?? '') === 'ICARE' ? __('app.ownership_icare') : __('app.ownership_nwc'));
                    $projectLabel = $row['project'] ?? 'Layihəsiz';
                @endphp
                <tr class="dashboard-drilldown-trigger"
                    role="button"
                    tabindex="0"
                    data-drilldown-title="{{ ($row['name'] ?? '') }} - {{ $row['date'] ?? '' }}"
                    data-drilldown-top-ranking="{{ $ranking }}"
                    data-drilldown-top-equipment-id="{{ $row['id'] ?? '' }}"
                    data-drilldown-top-stat-date="{{ $row['date'] ?? '' }}">
                    <td class="top20-rank-cell">{{ $loop->iteration }}</td>
                    @if ($showDate)
                        <td><span class="truncate" title="{{ $row['date'] ?? '' }}">{{ $row['date'] ?? '' }}</span></td>
                    @endif
                    <td>
                        <div class="fw-semibold truncate" title="{{ $row['name'] }}">{{ $row['name'] }}</div>
                    </td>
                    <td><span class="truncate" title="{{ $ownershipLabel }}">{{ $ownershipLabel }}</span></td>
                    <td><span class="truncate" title="{{ $row['type'] }}">{{ $row['type'] }}</span></td>
                    <td><span class="truncate" title="{{ $projectLabel }}">{{ $projectLabel }}</span></td>
                    <td class="text-end top20-hours-cell"><span class="truncate" title="{{ $row['hours'] }}">{{ $row['hours'] }}</span></td>
                </tr>
            @empty
                <tr><td colspan="{{ $showDate ? 7 : 6 }}" class="text-secondary">{{ __('app.no_data') }}</td></tr>
            @endforelse
        </tbody>
    </table>
    <div class="small text-secondary px-2 py-2">Hesablama yalnız Dump Truck, Bulldozer, Excavator, Loader, Backhoe Loader, Road Grader və Road Roller üzrə aparılır.</div>
</div>
