@props([
    'title',
    'subtitle' => null,
    'exportUrl' => null,
])

<div class="dashboard-panel-header d-flex align-items-start justify-content-between gap-2 mb-3">
    <div class="min-w-0">
        <h2 class="h6 fw-bold mb-0">{{ $title }}</h2>
        @if ($subtitle)
            <div class="small text-secondary mt-1">{{ $subtitle }}</div>
        @endif
    </div>
    <div class="d-flex align-items-center gap-1 flex-shrink-0">
        @if ($exportUrl)
            <a href="{{ $exportUrl }}" class="btn btn-sm dashboard-export-button" title="Excel" aria-label="Excel">
                <i class="bi bi-download"></i>
            </a>
        @endif
        <button type="button" class="btn btn-sm dashboard-drag-handle" title="Bloku daşı" aria-label="Bloku daşı">
            <i class="bi bi-grip-vertical"></i>
        </button>
    </div>
</div>
