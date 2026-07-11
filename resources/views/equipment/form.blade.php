@extends('layouts.app')

@section('title', __('app.equipment').' | '.__('app.app_name'))
@section('page-title', $equipment->exists ? __('app.edit') : __('app.create'))

@section('content')
    <form method="POST" action="{{ $equipment->exists ? route('equipment.update', $equipment) : route('equipment.store') }}" class="panel p-4">
        @csrf
        @if($equipment->exists)
            @method('PUT')
        @endif

        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">{{ __('app.name') }}</label>
                <input name="name" value="{{ old('name', $equipment->name) }}" class="form-control" required>
            </div>
            <div class="col-md-3">
                <label class="form-label">{{ __('app.registration_number') }}</label>
                <input name="registration_number" value="{{ old('registration_number', $equipment->registration_number) }}" class="form-control">
            </div>
            <div class="col-md-3">
                <label class="form-label">{{ __('app.wialon_unit_id') }}</label>
                <input name="wialon_unit_id" value="{{ old('wialon_unit_id', $equipment->wialon_unit_id) }}" class="form-control" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">{{ __('app.type') }}</label>
                <select name="equipment_type_id" class="form-select" required>
                    @foreach ($types as $type)
                        <option value="{{ $type->id }}" @selected((string) old('equipment_type_id', $equipment->equipment_type_id) === (string) $type->id)>{{ $type->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">{{ __('app.project') }}</label>
                <select name="project_id" class="form-select">
                    <option value="">-</option>
                    @foreach ($projects as $project)
                        <option value="{{ $project->id }}" @selected((string) old('project_id', $equipment->project_id) === (string) $project->id)>{{ $project->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">{{ __('app.ownership') }}</label>
                <select name="ownership_type" class="form-select" required>
                    @foreach ($ownershipTypes as $type)
                        <option value="{{ $type }}" @selected(old('ownership_type', $equipment->ownership_type ?: 'NWC') === $type)>{{ $type }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">{{ __('app.planned_hours') }}</label>
                <input type="number" step="0.25" min="1" max="24" name="planned_daily_hours" value="{{ old('planned_daily_hours', $equipment->planned_daily_hours ?: 10) }}" class="form-control" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">{{ __('app.calculation_mode') }}</label>
                <select name="calculation_mode" class="form-select" required>
                    @foreach ($calculationModes as $mode)
                        <option value="{{ $mode }}" @selected(old('calculation_mode', $equipment->calculation_mode ?: 'engine_hours') === $mode)>{{ $mode }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-12">
                <label class="form-check">
                    <input type="checkbox" name="active" value="1" class="form-check-input" @checked(old('active', $equipment->exists ? $equipment->active : true))>
                    <span class="form-check-label">{{ __('app.active') }}</span>
                </label>
            </div>
        </div>

        <div class="mt-4 d-flex gap-2">
            <button class="btn btn-primary btn-icon"><i class="bi bi-check2"></i><span>{{ __('app.save') }}</span></button>
            <a href="{{ route('equipment.index') }}" class="btn btn-outline-secondary">{{ __('app.cancel') }}</a>
        </div>
    </form>
@endsection
