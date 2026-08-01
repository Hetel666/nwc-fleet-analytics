<div class="dashboard-design-backdrop" id="dashboardDesignBackdrop" hidden></div>
<aside class="dashboard-design-drawer" id="dashboardDesignDrawer" role="dialog" aria-modal="true" aria-labelledby="dashboardDesignTitle" hidden>
    <div class="dashboard-design-header">
        <div>
            <h2 class="h5 mb-1" id="dashboardDesignTitle">Dizayn ayarları</h2>
            <div class="small text-secondary">Şəxsi Dashboard görünüşü</div>
        </div>
        <button type="button" class="dashboard-design-icon-button" id="dashboardDesignClose" aria-label="Bağla" title="Bağla">
            <i data-lucide="x"></i>
        </button>
    </div>

    <form class="dashboard-design-form" id="dashboardDesignForm">
        <fieldset class="dashboard-design-section">
            <legend>Dizayn variantı</legend>
            <div class="dashboard-layout-options">
                @foreach ([
                    'standard' => ['Standart', 'Cari Dashboard quruluşu'],
                    'compact' => ['Kompakt', 'Daha çox məlumat, az boşluq'],
                    'card_grid' => ['Kart Grid', 'İki sütunlu analitik baxış'],
                    'side_filters' => ['Yan panel filtri', 'Filtrlər həmişə əlçatan'],
                    'dark_analytics' => ['Qaranlıq analitika', 'Yüksək kontrastlı analitika'],
                ] as $code => [$label, $description])
                    <label class="dashboard-layout-option" data-layout-option="{{ $code }}">
                        <input type="radio" name="layout" value="{{ $code }}">
                        <span class="dashboard-layout-preview dashboard-layout-preview--{{ $code }}" aria-hidden="true">
                            <span class="preview-sidebar"></span>
                            <span class="preview-filter"></span>
                            <span class="preview-kpis"><i></i><i></i><i></i></span>
                            <span class="preview-donut"></span>
                            <span class="preview-table"><i></i><i></i><i></i></span>
                        </span>
                        <span class="dashboard-layout-copy">
                            <strong>{{ $label }}</strong>
                            <small>{{ $description }}</small>
                        </span>
                        <span class="dashboard-layout-selected">Seçilib</span>
                    </label>
                @endforeach
            </div>
        </fieldset>

        <fieldset class="dashboard-design-section">
            <legend>Görünüş üstünlükləri</legend>
            <div class="dashboard-preference-field">
                <span>Tema</span>
                <div class="dashboard-segmented-control">
                    <label><input type="radio" name="theme" value="system"><span>Sistem</span></label>
                    <label><input type="radio" name="theme" value="light"><span>Açıq</span></label>
                    <label><input type="radio" name="theme" value="dark"><span>Qaranlıq</span></label>
                </div>
            </div>
            <div class="dashboard-preference-field">
                <span>Sıxlıq</span>
                <select name="density" class="form-select form-select-sm">
                    <option value="comfortable">Rahat</option><option value="compact">Kompakt</option><option value="dense">Sıx</option>
                </select>
            </div>
            <div class="dashboard-preference-field">
                <span>Cədvəl görünüşü</span>
                <select name="table_density" class="form-select form-select-sm">
                    <option value="comfortable">Rahat</option><option value="compact">Kompakt</option><option value="dense">Sıx</option>
                </select>
            </div>
            <div class="dashboard-preference-field">
                <span>Donut əfsanəsinin yerləşməsi</span>
                <select name="donut_legend_position" class="form-select form-select-sm">
                    <option value="right">Sağda</option><option value="bottom">Aşağıda</option><option value="hidden">Gizli</option>
                </select>
            </div>
            <div class="dashboard-preference-field">
                <span>Yan menyu</span>
                <div class="dashboard-segmented-control">
                    <label><input type="radio" name="sidebar_state" value="expanded"><span>Açıq</span></label>
                    <label><input type="radio" name="sidebar_state" value="collapsed"><span>Yığcam</span></label>
                </div>
            </div>
            <div class="dashboard-preference-field">
                <span>KPI ölçüsü</span>
                <select name="kpi_size" class="form-select form-select-sm">
                    <option value="small">Kiçik</option><option value="medium">Orta</option><option value="large">Böyük</option>
                </select>
            </div>
        </fieldset>
    </form>

    <div class="dashboard-design-status small" id="dashboardDesignStatus" aria-live="polite"></div>
    <div class="dashboard-design-footer">
        <button type="button" class="btn btn-outline-secondary btn-icon" id="dashboardDesignReset">
            <i data-lucide="rotate-ccw"></i><span>Standarta qaytar</span>
        </button>
        <div class="d-flex gap-2 ms-auto">
            <button type="button" class="btn btn-outline-secondary" id="dashboardDesignCancel">Ləğv et</button>
            <button type="button" class="btn btn-primary btn-icon" id="dashboardDesignApply"><i data-lucide="check"></i><span>Tətbiq et</span></button>
        </div>
    </div>
</aside>
