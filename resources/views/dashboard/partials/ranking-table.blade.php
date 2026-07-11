<div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
        <thead>
            <tr>
                <th>#</th>
                <th>{{ __('app.equipment') }}</th>
                <th>{{ __('app.type') }}</th>
                <th class="text-end">{{ __('app.hours') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>
                        <div class="fw-semibold">{{ $row['name'] }}</div>
                        <div class="small text-secondary">{{ $row['ownership'] }}</div>
                    </td>
                    <td>{{ $row['type'] }}</td>
                    <td class="text-end">{{ $row['hours'] }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="text-secondary">{{ __('app.no_data') }}</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
