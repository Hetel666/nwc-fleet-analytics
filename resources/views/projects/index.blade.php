@extends('layouts.app')

@section('title', __('app.projects').' | '.__('app.app_name'))
@section('page-title', __('app.projects'))

@section('content')
    <div class="d-flex justify-content-end mb-3">
        <a href="{{ route('projects.create') }}" class="btn btn-primary btn-icon">
            <i class="bi bi-plus-lg"></i><span>{{ __('app.create') }}</span>
        </a>
    </div>

    <section class="panel p-3">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th>{{ __('app.name') }}</th>
                        <th>{{ __('app.code') }}</th>
                        <th class="text-end">Wialon qrupları</th>
                        <th class="text-end">Geozona qruplari</th>
                        <th class="text-end">NWC</th>
                        <th class="text-end">İCARƏ</th>
                        <th class="text-end">{{ __('app.equipment') }}</th>
                        <th class="text-end">Online</th>
                        <th class="text-end">Offline</th>
                        <th class="text-end">{{ __('app.geofences') }}</th>
                        <th>{{ __('app.status') }}</th>
                        <th class="text-end"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($projects as $project)
                        <tr>
                            <td>
                                <div class="fw-semibold">{{ $project->name }}</div>
                                <a href="{{ route('projects.dashboard', $project) }}" class="small text-decoration-none">{{ __('app.dashboard') }}</a>
                            </td>
                            <td>{{ $project->code }}</td>
                            <td class="text-end">{{ $project->wialon_groups_count }}</td>
                            <td class="text-end">{{ $project->wialon_geofence_groups_count }}</td>
                            <td class="text-end">{{ $project->nwc_equipment_count }}</td>
                            <td class="text-end">{{ $project->icare_equipment_count }}</td>
                            <td class="text-end">{{ $project->equipment_count }}</td>
                            <td class="text-end">{{ $project->online_equipment_count }}</td>
                            <td class="text-end">{{ $project->offline_equipment_count }}</td>
                            <td class="text-end">{{ $project->geofences_count }}</td>
                            <td><span class="badge text-bg-{{ $project->active ? 'success' : 'secondary' }}">{{ $project->active ? __('app.active') : __('app.inactive') }}</span></td>
                            <td class="text-end">
                                <a href="{{ route('projects.edit', $project) }}" class="btn btn-sm btn-outline-primary" title="{{ __('app.edit') }}"><i class="bi bi-pencil"></i></a>
                                @include('partials.delete-button', ['action' => route('projects.destroy', $project)])
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $projects->links() }}</div>
    </section>
@endsection
