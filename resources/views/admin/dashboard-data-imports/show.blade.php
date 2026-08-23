@extends('layouts.app')

@section('title', 'XLSX import yoxlaması | '.__('app.app_name'))
@section('page-title', 'XLSX import yoxlaması')
@section('page-subtitle', $import->date_from?->format('d.m.Y').' - '.$import->date_to?->format('d.m.Y'))

@section('content')
    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    @php
        $badge = match ($import->status) {
            'ready' => 'text-bg-primary',
            'completed' => 'text-bg-success',
            'invalid', 'failed' => 'text-bg-danger',
            'importing', 'validating' => 'text-bg-warning',
            default => 'text-bg-secondary',
        };
    @endphp

    <section class="panel p-4 mb-4">
        <div class="d-flex flex-column flex-xl-row justify-content-between gap-3">
            <div>
                <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                    <h2 class="h5 fw-bold mb-0">{{ $import->moduleLabel() }}</h2>
                    <span class="badge {{ $badge }}">{{ $import->status }}</span>
                </div>
                <div class="text-secondary">{{ $moduleSettings['report_name'] ?? $import->module }}</div>
            </div>
            <div class="d-flex flex-wrap align-items-start gap-2">
                <a href="{{ route('admin.dashboard-data-imports.index') }}" class="btn btn-outline-secondary btn-icon">
                    <i data-lucide="arrow-left"></i><span>Geri</span>
                </a>
                @if ($import->canBeConfirmed())
                    <form method="POST" action="{{ route('admin.dashboard-data-imports.confirm', $import) }}" onsubmit="return confirm('XLSX-də yoxlanmış obyektlərin seçilmiş dövr üzrə mövcud məlumatları əvəz edilsin?');">
                        @csrf
                        <button type="submit" class="btn btn-primary btn-icon">
                            <i data-lucide="database-backup"></i><span>Məlumatları əvəz et</span>
                        </button>
                    </form>
                @elseif (! in_array($import->status, ['completed', 'importing'], true))
                    <form method="POST" action="{{ route('admin.dashboard-data-imports.destroy', $import) }}" onsubmit="return confirm('Bu import qaralaması silinsin?');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-outline-danger btn-icon">
                            <i data-lucide="trash-2"></i><span>Sil</span>
                        </button>
                    </form>
                @endif
            </div>
        </div>

        <div class="row g-3 mt-2">
            <div class="col-6 col-md-3"><div class="small text-secondary">Layihə</div><div class="fw-semibold">{{ $import->project?->name ?: 'Bütün layihələr' }}</div></div>
            <div class="col-6 col-md-3"><div class="small text-secondary">Mənsubiyyət</div><div class="fw-semibold">{{ $import->ownership_type ?: 'NWC + İCARƏ' }}</div></div>
            <div class="col-6 col-md-2"><div class="small text-secondary">Qəbul edildi</div><div class="fw-semibold text-success">{{ $import->accepted_rows }}</div></div>
            <div class="col-6 col-md-2"><div class="small text-secondary">Xəta</div><div class="fw-semibold text-danger">{{ $import->rejected_rows }}</div></div>
            <div class="col-6 col-md-2"><div class="small text-secondary">Dublikat</div><div class="fw-semibold text-warning">{{ $import->duplicate_rows }}</div></div>
        </div>

        @if ($import->error_message)
            <div class="alert alert-danger mt-3 mb-0">{{ $import->error_message }}</div>
        @endif

        @if ($import->status === 'completed')
            <div class="alert alert-success mt-3 mb-0">
                XLSX-də olan obyektlər üzrə silinmiş sətirlər: <strong>{{ $import->deleted_rows }}</strong>. Yazılmış sətirlər: <strong>{{ $import->written_rows }}</strong>.
            </div>
        @endif
    </section>

    @if ($problemRows->isNotEmpty())
        <section class="panel p-4 mb-4">
            <h2 class="h6 fw-bold mb-3">Xətalar və təkrarlar</h2>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Status</th><th>Fayl</th><th>Vərəq</th><th>Sətir</th><th>Səbəb</th></tr></thead>
                    <tbody>
                    @foreach ($problemRows as $row)
                        <tr>
                            <td><span class="badge {{ $row->status === 'rejected' ? 'text-bg-danger' : 'text-bg-warning' }}">{{ $row->status }}</span></td>
                            <td>{{ $row->file_name }}</td>
                            <td>{{ $row->sheet_name ?: '-' }}</td>
                            <td>{{ $row->source_row_number ?: '-' }}</td>
                            <td>{{ $row->message }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    <section class="panel p-4">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
            <h2 class="h6 fw-bold mb-0">Qəbul edilən məlumatlar</h2>
            <div class="small text-secondary">Texnika: {{ data_get($import->summary_json, 'units', 0) }} · Cədvəl: {{ implode(', ', data_get($import->summary_json, 'tables', [])) }}</div>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                <tr><th>Tarix</th><th>Texnika</th><th>Layihə</th><th>Mənsubiyyət</th><th>Növ</th><th>Geofence</th><th>Motosaat</th><th>Yürüş</th></tr>
                </thead>
                <tbody>
                @forelse ($acceptedRows as $row)
                    @php($payload = $row->payload_json ?? [])
                    <tr>
                        <td class="text-nowrap">{{ data_get($payload, 'business_date') }}</td>
                        <td>{{ data_get($payload, 'unit_name') }}</td>
                        <td>{{ data_get($payload, 'project_name') }}</td>
                        <td>{{ data_get($payload, 'ownership_type') }}</td>
                        <td>{{ data_get($payload, 'vehicle_type') }}</td>
                        <td>{{ data_get($payload, 'geofence_name') ?: '-' }}</td>
                        <td>{{ number_format((float) data_get($payload, 'engine_hours_decimal', 0), 2, '.', '') }}</td>
                        <td>{{ data_get($payload, 'mileage_km') === null ? '-' : number_format((float) data_get($payload, 'mileage_km'), 2, '.', '') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-secondary">Qəbul edilən məlumat yoxdur.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection
