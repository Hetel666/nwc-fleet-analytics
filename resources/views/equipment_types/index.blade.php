@extends('layouts.app')

@section('title', __('app.equipment_types').' | '.__('app.app_name'))
@section('page-title', __('app.equipment_types'))

@section('content')
    <div class="d-flex justify-content-end mb-3">
        <a href="{{ route('equipment-types.create') }}" class="btn btn-primary btn-icon">
            <i class="bi bi-plus-lg"></i><span>{{ __('app.create') }}</span>
        </a>
    </div>

    <section class="panel p-3">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th>{{ __('app.name') }}</th>
                        <th class="text-end">{{ __('app.equipment') }}</th>
                        <th class="text-end"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($types as $type)
                        <tr>
                            <td class="fw-semibold">{{ $type->name }}</td>
                            <td class="text-end">{{ $type->equipment_count }}</td>
                            <td class="text-end">
                                <a href="{{ route('equipment-types.edit', $type) }}" class="btn btn-sm btn-outline-primary" title="{{ __('app.edit') }}"><i class="bi bi-pencil"></i></a>
                                @include('partials.delete-button', ['action' => route('equipment-types.destroy', $type)])
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $types->links() }}</div>
    </section>
@endsection
