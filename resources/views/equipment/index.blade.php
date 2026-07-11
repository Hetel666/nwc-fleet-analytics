@extends('layouts.app')

@section('title', __('app.equipment').' | '.__('app.app_name'))
@section('page-title', __('app.equipment'))

@section('content')
    <div class="d-flex flex-wrap justify-content-between gap-2 mb-3">
        <form method="GET" class="d-flex gap-2">
            <select name="project_id" class="form-select" onchange="this.form.submit()">
                <option value="">{{ __('app.all_projects') }}</option>
                @foreach ($projects as $project)
                    <option value="{{ $project->id }}" @selected((string) request('project_id') === (string) $project->id)>{{ $project->name }}</option>
                @endforeach
            </select>
        </form>
        <a href="{{ route('equipment.create') }}" class="btn btn-primary btn-icon">
            <i class="bi bi-plus-lg"></i><span>{{ __('app.create') }}</span>
        </a>
    </div>

    <section class="panel p-3">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th>{{ __('app.name') }}</th>
                        <th>{{ __('app.type') }}</th>
                        <th>{{ __('app.project') }}</th>
                        <th>{{ __('app.ownership') }}</th>
                        <th>{{ __('app.status') }}</th>
                        <th class="text-end"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($equipment as $item)
                        <tr>
                            <td>
                                <div class="fw-semibold">{{ $item->name }}</div>
                                <div class="small text-secondary">{{ $item->wialon_unit_id }}</div>
                            </td>
                            <td>{{ $item->type?->name }}</td>
                            <td>{{ $item->project?->name }}</td>
                            <td><span class="badge text-bg-{{ $item->ownership_type === 'ICARE' ? 'success' : 'primary' }}">{{ $item->ownership_type }}</span></td>
                            <td><span class="badge text-bg-{{ $item->active ? 'success' : 'secondary' }}">{{ $item->active ? __('app.active') : __('app.inactive') }}</span></td>
                            <td class="text-end">
                                <a href="{{ route('equipment.edit', $item) }}" class="btn btn-sm btn-outline-primary" title="{{ __('app.edit') }}"><i class="bi bi-pencil"></i></a>
                                @include('partials.delete-button', ['action' => route('equipment.destroy', $item)])
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $equipment->links() }}</div>
    </section>
@endsection
