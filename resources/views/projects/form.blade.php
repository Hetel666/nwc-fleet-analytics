@extends('layouts.app')

@section('title', __('app.projects').' | '.__('app.app_name'))
@section('page-title', $project->exists ? __('app.edit') : __('app.create'))

@section('content')
    <form method="POST" action="{{ $project->exists ? route('projects.update', $project) : route('projects.store') }}" class="panel p-4">
        @csrf
        @if($project->exists)
            @method('PUT')
        @endif

        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">{{ __('app.name') }}</label>
                <input name="name" value="{{ old('name', $project->name) }}" class="form-control" required>
            </div>
            <div class="col-md-3">
                <label class="form-label">{{ __('app.code') }}</label>
                <input name="code" value="{{ old('code', $project->code) }}" class="form-control">
            </div>
            <div class="col-12">
                <label class="form-label">{{ __('app.description') }}</label>
                <textarea name="description" rows="4" class="form-control">{{ old('description', $project->description) }}</textarea>
            </div>
            <div class="col-md-6">
                <label class="form-label">Wialon NWC qrup ID</label>
                <input name="wialon_group_nwc" value="{{ old('wialon_group_nwc', $wialonGroups[\App\Models\Equipment::OWNERSHIP_NWC] ?? '') }}" class="form-control">
            </div>
            <div class="col-md-6">
                <label class="form-label">Wialon Icare qrup ID</label>
                <input name="wialon_group_icare" value="{{ old('wialon_group_icare', $wialonGroups[\App\Models\Equipment::OWNERSHIP_ICARE] ?? '') }}" class="form-control">
            </div>
            <div class="col-12">
                <label class="form-check">
                    <input type="checkbox" name="active" value="1" class="form-check-input" @checked(old('active', $project->exists ? $project->active : true))>
                    <span class="form-check-label">{{ __('app.active') }}</span>
                </label>
            </div>
        </div>

        <div class="mt-4 d-flex gap-2">
            <button class="btn btn-primary btn-icon"><i class="bi bi-check2"></i><span>{{ __('app.save') }}</span></button>
            <a href="{{ route('projects.index') }}" class="btn btn-outline-secondary">{{ __('app.cancel') }}</a>
        </div>
    </form>
@endsection
