@extends('layouts.app')

@section('title', __('app.geofences').' | '.__('app.app_name'))
@section('page-title', $geofence->exists ? __('app.edit') : __('app.create'))

@section('content')
    <form method="POST" action="{{ $geofence->exists ? route('geofences.update', $geofence) : route('geofences.store') }}" class="panel p-4">
        @csrf
        @if($geofence->exists)
            @method('PUT')
        @endif

        <div class="row g-3">
            <div class="col-md-5">
                <label class="form-label">{{ __('app.name') }}</label>
                <input name="name" value="{{ old('name', $geofence->name) }}" class="form-control" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">{{ __('app.project') }}</label>
                <select name="project_id" class="form-select" required>
                    @foreach ($projects as $project)
                        <option value="{{ $project->id }}" @selected((string) old('project_id', $geofence->project_id) === (string) $project->id)>{{ $project->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">{{ __('app.wialon_geofence_id') }}</label>
                <input name="wialon_geofence_id" value="{{ old('wialon_geofence_id', $geofence->wialon_geofence_id) }}" class="form-control">
            </div>
            <div class="col-12">
                <label class="form-label">{{ __('app.geometry_json') }}</label>
                <textarea name="geometry_json" rows="8" class="form-control font-monospace">{{ old('geometry_json', $geofence->geometry_json ? json_encode($geofence->geometry_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : '') }}</textarea>
            </div>
            <div class="col-12">
                <label class="form-check">
                    <input type="checkbox" name="active" value="1" class="form-check-input" @checked(old('active', $geofence->exists ? $geofence->active : true))>
                    <span class="form-check-label">{{ __('app.active') }}</span>
                </label>
            </div>
        </div>

        <div class="mt-4 d-flex gap-2">
            <button class="btn btn-primary btn-icon"><i class="bi bi-check2"></i><span>{{ __('app.save') }}</span></button>
            <a href="{{ route('geofences.index') }}" class="btn btn-outline-secondary">{{ __('app.cancel') }}</a>
        </div>
    </form>
@endsection
