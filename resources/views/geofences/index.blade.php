@extends('layouts.app')

@section('title', __('app.geofences').' | '.__('app.app_name'))
@section('page-title', __('app.geofences'))

@section('content')
    <div class="d-flex justify-content-end mb-3">
        <a href="{{ route('geofences.create') }}" class="btn btn-primary btn-icon">
            <i class="bi bi-plus-lg"></i><span>{{ __('app.create') }}</span>
        </a>
    </div>

    <section class="panel p-3">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th>{{ __('app.name') }}</th>
                        <th>{{ __('app.project') }}</th>
                        <th>{{ __('app.wialon_geofence_id') }}</th>
                        <th>{{ __('app.status') }}</th>
                        <th class="text-end"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($geofences as $geofence)
                        <tr>
                            <td class="fw-semibold">{{ $geofence->name }}</td>
                            <td>{{ $geofence->project?->name }}</td>
                            <td>{{ $geofence->wialon_geofence_id }}</td>
                            <td><span class="badge text-bg-{{ $geofence->active ? 'success' : 'secondary' }}">{{ $geofence->active ? __('app.active') : __('app.inactive') }}</span></td>
                            <td class="text-end">
                                <a href="{{ route('geofences.edit', $geofence) }}" class="btn btn-sm btn-outline-primary" title="{{ __('app.edit') }}"><i class="bi bi-pencil"></i></a>
                                @include('partials.delete-button', ['action' => route('geofences.destroy', $geofence)])
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $geofences->links() }}</div>
    </section>
@endsection
