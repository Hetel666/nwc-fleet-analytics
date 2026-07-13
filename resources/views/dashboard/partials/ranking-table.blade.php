<div class="dashboard-scroll-table">
    <table class="table table-sm align-middle mb-0">
        <thead>
            <tr>
                <th>#</th>
                <th>{{ __('app.equipment') }}</th>
                <th>Vendor</th>
                <th>{{ __('app.type') }}</th>
                <th class="text-end">Faktiki {{ __('app.hours') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>
                        <div class="fw-semibold">{{ $row['name'] }}</div>
                    </td>
                    <td>{{ ($row['ownership'] ?? '') === 'ICARE' ? __('app.ownership_icare') : __('app.ownership_nwc') }}</td>
                    <td>{{ $row['type'] }}</td>
                    <td class="text-end">{{ $row['hours'] }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-secondary">{{ __('app.no_data') }}</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
