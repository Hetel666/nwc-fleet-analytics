<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', __('app.app_name'))</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --fleet-blue: #1f6feb;
            --fleet-green: #24b35b;
            --fleet-ink: #16213e;
            --fleet-muted: #6b7895;
            --fleet-line: #e7ecf4;
            --fleet-bg: #f5f7fb;
        }
        body {
            background: var(--fleet-bg);
            color: var(--fleet-ink);
            font-size: 14px;
        }
        .app-shell {
            min-height: 100vh;
        }
        .sidebar {
            width: 250px;
            background: #fff;
            border-right: 1px solid var(--fleet-line);
            position: fixed;
            inset: 0 auto 0 0;
            z-index: 1030;
        }
        .brand-logo {
            display: block;
            width: 168px;
            max-width: 100%;
            height: auto;
        }
        .brand-title {
            line-height: 1.15;
        }
        .nav-link {
            color: #52607a;
            border-radius: 8px;
            padding: .72rem .85rem;
            display: flex;
            align-items: center;
            gap: .75rem;
            font-weight: 600;
        }
        .nav-link.active,
        .nav-link:hover {
            color: #fff;
            background: var(--fleet-blue);
        }
        .content {
            margin-left: 250px;
            min-height: 100vh;
        }
        .topbar {
            min-height: 72px;
            background: rgba(255, 255, 255, .92);
            border-bottom: 1px solid var(--fleet-line);
            backdrop-filter: blur(12px);
            position: sticky;
            top: 0;
            z-index: 1020;
        }
        .panel,
        .metric-card {
            background: #fff;
            border: 1px solid var(--fleet-line);
            border-radius: 8px;
            box-shadow: 0 8px 28px rgba(24, 39, 75, .04);
        }
        .metric-icon {
            width: 54px;
            height: 54px;
            border-radius: 14px;
            display: grid;
            place-items: center;
            font-size: 1.6rem;
        }
        .metric-title {
            color: var(--fleet-muted);
            font-weight: 700;
            font-size: .78rem;
        }
        .metric-value {
            font-size: clamp(1.25rem, 2vw, 1.85rem);
            font-weight: 800;
            line-height: 1.1;
        }
        .change-up {
            color: var(--fleet-green);
        }
        .change-down {
            color: #dc3545;
        }
        .table-sm td,
        .table-sm th {
            padding: .42rem .55rem;
        }
        .chart-box {
            height: 260px;
        }
        .form-control,
        .form-select,
        .btn {
            border-radius: 8px;
        }
        .btn-icon {
            display: inline-flex;
            align-items: center;
            gap: .45rem;
        }
        @media (max-width: 991px) {
            .sidebar {
                position: static;
                width: 100%;
                border-right: 0;
                border-bottom: 1px solid var(--fleet-line);
            }
            .content {
                margin-left: 0;
            }
            .sidebar .nav {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
    </style>
    @stack('styles')
</head>
<body>
<div class="app-shell">
    <aside class="sidebar p-3">
        <div class="mb-4">
            <img src="{{ asset('assets/north-west-logo.png') }}" alt="North West" class="brand-logo mb-2">
            <div class="brand-title">
                <div class="fw-bold fs-5">{{ __('app.app_name') }}</div>
                <div class="text-secondary small">{{ __('app.tagline') }}</div>
            </div>
        </div>

        @php
            $dashboardYesterday = now(config('app.timezone'))->subDay()->toDateString();
        @endphp

        <nav class="nav flex-column gap-1">
            <a class="nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard', ['period' => 'yesterday', 'date_from' => $dashboardYesterday, 'date_to' => $dashboardYesterday]) }}">
                <i class="bi bi-speedometer2"></i><span>{{ __('app.dashboard') }}</span>
            </a>
            @if (auth()->user()?->isAdmin())
                <a class="nav-link {{ request()->routeIs('admin.dashboard-analytics.*') ? 'active' : '' }}" href="{{ route('admin.dashboard-analytics.index') }}">
                    <i class="bi bi-diagram-3"></i><span>Dashboard mənbələri</span>
                </a>
                <a class="nav-link {{ request()->routeIs('projects.*') ? 'active' : '' }}" href="{{ route('projects.index') }}">
                    <i class="bi bi-folder2-open"></i><span>{{ __('app.projects') }}</span>
                </a>
                <a class="nav-link {{ request()->routeIs('equipment.*') ? 'active' : '' }}" href="{{ route('equipment.index') }}">
                    <i class="bi bi-truck"></i><span>{{ __('app.equipment') }}</span>
                </a>
                <a class="nav-link {{ request()->routeIs('equipment-types.*') ? 'active' : '' }}" href="{{ route('equipment-types.index') }}">
                    <i class="bi bi-diagram-3"></i><span>{{ __('app.equipment_types') }}</span>
                </a>
                <a class="nav-link {{ request()->routeIs('geofences.*') ? 'active' : '' }}" href="{{ route('geofences.index') }}">
                    <i class="bi bi-geo-alt"></i><span>{{ __('app.geofences') }}</span>
                </a>
                <a class="nav-link {{ request()->routeIs('users.*') ? 'active' : '' }}" href="{{ route('users.index') }}">
                    <i class="bi bi-people"></i><span>{{ __('app.users') }}</span>
                </a>
                <a class="nav-link {{ request()->routeIs('admin.historical-recalculations.*') ? 'active' : '' }}" href="{{ route('admin.historical-recalculations.index') }}">
                    <i class="bi bi-clock-history"></i><span>Tarixi məlumatların yenilənməsi</span>
                </a>
                <a class="nav-link {{ request()->routeIs('settings.*') ? 'active' : '' }}" href="{{ route('settings.edit') }}">
                    <i class="bi bi-gear"></i><span>{{ __('app.settings') }}</span>
                </a>
            @endif
        </nav>

        <div class="position-absolute bottom-0 start-0 end-0 p-3 border-top">
            <div class="d-flex align-items-center gap-2">
                <div class="rounded-circle bg-primary text-white d-grid" style="width:38px;height:38px;place-items:center;">
                    {{ mb_substr(auth()->user()->name ?? 'U', 0, 1) }}
                </div>
                <div class="flex-grow-1 overflow-hidden">
                    <div class="fw-semibold text-truncate">{{ auth()->user()->name ?? '' }}</div>
                    <div class="small text-secondary">{{ auth()->user()->role ?? '' }}</div>
                </div>
            </div>
        </div>
    </aside>

    <main class="content">
        <header class="topbar d-flex align-items-center px-4">
            <div class="flex-grow-1">
                <h1 class="h5 mb-0 fw-bold">@yield('page-title', __('app.dashboard'))</h1>
                @hasSection('page-subtitle')
                    <div class="text-secondary small mt-1">@yield('page-subtitle')</div>
                @endif
            </div>
            <div class="dropdown me-2">
                <button class="btn btn-outline-secondary btn-icon dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-translate"></i><span>{{ __('app.language') }}</span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    @foreach (['az' => 'Azərbaycanca', 'ru' => 'Русский', 'en' => 'English'] as $locale => $label)
                        <li>
                            <a class="dropdown-item d-flex align-items-center justify-content-between gap-3 {{ app()->getLocale() === $locale ? 'active' : '' }}" href="{{ route('language.update', $locale) }}">
                                <span>{{ $label }}</span>
                                @if (app()->getLocale() === $locale)
                                    <i class="bi bi-check2"></i>
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="btn btn-outline-secondary btn-icon">
                    <i class="bi bi-box-arrow-right"></i><span>{{ __('app.logout') }}</span>
                </button>
            </form>
        </header>

        <div class="p-4">
            @include('partials.flash')
            @yield('content')
        </div>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.2.0/dist/chartjs-plugin-datalabels.min.js"></script>
@stack('scripts')
</body>
</html>
