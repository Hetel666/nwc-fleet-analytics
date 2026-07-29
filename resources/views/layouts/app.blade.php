<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', __('app.app_name'))</title>
    <script>
        (() => {
            const storedTheme = localStorage.getItem('fleet-theme');
            const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
            document.documentElement.dataset.theme = storedTheme || (prefersDark ? 'dark' : 'light');
        })();
    </script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --fleet-blue: #2563EB;
            --fleet-green: #22C55E;
            --fleet-ink: #0F172A;
            --fleet-muted: #64748B;
            --fleet-line: #E5E7EB;
            --fleet-bg: #F6F8FC;
            --fleet-card: #FFFFFF;
            --fleet-card-soft: #F8FAFC;
            --fleet-sidebar: #FFFFFF;
            --fleet-hover: #EFF6FF;
            --fleet-shadow: 0 18px 48px rgba(15, 23, 42, .06);
            --fleet-sidebar-width: 280px;
            --fleet-sidebar-collapsed-width: 72px;
        }
        [data-theme="dark"] {
            --fleet-blue: #3B82F6;
            --fleet-green: #22C55E;
            --fleet-ink: #F8FAFC;
            --fleet-muted: #94A3B8;
            --fleet-line: #1E293B;
            --fleet-bg: #0F172A;
            --fleet-card: #111827;
            --fleet-card-soft: #0B1220;
            --fleet-sidebar: #111827;
            --fleet-hover: #1E293B;
            --fleet-shadow: 0 18px 48px rgba(0, 0, 0, .28);
        }
        body {
            background: var(--fleet-bg);
            color: var(--fleet-ink);
            font-size: 14px;
            transition: background .2s ease, color .2s ease;
        }
        .app-shell {
            min-height: 100vh;
        }
        .sidebar {
            width: var(--fleet-sidebar-width);
            background: var(--fleet-sidebar);
            border-right: 1px solid var(--fleet-line);
            position: fixed;
            inset: 0 auto 0 0;
            z-index: 1030;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            transition: width .2s ease, transform .2s ease, background .2s ease, border-color .2s ease;
        }
        .sidebar-inner {
            min-height: 100%;
            display: flex;
            flex-direction: column;
            padding: 18px 14px 14px;
            gap: 14px;
        }
        .sidebar-logo-area {
            height: 110px;
            display: flex;
            align-items: center;
            gap: 12px;
            border-bottom: 1px solid var(--fleet-line);
            padding: 0 4px 16px;
            transition: border-color .2s ease;
        }
        .brand-logo {
            display: block;
            width: 86px;
            max-width: 100%;
            height: auto;
            flex: 0 0 auto;
        }
        .brand-title {
            line-height: 1.15;
            min-width: 0;
        }
        .brand-name {
            color: var(--fleet-ink);
            font-size: 18px;
            font-weight: 800;
            letter-spacing: 0;
        }
        .brand-subtitle {
            color: var(--fleet-muted);
            font-size: 12px;
            line-height: 1.3;
            margin-top: 4px;
        }
        .sidebar-search {
            position: relative;
            margin: 0 2px;
        }
        .sidebar-search .lucide {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            width: 16px;
            height: 16px;
            color: var(--fleet-muted);
        }
        .sidebar-search input {
            width: 100%;
            height: 38px;
            border: 1px solid var(--fleet-line);
            border-radius: 12px;
            padding: 0 72px 0 36px;
            color: var(--fleet-ink);
            background: var(--fleet-card-soft);
            font-size: 13px;
            outline: 0;
            transition: border-color .15s ease, background .15s ease, box-shadow .15s ease;
        }
        .sidebar-search input:focus {
            border-color: rgba(37, 99, 235, .45);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, .12);
            background: var(--fleet-card);
        }
        .sidebar-shortcut {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            border: 1px solid var(--fleet-line);
            border-radius: 7px;
            padding: 2px 6px;
            color: var(--fleet-muted);
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .02em;
            background: var(--fleet-card);
        }
        .sidebar-section {
            display: grid;
            gap: 6px;
        }
        .sidebar-section-title {
            padding: 10px 10px 4px;
            color: var(--fleet-muted);
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .08em;
            text-transform: uppercase;
        }
        .nav-link {
            color: var(--fleet-muted);
            border-radius: 12px;
            padding: .7rem .75rem;
            display: flex;
            align-items: center;
            gap: .72rem;
            font-size: 14px;
            font-weight: 500;
            min-height: 42px;
            transition: color .15s ease, background .15s ease;
        }
        .nav-link .lucide {
            width: 18px;
            height: 18px;
            stroke-width: 1.75;
            flex: 0 0 auto;
        }
        .nav-link:hover {
            color: var(--fleet-blue);
            background: var(--fleet-hover);
        }
        .nav-link.active {
            color: #fff;
            background: var(--fleet-blue);
        }
        .sidebar-scroll {
            flex: 1 1 auto;
            overflow-y: auto;
            padding-right: 2px;
        }
        .sidebar-user-panel {
            border-top: 1px solid var(--fleet-line);
            padding-top: 14px;
        }
        .sidebar-avatar {
            width: 38px;
            height: 38px;
            border-radius: 999px;
            display: grid;
            place-items: center;
            color: #fff;
            background: var(--fleet-blue);
            font-weight: 900;
            flex: 0 0 auto;
        }
        .sidebar-version {
            color: var(--fleet-muted);
            font-size: 11px;
            font-weight: 700;
            margin-top: 8px;
        }
        .theme-toggle {
            width: 34px;
            height: 34px;
            border: 1px solid var(--fleet-line);
            border-radius: 999px;
            display: inline-grid;
            place-items: center;
            color: var(--fleet-muted);
            background: var(--fleet-card);
            transition: background .15s ease, color .15s ease, border-color .15s ease;
        }
        .theme-toggle:hover {
            color: var(--fleet-blue);
            background: var(--fleet-hover);
        }
        .content {
            margin-left: var(--fleet-sidebar-width);
            min-height: 100vh;
            transition: margin-left .2s ease;
        }
        .topbar {
            min-height: 72px;
            background: color-mix(in srgb, var(--fleet-card) 92%, transparent);
            border-bottom: 1px solid var(--fleet-line);
            backdrop-filter: blur(12px);
            position: sticky;
            top: 0;
            z-index: 1020;
            transition: background .2s ease, border-color .2s ease;
        }
        .sidebar-toggle {
            width: 38px;
            height: 38px;
            display: none;
            place-items: center;
            border: 1px solid var(--fleet-line);
            border-radius: 12px;
            color: var(--fleet-muted);
            background: var(--fleet-card);
        }
        .panel,
        .metric-card {
            background: var(--fleet-card);
            border: 1px solid var(--fleet-line);
            border-radius: 14px;
            box-shadow: var(--fleet-shadow);
            transition: background .2s ease, border-color .2s ease, box-shadow .2s ease, transform .18s ease;
        }
        .panel:hover,
        .metric-card:hover {
            transform: translateY(-1px);
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
            border-radius: 10px;
        }
        .btn-icon {
            display: inline-flex;
            align-items: center;
            gap: .45rem;
        }
        [data-theme="dark"] .text-secondary {
            color: var(--fleet-muted) !important;
        }
        [data-theme="dark"] .dropdown-menu,
        [data-theme="dark"] .form-control,
        [data-theme="dark"] .form-select {
            color: var(--fleet-ink);
            background-color: var(--fleet-card);
            border-color: var(--fleet-line);
        }
        [data-theme="dark"] .table {
            --bs-table-color: var(--fleet-ink);
            --bs-table-bg: transparent;
            --bs-table-border-color: var(--fleet-line);
        }
        @media (max-width: 1440px) and (min-width: 992px) {
            .sidebar {
                width: var(--fleet-sidebar-collapsed-width);
            }
            .content {
                margin-left: var(--fleet-sidebar-collapsed-width);
            }
            .sidebar-inner {
                padding-inline: 10px;
            }
            .sidebar-logo-area {
                justify-content: center;
                padding-inline: 0;
            }
            .brand-logo {
                width: 46px;
            }
            .brand-title,
            .sidebar-search,
            .sidebar-section-title,
            .nav-link span,
            .sidebar-user-meta,
            .sidebar-version {
                display: none !important;
            }
            .nav-link {
                justify-content: center;
                padding-inline: 0;
            }
            .sidebar-user-panel .d-flex {
                justify-content: center;
            }
            .theme-toggle {
                margin: 10px auto 0;
            }
        }
        @media (max-width: 991px) {
            .sidebar {
                width: var(--fleet-sidebar-width);
                transform: translateX(-100%);
                box-shadow: 20px 0 50px rgba(15, 23, 42, .16);
            }
            body.sidebar-open .sidebar {
                transform: translateX(0);
            }
            .content {
                margin-left: 0;
            }
            .sidebar-toggle {
                display: inline-grid;
            }
        }
    </style>
    @stack('styles')
</head>
<body>
<div class="app-shell">
    <aside class="sidebar">
        <div class="sidebar-inner">
            <div class="sidebar-logo-area">
                <img src="{{ asset('assets/north-west-logo.png') }}" alt="North West" class="brand-logo">
                <div class="brand-title">
                    <div class="brand-name">{{ __('app.app_name') }}</div>
                    <div class="brand-subtitle">Ağıllı donanma idarəetməsi</div>
                </div>
            </div>

            <div class="sidebar-search">
                <i data-lucide="search"></i>
                <input id="sidebarSearchInput" type="search" placeholder="Axtar..." autocomplete="off">
                <span class="sidebar-shortcut">CTRL + K</span>
            </div>

            @php
                $dashboardYesterday = now(config('app.timezone'))->subDay()->toDateString();
            @endphp

            <nav class="sidebar-scroll">
                <div class="sidebar-section">
                    <div class="sidebar-section-title">Dashboard</div>
                    <a class="nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard', ['period' => 'yesterday', 'date_from' => $dashboardYesterday, 'date_to' => $dashboardYesterday]) }}">
                        <i data-lucide="layout-dashboard"></i><span>{{ __('app.dashboard') }}</span>
                    </a>
                    <a class="nav-link {{ request()->routeIs('geofence-violations.*') ? 'active' : '' }}" href="{{ route('geofence-violations.index') }}">
                        <i data-lucide="shield-alert"></i><span>Geofence Pozuntuları</span>
                    </a>
                    @if (auth()->user()?->isAdmin())
                        <a class="nav-link {{ request()->routeIs('admin.dashboard-analytics.*') ? 'active' : '' }}" href="{{ route('admin.dashboard-analytics.index') }}">
                            <i data-lucide="database"></i><span>Dashboard mənbələri</span>
                        </a>
                    @endif
                </div>

                @if (auth()->user()?->isAdmin())
                    <div class="sidebar-section">
                        <div class="sidebar-section-title">Fleet</div>
                        <a class="nav-link {{ request()->routeIs('projects.*') ? 'active' : '' }}" href="{{ route('projects.index') }}">
                            <i data-lucide="folder-kanban"></i><span>{{ __('app.projects') }}</span>
                        </a>
                        <a class="nav-link {{ request()->routeIs('equipment.*') ? 'active' : '' }}" href="{{ route('equipment.index') }}">
                            <i data-lucide="truck"></i><span>{{ __('app.equipment') }}</span>
                        </a>
                        <a class="nav-link {{ request()->routeIs('equipment-types.*') ? 'active' : '' }}" href="{{ route('equipment-types.index') }}">
                            <i data-lucide="layers-3"></i><span>{{ __('app.equipment_types') }}</span>
                        </a>
                    </div>

                    <div class="sidebar-section">
                        <div class="sidebar-section-title">Monitoring</div>
                        <a class="nav-link {{ request()->routeIs('geofences.*') ? 'active' : '' }}" href="{{ route('geofences.index') }}">
                            <i data-lucide="map-pinned"></i><span>{{ __('app.geofences') }}</span>
                        </a>
                        <a class="nav-link {{ request()->routeIs('users.*') ? 'active' : '' }}" href="{{ route('users.index') }}">
                            <i data-lucide="users"></i><span>{{ __('app.users') }}</span>
                        </a>
                        <a class="nav-link {{ request()->routeIs('admin.historical-recalculations.*') ? 'active' : '' }}" href="{{ route('admin.historical-recalculations.index') }}">
                            <i data-lucide="history"></i><span>Tarixi məlumatların yenilənməsi</span>
                        </a>
                    </div>

                    <div class="sidebar-section">
                        <div class="sidebar-section-title">Settings</div>
                        <a class="nav-link {{ request()->routeIs('settings.*') ? 'active' : '' }}" href="{{ route('settings.edit') }}">
                            <i data-lucide="settings"></i><span>{{ __('app.settings') }}</span>
                        </a>
                    </div>
                @endif
            </nav>

            <div class="sidebar-user-panel">
                <div class="d-flex align-items-center gap-2">
                    <div class="sidebar-avatar">{{ mb_substr(auth()->user()->name ?? 'U', 0, 1) }}</div>
                    <div class="sidebar-user-meta flex-grow-1 overflow-hidden">
                        <div class="fw-semibold text-truncate">Fleet Admin</div>
                        <div class="small text-secondary">admin</div>
                    </div>
                    <button id="themeToggle" type="button" class="theme-toggle" aria-label="Theme">
                        <i data-lucide="sun"></i>
                    </button>
                </div>
                <div class="sidebar-version">v2.0</div>
            </div>
        </div>
    </aside>

    <main class="content">
        <header class="topbar d-flex align-items-center px-4">
            <button id="sidebarToggle" type="button" class="sidebar-toggle me-3" aria-label="Menu">
                <i data-lucide="menu"></i>
            </button>
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
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
<script>
    const refreshThemeIcon = () => {
        const toggle = document.getElementById('themeToggle');
        if (!toggle) {
            return;
        }
        toggle.innerHTML = `<i data-lucide="${document.documentElement.dataset.theme === 'dark' ? 'moon' : 'sun'}"></i>`;
        window.lucide?.createIcons();
    };

    document.getElementById('themeToggle')?.addEventListener('click', () => {
        const nextTheme = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
        document.documentElement.dataset.theme = nextTheme;
        localStorage.setItem('fleet-theme', nextTheme);
        refreshThemeIcon();
    });

    document.getElementById('sidebarToggle')?.addEventListener('click', () => {
        document.body.classList.toggle('sidebar-open');
    });

    document.addEventListener('keydown', event => {
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            document.getElementById('sidebarSearchInput')?.focus();
        }
    });

    window.lucide?.createIcons();
    refreshThemeIcon();
</script>
@stack('scripts')
</body>
</html>
