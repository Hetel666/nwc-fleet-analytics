@extends('layouts.app')

@section('title', __('app.users').' | '.__('app.app_name'))
@section('page-title', $user->exists ? __('app.edit') : __('app.create'))

@section('content')
    @php
        $selectedDashboardSections = old('dashboard_sections_present')
            ? old('dashboard_sections', [])
            : ($user->dashboard_sections ?? array_keys($dashboardSections));
        $selectedDashboardSections = collect((array) $selectedDashboardSections)
            ->map(fn ($section): string => (string) $section)
            ->all();
        $selectedPermissions = old('permissions_present')
            ? old('permissions', [])
            : ($user->permissions ?? []);
        $selectedPermissions = collect((array) $selectedPermissions)
            ->map(fn ($permission): string => (string) $permission)
            ->all();
    @endphp

    <form method="POST" action="{{ $user->exists ? route('users.update', $user) : route('users.store') }}" class="panel p-4">
        @csrf
        @if($user->exists)
            @method('PUT')
        @endif

        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">{{ __('app.name') }}</label>
                <input name="name" value="{{ old('name', $user->name) }}" class="form-control @error('name') is-invalid @enderror" required>
                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-6">
                <label class="form-label">{{ __('app.email') }}</label>
                <input type="email" name="email" value="{{ old('email', $user->email) }}" class="form-control @error('email') is-invalid @enderror" required>
                @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-6">
                <label class="form-label">{{ __('app.role') }}</label>
                <select name="role" class="form-select @error('role') is-invalid @enderror" @disabled(auth()->id() === $user->id)>
                    @foreach ($roles as $value => $label)
                        <option value="{{ $value }}" @selected(old('role', $user->role) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                @if (auth()->id() === $user->id)
                    <input type="hidden" name="role" value="{{ $user->role }}">
                    <div class="form-text">Öz rolunuzu dəyişmək olmaz.</div>
                @endif
                @error('role')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-6 d-flex align-items-end">
                <label class="form-check mb-2">
                    <input type="checkbox" name="active" value="1" class="form-check-input" @checked(old('active', $user->exists ? $user->active : true)) @disabled(auth()->id() === $user->id)>
                    <span class="form-check-label">{{ __('app.active') }}</span>
                </label>
                @if (auth()->id() === $user->id)
                    <input type="hidden" name="active" value="1">
                @endif
            </div>
            <div class="col-12">
                <div class="border rounded-3 p-3">
                    <div class="d-flex flex-column flex-lg-row gap-2 justify-content-between mb-3">
                        <div>
                            <div class="fw-semibold">{{ __('app.dashboard_access') }}</div>
                            <div class="form-text">{{ __('app.dashboard_access_hint') }}</div>
                        </div>
                        <span class="badge text-bg-light align-self-start">{{ __('app.dashboard') }}</span>
                    </div>
                    <input type="hidden" name="dashboard_sections_present" value="1">
                    <div class="row g-2">
                        @foreach ($dashboardSections as $value => $label)
                            <div class="col-md-4">
                                <label class="form-check">
                                    <input
                                        type="checkbox"
                                        name="dashboard_sections[]"
                                        value="{{ $value }}"
                                        id="dashboardSection{{ \Illuminate\Support\Str::studly($value) }}"
                                        class="form-check-input @error('dashboard_sections') is-invalid @enderror @error('dashboard_sections.*') is-invalid @enderror"
                                        @checked(in_array($value, $selectedDashboardSections, true))
                                    >
                                    <span class="form-check-label">{{ $label }}</span>
                                </label>
                            </div>
                        @endforeach
                    </div>
                    @error('dashboard_sections')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    @error('dashboard_sections.*')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    <div class="form-text mt-2">{{ __('app.dashboard_access_admin_hint') }}</div>
                </div>
            </div>
            <div class="col-12">
                <div class="border rounded-3 p-3">
                    <div class="d-flex flex-column flex-lg-row gap-2 justify-content-between mb-3">
                        <div>
                            <div class="fw-semibold">Əlavə giriş hüquqları</div>
                            <div class="form-text">Viewer istifadəçiyə yalnız lazım olan inzibati imkanları seçin.</div>
                        </div>
                        <span class="badge text-bg-light align-self-start">Permissions</span>
                    </div>
                    <input type="hidden" name="permissions_present" value="1">
                    <div class="row g-2">
                        @foreach ($permissions as $value => $label)
                            <div class="col-md-4">
                                <label class="form-check">
                                    <input
                                        type="checkbox"
                                        name="permissions[]"
                                        value="{{ $value }}"
                                        id="permission{{ \Illuminate\Support\Str::studly(str_replace('.', '_', $value)) }}"
                                        class="form-check-input @error('permissions') is-invalid @enderror @error('permissions.*') is-invalid @enderror"
                                        @checked(in_array($value, $selectedPermissions, true))
                                    >
                                    <span class="form-check-label">{{ $label }}</span>
                                    <span class="form-text d-block">{{ $value }}</span>
                                </label>
                            </div>
                        @endforeach
                    </div>
                    @error('permissions')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    @error('permissions.*')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    <div class="form-text mt-2">Admin istifadəçilər bu hüquqları avtomatik alır; adi istifadəçilərdə yalnız seçilənlər aktivdir.</div>
                </div>
            </div>
            <div class="col-md-6">
                <label class="form-label">{{ __('app.password') }}</label>
                <input type="password" name="password" class="form-control @error('password') is-invalid @enderror" @required(! $user->exists)>
                @if ($user->exists)
                    <div class="form-text">Dəyişmək istəmirsinizsə boş saxlayın.</div>
                @endif
                @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-6">
                <label class="form-label">{{ __('app.password_confirmation') }}</label>
                <input type="password" name="password_confirmation" class="form-control">
            </div>
        </div>

        <div class="mt-4 d-flex gap-2">
            <button class="btn btn-primary btn-icon"><i class="bi bi-check2"></i><span>{{ __('app.save') }}</span></button>
            <a href="{{ route('users.index') }}" class="btn btn-outline-secondary">{{ __('app.cancel') }}</a>
        </div>
    </form>
@endsection
