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
                    <label class="form-label">Geofence minimum çıxış müddəti</label>
                    <input type="number" min="1" max="1440" name="geofence_min_exit_minutes" value="{{ old('geofence_min_exit_minutes', $settings['geofence_min_exit_minutes'] ?? config('fleet.geofence.min_exit_minutes')) }}" class="form-control">
                </div>

                <button class="btn btn-primary btn-icon">
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
        </div>
    </div>
@endsection
