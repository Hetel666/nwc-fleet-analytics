<div class="dashboard-card-body flex-grow-1">
    @if ($rows->isNotEmpty())
        <div class="dashboard-donut-layout">
            <div class="chart-box chart-box--donut"><canvas id="{{ $chartId }}"></canvas></div>
            <div>
                <div class="dashboard-scroll-table" data-expandable="{{ $expandId }}">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Növ</th>
                                <th class="text-end">Say</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $type)
                                <tr class="{{ $loop->iteration > 10 ? 'expandable-extra d-none' : '' }}">
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
                        class="btn btn-link dashboard-expand-toggle mt-2"
                        data-expand-toggle="{{ $expandId }}"
                        data-show-label="Hamısını göstər"
                        data-hide-label="Gizlət"
                    >Hamısını göstər</button>
                @endif
            </div>
        </div>
    @else
        <div class="dashboard-empty">{{ __('app.no_data') }}</div>
    @endif
</div>
