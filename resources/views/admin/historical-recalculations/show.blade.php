@extends('layouts.app')

@section('title', 'Tarixi yenilənmə statusu')
@section('page-title', 'Tarixi yenilənmə statusu')
@section('page-subtitle', $run->date_from->toDateString().' - '.$run->date_to->toDateString())

@section('content')
    @php
        $done = $run->completed_tasks + $run->failed_tasks + $run->cancelled_tasks;
        $percent = $run->total_tasks > 0 ? round(($done / $run->total_tasks) * 100, 1) : 0;
    @endphp

    <div class="panel p-4 mb-4">
        <div class="d-flex flex-wrap justify-content-between gap-3">
            <div>
                <div class="text-secondary small">Status</div>
                <div id="run-status" class="h5 fw-bold mb-0">{{ $run->status }}</div>
            </div>
            <div>
                <div class="text-secondary small">Əməliyyat</div>
                <div class="fw-semibold">{{ $run->operation }}</div>
            </div>
            <div>
                <div class="text-secondary small">Tapşırıqlar</div>
                <div class="fw-semibold"><span id="run-done">{{ $done }}</span> / <span id="run-total">{{ $run->total_tasks }}</span></div>
            </div>
            <div>
                <div class="text-secondary small">Obyekt sayı</div>
                <div id="run-objects" class="fw-semibold">{{ $run->processed_objects }}</div>
            </div>
            <div class="d-flex align-items-start gap-2">
                @if (! $run->isTerminal())
                    <form method="POST" action="{{ route('admin.historical-recalculations.cleanup-stuck', $run) }}" onsubmit="return confirm('Zavis queue cleanup icra edilsin? Hesabat datasi silinmeyecek.');">
                        @csrf
                        <button class="btn btn-outline-warning btn-sm">Cleanup</button>
                    </form>
                    <form method="POST" action="{{ route('admin.historical-recalculations.cancel', $run) }}">
                        @csrf
                        <button class="btn btn-outline-danger btn-sm">Ləğv et</button>
                    </form>
                @endif
                @if ($run->failed_tasks > 0)
                    <form method="POST" action="{{ route('admin.historical-recalculations.retry', $run) }}">
                        @csrf
                        <button class="btn btn-outline-primary btn-sm">Uğursuzları təkrar et</button>
                    </form>
                @endif
            </div>
        </div>

        <div class="progress mt-4" style="height: 12px;">
            <div id="run-progress" class="progress-bar" style="width: {{ $percent }}%">{{ $percent }}%</div>
        </div>

        <div class="row g-3 mt-3 small text-secondary">
            <div class="col-md-3">Tamamlandı: <strong id="run-completed">{{ $run->completed_tasks }}</strong></div>
            <div class="col-md-3">Uğursuz: <strong id="run-failed">{{ $run->failed_tasks }}</strong></div>
            <div class="col-md-3">Ləğv: <strong id="run-cancelled">{{ $run->cancelled_tasks }}</strong></div>
            <div class="col-md-3">Heartbeat: <strong id="run-heartbeat">{{ optional($run->last_heartbeat_at)->toDateTimeString() }}</strong></div>
        </div>
    </div>

    <div class="panel p-4">
        <h2 class="h6 fw-bold mb-3">Tapşırıqlar</h2>

        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead>
                <tr>
                    <th>Tarix</th>
                    <th>Layihə</th>
                    <th>Mənsubiyyət</th>
                    <th>Əməliyyat</th>
                    <th>Status</th>
                    <th>Cəhd</th>
                    <th>Obyekt</th>
                    <th>Xəta</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($tasks as $task)
                    <tr>
                        <td>{{ optional($task->stat_date)->toDateString() ?: '-' }}</td>
                        <td>{{ $task->project?->name ?: 'Bütün layihələr' }}</td>
                        <td>{{ $task->ownership_type ?: '-' }}</td>
                        <td>{{ $task->operation }}</td>
                        <td><span class="badge text-bg-secondary">{{ $task->status }}</span></td>
                        <td>{{ $task->attempts }}</td>
                        <td>{{ $task->equipment_count }}</td>
                        <td class="text-danger small" style="max-width: 360px;">{{ $task->error_message }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="text-secondary">Məlumat yoxdur</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        {{ $tasks->links() }}
    </div>
@endsection

@push('scripts')
    <script>
        const statusUrl = '{{ route('admin.historical-recalculations.status', $run) }}';

        async function refreshRunStatus() {
            const response = await fetch(statusUrl, {headers: {'Accept': 'application/json'}});
            if (!response.ok) {
                return;
            }

            const data = await response.json();
            const done = data.completed_tasks + data.failed_tasks + data.cancelled_tasks;
            document.getElementById('run-status').textContent = data.status;
            document.getElementById('run-done').textContent = done;
            document.getElementById('run-total').textContent = data.total_tasks;
            document.getElementById('run-completed').textContent = data.completed_tasks;
            document.getElementById('run-failed').textContent = data.failed_tasks;
            document.getElementById('run-cancelled').textContent = data.cancelled_tasks;
            document.getElementById('run-objects').textContent = data.processed_objects;
            document.getElementById('run-heartbeat').textContent = data.last_heartbeat_at || '-';
            document.getElementById('run-progress').style.width = `${data.progress_percent}%`;
            document.getElementById('run-progress').textContent = `${data.progress_percent}%`;

            if (!['completed', 'completed_with_errors', 'failed', 'cancelled'].includes(data.status)) {
                setTimeout(refreshRunStatus, 5000);
            }
        }

        setTimeout(refreshRunStatus, 5000);
    </script>
@endpush
