@extends('layouts.app')

@section('title', __('app.settings').' | '.__('app.app_name'))
@section('page-title', __('app.settings'))

@section('content')
    <div class="row g-4">
        <div class="col-lg-7">
            <form method="POST" action="{{ route('settings.update') }}" class="panel p-4">
                @csrf
                @method('PUT')

                <div class="mb-3">
                    <label class="form-label">Wialon resource ID</label>
                    <input name="wialon_resource_id" value="{{ old('wialon_resource_id', $settings['wialon_resource_id'] ?? '') }}" class="form-control">
                </div>
                <div class="mb-3">
                    <label class="form-label">Wialon report template ID</label>
                    <input name="wialon_report_template_id" value="{{ old('wialon_report_template_id', $settings['wialon_report_template_id'] ?? '') }}" class="form-control">
                </div>
                <div class="mb-3">
                    <label class="form-label">Geofence minimum cixis muddati</label>
                    <input type="number" min="1" max="1440" name="geofence_min_exit_minutes" value="{{ old('geofence_min_exit_minutes', $settings['geofence_min_exit_minutes'] ?? config('fleet.geofence.min_exit_minutes')) }}" class="form-control">
                </div>

                <hr class="my-4">

                <div class="d-flex align-items-center justify-content-between gap-3 mb-3">
                    <div>
                        <h2 class="h6 fw-bold mb-1">Avtomatik sinxronizasiya</h2>
                        <div class="text-secondary small">Planlayici avtomatik yenilenmeni her gun saat 00:00-da ise salir. Ferdi intervallar Tarixi melumatlarin yenilenmesi bolmesinden el ile icra olunur.</div>
                    </div>
                    <label class="form-check form-switch m-0">
                        <input type="checkbox" name="auto_sync_enabled" value="1" class="form-check-input" @checked(old('auto_sync_enabled', $settings['auto_sync_enabled'] ?? '1'))>
                        <span class="form-check-label">Aktiv</span>
                    </label>
                </div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="border rounded-3 p-3 h-100">
                            <label class="form-check mb-3">
                                <input type="checkbox" name="auto_sync_units_enabled" value="1" class="form-check-input" @checked(old('auto_sync_units_enabled', $settings['auto_sync_units_enabled'] ?? '1'))>
                                <span class="form-check-label fw-semibold">Texnikalari avtomatik sinxronlasdir</span>
                            </label>
                            <input type="hidden" name="auto_sync_units_interval_minutes" value="1440">
                            <div class="text-secondary small">Her gun saat 00:00-da yenilenir.</div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="border rounded-3 p-3 h-100">
                            <label class="form-check mb-3">
                                <input type="checkbox" name="auto_sync_geofences_enabled" value="1" class="form-check-input" @checked(old('auto_sync_geofences_enabled', $settings['auto_sync_geofences_enabled'] ?? '1'))>
                                <span class="form-check-label fw-semibold">Geofence-leri avtomatik sinxronlasdir</span>
                            </label>
                            <input type="hidden" name="auto_sync_geofences_interval_minutes" value="1440">
                            <div class="text-secondary small">Her gun saat 00:00-da yenilenir.</div>
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="border rounded-3 p-3">
                            <label class="form-check mb-3">
                                <input type="checkbox" name="auto_sync_daily_enabled" value="1" class="form-check-input" @checked(old('auto_sync_daily_enabled', $settings['auto_sync_daily_enabled'] ?? '1'))>
                                <span class="form-check-label fw-semibold">Gundelik statistikani avtomatik hesabla</span>
                            </label>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Icra vaxti</label>
                                    <input type="hidden" name="auto_sync_daily_interval_minutes" value="1440">
                                    <div class="form-control bg-body-secondary">00:00</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Son nece gun yenilensin</label>
                                    <input type="number" min="1" max="7" name="auto_sync_daily_recent_days" value="{{ old('auto_sync_daily_recent_days', $settings['auto_sync_daily_recent_days'] ?? 3) }}" class="form-control">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <button class="btn btn-primary btn-icon mt-4">
                    <i class="bi bi-check2"></i><span>{{ __('app.save') }}</span>
                </button>
            </form>
        </div>
        <div class="col-lg-5">
            <section class="panel p-4 mb-4">
                <h2 class="h6 fw-bold">Wialon</h2>
                <div class="d-flex align-items-center gap-2 mb-3">
                    <span class="badge text-bg-{{ $wialonTokenConfigured ? 'success' : 'warning' }}">
                        {{ $wialonTokenConfigured ? __('app.token_configured') : __('app.token_missing') }}
                    </span>
                </div>
                <form method="POST" action="{{ route('settings.sync-units') }}" data-sync-form>
                    @csrf
                    <button type="submit" class="btn btn-outline-primary btn-icon" data-loading-text="Sinxronizasiya gedir...">
                        <i class="bi bi-arrow-repeat"></i><span>{{ __('app.sync_units') }}</span>
                    </button>
                </form>
                <form method="POST" action="{{ route('settings.sync-geofences') }}" class="mt-2" data-sync-form>
                    @csrf
                    <button type="submit" class="btn btn-outline-primary btn-icon" data-loading-text="Sinxronizasiya gedir...">
                        <i class="bi bi-bounding-box"></i><span>Geofence-lari sinxronlasdir</span>
                    </button>
                </form>
            </section>

            <section class="panel p-4 mb-4">
                <h2 class="h6 fw-bold">Historical run cleanup</h2>
                <div class="text-secondary small mb-3">
                    Zavis historical queue ucun: yalniz cancelled/completed/failed task-lara aid kohne queue job-lari silinir, hesabat datasi silinmir.
                </div>

                <dl class="row small mb-3">
                    <dt class="col-6 text-secondary">Queue</dt>
                    <dd class="col-6 text-end">{{ config('historical_recalculation.queue', 'historical-recalculations') }}</dd>
                    <dt class="col-6 text-secondary">Queue jobs</dt>
                    <dd class="col-6 text-end">{{ $historicalQueueSize ?? '-' }}</dd>
                    <dt class="col-6 text-secondary">Son run</dt>
                    <dd class="col-6 text-end">
                        @if ($latestHistoricalRun)
                            #{{ $latestHistoricalRun->id }} / {{ $latestHistoricalRun->status }}
                        @else
                            -
                        @endif
                    </dd>
                </dl>

                <form method="POST" action="{{ route('settings.cleanup-historical-runs') }}" data-sync-form data-confirm="Zavis historical queue cleanup icra edilsin? Hesabat datasi silinmeyecek.">
                    @csrf
                    <button type="submit" class="btn btn-outline-warning btn-icon" data-loading-text="Cleanup gedir...">
                        <i class="bi bi-tools"></i><span>Zavis run temizle</span>
                    </button>
                </form>
            </section>

            <section class="panel p-4">
                <h2 class="h6 fw-bold">Avtomatik sinxronizasiya statusu</h2>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Tapsiriq</th>
                                <th>Status</th>
                                <th>Son icra</th>
                                <th>Netice</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($syncStatusRows as $row)
                                @php
                                    $status = $settings['auto_sync_'.$row['key'].'_last_status'] ?? '-';
                                    $statusClass = $status === 'success' ? 'success' : ($status === 'failed' ? 'danger' : 'secondary');
                                @endphp
                                <tr>
                                    <td>{{ $row['label'] }}</td>
                                    <td><span class="badge text-bg-{{ $statusClass }}">{{ $status }}</span></td>
                                    <td class="small text-secondary">{{ $settings['auto_sync_'.$row['key'].'_last_run_at'] ?? '-' }}</td>
                                    <td class="small text-secondary">{{ $settings['auto_sync_'.$row['key'].'_last_message'] ?? '-' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="small text-secondary mt-3">Server planlayicisi aktiv olduqda bu bolme avtomatik yenilenen neticeleri gosterir.</div>
            </section>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.querySelectorAll('[data-sync-form]').forEach(form => {
            form.addEventListener('submit', event => {
                if (form.dataset.confirm && !window.confirm(form.dataset.confirm)) {
                    event.preventDefault();

                    return;
                }

                const button = form.querySelector('button[type="submit"]');

                if (!button) {
                    return;
                }

                button.disabled = true;
                button.querySelector('span').textContent = button.dataset.loadingText || 'Sinxronizasiya gedir...';
            });
        });
    </script>
@endpush
