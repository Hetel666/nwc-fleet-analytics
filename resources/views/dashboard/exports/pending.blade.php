@extends('layouts.app')

@section('title', 'Excel export')
@section('page-title', 'Excel faylı hazırlanır')
@section('page-subtitle', 'Hazır olduqda fayl avtomatik yüklənəcək')

@section('content')
<div class="container py-4" style="max-width: 720px;">
    <section class="panel p-4">
        <div class="d-flex align-items-center gap-3">
            <div class="spinner-border text-primary" id="exportSpinner" role="status" aria-hidden="true"></div>
            <div>
                <h2 class="h5 mb-1">Excel faylı hazırlanır</h2>
                <p class="text-secondary mb-0" id="exportStatus">Məlumatlar fon rejimində hesablanır.</p>
            </div>
        </div>
        <div class="mt-4">
            <a class="btn btn-primary d-none" id="exportDownload" href="{{ route('dashboard.exports.download', $export) }}">
                <i class="bi bi-download"></i>
                <span>Excel faylını yüklə</span>
            </a>
            <a class="btn btn-outline-secondary" href="{{ route('dashboard') }}">İdarə Panelinə qayıt</a>
        </div>
    </section>
</div>
@endsection

@push('scripts')
<script>
(() => {
    const statusUrl = @json(route('dashboard.exports.status', $export));
    const statusNode = document.getElementById('exportStatus');
    const spinner = document.getElementById('exportSpinner');
    const download = document.getElementById('exportDownload');
    let downloaded = false;

    const poll = async () => {
        try {
            const response = await fetch(statusUrl, {headers: {'Accept': 'application/json'}});
            const payload = await response.json();

            if (payload.status === 'ready') {
                spinner.classList.add('d-none');
                statusNode.textContent = 'Excel faylı hazırdır.';
                download.classList.remove('d-none');

                if (!downloaded) {
                    downloaded = true;
                    window.location.assign(download.href);
                }

                return;
            }

            if (payload.status === 'failed') {
                spinner.classList.add('d-none');
                statusNode.textContent = payload.message || 'Excel faylı yaradıla bilmədi.';
                return;
            }
        } catch (error) {
            statusNode.textContent = 'Status yoxlanılır...';
        }

        window.setTimeout(poll, 2000);
    };

    poll();
})();
</script>
@endpush
