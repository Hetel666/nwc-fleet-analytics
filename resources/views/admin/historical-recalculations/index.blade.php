@extends('layouts.app')

@section('title', 'Tarixi məlumatların yenilənməsi')
@section('page-title', 'Tarixi məlumatların yenilənməsi')
@section('page-subtitle', 'Wialon məlumatlarını fon rejimində yüklə və Dashboard statistikasını yenilə')

@section('content')
    <div class="row g-4">
        <div class="col-lg-5">
            <div class="panel p-4">
                <h2 class="h6 fw-bold mb-3">Yeni yenilənmə</h2>

                @if ($errors->any())
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form id="historical-recalculation-form" method="POST" action="{{ route('admin.historical-recalculations.store') }}">
                    @csrf

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Başlanğıc tarixi</label>
                            <input type="date" name="date_from" class="form-control" value="{{ old('date_from', $today) }}" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Bitmə tarixi</label>
                            <input type="date" name="date_to" class="form-control" value="{{ old('date_to', $today) }}" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Saat qurşağı</label>
                            <input type="text" name="timezone" class="form-control" value="{{ old('timezone', $defaultTimezone) }}" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Bölmə</label>
                            <div class="d-grid gap-2">
                                <input type="radio" class="btn-check" name="dashboard_section" id="section-daily-averages" value="daily_averages" autocomplete="off" @checked(old('dashboard_section', 'daily_averages') === 'daily_averages')>
                                <label class="btn btn-outline-primary text-start" for="section-daily-averages">Orta motosaat göstəricisi / Orta yürüş göstəricisi</label>

                                <input type="radio" class="btn-check" name="dashboard_section" id="section-efficiency" value="efficiency" autocomplete="off" @checked(old('dashboard_section') === 'efficiency')>
                                <label class="btn btn-outline-primary text-start" for="section-efficiency">Effektivlik</label>

                                <input type="radio" class="btn-check" name="dashboard_section" id="section-top-working-units" value="top_working_units" autocomplete="off" @checked(old('dashboard_section') === 'top_working_units')>
                                <label class="btn btn-outline-primary text-start" for="section-top-working-units">Top 20 az işləyənlər / Top 20 çox işləyənlər</label>

                                <input type="radio" class="btn-check" name="dashboard_section" id="section-geofence-outside" value="geofence_outside" autocomplete="off" @checked(old('dashboard_section') === 'geofence_outside')>
                                <label class="btn btn-outline-primary text-start" for="section-geofence-outside">Geofence Transferləri</label>

                                <input type="radio" class="btn-check" name="dashboard_section" id="section-geofence-violations" value="geofence_violations" autocomplete="off" @checked(old('dashboard_section') === 'geofence_violations')>
                                <label class="btn btn-outline-primary text-start" for="section-geofence-violations">Geofence Pozuntuları</label>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Əməliyyat növü</label>
                            <select name="operation" class="form-select" required>
                                <option value="fetch_and_recalculate" @selected(old('operation', 'fetch_and_recalculate') === 'fetch_and_recalculate')>Məlumatları yüklə və statistikanı hesabla</option>
                                <option value="fetch" @selected(old('operation') === 'fetch')>Yalnız Wialon məlumatlarını yüklə</option>
                                <option value="recalculate" @selected(old('operation') === 'recalculate')>Yalnız statistikanı yenidən hesabla</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Əhatə dairəsi</label>
                            <select id="scope-select" name="scope" class="form-select" required>
                                <option value="all_projects" @selected(old('scope', 'all_projects') === 'all_projects')>Bütün layihələr</option>
                                <option value="selected_projects" @selected(old('scope') === 'selected_projects')>Seçilmiş layihələr</option>
                            </select>
                        </div>
                        <div id="project-select-wrap" class="col-12">
                            <label class="form-label">Layihələr</label>
                            <select name="project_ids[]" class="form-select" multiple size="10">
                                @foreach ($projects as $project)
                                    <option value="{{ $project->id }}" @selected(in_array($project->id, old('project_ids', [])))>{{ $project->name }}</option>
                                @endforeach
                            </select>
                            <div class="form-text">Seçilmiş layihələr üçün Ctrl düyməsi ilə bir neçə layihə seçilə bilər.</div>
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input type="hidden" name="force" value="0">
                                <input class="form-check-input" type="checkbox" name="force" value="1" id="force" @checked(old('force'))>
                                <label class="form-check-label" for="force">Mövcud uğurlu günləri də yenidən hesabla</label>
                            </div>
                        </div>
                    </div>

                    <div id="preview-result" class="alert alert-secondary small mt-3 d-none"></div>
                    <div class="alert alert-info small mt-3 mb-0">
                        Wialon report tapsiriqlari serveri yuklememek ucun ardicil novbede icra olunur: bir tapsiriq bitdikden sonra novbetisi baslayir.
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="button" id="preview-button" class="btn btn-outline-primary btn-icon">
                            <i class="bi bi-search"></i><span>Ön baxış</span>
                        </button>
                        <button class="btn btn-primary btn-icon">
                            <i class="bi bi-play-fill"></i><span>Növbəyə əlavə et</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="panel p-4">
                <h2 class="h6 fw-bold mb-3">Son yenilənmələr</h2>

                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead>
                        <tr>
                            <th>Dövr</th>
                            <th>Əməliyyat</th>
                            <th>Bölmə</th>
                            <th>Status</th>
                            <th>Progress</th>
                            <th>Obyekt</th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse ($runs as $run)
                            @php
                                $done = $run->completed_tasks + $run->failed_tasks + $run->cancelled_tasks;
                                $percent = $run->total_tasks > 0 ? round(($done / $run->total_tasks) * 100, 1) : 0;
                            @endphp
                            <tr>
                                <td>{{ $run->date_from->toDateString() }} - {{ $run->date_to->toDateString() }}</td>
                                <td>{{ $run->operation }}</td>
                                <td>{{ [
                                    'daily_averages' => 'Orta göstəricilər',
                                    'efficiency' => 'Effektivlik',
                                    'top_working_units' => 'Top 20',
                                    'geofence_outside' => 'Geofence Transferləri',
                                    'geofence_violations' => 'Geofence Pozuntuları',
                                ][$run->dashboard_section] ?? $run->dashboard_section }}</td>
                                <td><span class="badge text-bg-secondary">{{ $run->status }}</span></td>
                                <td style="min-width: 150px;">
                                    <div class="progress" style="height: 8px;">
                                        <div class="progress-bar" style="width: {{ $percent }}%"></div>
                                    </div>
                                    <div class="small text-secondary mt-1">{{ $done }} / {{ $run->total_tasks }}</div>
                                </td>
                                <td>{{ $run->processed_objects }}</td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-outline-primary" href="{{ route('admin.historical-recalculations.show', $run) }}">Aç</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-secondary">Məlumat yoxdur</td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        const scopeSelect = document.getElementById('scope-select');
        const projectWrap = document.getElementById('project-select-wrap');
        const previewButton = document.getElementById('preview-button');
        const previewResult = document.getElementById('preview-result');
        const form = document.getElementById('historical-recalculation-form');

        function toggleProjects() {
            projectWrap.classList.toggle('d-none', scopeSelect.value !== 'selected_projects');
        }

        scopeSelect.addEventListener('change', toggleProjects);
        toggleProjects();

        previewButton.addEventListener('click', async () => {
            previewResult.classList.remove('d-none', 'alert-danger');
            previewResult.classList.add('alert-secondary');
            previewResult.textContent = 'Hesablanır...';

            try {
                const response = await fetch('{{ route('admin.historical-recalculations.preview') }}', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                    body: new FormData(form),
                });

                const data = await response.json();

                if (!response.ok) {
                    throw new Error(Object.values(data.errors || {}).flat().join(' ') || 'Ön baxış alınmadı.');
                }

                previewResult.textContent = `${data.days} gün, ${data.project_groups} obyekt, ${data.fetch_tasks} yükləmə tapşırığı, ${data.aggregate_tasks} hesablama tapşırığı. Cəmi: ${data.total_tasks}.`;
            } catch (error) {
                previewResult.classList.remove('alert-secondary');
                previewResult.classList.add('alert-danger');
                previewResult.textContent = error.message;
            }
        });
    </script>
@endpush
