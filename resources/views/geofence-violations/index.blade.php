@extends('layouts.app')

@section('title', 'Geofence Pozuntuları | '.__('app.app_name'))
@section('page-title', 'Geofence Pozuntuları')
@section('page-subtitle', 'Bütün layihə geozonalarından kənarda fasiləsiz 3 saatdan çox qalan texnika')

@push('styles')
<style>
    .gv-shell {
        max-width: 1800px;
        margin: 0 auto;
    }
    .gv-panel,
    .gv-kpi {
        background: var(--fleet-card);
        border: 1px solid var(--fleet-line);
        border-radius: 8px;
        box-shadow: var(--fleet-shadow);
    }
    .gv-filter-grid {
        display: grid;
        grid-template-columns: repeat(7, minmax(140px, 1fr));
        gap: 12px;
        align-items: end;
    }
    .gv-field label {
        display: block;
        margin-bottom: 6px;
        color: var(--fleet-muted);
        font-size: 11px;
        font-weight: 800;
        text-transform: uppercase;
    }
    .gv-actions {
        display: flex;
        gap: 8px;
    }
    .gv-actions .btn {
        min-height: 38px;
    }
    .gv-source {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        border-top: 1px solid var(--fleet-line);
        color: var(--fleet-muted);
        font-size: 12px;
    }
    .gv-rule {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        font-weight: 700;
        color: var(--fleet-ink);
    }
    .gv-rule .lucide {
        width: 16px;
        height: 16px;
        color: var(--fleet-blue);
    }
    .gv-kpi-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 14px;
    }
    .gv-kpi {
        min-height: 116px;
        padding: 18px;
        display: grid;
        grid-template-columns: 44px minmax(0, 1fr);
        gap: 13px;
        align-items: center;
    }
    .gv-kpi-icon {
        width: 44px;
        height: 44px;
        border-radius: 8px;
        display: grid;
        place-items: center;
        color: var(--gv-accent);
        background: color-mix(in srgb, var(--gv-accent) 12%, transparent);
    }
    .gv-kpi-icon .lucide {
        width: 21px;
        height: 21px;
    }
    .gv-kpi-label {
        color: var(--fleet-muted);
        font-size: 12px;
        font-weight: 700;
    }
    .gv-kpi-value {
        margin-top: 4px;
        color: var(--fleet-ink);
        font-size: 25px;
        font-weight: 850;
        line-height: 1.05;
    }
    .gv-kpi-note {
        margin-top: 6px;
        color: var(--fleet-muted);
        font-size: 11px;
    }
    .gv-distribution-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        border-bottom: 1px solid var(--fleet-line);
    }
    .gv-distribution-head h2 {
        margin: 0;
        font-size: 18px;
        font-weight: 850;
    }
    .gv-distribution-body {
        display: grid;
        grid-template-columns: minmax(260px, 390px) minmax(0, 1fr);
        gap: 28px;
        align-items: center;
        min-height: 360px;
    }
    .gv-donut-wrap {
        display: grid;
        place-items: center;
        min-width: 0;
    }
    .gv-donut {
        position: relative;
        width: min(290px, 100%);
        aspect-ratio: 1;
        border-radius: 50%;
        background: var(--gv-donut-background);
        box-shadow: 0 12px 30px rgba(15, 23, 42, .08);
    }
    .gv-donut::after {
        content: "";
        position: absolute;
        inset: 20%;
        border-radius: 50%;
        background: var(--fleet-card);
        box-shadow: inset 0 0 0 1px var(--fleet-line);
    }
    .gv-donut-center {
        position: absolute;
        inset: 27%;
        z-index: 1;
        display: grid;
        place-content: center;
        text-align: center;
    }
    .gv-donut-value {
        font-size: 34px;
        font-weight: 900;
        line-height: 1;
    }
    .gv-donut-label {
        margin-top: 7px;
        color: var(--fleet-muted);
        font-size: 12px;
        font-weight: 800;
    }
    .gv-distribution-list {
        min-width: 0;
    }
    .gv-distribution-list h3 {
        margin: 0 0 12px;
        font-size: 15px;
        font-weight: 800;
    }
    .gv-legend-scroll {
        display: grid;
        gap: 8px;
        max-height: 290px;
        overflow-y: auto;
        padding-right: 5px;
    }
    .gv-legend-row {
        min-height: 44px;
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto auto;
        align-items: center;
        gap: 15px;
        padding: 9px 12px;
        border-radius: 7px;
        background: var(--fleet-card-soft);
    }
    .gv-legend-name {
        min-width: 0;
        display: flex;
        align-items: center;
        gap: 10px;
        font-weight: 750;
    }
    .gv-legend-name span:last-child {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .gv-legend-swatch {
        width: 10px;
        height: 10px;
        border-radius: 999px;
        flex: 0 0 auto;
        background: var(--gv-segment-color);
    }
    .gv-legend-count {
        min-width: 38px;
        text-align: right;
        font-weight: 850;
    }
    .gv-legend-percent {
        min-width: 52px;
        color: var(--fleet-muted);
        text-align: right;
        font-size: 12px;
        font-weight: 800;
    }
    .gv-table-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        border-bottom: 1px solid var(--fleet-line);
    }
    .gv-table-head h2 {
        margin: 0;
        font-size: 17px;
        font-weight: 800;
    }
    .gv-count {
        color: var(--fleet-muted);
        font-size: 12px;
        font-weight: 700;
    }
    .gv-table {
        min-width: 1500px;
        margin: 0;
    }
    .gv-table th {
        padding: 12px 10px;
        color: var(--fleet-muted);
        background: var(--fleet-card-soft);
        font-size: 10px;
        font-weight: 800;
        text-transform: uppercase;
        vertical-align: middle;
        white-space: nowrap;
    }
    .gv-table td {
        padding: 12px 10px;
        color: var(--fleet-ink);
        vertical-align: top;
    }
    .gv-table tbody tr:last-child td {
        border-bottom: 0;
    }
    .gv-equipment {
        font-weight: 800;
    }
    .gv-secondary {
        margin-top: 3px;
        color: var(--fleet-muted);
        font-size: 11px;
    }
    .gv-location {
        max-width: 260px;
        white-space: normal;
    }
    .gv-status {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        border-radius: 6px;
        padding: 5px 8px;
        font-size: 11px;
        font-weight: 800;
        white-space: nowrap;
    }
    .gv-status::before {
        content: "";
        width: 7px;
        height: 7px;
        border-radius: 999px;
        background: currentColor;
    }
    .gv-status-active {
        color: #b42318;
        background: #fff1f0;
    }
    .gv-status-completed {
        color: #475467;
        background: #f2f4f7;
    }
    [data-theme="dark"] .gv-status-active {
        color: #fda29b;
        background: rgba(180, 35, 24, .22);
    }
    [data-theme="dark"] .gv-status-completed {
        color: #d0d5dd;
        background: rgba(71, 84, 103, .32);
    }
    .gv-empty {
        padding: 56px 24px;
        text-align: center;
    }
    .gv-empty-icon {
        width: 48px;
        height: 48px;
        margin: 0 auto 13px;
        border-radius: 8px;
        display: grid;
        place-items: center;
        color: var(--fleet-muted);
        background: var(--fleet-card-soft);
    }
    .gv-empty-icon .lucide {
        width: 23px;
        height: 23px;
    }
    @media (max-width: 1500px) {
        .gv-filter-grid {
            grid-template-columns: repeat(4, minmax(150px, 1fr));
        }
    }
    @media (max-width: 1100px) {
        .gv-kpi-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        .gv-filter-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        .gv-distribution-body {
            grid-template-columns: minmax(230px, 320px) minmax(0, 1fr);
            gap: 20px;
        }
    }
    @media (max-width: 640px) {
        .gv-filter-grid,
        .gv-kpi-grid {
            grid-template-columns: 1fr;
        }
        .gv-actions {
            display: grid;
            grid-template-columns: 1fr auto;
        }
        .gv-kpi {
            min-height: 98px;
        }
        .gv-distribution-body {
            grid-template-columns: 1fr;
            min-height: 0;
        }
        .gv-donut {
            width: min(250px, 78vw);
        }
        .gv-legend-scroll {
            max-height: 260px;
        }
    }
</style>
@endpush

@section('content')
@php
    $formatDateTime = static fn ($value) => $value
        ? \Illuminate\Support\Carbon::parse($value)->timezone(config('app.timezone'))->format('d.m.Y H:i:s')
        : '—';
    $donutCursor = 0.0;
    $donutSegments = [];

    foreach ($distribution as $segmentIndex => $segment) {
        $segmentEnd = $segmentIndex === $distribution->count() - 1
            ? 100.0
            : min(100.0, $donutCursor + $segment['share']);
        $donutSegments[] = sprintf(
            '%s %.4f%% %.4f%%',
            $segment['color'],
            $donutCursor,
            $segmentEnd
        );
        $donutCursor = $segmentEnd;
    }

    $donutBackground = $donutSegments === []
        ? 'conic-gradient(#E5E7EB 0% 100%)'
        : 'conic-gradient('.implode(', ', $donutSegments).')';
@endphp

<div class="gv-shell py-4 px-3 px-lg-4">
    <form method="GET" action="{{ route('geofence-violations.index') }}" class="gv-panel mb-3">
        <div class="gv-filter-grid p-3 p-lg-4">
            <div class="gv-field">
                <label for="gvDateFrom">Başlanğıc</label>
                <input id="gvDateFrom" class="form-control" type="date" name="date_from" value="{{ $filters['date_from'] }}">
            </div>
            <div class="gv-field">
                <label for="gvDateTo">Son</label>
                <input id="gvDateTo" class="form-control" type="date" name="date_to" value="{{ $filters['date_to'] }}">
            </div>
            <div class="gv-field">
                <label for="gvProject">Layihə</label>
                <select id="gvProject" class="form-select" name="project_id">
                    <option value="">Bütün layihələr</option>
                    @foreach ($projects as $project)
                        <option value="{{ $project->project_id }}" @selected((string) $filters['project_id'] === (string) $project->project_id)>
                            {{ $project->project_name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="gv-field">
                <label for="gvType">Texnika növü</label>
                <select id="gvType" class="form-select" name="equipment_type">
                    <option value="">Bütün icazəli növlər</option>
                    @foreach ($equipment_types as $type)
                        <option value="{{ $type }}" @selected($filters['equipment_type'] === $type)>{{ $type }}</option>
                    @endforeach
                </select>
            </div>
            <div class="gv-field">
                <label for="gvOwnership">Ownership</label>
                <select id="gvOwnership" class="form-select" name="ownership_type">
                    <option value="">NWC + İCARƏ</option>
                    <option value="NWC" @selected($filters['ownership_type'] === 'NWC')>NWC</option>
                    <option value="ICARE" @selected($filters['ownership_type'] === 'ICARE')>İCARƏ</option>
                </select>
            </div>
            <div class="gv-field">
                <label for="gvStatus">Pozuntu statusu</label>
                <select id="gvStatus" class="form-select" name="status">
                    <option value="">Bütün statuslar</option>
                    <option value="active" @selected($filters['status'] === 'active')>Aktiv pozuntu</option>
                    <option value="completed" @selected($filters['status'] === 'completed')>Tamamlanmış pozuntu</option>
                </select>
            </div>
            <div class="gv-field">
                <label for="gvSearch">Axtarış</label>
                <div class="gv-actions">
                    <input id="gvSearch" class="form-control" type="search" name="search" value="{{ $filters['search'] }}" placeholder="Texnika və ya məkan">
                    <button class="btn btn-primary btn-icon" type="submit" title="Filtrlə">
                        <i data-lucide="filter"></i><span>Filtrlə</span>
                    </button>
                    <a class="btn btn-outline-secondary btn-icon" href="{{ route('geofence-violations.index') }}" title="Filtrləri təmizlə">
                        <i data-lucide="rotate-ccw"></i>
                    </a>
                </div>
            </div>
        </div>
        <div class="gv-source px-3 px-lg-4 py-3">
            <span class="gv-rule">
                <i data-lucide="shield-check"></i>
                Cari layihə geozonası: Yoxdur · fasiləsiz müddət &gt; 3 saat
            </span>
            <span>
                Mənbə: <strong>Geofence Pozuntuları api</strong>
                · Son təsdiqlənmiş hesabat: {{ $formatDateTime($latest_report_at) }}
                · Məlumatın son vaxtı: {{ $formatDateTime($latest_report_period_to) }}
            </span>
        </div>
    </form>

    <section class="gv-kpi-grid mb-3" aria-label="Geofence pozuntuları KPI">
        <article class="gv-kpi" style="--gv-accent: #2563eb;">
            <div class="gv-kpi-icon"><i data-lucide="list-checks"></i></div>
            <div>
                <div class="gv-kpi-label">Ümumi pozuntu</div>
                <div class="gv-kpi-value">{{ number_format($kpis['total_violations']) }}</div>
                <div class="gv-kpi-note">Unikal fasiləsiz dövrlər</div>
            </div>
        </article>
        <article class="gv-kpi" style="--gv-accent: #dc2626;">
            <div class="gv-kpi-icon"><i data-lucide="radio-tower"></i></div>
            <div>
                <div class="gv-kpi-label">Hesabat sonuna aktiv</div>
                <div class="gv-kpi-value">{{ number_format($kpis['active_violations']) }}</div>
                <div class="gv-kpi-note">Son təsdiqlənmiş vaxtda davam edən</div>
            </div>
        </article>
        <article class="gv-kpi" style="--gv-accent: #16a34a;">
            <div class="gv-kpi-icon"><i data-lucide="folder-kanban"></i></div>
            <div>
                <div class="gv-kpi-label">Aktiv layihələr</div>
                <div class="gv-kpi-value">{{ number_format($kpis['active_projects']) }}</div>
                <div class="gv-kpi-note">Pozuntu qeydi olan layihələr</div>
            </div>
        </article>
        <article class="gv-kpi" style="--gv-accent: #7c3aed;">
            <div class="gv-kpi-icon"><i data-lucide="timer"></i></div>
            <div>
                <div class="gv-kpi-label">Ən uzun pozuntu</div>
                <div class="gv-kpi-value">{{ $durationFormatter($kpis['longest_duration_seconds']) }}</div>
                <div class="gv-kpi-note">Maksimum fasiləsiz müddət</div>
            </div>
        </article>
    </section>

    <section class="gv-panel overflow-hidden mb-3">
        <div class="gv-distribution-head p-3 p-lg-4">
            <div>
                <h2>Geofence Pozuntuları</h2>
                <div class="text-secondary small mt-1">Seçilmiş filtrlər üzrə pozuntu dövrlərinin layihə paylanması</div>
            </div>
            <span class="gv-count">{{ number_format($kpis['total_violations']) }} pozuntu</span>
        </div>
        <div class="gv-distribution-body p-3 p-lg-4">
            <div class="gv-donut-wrap">
                <div class="gv-donut" style="--gv-donut-background: {{ $donutBackground }};">
                    <div class="gv-donut-center">
                        <div class="gv-donut-value">{{ number_format($kpis['total_violations']) }}</div>
                        <div class="gv-donut-label">Ümumi pozuntu</div>
                    </div>
                </div>
            </div>
            <div class="gv-distribution-list">
                <h3>Layihələr üzrə pozuntular</h3>
                @if ($distribution->isEmpty())
                    <div class="text-secondary">Seçilmiş dövr üçün paylanma məlumatı yoxdur.</div>
                @else
                    <div class="gv-legend-scroll">
                        @foreach ($distribution as $segment)
                            <div class="gv-legend-row" style="--gv-segment-color: {{ $segment['color'] }};">
                                <div class="gv-legend-name" title="{{ $segment['label'] }}">
                                    <span class="gv-legend-swatch"></span>
                                    <span>{{ $segment['label'] }}</span>
                                </div>
                                <div class="gv-legend-count">{{ number_format($segment['count']) }}</div>
                                <div class="gv-legend-percent">{{ number_format($segment['percentage'], 1) }}%</div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </section>

    <section class="gv-panel overflow-hidden">
        <div class="gv-table-head p-3 p-lg-4">
            <h2>Pozuntu dövrləri</h2>
            <span class="gv-count">{{ number_format($rows->total()) }} qeyd</span>
        </div>

        @if ($rows->isEmpty())
            <div class="gv-empty">
                <div class="gv-empty-icon"><i data-lucide="shield-check"></i></div>
                <div class="fw-bold">Seçilmiş dövr üçün etibarlı pozuntu tapılmadı</div>
                <div class="text-secondary small mt-1">
                    Yalnız “Geofence Pozuntuları api” hesabatından alınmış və 3 saatdan çox olan dövrlər göstərilir.
                </div>
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle gv-table">
                    <thead>
                    <tr>
                        <th>№</th>
                        <th>Texnika</th>
                        <th>Texnika növü</th>
                        <th>Ownership</th>
                        <th>Son layihə geozonası</th>
                        <th>Geozonadan çıxış vaxtı</th>
                        <th>Son təsdiqlənmiş vaxt</th>
                        <th>Geozonalardan kənarda müddət</th>
                        <th>Son məkan</th>
                        <th>Pozuntu statusu</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <td>{{ ($rows->firstItem() ?? 1) + $loop->index }}</td>
                            <td>
                                <div class="gv-equipment">{{ $row->equipment_name }}</div>
                                <div class="gv-secondary">{{ $row->project_name ?: 'Layihə göstərilməyib' }}</div>
                            </td>
                            <td>{{ $row->equipment_type }}</td>
                            <td>{{ $row->ownership_type === 'ICARE' ? 'İCARƏ' : ($row->ownership_type ?: '—') }}</td>
                            <td>
                                {{ $row->last_project_geofence ?: 'Hesabat təqdim etmir' }}
                                <div class="gv-secondary">Cari layihə geozonası: Yoxdur</div>
                            </td>
                            <td>{{ $formatDateTime($row->exited_at) }}</td>
                            <td>{{ $formatDateTime($row->last_confirmed_at) }}</td>
                            <td class="fw-semibold">{{ $row->duration_label }}</td>
                            <td class="gv-location">{{ $row->last_location ?: '—' }}</td>
                            <td>
                                <span class="gv-status {{ $row->is_active ? 'gv-status-active' : 'gv-status-completed' }}">
                                    {{ $row->status_label }}
                                </span>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="px-3 px-lg-4 py-3 border-top">
                {{ $rows->links() }}
            </div>
        @endif
    </section>
</div>
@endsection
