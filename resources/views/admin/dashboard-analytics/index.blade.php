@extends('layouts.app')

@section('title', 'Dashboard mənbələri | '.__('app.app_name'))
@section('page-title', 'Dashboard mənbələri')
@section('page-subtitle', 'Hər vidjet üçün hesabat, cədvəl, service, modal və Excel bağlılığı')

@push('styles')
    <style>
        .analytics-map-hero {
            background: linear-gradient(135deg, var(--fleet-card) 0%, var(--fleet-card-soft) 100%);
            border: 1px solid var(--fleet-line);
            border-radius: 16px;
            box-shadow: var(--fleet-shadow);
        }

        .analytics-map-stat {
            min-height: 86px;
            border-radius: 14px;
            background: var(--fleet-card);
            border: 1px solid var(--fleet-line);
        }

        .analytics-map-card {
            border: 1px solid var(--fleet-line);
            border-radius: 16px;
            background: var(--fleet-card);
            box-shadow: var(--fleet-shadow);
        }

        .analytics-map-card[data-hidden="true"] {
            display: none;
        }

        .analytics-map-key {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
            color: var(--fleet-blue);
            background: color-mix(in srgb, var(--fleet-blue) 12%, var(--fleet-card));
            border-radius: 999px;
            padding: .28rem .62rem;
            font-size: .76rem;
            font-weight: 700;
        }

        .analytics-map-label {
            color: var(--fleet-muted);
            font-size: .74rem;
            font-weight: 800;
            letter-spacing: .03em;
            text-transform: uppercase;
        }

        .analytics-map-value {
            color: var(--fleet-ink);
            font-weight: 700;
        }

        .analytics-map-chip {
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            border-radius: 999px;
            background: var(--fleet-card-soft);
            border: 1px solid var(--fleet-line);
            color: var(--fleet-ink);
            padding: .35rem .58rem;
            font-size: .78rem;
            font-weight: 700;
        }

        .analytics-map-list {
            margin: 0;
            padding-left: 1rem;
            color: var(--fleet-muted);
        }

        .analytics-map-list li + li {
            margin-top: .28rem;
        }

        .analytics-map-section-title {
            font-size: .86rem;
            font-weight: 800;
            color: var(--fleet-ink);
        }

        .analytics-map-search {
            min-height: 44px;
            border-radius: 12px;
        }

        .analytics-map-search-icon {
            color: var(--fleet-muted);
            background: var(--fleet-card);
            border-color: var(--fleet-line);
        }

        .analytics-map-binding {
            background: var(--fleet-card-soft);
            border: 1px solid var(--fleet-line);
            border-radius: 14px;
        }

        .analytics-map-update {
            border-left: 4px solid #2563eb;
        }
    </style>
@endpush

@section('content')
    <div class="analytics-map-hero p-4 mb-4">
        <div class="d-flex flex-column flex-xl-row align-items-xl-center justify-content-between gap-3">
            <div>
                <div class="d-inline-flex align-items-center gap-2 text-primary fw-bold small mb-2">
                    <i class="bi bi-diagram-3"></i>
                    Dashboard source map
                </div>
                <h2 class="h3 fw-bold mb-2">Dashboard analitika xəritəsi</h2>
                <p class="text-secondary mb-0">
                    Bu səhifə yalnız administrasiya üçün məlumat xəritəsidir. Hesablamalara, API cavablarına və Wialon sinxronizasiyasına təsir etmir.
                </p>
            </div>
            <div class="col-12 col-xl-4">
                <label for="analyticsMapSearch" class="form-label analytics-map-label">Axtarış</label>
                <div class="input-group">
                    <span class="input-group-text analytics-map-search-icon border-end-0"><i class="bi bi-search"></i></span>
                    <input id="analyticsMapSearch" type="search" class="form-control analytics-map-search border-start-0" placeholder="Vidjet, report, service və ya cədvəl...">
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-12 col-md-4">
            <div class="analytics-map-stat p-3">
                <div class="analytics-map-label mb-1">Vidjet sayı</div>
                <div class="fs-3 fw-bold">{{ count($widgets) }}</div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="analytics-map-stat p-3">
                <div class="analytics-map-label mb-1">Əsas data prinsipi</div>
                <div class="fw-bold">Dashboard lokal cədvəllərdən oxuyur</div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="analytics-map-stat p-3">
                <div class="analytics-map-label mb-1">Dəyişiklik yeri</div>
                <div class="fw-bold">config/dashboard_analytics.php</div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        @foreach ($updateSections as $key => $section)
            <div class="col-12 col-xl-6">
                <div class="analytics-map-binding analytics-map-update h-100 p-3">
                    <div class="d-flex align-items-start justify-content-between gap-3 mb-2">
                        <div class="analytics-map-section-title">{{ $section['title'] }}</div>
                        <span class="analytics-map-key">{{ $key }}</span>
                    </div>
                    <div class="row g-2 small">
                        <div class="col-12">
                            <span class="analytics-map-label">Əl ilə yenilə</span>
                            <div class="analytics-map-value">{{ $section['manual'] }}</div>
                        </div>
                        <div class="col-12">
                            <span class="analytics-map-label">Avtomatik yenilə</span>
                            <div>{{ $section['auto'] }}</div>
                        </div>
                        <div class="col-12 col-md-6">
                            <span class="analytics-map-label">Wialon əmri</span>
                            <div class="small text-secondary">{{ $section['wialon_command'] }}</div>
                        </div>
                        <div class="col-12 col-md-6">
                            <span class="analytics-map-label">Lokal cədvəllər</span>
                            <div class="small text-secondary">{{ $section['local_tables'] }}</div>
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="row g-3 mb-4">
        @foreach ($sharedBindings as $binding)
            <div class="col-12 col-lg-4">
                <div class="analytics-map-binding h-100 p-3">
                    <div class="analytics-map-section-title mb-2">{{ $binding['title'] }}</div>
                    <p class="text-secondary small mb-2">{{ $binding['description'] }}</p>
                    <ul class="analytics-map-list small">
                        @foreach ($binding['items'] as $item)
                            <li>{{ $item }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endforeach
    </div>

    <div class="d-flex align-items-center justify-content-between gap-3 mb-3">
        <h3 class="h5 fw-bold mb-0">Vidjet bağlılıqları</h3>
        <span class="text-secondary small"><span id="analyticsMapVisibleCount">{{ count($widgets) }}</span> / {{ count($widgets) }}</span>
    </div>

    <div class="row g-3" id="analyticsMapGrid">
        @foreach ($widgets as $widget)
            <div class="col-12 col-xl-6 analytics-map-card-wrap">
                <article class="analytics-map-card h-100 p-3" data-analytics-card data-search="{{ mb_strtolower(implode(' ', [
                    $widget['key'],
                    $widget['title'],
                    $widget['purpose'],
                    $widget['dashboard_block'],
                    $widget['wialon_report'],
                    $widget['local_source'],
                    $widget['service'],
                    $widget['binding'],
                    $widget['click'],
                    $widget['excel'],
                    $widget['update_section'],
                    $updateSections[$widget['update_section']]['title'] ?? '',
                    $updateSections[$widget['update_section']]['manual'] ?? '',
                    $updateSections[$widget['update_section']]['wialon_command'] ?? '',
                    $updateSections[$widget['update_section']]['local_tables'] ?? '',
                    implode(' ', $widget['report_rows'] ?? []),
                ])) }}">
                    <div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3">
                        <div>
                            <span class="analytics-map-key">{{ $widget['key'] }}</span>
                            <h4 class="h5 fw-bold mt-3 mb-1">{{ $widget['title'] }}</h4>
                            <p class="text-secondary mb-0">{{ $widget['purpose'] }}</p>
                        </div>
                        <div class="d-flex flex-wrap gap-2">
                            <span class="analytics-map-chip"><i class="bi bi-shield-check"></i> Read only</span>
                            <span class="analytics-map-chip"><i class="bi bi-arrow-repeat"></i> {{ $updateSections[$widget['update_section']]['title'] ?? $widget['update_section'] }}</span>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <div class="analytics-map-label mb-1">Dashboard bloku</div>
                            <div class="analytics-map-value">{{ $widget['dashboard_block'] }}</div>
                        </div>
                        <div class="col-12 col-md-6">
                            <div class="analytics-map-label mb-1">Wialon report</div>
                            <div class="analytics-map-value">{{ $widget['wialon_report'] }}</div>
                        </div>
                        <div class="col-12 col-md-6">
                            <div class="analytics-map-label mb-1">Lokal mənbə</div>
                            <div class="analytics-map-value">{{ $widget['local_source'] }}</div>
                        </div>
                        <div class="col-12 col-md-6">
                            <div class="analytics-map-label mb-1">Service / query</div>
                            <div class="analytics-map-value">{{ $widget['service'] }}</div>
                        </div>
                    </div>

                    <hr class="my-3">

                    <div class="mb-3">
                        <div class="analytics-map-label mb-1">Bağlılıq prinsipi</div>
                        <div>{{ $widget['binding'] }}</div>
                    </div>

                    <div class="mb-3">
                        <div class="analytics-map-label mb-1">Yenilənmə məntiqi</div>
                        <div class="small text-secondary">
                            Əl ilə: {{ $updateSections[$widget['update_section']]['manual'] ?? '-' }}<br>
                            Avto: {{ $updateSections[$widget['update_section']]['auto'] ?? '-' }}
                        </div>
                    </div>

                    <div class="mb-3">
                        <div class="analytics-map-label mb-2">Report sətri / sütunları</div>
                        <ul class="analytics-map-list">
                            @foreach ($widget['report_rows'] as $row)
                                <li>{{ $row }}</li>
                            @endforeach
                        </ul>
                    </div>

                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <div class="analytics-map-label mb-1">Klik / modal</div>
                            <div class="small text-secondary">{{ $widget['click'] }}</div>
                        </div>
                        <div class="col-12 col-md-6">
                            <div class="analytics-map-label mb-1">Excel</div>
                            <div class="small text-secondary">{{ $widget['excel'] }}</div>
                        </div>
                    </div>
                </article>
            </div>
        @endforeach
    </div>
@endsection

@push('scripts')
    <script>
        (() => {
            const input = document.getElementById('analyticsMapSearch');
            const cards = Array.from(document.querySelectorAll('[data-analytics-card]'));
            const count = document.getElementById('analyticsMapVisibleCount');

            if (!input || !cards.length || !count) {
                return;
            }

            input.addEventListener('input', () => {
                const value = input.value.trim().toLowerCase();
                let visible = 0;

                cards.forEach((card) => {
                    const matched = value === '' || (card.dataset.search || '').includes(value);
                    card.dataset.hidden = matched ? 'false' : 'true';
                    card.closest('.analytics-map-card-wrap')?.classList.toggle('d-none', !matched);
                    visible += matched ? 1 : 0;
                });

                count.textContent = visible.toString();
            });
        })();
    </script>
@endpush
