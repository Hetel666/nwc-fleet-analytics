<div class="dashboard-card-body dashboard-type-card-body flex-grow-1">
    @if ($rows->isNotEmpty())
        <div class="dashboard-type-layout">
            <div class="dashboard-type-chart-panel">
                <div class="chart-box chart-box--donut dashboard-type-chart-box"><canvas id="{{ $chartId }}"></canvas></div>
            </div>
            <div class="dashboard-type-table-panel">
                <div class="dashboard-scroll-table dashboard-type-table" data-expandable="{{ $expandId }}">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Növ</th>
                                <th class="text-end">Say</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $type)
                                <tr
                                    class="{{ $loop->iteration > 10 ? 'expandable-extra d-none' : '' }} dashboard-drilldown-trigger"
                                    role="button"
                                    tabindex="0"
                                    data-drilldown-title="{{ $ownershipLabel ?? '' }} — {{ $type['name'] }}"
                                    data-drilldown-ownership="{{ $ownership ?? 'all' }}"
                                    data-drilldown-ownership-scope="project_groups"
                                    data-drilldown-equipment-type-id="{{ $type['id'] ?? '' }}"
                                >
                                    <td>{{ $type['name'] }}</td>
                                    <td class="text-end">{{ $type['total'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($hasMore)
                    <button
                        type="button"
                        class="btn btn-link dashboard-expand-toggle mt-2 dashboard-drilldown-trigger"
                        data-drilldown-title="{{ $ownershipLabel ?? '' }}"
                        data-drilldown-ownership="{{ $ownership ?? 'all' }}"
                        data-drilldown-ownership-scope="project_groups"
                    >Hamısını göstər</button>
                @endif
            </div>
        </div>
    @else
        <div class="dashboard-empty">{{ __('app.no_data') }}</div>
    @endif
</div>
