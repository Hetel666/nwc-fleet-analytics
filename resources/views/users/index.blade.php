@extends('layouts.app')

@section('title', __('app.users').' | '.__('app.app_name'))
@section('page-title', __('app.users'))

@section('content')
    <div class="d-flex justify-content-end mb-3">
        <a href="{{ route('users.create') }}" class="btn btn-primary btn-icon">
            <i class="bi bi-plus-lg"></i><span>{{ __('app.create') }}</span>
        </a>
    </div>

    <section class="panel p-3">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th>{{ __('app.name') }}</th>
                        <th>{{ __('app.email') }}</th>
                        <th>{{ __('app.role') }}</th>
                        <th>{{ __('app.status') }}</th>
                        <th class="text-end"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($users as $user)
                        <tr>
                            <td>
                                <div class="fw-semibold">{{ $user->name }}</div>
                                @if (auth()->id() === $user->id)
                                    <div class="small text-secondary">Cari istifadəçi</div>
                                @endif
                            </td>
                            <td>{{ $user->email }}</td>
                            <td>
                                <span class="badge text-bg-{{ $user->isAdmin() ? 'primary' : 'secondary' }}">
                                    {{ $user->isAdmin() ? 'Admin' : 'Viewer' }}
                                </span>
                            </td>
                            <td>
                                <span class="badge text-bg-{{ $user->active ? 'success' : 'secondary' }}">
                                    {{ $user->active ? __('app.active') : __('app.inactive') }}
                                </span>
                            </td>
                            <td class="text-end">
                                <a href="{{ route('users.edit', $user) }}" class="btn btn-sm btn-outline-primary" title="{{ __('app.edit') }}">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                @if (auth()->id() !== $user->id)
                                    @include('partials.delete-button', ['action' => route('users.destroy', $user)])
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $users->links() }}</div>
    </section>
@endsection
