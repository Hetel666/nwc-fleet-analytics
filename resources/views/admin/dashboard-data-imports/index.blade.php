@extends('layouts.app')

@section('title', 'Wialon XLSX yüklə | '.__('app.app_name'))
@section('page-title', 'Wialon XLSX yüklə')
@section('page-subtitle', 'Hazır Wialon hesabatını yoxla və seçilmiş Dashboard dövrünü yenilə')

@section('content')
    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <div class="row g-4">
        <div class="col-12 col-xl-5">
            <section class="panel p-4">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <i data-lucide="file-spreadsheet"></i>
                    <h2 class="h6 fw-bold mb-0">Yeni import</h2>
                </div>

                @if ($errors->any())
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form method="POST" action="{{ route('admin.dashboard-data-imports.store') }}" enctype="multipart/form-data">
                    @csrf
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label" for="module">Dashboard</label>
                            <select id="module" name="module" class="form-select" required>
                                @foreach ($modules as $code => $label)
                                    <option value="{{ $code }}" @selected(old('module') === $code)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="project_id">Layihə</label>
                            <select id="project_id" name="project_id" class="form-select">
                                <option value="">Bütün layihələr</option>
                                @foreach ($projects as $project)
                                    <option value="{{ $project->id }}" @selected((string) old('project_id') === (string) $project->id)>{{ $project->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="ownership_type">Mənsubiyyət</label>
                            <select id="ownership_type" name="ownership_type" class="form-select">
                                <option value="">NWC + İCARƏ</option>
                                @foreach ($ownerships as $code => $label)
                                    <option value="{{ $code }}" @selected(old('ownership_type') === $code)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="date_from">Başlanğıc</label>
                            <input id="date_from" type="date" name="date_from" class="form-control" value="{{ old('date_from', $today) }}" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="date_to">Son</label>
                            <input id="date_to" type="date" name="date_to" class="form-control" value="{{ old('date_to', $today) }}" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="files">Wialon XLSX</label>
                            <input id="files" type="file" name="files[]" class="form-control" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" multiple required>
                        </div>
                        <div class="col-12">
                            <div class="border rounded-2 p-3 bg-body-tertiary" id="report-requirement">
                                @foreach ($moduleSettings as $code => $settings)
                                    <div data-module-requirement="{{ $code }}" @class(['d-none' => old('module', array_key_first($modules)) !== $code])>
                                        <div class="small text-secondary">Wialon hesabatı</div>
                                        <div class="fw-semibold">{{ $settings['report_name'] }}</div>
                                        <div class="small text-secondary mt-1">Cədvəl: {{ implode(', ', $settings['required_tables']) }}</div>
                                        <div class="small mt-2">{{ $settings['description'] }}</div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                        <div class="col-12 d-flex justify-content-end">
                            <button type="submit" class="btn btn-primary btn-icon">
                                <i data-lucide="scan-search"></i><span>Yoxla</span>
                            </button>
                        </div>
                    </div>
                </form>
            </section>
        </div>

        <div class="col-12 col-xl-7">
            <section class="panel p-4">
                <div class="d-flex align-items-center justify-content-between gap-2 mb-3">
                    <h2 class="h6 fw-bold mb-0">Son importlar</h2>
                    <a href="{{ route('admin.dashboard-data-imports.index') }}" class="btn btn-outline-secondary btn-sm btn-icon">
                        <i data-lucide="refresh-cw"></i><span>Yenilə</span>
                    </a>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Tarix</th>
                            <th>Dashboard</th>
                            <th>Dövr</th>
                            <th>Layihə</th>
                            <th>Status</th>
                            <th>Sətir</th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse ($imports as $import)
                            @php
                                $badge = match ($import->status) {
                                    'ready' => 'text-bg-primary',
                                    'completed' => 'text-bg-success',
                                    'invalid', 'failed' => 'text-bg-danger',
                                    'importing', 'validating' => 'text-bg-warning',
                                    default => 'text-bg-secondary',
                                };
                            @endphp
                            <tr>
                                <td class="text-nowrap">{{ $import->created_at?->format('d.m.Y H:i') }}</td>
                                <td>{{ $import->moduleLabel() }}</td>
                                <td class="text-nowrap">{{ $import->date_from?->format('d.m.Y') }} - {{ $import->date_to?->format('d.m.Y') }}</td>
                                <td>{{ $import->project?->name ?: 'Bütün layihələr' }}</td>
                                <td><span class="badge {{ $badge }}">{{ $import->status }}</span></td>
                                <td>{{ $import->accepted_rows }}</td>
                                <td class="text-end">
                                    <a class="btn btn-outline-primary btn-sm btn-icon" href="{{ route('admin.dashboard-data-imports.show', $import) }}" title="Aç">
                                        <i data-lucide="arrow-right"></i>
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-secondary">Import tarixçəsi boşdur.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        (() => {
            const module = document.getElementById('module');
            const requirements = document.querySelectorAll('[data-module-requirement]');
            if (!module) return;
            module.addEventListener('change', () => {
                requirements.forEach(item => item.classList.toggle('d-none', item.dataset.moduleRequirement !== module.value));
            });
        })();
    </script>
@endpush
