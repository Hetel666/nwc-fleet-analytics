@extends('layouts.app')

@section('title', 'Dashboard idaretmesi | '.__('app.app_name'))
@section('page-title', 'Dashboard idaretmesi')
@section('page-subtitle', 'Kartlarin ve Effektivlik statuslarinin qlobal gorunurluyu')

@push('styles')
    <style>
        .dashboard-visibility-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr);
            gap: 16px;
        }

        .dashboard-visibility-toolbar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .dashboard-visibility-tab-pane[hidden] {
            display: none !important;
        }

        .dashboard-visibility-table th {
            white-space: nowrap;
        }

        .dashboard-visibility-order {
            width: 96px;
        }

        .dashboard-visibility-code {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
            font-size: 12px;
            color: var(--fleet-blue);
        }

        .dashboard-visibility-warning {
            border-left: 4px solid #f59e0b;
            background: color-mix(in srgb, #f59e0b 9%, var(--fleet-card));
        }

        .dashboard-visibility-audit {
            max-height: 520px;
            overflow: auto;
        }

        .dashboard-visibility-preview-list {
            max-height: 180px;
            overflow: auto;
        }
    </style>
@endpush

@section('content')
    @php
        $previewDashboards = collect($configuration['dashboards']);
        $previewStatuses = collect($configuration['statuses'])->flatMap(fn (array $rows): array => $rows);
        $previewVisibleDashboards = $previewDashboards->where('is_visible', true);
    @endphp

    <div class="dashboard-visibility-grid" data-dashboard-visibility-admin>
        <section class="panel p-3 dashboard-visibility-warning">
            <div class="d-flex flex-column flex-xl-row align-items-xl-center justify-content-between gap-3">
                <div>
                    <div class="fw-bold">Qlobal ayar</div>
                    <div class="text-secondary small">
                        Gizletmek yalniz ekranda gostermeni dayandirir. Data, hesablamalar, Wialon sinxronizasiya, queue ve scheduler deyismir.
                    </div>
                </div>
                <button type="button" class="btn btn-outline-danger btn-sm btn-icon" data-dashboard-visibility-reset>
                    <i class="bi bi-arrow-counterclockwise"></i><span>Standarta qaytar</span>
                </button>
            </div>
        </section>

        <section class="panel p-3">
            <div class="d-flex flex-column flex-xl-row justify-content-between gap-3">
                <div>
                    <div class="fw-bold">On baxish</div>
                    <div class="text-secondary small">Cari qlobal ayarin Dashboard-da nece goruneceyinin qisa xulasasi.</div>
                </div>
                <div class="d-flex flex-wrap gap-2 small">
                    <span class="badge text-bg-success" data-preview-visible-count>Gorunen: {{ $previewVisibleDashboards->count() }}</span>
                    <span class="badge text-bg-secondary" data-preview-hidden-count>Gizli: {{ $previewDashboards->count() - $previewVisibleDashboards->count() }}</span>
                    <span class="badge text-bg-primary" data-preview-status-count>Statuslar: {{ $previewStatuses->where('is_visible', true)->count() }} / {{ $previewStatuses->count() }}</span>
                </div>
            </div>
            <ol class="dashboard-visibility-preview-list small mt-3 mb-0" data-dashboard-preview-order>
                @foreach ($previewVisibleDashboards->sortBy('display_order')->take(12) as $dashboard)
                    <li><span class="dashboard-visibility-code">{{ $dashboard['display_order'] }}</span> {{ $dashboard['title_az'] }}</li>
                @endforeach
            </ol>
        </section>

        <section class="panel p-3">
            <div class="dashboard-visibility-toolbar mb-3">
                <ul class="nav nav-tabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button type="button" class="nav-link active" data-dashboard-visibility-tab="dashboards">Dashboard-lar</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button type="button" class="nav-link" data-dashboard-visibility-tab="statuses">Effektivlik statuslari</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button type="button" class="nav-link" data-dashboard-visibility-tab="order">Gosterilme sirasi</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button type="button" class="nav-link" data-dashboard-visibility-tab="audit">Deyisiklik tarixcesi</button>
                    </li>
                </ul>
                <div class="small text-secondary" data-dashboard-visibility-status></div>
            </div>

            <div data-dashboard-visibility-pane="dashboards">
                <div class="table-responsive">
                    <table class="table table-sm align-middle dashboard-visibility-table mb-0">
                        <thead>
                            <tr>
                                <th>Kod</th>
                                <th>Basliq</th>
                                <th>Bolme</th>
                                <th>Sira</th>
                                <th class="text-end">Gorunur</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($configuration['dashboards'] as $dashboard)
                                <tr data-dashboard-row="{{ $dashboard['code'] }}" data-dashboard-title="{{ $dashboard['title_az'] }}">
                                    <td><span class="dashboard-visibility-code">{{ $dashboard['code'] }}</span></td>
                                    <td>{{ $dashboard['title_az'] }}</td>
                                    <td>{{ $dashboard['section_code'] }}</td>
                                    <td>
                                        <input
                                            type="number"
                                            min="1"
                                            max="10000"
                                            class="form-control form-control-sm dashboard-visibility-order"
                                            value="{{ $dashboard['display_order'] }}"
                                            data-dashboard-order-input
                                            data-dashboard-code="{{ $dashboard['code'] }}"
                                        >
                                    </td>
                                    <td class="text-end">
                                        <div class="form-check form-switch d-inline-flex">
                                            <input
                                                type="checkbox"
                                                class="form-check-input"
                                                role="switch"
                                                @checked($dashboard['is_visible'])
                                                data-dashboard-visible-toggle
                                                data-dashboard-code="{{ $dashboard['code'] }}"
                                            >
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div data-dashboard-visibility-pane="statuses" hidden>
                @foreach ($configuration['statuses'] as $dashboardType => $statuses)
                    <div class="mb-4">
                        <h3 class="h6 fw-bold mb-2">{{ $statuses[0]['dashboard_type_title_az'] ?? $dashboardType }}</h3>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle dashboard-visibility-table mb-0">
                                <thead>
                                    <tr>
                                        <th>Status</th>
                                        <th>Kod</th>
                                        <th class="text-end">Gorunur</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($statuses as $status)
                                        <tr>
                                            <td>{{ $status['title_az'] }}</td>
                                            <td><span class="dashboard-visibility-code">{{ $status['status_code'] }}</span></td>
                                            <td class="text-end">
                                                <div class="form-check form-switch d-inline-flex">
                                                    <input
                                                        type="checkbox"
                                                        class="form-check-input"
                                                        role="switch"
                                                        @checked($status['is_visible'])
                                                        data-status-visible-toggle
                                                        data-dashboard-type="{{ $dashboardType }}"
                                                        data-status-code="{{ $status['status_code'] }}"
                                                    >
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endforeach
            </div>

            <div data-dashboard-visibility-pane="order" hidden>
                <div class="d-flex justify-content-end mb-3">
                    <button type="button" class="btn btn-primary btn-sm btn-icon" data-dashboard-order-save>
                        <i class="bi bi-check2"></i><span>Sirani saxla</span>
                    </button>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle dashboard-visibility-table mb-0">
                        <thead>
                            <tr>
                                <th>Kod</th>
                                <th>Basliq</th>
                                <th>Bolme</th>
                                <th>Sira</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($configuration['dashboards'] as $dashboard)
                                <tr>
                                    <td><span class="dashboard-visibility-code">{{ $dashboard['code'] }}</span></td>
                                    <td>{{ $dashboard['title_az'] }}</td>
                                    <td>{{ $dashboard['section_code'] }}</td>
                                    <td>
                                        <input
                                            type="number"
                                            min="1"
                                            max="10000"
                                            class="form-control form-control-sm dashboard-visibility-order"
                                            value="{{ $dashboard['display_order'] }}"
                                            data-dashboard-order-input
                                            data-dashboard-code="{{ $dashboard['code'] }}"
                                        >
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div data-dashboard-visibility-pane="audit" hidden>
                <div class="dashboard-visibility-audit table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Tarix</th>
                                <th>Admin</th>
                                <th>Emeliyyat</th>
                                <th>Obyekt</th>
                                <th>Evvel</th>
                                <th>Yeni</th>
                                <th>IP</th>
                            </tr>
                        </thead>
                        <tbody data-dashboard-audit-body>
                            @forelse ($auditRows as $row)
                                <tr>
                                    <td>{{ $row['created_at'] }}</td>
                                    <td>{{ $row['admin_name'] ?? $row['admin_user_id'] }}</td>
                                    <td>{{ $row['action'] }}</td>
                                    <td><span class="dashboard-visibility-code">{{ $row['entity_type'] }}:{{ $row['entity_code'] }}</span></td>
                                    <td><code class="small">{{ \Illuminate\Support\Str::limit(json_encode($row['old_value'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 140) }}</code></td>
                                    <td><code class="small">{{ \Illuminate\Support\Str::limit(json_encode($row['new_value'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 140) }}</code></td>
                                    <td>{{ $row['ip_address'] ?? '-' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="text-secondary">Tarixce yoxdur</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>
@endsection

@push('scripts')
<script>
const visibilityRoot = document.querySelector('[data-dashboard-visibility-admin]');
const visibilityStatus = document.querySelector('[data-dashboard-visibility-status]');
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
const previewVisibleCount = document.querySelector('[data-preview-visible-count]');
const previewHiddenCount = document.querySelector('[data-preview-hidden-count]');
const previewStatusCount = document.querySelector('[data-preview-status-count]');
const previewOrder = document.querySelector('[data-dashboard-preview-order]');

const setVisibilityStatus = message => {
    if (visibilityStatus) {
        visibilityStatus.textContent = message || '';
    }
};

const visibilityRequest = async (url, method = 'GET', payload = null) => {
    const response = await fetch(url, {
        method,
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken,
        },
        body: payload === null ? null : JSON.stringify(payload),
    });

    if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
    }

    return response.json();
};

const confirmGlobalVisibilityChange = () => window.confirm('Bu deyisiklik butun istifadecilerin Dashboard gorunusune tetbiq edilecek. Davam edilsin?');

const escapePreviewText = value => String(value).replace(/[&<>"']/g, character => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;',
}[character]));

const updateVisibilityPreview = () => {
    const dashboardToggles = Array.from(document.querySelectorAll('[data-dashboard-visible-toggle]'));
    const visibleDashboards = dashboardToggles.filter(toggle => toggle.checked);
    const statusToggles = Array.from(document.querySelectorAll('[data-status-visible-toggle]'));

    if (previewVisibleCount) {
        previewVisibleCount.textContent = `Gorunen: ${visibleDashboards.length}`;
    }

    if (previewHiddenCount) {
        previewHiddenCount.textContent = `Gizli: ${dashboardToggles.length - visibleDashboards.length}`;
    }

    if (previewStatusCount) {
        previewStatusCount.textContent = `Statuslar: ${statusToggles.filter(toggle => toggle.checked).length} / ${statusToggles.length}`;
    }

    if (previewOrder) {
        previewOrder.innerHTML = visibleDashboards
            .map(toggle => {
                const code = toggle.dataset.dashboardCode;
                const row = document.querySelector(`[data-dashboard-row="${CSS.escape(code)}"]`);
                const orderInput = document.querySelector(`[data-dashboard-order-input][data-dashboard-code="${CSS.escape(code)}"]`);

                return {
                    code,
                    title: row?.dataset.dashboardTitle || code,
                    order: Number(orderInput?.value || 999),
                };
            })
            .sort((left, right) => left.order - right.order || left.code.localeCompare(right.code))
            .slice(0, 12)
            .map(item => `<li><span class="dashboard-visibility-code">${item.order}</span> ${escapePreviewText(item.title)}</li>`)
            .join('');
    }
};

document.querySelectorAll('[data-dashboard-visibility-tab]').forEach(button => {
    button.addEventListener('click', () => {
        const tab = button.dataset.dashboardVisibilityTab;

        document.querySelectorAll('[data-dashboard-visibility-tab]').forEach(item => {
            item.classList.toggle('active', item === button);
        });
        document.querySelectorAll('[data-dashboard-visibility-pane]').forEach(pane => {
            pane.hidden = pane.dataset.dashboardVisibilityPane !== tab;
        });
    });
});

document.querySelectorAll('[data-dashboard-visible-toggle]').forEach(toggle => {
    toggle.addEventListener('change', async () => {
        const code = toggle.dataset.dashboardCode;
        const orderInput = document.querySelector(`[data-dashboard-order-input][data-dashboard-code="${CSS.escape(code)}"]`);

        if (! confirmGlobalVisibilityChange()) {
            toggle.checked = !toggle.checked;
            updateVisibilityPreview();

            return;
        }

        toggle.disabled = true;
        setVisibilityStatus('Saxlanilir...');

        try {
            await visibilityRequest(
                @json(url('/api/admin/dashboard-visibility')).replace(/\/$/, '') + `/${encodeURIComponent(code)}`,
                'PUT',
                {
                    is_visible: toggle.checked,
                    display_order: Number(orderInput?.value || 999),
                },
            );
            setVisibilityStatus('Saxlandi.');
            updateVisibilityPreview();
        } catch (error) {
            toggle.checked = !toggle.checked;
            setVisibilityStatus('Saxlanilmadi.');
            updateVisibilityPreview();
        } finally {
            toggle.disabled = false;
        }
    });
});

document.querySelectorAll('[data-status-visible-toggle]').forEach(toggle => {
    toggle.addEventListener('change', async () => {
        const dashboardType = toggle.dataset.dashboardType;
        const statusCode = toggle.dataset.statusCode;

        if (! confirmGlobalVisibilityChange()) {
            toggle.checked = !toggle.checked;
            updateVisibilityPreview();

            return;
        }

        toggle.disabled = true;
        setVisibilityStatus('Saxlanilir...');

        try {
            await visibilityRequest(
                @json(url('/api/admin/dashboard-status-visibility')).replace(/\/$/, '') + `/${encodeURIComponent(dashboardType)}/${encodeURIComponent(statusCode)}`,
                'PUT',
                { is_visible: toggle.checked },
            );
            setVisibilityStatus('Saxlandi.');
            updateVisibilityPreview();
        } catch (error) {
            toggle.checked = !toggle.checked;
            setVisibilityStatus('Saxlanilmadi.');
            updateVisibilityPreview();
        } finally {
            toggle.disabled = false;
        }
    });
});

document.querySelectorAll('[data-dashboard-order-input]').forEach(input => {
    input.addEventListener('input', () => {
        document.querySelectorAll(`[data-dashboard-order-input][data-dashboard-code="${CSS.escape(input.dataset.dashboardCode)}"]`).forEach(peer => {
            if (peer !== input) {
                peer.value = input.value;
            }
        });

        updateVisibilityPreview();
    });
});

document.querySelector('[data-dashboard-order-save]')?.addEventListener('click', async buttonEvent => {
    const button = buttonEvent.currentTarget;
    const values = Array.from(document.querySelectorAll('[data-dashboard-order-input]'))
        .reduce((items, input) => {
            if (!items.some(item => item.code === input.dataset.dashboardCode)) {
                items.push({
                    code: input.dataset.dashboardCode,
                    display_order: Number(input.value || 999),
                });
            }

            return items;
        }, []);

    button.disabled = true;
    setVisibilityStatus('Sira saxlanilir...');

    try {
        await visibilityRequest(@json(route('api.admin.dashboard-order.update')), 'PUT', { dashboards: values });
        setVisibilityStatus('Sira saxlandi.');
        updateVisibilityPreview();
    } catch (error) {
        setVisibilityStatus('Sira saxlanilmadi.');
    } finally {
        button.disabled = false;
    }
});

document.querySelector('[data-dashboard-visibility-reset]')?.addEventListener('click', async event => {
    const button = event.currentTarget;

    if (!window.confirm('Butun Dashboard gorunurluk ayarlari standarta qaytarilsin?')) {
        return;
    }

    button.disabled = true;
    setVisibilityStatus('Sifirlanir...');

    try {
        await visibilityRequest(@json(route('api.admin.dashboard-visibility.reset')), 'POST', {});
        window.location.reload();
    } catch (error) {
        setVisibilityStatus('Sifirlanmadi.');
        button.disabled = false;
    }
});

updateVisibilityPreview();
</script>
@endpush
