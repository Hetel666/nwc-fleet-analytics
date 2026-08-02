@extends('layouts.app')

@section('title', 'Wialon kataloqu | '.__('app.app_name'))
@section('page-title', 'Wialon kataloqu')
@section('page-subtitle', 'Wialon qrupları, obyektləri, geozonaları, resursları və hesabat şablonları')

@push('styles')
    <style>
        .wialon-catalog-kpi {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
        }
        .wialon-catalog-kpi-card {
            min-height: 104px;
            border: 1px solid var(--fleet-line);
            border-radius: 8px;
            background: var(--fleet-card);
            padding: 16px;
        }
        .wialon-catalog-label {
            color: var(--fleet-muted);
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .04em;
        }
        .wialon-catalog-value {
            margin-top: 8px;
            color: var(--fleet-ink);
            font-size: 24px;
            font-weight: 900;
            line-height: 1;
        }
        .wialon-catalog-table th {
            white-space: nowrap;
        }
        .wialon-catalog-table td {
            vertical-align: middle;
        }
        .wialon-id {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .wialon-id code {
            color: var(--fleet-ink);
            background: var(--fleet-card-soft);
            border: 1px solid var(--fleet-line);
            border-radius: 6px;
            padding: 2px 6px;
        }
        .copy-id-btn {
            width: 28px;
            height: 28px;
            border-radius: 8px;
            display: inline-grid;
            place-items: center;
            border: 1px solid var(--fleet-line);
            color: var(--fleet-muted);
            background: var(--fleet-card);
        }
        .copy-id-btn:hover {
            color: var(--fleet-blue);
            background: var(--fleet-hover);
        }
        .catalog-tab-pane[hidden] {
            display: none !important;
        }
    </style>
@endpush

@section('content')
    <div class="d-flex flex-column flex-xl-row gap-3 justify-content-between align-items-xl-center mb-3">
        <div>
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <span class="badge {{ $overview['wialon_connection'] === 'configured' ? 'text-bg-success' : 'text-bg-danger' }}">
                    {{ $overview['wialon_connection'] === 'configured' ? 'Wialon token aktivdir' : 'Wialon token yoxdur' }}
                </span>
                <span class="badge text-bg-light">Queue: {{ config('wialon_catalog.queue', 'wialon-catalog') }}</span>
                <span class="badge text-bg-light">Auto sync: {{ config('wialon_catalog.auto_sync_enabled') ? config('wialon_catalog.auto_sync_time') : 'off' }}</span>
            </div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            @if ($canManageProjects)
                <a class="btn btn-outline-primary btn-icon" href="{{ route('projects.create') }}">
                    <i class="bi bi-folder-plus"></i><span>Yeni layihə yarat</span>
                </a>
            @endif
            @if ($canSync)
                @foreach ($sections as $section => $label)
                    <button type="button" class="btn {{ $section === 'all' ? 'btn-primary' : 'btn-outline-primary' }} btn-icon js-catalog-sync" data-section="{{ $section }}">
                        <i class="bi bi-arrow-repeat"></i><span>{{ $label }}</span>
                    </button>
                @endforeach
            @endif
        </div>
    </div>

    <section class="panel p-3 mb-3">
        <div class="wialon-catalog-kpi">
            <div class="wialon-catalog-kpi-card">
                <div class="wialon-catalog-label">Obyekt qrupları</div>
                <div class="wialon-catalog-value">{{ number_format($overview['counts']['unit_groups'] ?? 0) }}</div>
            </div>
            <div class="wialon-catalog-kpi-card">
                <div class="wialon-catalog-label">Obyektlər</div>
                <div class="wialon-catalog-value">{{ number_format($overview['counts']['units'] ?? 0) }}</div>
            </div>
            <div class="wialon-catalog-kpi-card">
                <div class="wialon-catalog-label">Geozonalar</div>
                <div class="wialon-catalog-value">{{ number_format($overview['counts']['geofences'] ?? 0) }}</div>
            </div>
            <div class="wialon-catalog-kpi-card">
                <div class="wialon-catalog-label">Geozona qrupları</div>
                <div class="wialon-catalog-value">{{ number_format($overview['counts']['geofence_groups'] ?? 0) }}</div>
            </div>
            <div class="wialon-catalog-kpi-card">
                <div class="wialon-catalog-label">Hesabat resursları</div>
                <div class="wialon-catalog-value">{{ number_format($overview['counts']['resources'] ?? 0) }}</div>
            </div>
            <div class="wialon-catalog-kpi-card">
                <div class="wialon-catalog-label">Hesabat şablonları</div>
                <div class="wialon-catalog-value">{{ number_format($overview['counts']['report_templates'] ?? 0) }}</div>
            </div>
            <div class="wialon-catalog-kpi-card">
                <div class="wialon-catalog-label">Layihəsiz qruplar</div>
                <div class="wialon-catalog-value">{{ number_format($overview['counts']['unlinked_unit_groups'] ?? 0) }}</div>
            </div>
            <div class="wialon-catalog-kpi-card">
                <div class="wialon-catalog-label">Son uğurlu sync</div>
                <div class="fw-semibold mt-2">{{ $overview['last_successful_sync'] ?? '-' }}</div>
                <div class="small text-secondary mt-1">{{ $overview['last_sync_duration_ms'] ? $overview['last_sync_duration_ms'].' ms' : '-' }}</div>
            </div>
        </div>
    </section>

    <section class="panel p-3">
        <ul class="nav nav-tabs" id="wialonCatalogTabs" role="tablist">
            @foreach ($tabs as $key => $label)
                <li class="nav-item" role="presentation">
                    <button class="nav-link {{ $loop->first ? 'active' : '' }}" type="button" data-catalog-tab="{{ $key }}">
                        {{ $label }}
                    </button>
                </li>
            @endforeach
        </ul>

        <div class="pt-3">
            <div id="catalogOverviewPane" class="catalog-tab-pane">
                <div class="row g-3">
                    <div class="col-lg-7">
                        <div class="border rounded-3 p-3 h-100">
                            <div class="fw-semibold mb-2">Son sinxronizasiya</div>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>Run ID</th>
                                            <th>Status</th>
                                            <th>Bölmələr</th>
                                            <th>Başlama</th>
                                            <th>Müddət</th>
                                            <th>Xəta</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse ($recentRuns as $run)
                                            <tr>
                                                <td>{{ $run->id }}</td>
                                                <td><span class="badge text-bg-secondary">{{ $run->status }}</span></td>
                                                <td>{{ implode(', ', $run->sections_json ?? []) }}</td>
                                                <td>{{ optional($run->started_at)->toDateTimeString() ?? '-' }}</td>
                                                <td>{{ $run->duration_ms ? $run->duration_ms.' ms' : '-' }}</td>
                                                <td class="text-danger">{{ $run->last_error ? \Illuminate\Support\Str::limit($run->last_error, 80) : '-' }}</td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="6" class="text-secondary">Məlumat yoxdur</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-5">
                        <div class="border rounded-3 p-3 h-100">
                            <div class="fw-semibold mb-2">Layihə uyğunlaşdırılması</div>
                            <div class="row g-2 small">
                                <div class="col-6 text-secondary">Əlavə edildi</div>
                                <div class="col-6 fw-semibold">{{ number_format($overview['last_added_count'] ?? 0) }}</div>
                                <div class="col-6 text-secondary">Yeniləndi</div>
                                <div class="col-6 fw-semibold">{{ number_format($overview['last_updated_count'] ?? 0) }}</div>
                                <div class="col-6 text-secondary">Deaktiv edildi</div>
                                <div class="col-6 fw-semibold">{{ number_format($overview['last_deactivated_count'] ?? 0) }}</div>
                            </div>
                            @if ($overview['last_error'])
                                <div class="alert alert-warning mt-3 mb-0">{{ $overview['last_error'] }}</div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            <div id="catalogDataPane" class="catalog-tab-pane" hidden>
                <div class="row g-2 align-items-end mb-3">
                    <div class="col-lg-5">
                        <label class="form-label">Axtarış</label>
                        <input id="catalogSearch" class="form-control" placeholder="Ad, ID, IMEI...">
                    </div>
                    <div class="col-md-3 col-lg-2">
                        <label class="form-label">Status</label>
                        <select id="catalogStatus" class="form-select">
                            <option value="">Hamısı</option>
                            <option value="active">Aktiv</option>
                            <option value="inactive">Passiv</option>
                        </select>
                    </div>
                    <div class="col-md-3 col-lg-2">
                        <label class="form-label">Sətir</label>
                        <select id="catalogPerPage" class="form-select">
                            <option value="25">25</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </select>
                    </div>
                    <div class="col-md-6 col-lg-3 d-flex gap-2">
                        <button id="catalogReload" type="button" class="btn btn-primary btn-icon flex-fill">
                            <i class="bi bi-arrow-clockwise"></i><span>Yenilə</span>
                        </button>
                        <a id="catalogExport" class="btn btn-outline-secondary btn-icon" href="#">
                            <i class="bi bi-file-earmark-excel"></i><span>Excel</span>
                        </a>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm wialon-catalog-table align-middle">
                        <thead><tr id="catalogTableHead"></tr></thead>
                        <tbody id="catalogTableBody">
                            <tr><td class="text-secondary">Məlumat yüklənir...</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mt-3">
                    <div id="catalogTableMeta" class="small text-secondary"></div>
                    <div class="btn-group">
                        <button id="catalogPrev" class="btn btn-outline-secondary btn-sm" type="button">Əvvəlki</button>
                        <button id="catalogNext" class="btn btn-outline-secondary btn-sm" type="button">Növbəti</button>
                    </div>
                </div>
            </div>

            <div id="catalogProjectsPane" class="catalog-tab-pane" hidden>
                <div class="d-flex flex-column flex-lg-row gap-2 justify-content-between mb-3">
                    <div>
                        <div class="fw-semibold">Layihə uyğunlaşdırılması</div>
                        <div class="text-secondary small">Yeni layihə üçün istifadəçi adı seçir, sistem Wialon ID-ni kataloqdan götürür.</div>
                    </div>
                    @if ($canManageProjects)
                        <a class="btn btn-outline-primary btn-icon" href="{{ route('projects.create') }}">
                            <i class="bi bi-folder-plus"></i><span>Yeni layihə əlavə et</span>
                        </a>
                    @endif
                </div>
                <div id="catalogProjectOptions" class="row g-3"></div>
            </div>
        </div>
    </section>
@endsection

@push('scripts')
    <script>
        (() => {
            const endpoints = {
                resources: @json(route('api.wialon-catalog.resources')),
                'unit-groups': @json(route('api.wialon-catalog.unit-groups')),
                units: @json(route('api.wialon-catalog.units')),
                'geofence-groups': @json(route('api.wialon-catalog.geofence-groups')),
                geofences: @json(route('api.wialon-catalog.geofences')),
                'report-templates': @json(route('api.wialon-catalog.report-templates')),
                'sync-runs': @json(route('api.wialon-catalog.sync-runs')),
                projects: @json($canManageProjects ? route('api.projects.wialon-options') : null),
            };
            const syncUrl = @json(route('api.wialon-catalog.sync'));
            const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
            const columns = {
                resources: ['wialon_resource_id', 'name', 'account_id', 'report_templates_count', 'geofences_count', 'geofence_groups_count', 'status', 'last_synced_at'],
                'unit-groups': ['wialon_group_id', 'name', 'resource_id', 'units_count', 'project', 'ownership_type', 'status', 'last_synced_at'],
                units: ['wialon_unit_id', 'name', 'equipment_type_name', 'ownership_type', 'project', 'local_equipment', 'imei', 'status', 'last_synced_at'],
                'geofence-groups': ['wialon_geofence_group_id', 'name', 'resource_id', 'resource_name', 'geofences_count', 'project', 'status', 'last_synced_at'],
                geofences: ['wialon_geofence_id', 'name', 'resource_id', 'resource_name', 'geofence_group_id', 'zone_type', 'project', 'is_home_geofence', 'status', 'last_synced_at'],
                'report-templates': ['wialon_template_id', 'name', 'resource_id', 'resource_name', 'report_type', 'used_by_modules', 'usage_status', 'status', 'last_synced_at'],
                'sync-runs': ['id', 'status', 'sync_type', 'sections', 'started_by', 'started_at', 'completed_at', 'added_count', 'updated_count', 'deactivated_count', 'error_count', 'last_error'],
            };
            const labels = {
                wialon_resource_id: 'Resource ID',
                wialon_group_id: 'Wialon ID',
                wialon_unit_id: 'Wialon ID',
                wialon_geofence_id: 'Wialon ID',
                wialon_geofence_group_id: 'Qrup ID',
                wialon_template_id: 'Template ID',
                name: 'Ad',
                account_id: 'Hesab',
                resource_id: 'Resource ID',
                resource_name: 'Resurs',
                report_templates_count: 'Şablon',
                geofences_count: 'Geozona',
                geofence_groups_count: 'Geozona qrupu',
                units_count: 'Obyekt sayı',
                project: 'Layihə',
                ownership_type: 'Mənsubiyyət',
                status: 'Status',
                last_synced_at: 'Son sync',
                equipment_type_name: 'Texnika növü',
                local_equipment: 'Lokal obyekt',
                imei: 'IMEI',
                geofence_group_id: 'Geozona qrupu',
                zone_type: 'Növ',
                is_home_geofence: 'Ev geozonası',
                report_type: 'Hesabat tipi',
                used_by_modules: 'İstifadə edən modul',
                usage_status: 'İstifadə statusu',
                id: 'Run ID',
                sync_type: 'Sync növü',
                sections: 'Bölmələr',
                started_by: 'Başladan',
                started_at: 'Başlama',
                completed_at: 'Bitmə',
                added_count: 'Əlavə',
                updated_count: 'Yeniləndi',
                deactivated_count: 'Deaktiv',
                error_count: 'Xəta',
                last_error: 'Son xəta',
            };
            let activeTab = 'overview';
            let currentPage = 1;

            const dataPane = document.getElementById('catalogDataPane');
            const overviewPane = document.getElementById('catalogOverviewPane');
            const projectsPane = document.getElementById('catalogProjectsPane');
            const searchInput = document.getElementById('catalogSearch');
            const statusSelect = document.getElementById('catalogStatus');
            const perPageSelect = document.getElementById('catalogPerPage');
            const exportLink = document.getElementById('catalogExport');

            document.querySelectorAll('[data-catalog-tab]').forEach((button) => {
                button.addEventListener('click', () => {
                    document.querySelectorAll('[data-catalog-tab]').forEach((item) => item.classList.remove('active'));
                    button.classList.add('active');
                    activeTab = button.dataset.catalogTab;
                    currentPage = 1;
                    activateTab();
                });
            });

            document.getElementById('catalogReload')?.addEventListener('click', () => loadTable());
            document.getElementById('catalogPrev')?.addEventListener('click', () => {
                if (currentPage > 1) {
                    currentPage--;
                    loadTable();
                }
            });
            document.getElementById('catalogNext')?.addEventListener('click', () => {
                currentPage++;
                loadTable();
            });
            [searchInput, statusSelect, perPageSelect].forEach((item) => item?.addEventListener('change', () => {
                currentPage = 1;
                loadTable();
            }));
            searchInput?.addEventListener('keyup', (event) => {
                if (event.key === 'Enter') {
                    currentPage = 1;
                    loadTable();
                }
            });

            document.querySelectorAll('.js-catalog-sync').forEach((button) => {
                button.addEventListener('click', async () => {
                    button.disabled = true;
                    const label = button.innerHTML;
                    button.innerHTML = '<span class="spinner-border spinner-border-sm"></span><span>Queue...</span>';
                    try {
                        const response = await fetch(syncUrl, {
                            method: 'POST',
                            headers: {
                                'Accept': 'application/json',
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': csrf,
                            },
                            body: JSON.stringify({sections: [button.dataset.section]}),
                        });
                        const payload = await response.json();
                        alert(`Run ID: ${payload.run_id || '-'}\nStatus: ${payload.status || response.status}`);
                    } catch (error) {
                        alert(error.message || 'Sync failed');
                    } finally {
                        button.disabled = false;
                        button.innerHTML = label;
                    }
                });
            });

            document.addEventListener('click', async (event) => {
                const button = event.target.closest('[data-copy-id]');
                if (!button) return;
                await navigator.clipboard?.writeText(button.dataset.copyId);
                button.classList.add('text-success');
                setTimeout(() => button.classList.remove('text-success'), 800);
            });

            function activateTab() {
                overviewPane.hidden = activeTab !== 'overview';
                dataPane.hidden = activeTab === 'overview' || activeTab === 'projects';
                projectsPane.hidden = activeTab !== 'projects';

                if (activeTab === 'projects') {
                    loadProjectOptions();
                } else if (activeTab !== 'overview') {
                    loadTable();
                }
            }

            async function loadTable() {
                const endpoint = endpoints[activeTab];
                if (!endpoint) return;
                const params = new URLSearchParams({
                    page: currentPage,
                    per_page: perPageSelect.value,
                    search: searchInput.value,
                    status: statusSelect.value,
                });
                exportLink.href = `${endpoint}?${params.toString()}&export=xlsx`;
                renderLoading();

                const response = await fetch(`${endpoint}?${params.toString()}`, {headers: {'Accept': 'application/json'}});
                const payload = await response.json();
                renderTable(payload.data || [], payload.meta || {});
            }

            function renderLoading() {
                const body = document.getElementById('catalogTableBody');
                body.innerHTML = '<tr><td class="text-secondary">Məlumat yüklənir...</td></tr>';
            }

            function renderTable(rows, meta) {
                const head = document.getElementById('catalogTableHead');
                const body = document.getElementById('catalogTableBody');
                const metaBox = document.getElementById('catalogTableMeta');
                const activeColumns = columns[activeTab] || [];
                head.innerHTML = activeColumns.map((column) => `<th>${escapeHtml(labels[column] || column)}</th>`).join('');

                if (!rows.length) {
                    body.innerHTML = `<tr><td colspan="${activeColumns.length || 1}" class="text-secondary">Məlumat yoxdur</td></tr>`;
                } else {
                    body.innerHTML = rows.map((row) => `<tr>${activeColumns.map((column) => `<td>${cell(row[column], column)}</td>`).join('')}</tr>`).join('');
                }

                metaBox.textContent = meta.total === undefined
                    ? ''
                    : `Səhifə ${meta.current_page} / ${meta.last_page}, cəmi ${meta.total}`;
                document.getElementById('catalogPrev').disabled = Number(meta.current_page || 1) <= 1;
                document.getElementById('catalogNext').disabled = Number(meta.current_page || 1) >= Number(meta.last_page || 1);
            }

            async function loadProjectOptions() {
                const container = document.getElementById('catalogProjectOptions');
                if (!endpoints.projects) {
                    container.innerHTML = '<div class="col-12 text-secondary">projects.manage icazəsi yoxdur.</div>';
                    return;
                }

                container.innerHTML = '<div class="col-12 text-secondary">Məlumat yüklənir...</div>';
                const response = await fetch(endpoints.projects, {headers: {'Accept': 'application/json'}});
                const payload = await response.json();
                const groups = payload.unit_groups || [];
                const geofences = payload.geofences || [];
                const resources = payload.resources || [];

                container.innerHTML = [
                    projectOptionCard('Wialon obyekt qrupları', groups, 'wialon_group_id', 'units_count'),
                    projectOptionCard('Ev geozonaları', geofences, 'wialon_geofence_id', 'resource_id'),
                    projectOptionCard('Hesabat resursları', resources, 'wialon_resource_id', null),
                ].join('');
            }

            function projectOptionCard(title, rows, idKey, secondaryKey) {
                return `<div class="col-xl-4">
                    <div class="border rounded-3 p-3 h-100">
                        <div class="fw-semibold mb-2">${escapeHtml(title)}</div>
                        <div class="d-grid gap-2">
                            ${rows.slice(0, 12).map((row) => `
                                <div class="d-flex justify-content-between gap-2 border-bottom pb-2">
                                    <div class="min-w-0">
                                        <div class="fw-semibold text-truncate">${escapeHtml(row.name || '-')}</div>
                                        <div class="small text-secondary">ID: ${escapeHtml(row[idKey] || '-')}</div>
                                    </div>
                                    <div class="small text-secondary text-nowrap">${secondaryKey ? escapeHtml(row[secondaryKey] || '-') : ''}</div>
                                </div>
                            `).join('') || '<div class="text-secondary small">Məlumat yoxdur</div>'}
                        </div>
                    </div>
                </div>`;
            }

            function cell(value, column) {
                if (Array.isArray(value)) {
                    value = value.join(', ');
                }
                if (typeof value === 'boolean') {
                    value = value ? 'Bəli' : 'Xeyr';
                }
                value = value === null || value === undefined || value === '' ? '-' : String(value);
                if (column.includes('id') && value !== '-') {
                    return `<span class="wialon-id"><code>${escapeHtml(value)}</code><button type="button" class="copy-id-btn" data-copy-id="${escapeHtml(value)}" title="ID-ni kopyala"><i class="bi bi-clipboard"></i></button></span>`;
                }
                if (column === 'status' || column === 'usage_status') {
                    return `<span class="badge text-bg-secondary">${escapeHtml(value)}</span>`;
                }
                return escapeHtml(value);
            }

            function escapeHtml(value) {
                return String(value)
                    .replaceAll('&', '&amp;')
                    .replaceAll('<', '&lt;')
                    .replaceAll('>', '&gt;')
                    .replaceAll('"', '&quot;')
                    .replaceAll("'", '&#039;');
            }
        })();
    </script>
@endpush
