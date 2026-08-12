<?php

return [
  'dashboards' =>
  [
    'overview' =>
    [
      'title' => 'Ümumi baxış',
      'route' => '/dashboard?tab=overview',
      'purpose' => 'Production Dashboard overview shows fleet, ownership share, project comparison, averages and geofence summaries in one filter context.',
      'source' => 'HTTP request Wialon API çağırmır; bütün göstəricilər lokal MySQL cədvəllərindən oxunur.',
      'refresh' => '00:00 Asia/Baku dashboard-reports:sync-daily pipeline efficiency, geofence_violations v? geofence_outside m?rh?l?l?rini database queue-ya qoyur. Efficiency m?rh?l?si shared Engine-hours c?dv?ll?rini d? yenil?yir.',
      'worker' => 'Scheduler yalnız pipeline yaradır; Wialon API-ni historical-recalculations database worker icra edir.',
      'reads' =>
      [
        0 => 'equipments, projects, equipment_types, project_wialon_groups',
        1 => 'equipment_daily_stats və daily_unit_aggregates',
        2 => 'engine_hours_report_unit_days və wialon_report_sync_items',
        3 => 'unit_foreign_geofence_intervals və geofence_violation_report_rows',
      ],
      'calculation' =>
      [
        0 => 'Bütün widget-lər eyni date_from/date_to, project_id, equipment_type_id və ownership_type filterlərini paylaşır.',
        1 => 'Aktiv, Dashboard-da görünən və project Wialon group-a bağlı texnikalar əsas götürülür.',
      ],
      'widgets' =>
      [
        0 => 'NWC və İCARƏ payı',
        1 => 'Texnika növü üzrə pay',
        2 => 'Layihə üzrə NWC/İCARƏ müqayisəsi',
        3 => 'Orta motosaat və orta yürüş',
        4 => 'Geofence Transferləri və Geofence Pozuntuları',
      ],
      'controls' =>
      [
        0 => 'Drill-down modal, sort, pagination və Excel export eyni backend filterlərini istifadə edir.',
        1 => 'Dashboard layout dəyişiklikləri yalnız admin üçündür.',
      ],
    ],
    'efficiency' =>
    [
      'title' => 'Effektivlik - ümumi gün',
      'route' => '/dashboard?tab=efficiency',
      'purpose' => 'Qrup date report Engine hours (api) əsasında hər layihə/ownership üçün tam günlük effektivlik statuslarını göstərir.',
      'source' => 'Wialon unit group report: Qrup date report Engine hours (api). Lokal oxuma: efficiency_daily_facts.',
      'refresh' => '00:00 daily pipeline-də efficiency stage yaradılır. Manual yeniləmə Tarixi məlumatların yenilənməsi və ya fleet:queue-efficiency-sync ilə edilə bilər.',
      'worker' => 'historical-recalculations database worker hər project task-ı icra edir; force seçiləndə həmin project/date üzrə köhnə faktlar silinib yenidən yazılır.',
      'reads' =>
      [
        0 => 'project_wialon_groups: active NWC və ICARE qrupları',
        1 => 'equipments: active, visibleInDashboard və efficiency vehicle types',
        2 => 'efficiency_daily_facts, efficiency_sync_runs, efficiency_sync_tasks',
        3 => 'efficiency_unmatched_report_rows audit qeydləri',
      ],
      'calculation' =>
      [
        0 => 'Wialon row wialon_unit_id ilə equipment-ə bağlanır; uyğun gəlməyən row auditə gedir.',
        1 => 'Qrupda olan, amma reportda gəlməyən uyğun texnika no_data kimi 0 saniyə ilə saxlanır.',
        2 => 'Status raw engine_seconds ilə verilir: 0=no_data, <1 saat=0_1, <7 saat=1_7, <=10 saat=7_10, >10 saat=over_10.',
      ],
      'widgets' =>
      [
        0 => 'Effektivlik: NWC üzrə 24 saat',
        1 => 'Effektivlik: İcarə üzrə 24 saat',
        2 => 'Layihələr drill-down',
        3 => 'Texnika siyahısı drill-down',
      ],
      'controls' =>
      [
        0 => 'Mövcud uğurlu günləri yenidən hesabla seçiləndə uyğun köhnə faktlar silinib yenidən yaradılır.',
        1 => 'Excel export Summary və Detallar vərəqlərini lokal faktlardan yaradır.',
      ],
    ],
    'geozones' =>
    [
      'title' => 'Geozonalar',
      'route' => '/dashboard?tab=geozones',
      'purpose' => 'Layihə geozonalarından kənara çıxan və başqa layihə geozonalarında görünən texnika hərəkətlərini göstərir.',
      'source' => 'Wialon geozon api nəticələri lokal unit_foreign_geofence_intervals cədvəlinə yazılır.',
      'refresh' => '00:00 daily pipeline-də geofence_outside stage yaradılır. Manual yeniləmə Tarixi məlumatların yenilənməsi -> Geofence Transferləri.',
      'worker' => 'historical-recalculations database worker geozon api task-larını icra edir.',
      'reads' =>
      [
        0 => 'unit_foreign_geofence_intervals',
        1 => 'geofences və projects',
        2 => 'equipments və project_wialon_groups',
      ],
      'calculation' =>
      [
        0 => 'home_project_id texnikanın ev layihəsidir; foreign_project_id isə cari/ziyarət edilən layihə geozonasıdır.',
        1 => 'Layihə geofence ID-ləri geofences.wialon_geofence_id ilə bağlanır.',
        2 => 'Dashboard cari period və filterlər üzrə foreign geozone qruplaşdırmasını göstərir.',
      ],
      'widgets' =>
      [
        0 => 'Geofence Transferləri',
        1 => 'Geofence Pozuntuları widget-i həmin tabda ayrıca report-backed blok kimi görünür.',
      ],
      'controls' =>
      [
        0 => 'Donut və legend klikləri current_geozone_key ilə drill-down modal açır.',
        1 => 'Geofence Pozuntuları ayrıca /geofence-violations səhifəsinə də keçə bilir.',
      ],
    ],
    'geofence_violations' =>
    [
      'title' => 'Geofence Pozuntuları',
      'route' => '/geofence-violations və /dashboard?tab=geozones widget-i',
      'purpose' => 'Bütün layihə geozonalarından kənarda fasiləsiz 3 saatdan çox qalan icazəli texnikaları göstərir.',
      'source' => 'Wialon report: Geofence Pozuntuları api. Lokal oxuma: geofence_violation_report_rows.',
      'refresh' => '00:00 daily pipeline-də geofence_violations stage yaradılır; manual sync Tarixi məlumatların yenilənməsi və ya fleet:sync-geofence-violations-report.',
      'worker' => 'historical-recalculations database worker Wialon reportu chunk-larla icra edir.',
      'reads' =>
      [
        0 => 'geofence_violation_report_rows',
        1 => 'projects, equipments və allowed equipment types',
        2 => 'report_period_from/report_period_to audit sahələri',
      ],
      'calculation' =>
      [
        0 => 'Yalnız allowed equipment types qəbul edilir: Dump Truck, Bulldozer, Excavator, Loader, Backhoe Loader, Road Grader, Road Roller.',
        1 => 'outside_duration_seconds > 10800 olmalıdır; report period və timestamp-lər null ola bilməz.',
        2 => 'last_confirmed_at >= exited_at olmalıdır; active/completed status is_active sahəsindən gəlir.',
      ],
      'widgets' =>
      [
        0 => 'Geofence Pozuntuları donut',
        1 => 'Layihə distribution legend',
        2 => 'Ayrıca pozuntu siyahısı səhifəsi',
      ],
      'controls' =>
      [
        0 => 'Filterlər: period, project_id, equipment_type, ownership_type, status, search.',
        1 => 'Drill-down eyni filteredQuery məntiqindən istifadə edir.',
      ],
    ],
  ],
  'update_sections' =>
  [
    'static_fleet' =>
    [
      'title' => 'Struktur və texnika siyahısı',
      'dashboard_section' => NULL,
      'manual' => 'Parametrlər -> Texnikaları sinxronlaşdır',
      'auto' => 'Dashboard report pipeline bu struktur siyahısını dəyişmir; lazım olduqda ayrıca sync istifadə olunur.',
      'wialon_command' => 'fleet:sync-units / fleet:sync-geofences',
      'local_tables' => 'equipments, equipment_types, projects, project_wialon_groups, geofences',
    ],
    'daily_averages' =>
    [
      'title' => 'Orta motosaat / Orta yürüş',
      'dashboard_section' => 'daily_averages',
      'manual' => 'Tarixi məlumatların yenilənməsi -> Orta motosaat göstəricisi / Orta yürüş göstəricisi',
      'auto' => 'Ayrıca daily_averages stage schedule-da yoxdur; 00:00 efficiency stage Qrup date report Engine hours (api) nəticəsini equipment_daily_stats və daily_unit_aggregates cədvəllərinə yazır.',
      'wialon_command' => 'dashboard-reports:sync-daily -> shared Qrup Engine hours stage',
      'local_tables' => 'equipment_daily_stats, daily_unit_aggregates',
    ],
    'geofence_outside' =>
    [
      'title' => 'Geofence Transferləri',
      'dashboard_section' => 'geofence_outside',
      'manual' => 'Tarixi məlumatların yenilənməsi -> Geofence Transferləri',
      'auto' => 'Gündəlik 00:00 pipeline paketində dünənki geozon api intervalı yenilənir.',
      'wialon_command' => 'fleet:sync-geozon-api',
      'local_tables' => 'unit_foreign_geofence_intervals',
    ],
    'geofence_violations' =>
    [
      'title' => 'Geofence Pozuntuları',
      'dashboard_section' => 'geofence_violations',
      'manual' => 'Tarixi məlumatların yenilənməsi -> Geofence Pozuntuları',
      'auto' => 'Gündəlik 00:00 pipeline paketində dünənki “Geofence Pozuntuları api” hesabatı yenilənir.',
      'wialon_command' => 'fleet:sync-geofence-violations-report',
      'local_tables' => 'geofence_violation_report_rows',
    ],
    'efficiency' =>
    [
      'title' => 'Effektivlik',
      'dashboard_section' => 'efficiency',
      'manual' => 'Tarixi məlumatların yenilənməsi -> Effektivlik.',
      'auto' => 'Gündəlik 00:00 pipeline paketində Engine hours effektivlik sinxronizasiyası növbəyə verilir.',
      'wialon_command' => 'fleet:queue-efficiency-sync',
      'local_tables' => 'efficiency_daily_facts, efficiency_sync_runs, efficiency_sync_tasks',
    ],
  ],
  'shared_bindings' =>
  [
    0 =>
    [
      'title' => 'Project binding',
      'description' => 'Layihə və ownership Wialon qrupuna project_wialon_groups cədvəli ilə bağlanır.',
      'items' =>
      [
        0 => 'projects.id -> project_wialon_groups.project_id',
        1 => 'project_wialon_groups.ownership_type -> NWC / ICARE',
        2 => 'project_wialon_groups.wialon_group_id -> Wialon qrup ID',
      ],
    ],
    1 =>
    [
      'title' => 'Geofence binding',
      'description' => 'Layihə geozonaları Wialon geofence ID ilə bağlanır. Bir layihədə bir neçə geofence ola bilər.',
      'items' =>
      [
        0 => 'geofences.project_id -> projects.id',
        1 => 'geofences.wialon_geofence_id -> Wialon geofence ID',
        2 => 'Wialon geofence group istifadə olunmur',
      ],
    ],
    2 =>
    [
      'title' => 'Pipeline və queue binding',
      'description' => 'Auto və manual yeniləmələr Wialon API-ni HTTP request və ya scheduler içində icra etmir; task-lar database queue-ya yazılır.',
      'items' =>
      [
        0 => 'dashboard_report_pipelines -> Setting JSON checkpoint',
        1 => 'historical_recalculations -> run status/progress',
        2 => 'historical_recalculation_tasks -> project/date task-ları',
        3 => 'jobs queue=historical-recalculations -> worker icrası',
      ],
    ],
    3 =>
    [
      'title' => 'Dashboard filters',
      'description' => 'Dashboard, modal və Excel eyni filter kontekstini istifadə etməlidir.',
      'items' =>
      [
        0 => 'date_from / date_to',
        1 => 'project_id',
        2 => 'ownership_type',
        3 => 'equipment_type_id',
        4 => 'status / current_geozone_key',
      ],
    ],
  ],
  'widgets' =>
  [
    0 =>
    [
      'key' => 'ownership-share',
      'title' => 'NWC və İCARƏ payı',
      'purpose' => 'Layihələrə bağlı texnikaların ownership üzrə say bölgüsünü göstərir.',
      'dashboard_block' => 'Donut chart + sağ legend',
      'wialon_report' => 'Birbaşa Wialon report istifadə etmir',
      'local_source' => 'equipments, project_wialon_groups',
      'service' => 'FleetOwnershipStatsService, DashboardService::getOverview',
      'binding' => 'Yalnız layihələrə bağlı NWC / ICARE qrupları sayılır; +NWC+ və +İcarə+ root qrupları sayılmır.',
      'report_rows' =>
      [
        0 => 'equipments.project_wialon_group_id',
        1 => 'equipments.matched_wialon_group_id',
        2 => 'equipments.ownership_type',
        3 => 'equipments.active / excluded_from_dashboard',
      ],
      'click' => 'Sektor kliklənəndə Dashboard drilldown modal ownership filteri ilə açılır.',
      'excel' => 'Dashboard export eyni ownership seçimini istifadə edir.',
    ],
    1 =>
    [
      'key' => 'equipment-types-nwc',
      'title' => 'Texnika növü üzrə: NWC payı',
      'purpose' => 'NWC texnikalarının növ üzrə sayını göstərir.',
      'dashboard_block' => 'Donut chart + növ cədvəli',
      'wialon_report' => 'Birbaşa Wialon report istifadə etmir',
      'local_source' => 'equipments, equipment_types',
      'service' => 'DashboardService::getEquipmentTypeDistributionByOwnership',
      'binding' => 'ownership_type = NWC, project_id filteri varsa yalnız seçilmiş layihə.',
      'report_rows' =>
      [
        0 => 'equipment_types.name',
        1 => 'COUNT(DISTINCT equipments.id)',
        2 => 'equipments.project_id',
        3 => 'equipments.ownership_type',
      ],
      'click' => 'Növ sətri/sektoru drilldown modalı equipment_type_id + ownership_type=NWC ilə açır.',
      'excel' => 'equipment-types-nwc block export eyni növ və ownership məntiqini istifadə edir.',
    ],
    2 =>
    [
      'key' => 'equipment-types-icare',
      'title' => 'Texnika növü üzrə: İcarə payı',
      'purpose' => 'İcarə texnikalarının növ üzrə sayını göstərir.',
      'dashboard_block' => 'Donut chart + növ cədvəli',
      'wialon_report' => 'Birbaşa Wialon report istifadə etmir',
      'local_source' => 'equipments, equipment_types',
      'service' => 'DashboardService::getEquipmentTypeDistributionByOwnership',
      'binding' => 'ownership_type = ICARE, project_id filteri varsa yalnız seçilmiş layihə.',
      'report_rows' =>
      [
        0 => 'equipment_types.name',
        1 => 'COUNT(DISTINCT equipments.id)',
        2 => 'equipments.project_id',
        3 => 'equipments.ownership_type',
      ],
      'click' => 'Növ sətri/sektoru drilldown modalı equipment_type_id + ownership_type=ICARE ilə açır.',
      'excel' => 'equipment-types-icare block export eyni növ və ownership məntiqini istifadə edir.',
    ],
    3 =>
    [
      'key' => 'project-comparison',
      'title' => 'Layihə üzrə: NWC və İcarə payı',
      'purpose' => 'Hər layihədə NWC və İcarə texnika sayını müqayisə edir.',
      'dashboard_block' => 'Horizontal bar chart + layihə cədvəli',
      'wialon_report' => 'Birbaşa Wialon report istifadə etmir',
      'local_source' => 'equipments, projects',
      'service' => 'DashboardService::getProjectOwnershipComparison',
      'binding' => 'projects.id -> equipments.project_id; ownership_type üzrə iki say sütunu.',
      'report_rows' =>
      [
        0 => 'projects.name',
        1 => 'COUNT(DISTINCT equipments.id) where ownership_type=NWC',
        2 => 'COUNT(DISTINCT equipments.id) where ownership_type=ICARE',
      ],
      'click' => 'Layihə seçimi cari Dashboard filtrlərini saxlayaraq modalda texnika növlərini göstərir; növ seçimi eyni layihə + ownership filtrləri ilə texnika siyahısını açır.',
      'excel' => 'Dashboard export layihə + ownership saylarını verir.',
    ],
    4 =>
    [
      'key' => 'project-work-categories-nwc',
      'title' => 'Effektivlik: NWC üzrə 24 saat',
      'purpose' => 'NWC texnika-günlərini Engine hours üzrə beş statusda göstərir.',
      'dashboard_block' => 'Donut beş qarşılıqlı istisna statusun texnika-gün sayını göstərir.',
      'wialon_report' => 'Qrup date report Engine hours (api)',
      'local_source' => 'efficiency_daily_facts',
      'service' => 'EfficiencyDashboardService',
      'binding' => 'project_wialon_groups.wialon_group_id üzrə Wialon qrup; ownership_type=NWC.',
      'report_rows' =>
      [
        0 => 'Engine hours -> engine_hours_decimal və engine_seconds',
        1 => 'Hər obyekt + tarix yalnız bir əsas status alır',
        2 => 'Başlama, bitmə və yürüş audit üçün saxlanılır',
      ],
      'click' => 'Status kliklənəndə əvvəl layihələr NWC/İCARƏ/Say üzrə, sonra layihənin texnika jurnalı açılır.',
      'excel' => 'Excel Xülasə və Detallar vərəqlərini eyni lokal faktlardan çıxarır.',
    ],
    5 =>
    [
      'key' => 'project-work-categories-icare',
      'title' => 'Effektivlik: İcarə üzrə 24 saat',
      'purpose' => 'İcarə texnika-günlərini Engine hours üzrə beş statusda göstərir.',
      'dashboard_block' => 'Donut beş qarşılıqlı istisna statusun texnika-gün sayını göstərir.',
      'wialon_report' => 'Qrup date report Engine hours (api)',
      'local_source' => 'efficiency_daily_facts',
      'service' => 'EfficiencyDashboardService',
      'binding' => 'project_wialon_groups.wialon_group_id üzrə Wialon qrup; ownership_type=ICARE.',
      'report_rows' =>
      [
        0 => 'Engine hours -> engine_hours_decimal və engine_seconds',
        1 => 'Hər obyekt + tarix yalnız bir əsas status alır',
        2 => 'Başlama, bitmə və yürüş audit üçün saxlanılır',
      ],
      'click' => 'Status kliklənəndə əvvəl layihələr NWC/İCARƏ/Say üzrə, sonra layihənin texnika jurnalı açılır.',
      'excel' => 'Excel Xülasə və Detallar vərəqlərini eyni lokal faktlardan çıxarır.',
    ],
    6 =>
    [
      'key' => 'average-engine-hours',
      'title' => 'Orta motosaat göstəricisi',
      'purpose' => 'Texnika növü və ownership üzrə gündəlik orta motosaatı göstərir.',
      'dashboard_block' => 'Horizontal average bars',
      'wialon_report' => 'Qrup date report Engine hours (api)',
      'local_source' => 'equipment_daily_stats',
      'service' => 'DashboardDailyAverageService',
      'binding' => 'Layihə + ownership Wialon qrupundan Engine hours sütunu yerli statistikaya yazılır.',
      'report_rows' =>
      [
        0 => 'Engine hours -> worked_hours / engine_hours',
        1 => 'Texnika adı -> equipment_id match',
        2 => 'statistic_date',
        3 => 'Formula: SUM(valid engine_hours) / COUNT(valid unit-day rows)',
        4 => 'Explicit data_available=false and invalid calculation statuses are excluded',
      ],
      'click' => 'Bar kliklənəndə metric=engine_hours, type, ownership və period ilə modal açılır.',
      'excel' => 'Xülasə və Gündəlik detallar eyni DashboardDailyAverageService seçimini istifadə edir.',
    ],
    7 =>
    [
      'key' => 'average-mileage',
      'title' => 'Orta yürüş göstəricisi',
      'purpose' => 'Dump Truck üçün ownership üzrə gündəlik orta yürüşü göstərir.',
      'dashboard_block' => 'Horizontal average bars',
      'wialon_report' => 'Qrup date report Engine hours (api)',
      'local_source' => 'equipment_daily_stats',
      'service' => 'DashboardDailyAverageService',
      'binding' => 'Yalnız Dump Truck; mənfi mileage çıxarılır; NULL adi 0 kimi maskalanmır.',
      'report_rows' =>
      [
        0 => 'Mileage / distance -> distance_km',
        1 => 'Texnika adı -> equipment_id match',
        2 => 'statistic_date',
        3 => 'Formula: SUM(valid distance_km) / COUNT(valid unit-day rows)',
        4 => 'Explicit data_available=false, negative mileage and invalid calculation statuses are excluded',
      ],
      'click' => 'Bar kliklənəndə metric=mileage, type=Dump Truck, ownership və period ilə modal açılır.',
      'excel' => 'Xülasə və Gündəlik detallar eyni DashboardDailyAverageService seçimini istifadə edir.',
    ],
    8 =>
    [
      'key' => 'geofence-analysis',
      'title' => 'Geofence Transferləri',
      'purpose' => 'Ev geozonasından çıxıb başqa layihə geozonasında olan texnikaları göstərir.',
      'dashboard_block' => 'Donut chart cari foreign layihə/geozona üzrə + sağ cədvəl',
      'wialon_report' => 'geozon api',
      'local_source' => 'unit_foreign_geofence_intervals',
      'service' => 'GeofenceViolationService, GeofenceReportViolationCalculator',
      'binding' => 'home_project_id və foreign_geofence_id intervaldan oxunur; layihə geofence ID-ləri geofences.wialon_geofence_id ilə bağlanır.',
      'report_rows' =>
      [
        0 => 'Source group -> home_project_id / ownership_type',
        1 => 'Visited geofence -> foreign_geofence_id / foreign_project_id',
        2 => 'entered_at, left_at, last_position_at',
        3 => 'duration_seconds >= 3 saat',
      ],
      'click' => 'Sektor və ya cədvəl sətri current_geozone_key ilə modal açır; yalnız həmin foreign geozonanın texnikaları göstərilir.',
      'excel' => 'Excel GeofenceViolationService::exportRows ilə Dashboard/modal seçimini təkrarlayır.',
    ],
    9 =>
    [
      'key' => 'geofence-violations-report',
      'title' => 'Geofence Pozuntuları',
      'purpose' => 'Bütün layihə geozonalarından kənarda fasiləsiz 3 saatdan çox qalan icazəli texnikaları göstərir.',
      'dashboard_block' => 'Ayrı donut chart + layihə üzrə sağ legend',
      'wialon_report' => 'Geofence Pozuntuları api',
      'local_source' => 'geofence_violation_report_rows',
      'service' => 'GeofenceViolationsDashboardService, GeofenceViolationReportImporter',
      'binding' => 'Hesabat sətri equipment wialon_unit_id ilə bağlanır; yalnız ayrıca fasiləsiz interval müddəti 3 saatdan çox olduqda qəbul edilir.',
      'report_rows' =>
      [
        0 => 'Out of geofences -> nested unit rows',
        1 => 'Unit name / Wialon unit ID',
        2 => 'Equipment type -> yalnız icazəli 7 növ',
        3 => 'Entry time -> exited_at',
        4 => 'Exit time / last confirmed position -> last_confirmed_at',
        5 => 'Duration must equal the continuous entry-exit span',
      ],
      'click' => 'Donut və legend sətri seçilmiş period və layihə filtrləri ilə pozuntu siyahısını modalda açır.',
      'excel' => 'Ayrıca /geofence-violations/export endpoint-i eyni filterlərlə Xülasə, Layihələr və Pozuntular vərəqlərini yaradır.',
    ],
    10 =>
    [
      'key' => 'utilization-trend',
      'title' => 'İstifadə əmsalı',
      'purpose' => 'Seçilmiş period üzrə gündəlik utilization trendini göstərir.',
      'dashboard_block' => 'Trend chart',
      'wialon_report' => 'Birbaşa Wialon report istifadə etmir',
      'local_source' => 'daily_fleet_statistics / equipment_daily_stats',
      'service' => 'DashboardService::getUtilizationTrend, DashboardService::getUtilizationTrendByOwnership',
      'binding' => 'Period, project_id və ownership filterləri ilə lokal gündəlik statistikalar oxunur.',
      'report_rows' =>
      [
        0 => 'statistic_date',
        1 => 'worked_hours',
        2 => 'planned_hours',
        3 => 'utilization_percent',
      ],
      'click' => 'Trend nöqtəsi seçilmiş gün üçün drilldown kontekstini saxlayır.',
      'excel' => 'Dashboard export utilization-trend summary rows ilə çıxarılır.',
    ],
  ],
];
