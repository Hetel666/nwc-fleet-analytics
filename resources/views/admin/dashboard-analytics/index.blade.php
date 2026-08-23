@extends('layouts.app')

@section('title', 'Dashboard mənbələri | '.__('app.app_name'))
@section('page-title', 'Dashboard mənbələri')

@push('styles')
    <style>
        .source-map-hero,
        .source-map-card {
            background: var(--fleet-card);
            border: 1px solid var(--fleet-line);
            border-radius: 8px;
        }

        .source-map-hero { box-shadow: var(--fleet-shadow); }

        .source-map-kicker,
        .source-map-label {
            color: var(--fleet-muted);
            font-size: .72rem;
            font-weight: 700;
            text-transform: uppercase;
        }

        .source-map-key,
        .source-map-route {
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            border: 1px solid var(--fleet-line);
            border-radius: 6px;
            color: var(--fleet-muted);
            font-size: .76rem;
            padding: .28rem .5rem;
        }

        .source-map-report {
            background: var(--fleet-card-soft);
            border-left: 3px solid #2563eb;
            padding: .75rem .85rem;
        }

        .source-map-list { margin: 0; padding-left: 1.1rem; }
        .source-map-list li + li { margin-top: .35rem; }

        .source-map-search {
            background: var(--fleet-card);
            border-color: var(--fleet-line);
            color: var(--fleet-ink);
        }

        .source-map-search::placeholder { color: var(--fleet-muted); }
    </style>
@endpush

@section('content')
    @php
        $blockCount = collect($groups)->sum(fn (array $group): int => count($group['blocks'] ?? []));
    @endphp

    <section class="source-map-hero p-4 mb-4">
        <div class="d-flex flex-column flex-xl-row align-items-xl-end justify-content-between gap-3">
            <div>
                <div class="source-map-kicker mb-2">Aktual hesablama xəritəsi</div>
                <h2 class="h3 fw-bold mb-2">Hər Dashboard blokunun mənbəsi və hesablama qaydası</h2>
                <p class="text-secondary mb-0">
                    Aşağıda yalnız hazırda işləyən bloklar göstərilir. Hər kartda Wialon hesabatı, layihə bağlılığı, tarix qaydası, formula və lokal cədvəl ayrı yazılıb.
                </p>
            </div>
            <div class="col-12 col-xl-4">
                <label for="sourceMapSearch" class="form-label source-map-label">Axtarış</label>
                <input id="sourceMapSearch" type="search" class="form-control source-map-search" placeholder="Blok, hesabat, formula və ya cədvəl...">
            </div>
        </div>
        <div class="d-flex flex-wrap gap-2 mt-3">
            <span class="source-map-key"><i class="bi bi-grid"></i> {{ $blockCount }} aktiv blok</span>
            <span class="source-map-key"><i class="bi bi-database-check"></i> Dashboard yalnız lokal faktlardan oxuyur</span>
        </div>
    </section>

    <div id="sourceMapEmpty" class="alert alert-secondary d-none">Axtarışa uyğun blok tapılmadı.</div>

    <div id="sourceMapGroups">
        @foreach ($groups as $group)
            <section class="source-map-group mb-4" data-source-group>
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                    <h3 class="h5 fw-bold mb-0">{{ $group['title'] }}</h3>
                    <span class="source-map-route"><i class="bi bi-window"></i> {{ $group['route'] }}</span>
                </div>

                <div class="row g-3">
                    @foreach ($group['blocks'] as $block)
                        <div class="col-12 col-xl-6 source-map-card-wrap"
                             data-source-block
                             data-search="{{ mb_strtolower(implode(' ', [
                                 $group['title'], $block['key'], $block['title'], $block['report'],
                                 $block['local_source'], $block['project_rule'], $block['period_rule'],
                                 implode(' ', $block['calculation']), $block['result'],
                             ])) }}">
                            <article class="source-map-card h-100 p-3">
                                <div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3">
                                    <h4 class="h5 fw-bold mb-0">{{ $block['title'] }}</h4>
                                    <span class="source-map-key">{{ $block['key'] }}</span>
                                </div>

                                <div class="source-map-report mb-3">
                                    <div class="source-map-label mb-1">Wialon hesabatı</div>
                                    <div class="fw-semibold">{{ $block['report'] }}</div>
                                </div>

                                <div class="row g-3">
                                    <div class="col-12">
                                        <div class="source-map-label mb-1">Layihəyə aidiyyət qaydası</div>
                                        <div>{{ $block['project_rule'] }}</div>
                                    </div>
                                    <div class="col-12">
                                        <div class="source-map-label mb-1">Dövr və sətir seçimi</div>
                                        <div>{{ $block['period_rule'] }}</div>
                                    </div>
                                    <div class="col-12">
                                        <div class="source-map-label mb-2">Hesablama prinsipi</div>
                                        <ul class="source-map-list">
                                            @foreach ($block['calculation'] as $rule)
                                                <li>{{ $rule }}</li>
                                            @endforeach
                                        </ul>
                                    </div>
                                    <div class="col-12 col-md-7">
                                        <div class="source-map-label mb-1">Dashboard nəticəsi</div>
                                        <div>{{ $block['result'] }}</div>
                                    </div>
                                    <div class="col-12 col-md-5">
                                        <div class="source-map-label mb-1">Lokal mənbə</div>
                                        <div class="text-secondary">{{ $block['local_source'] }}</div>
                                    </div>
                                </div>
                            </article>
                        </div>
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>
@endsection

@push('scripts')
    <script>
        (() => {
            const search = document.getElementById('sourceMapSearch');
            const groups = Array.from(document.querySelectorAll('[data-source-group]'));
            const empty = document.getElementById('sourceMapEmpty');

            if (!search) return;

            search.addEventListener('input', () => {
                const term = search.value.trim().toLocaleLowerCase('az');
                let visibleBlocks = 0;

                groups.forEach(group => {
                    let visibleInGroup = 0;

                    group.querySelectorAll('[data-source-block]').forEach(block => {
                        const visible = term === '' || (block.dataset.search || '').includes(term);
                        block.classList.toggle('d-none', !visible);
                        if (visible) visibleInGroup += 1;
                    });

                    group.classList.toggle('d-none', visibleInGroup === 0);
                    visibleBlocks += visibleInGroup;
                });

                empty.classList.toggle('d-none', visibleBlocks !== 0);
            });
        })();
    </script>
@endpush
