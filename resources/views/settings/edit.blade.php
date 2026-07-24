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
                        <div class="text-secondary small">Planlayici her 5 deqiqeden bir yoxlayir ve yalniz vaxti catmis tapsiriqlari ise salir.</div>
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
                            <label class="form-label">Interval</label>
                            <select name="auto_sync_units_interval_minutes" class="form-select">
                                @foreach ([30, 60, 180, 360, 720, 1440] as $minutes)
                                    <option value="{{ $minutes }}" @selected((string) old('auto_sync_units_interval_minutes', $settings['auto_sync_units_interval_minutes'] ?? 60) === (string) $minutes)>
                                        {{ $syncIntervalOptions[$minutes] }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="border rounded-3 p-3 h-100">
                            <label class="form-check mb-3">
                                <input type="checkbox" name="auto_sync_geofences_enabled" value="1" class="form-check-input" @checked(old('auto_sync_geofences_enabled', $settings['auto_sync_geofences_enabled'] ?? '1'))>
                                <span class="form-check-label fw-semibold">Geofence-leri avtomatik sinxronlasdir</span>
                            </label>
                            <label class="form-label">Interval</label>
                            <select name="auto_sync_geofences_interval_minutes" class="form-select">
                                @foreach ([360, 720, 1440, 10080] as $minutes)
                                    <option value="{{ $minutes }}" @selected((string) old('auto_sync_geofences_interval_minutes', $settings['auto_sync_geofences_interval_minutes'] ?? 1440) === (string) $minutes)>
                                        {{ $syncIntervalOptions[$minutes] }}
                                    </option>
                                @endforeach
                            </select>
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
                                    <label class="form-label">Interval</label>
                                    <select name="auto_sync_daily_interval_minutes" class="form-select">
                                        @foreach ([60, 180, 360, 720, 1440] as $minutes)
                                            <option value="{{ $minutes }}" @selected((string) old('auto_sync_daily_interval_minutes', $settings['auto_sync_daily_interval_minutes'] ?? 180) === (string) $minutes)>
                                                {{ $syncIntervalOptions[$minutes] }}
                                            </option>
                                        @endforeach
                                    </select>
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
                <form method="POST" action="{{ route('settings.sync-units') }}">
                    @csrf
                    <button class="btn btn-outline-primary btn-icon">
                        <i class="bi bi-arrow-repeat"></i><span>{{ __('app.sync_units') }}</span>
                    </button>
                </form>
                <form method="POST" action="{{ route('settings.sync-geofences') }}" class="mt-2">
                    @csrf
                    <button class="btn btn-outline-primary btn-icon">
                        <i class="bi bi-bounding-box"></i><span>Geofence-lari sinxronlasdir</span>
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
