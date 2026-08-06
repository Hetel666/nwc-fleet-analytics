@props([
    'title',
    'subtitle' => null,
    'exportUrl' => null,
    'exportItems' => [],
])

<div class="dashboard-panel-header d-flex align-items-start justify-content-between gap-2 mb-3">
    <div class="min-w-0">
        <h2 class="h6 fw-bold mb-0 dashboard-card-title-text">{{ $title }}</h2>
        <input type="text" class="form-control form-control-sm dashboard-title-input mt-1 d-none" value="{{ $title }}" maxlength="120" aria-label="Dashboard başlığı">
        @if ($subtitle)
            <div class="small text-secondary mt-1">{{ $subtitle }}</div>
        @endif
    </div>
    <div class="d-flex align-items-center gap-1 flex-shrink-0">
        @if ($exportUrl)
            @if (! empty($exportItems))
                <div class="dropdown">
                    <a href="{{ $exportUrl }}" class="btn btn-sm dashboard-export-button" title="Excel" aria-label="Excel">
                        <i class="bi bi-download"></i>
                    </a>
                    <button type="button" class="btn btn-sm dashboard-export-button dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Excel seçimləri"></button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        @foreach ($exportItems as $item)
                            <li><a class="dropdown-item" href="{{ $item['url'] }}">{{ $item['label'] }}</a></li>
                        @endforeach
                    </ul>
                </div>
            @else
                <a href="{{ $exportUrl }}" class="btn btn-sm dashboard-export-button" title="Excel" aria-label="Excel">
                    <i class="bi bi-download"></i>
                </a>
            @endif
        @endif
        <button type="button" class="btn btn-sm dashboard-visibility-toggle" title="Bloku gizlət" aria-label="Bloku gizlət">
            <i class="bi bi-eye-slash"></i>
        </button>
        <button type="button" class="btn btn-sm dashboard-personal-hide-toggle" title="Hide" aria-label="Hide">
            <i class="bi bi-eye-slash"></i>
        </button>
        <button type="button" class="btn btn-sm dashboard-drag-handle" title="Bloku daşı" aria-label="Bloku daşı">
            <i class="bi bi-grip-vertical"></i>
        </button>
    </div>
</div>
