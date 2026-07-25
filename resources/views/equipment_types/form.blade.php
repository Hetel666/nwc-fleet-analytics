@extends('layouts.app')

@section('title', __('app.equipment_types').' | '.__('app.app_name'))
@section('page-title', $type->exists ? __('app.edit') : __('app.create'))

@section('content')
    <form method="POST" action="{{ $type->exists ? route('equipment-types.update', $type) : route('equipment-types.store') }}" class="panel p-4">
        @csrf
        @if($type->exists)
            @method('PUT')
        @endif
        <div class="col-md-6">
            <label class="form-label">{{ __('app.name') }}</label>
            <input name="name" value="{{ old('name', $type->name) }}" class="form-control" required>
        </div>
        <div class="mt-4 d-flex gap-2">
            <button class="btn btn-primary btn-icon"><i class="bi bi-check2"></i><span>{{ __('app.save') }}</span></button>
            <a href="{{ route('equipment-types.index') }}" class="btn btn-outline-secondary">{{ __('app.cancel') }}</a>
        </div>
    </form>
@endsection
