<?php

use App\Models\DashboardDataImport;

return [
    'disk' => env('DASHBOARD_IMPORT_DISK', 'local'),
    'directory' => env('DASHBOARD_IMPORT_DIRECTORY', 'dashboard-imports'),
    'max_files' => (int) env('DASHBOARD_IMPORT_MAX_FILES', 100),
    'max_file_kilobytes' => (int) env('DASHBOARD_IMPORT_MAX_FILE_KILOBYTES', 51200),
    'preview_limit' => (int) env('DASHBOARD_IMPORT_PREVIEW_LIMIT', 100),

    'modules' => [
        DashboardDataImport::MODULE_DAILY_EFFICIENCY => [
            'report_name' => 'Qrup date report Engine hours (api)',
            'description' => 'Ümumi 24 saat, orta motosaat, orta yürüş və istifadə əmsalı bloklarını yeniləyir.',
            'required_tables' => ['Engine hours'],
        ],
        DashboardDataImport::MODULE_MONTHLY_EFFICIENCY => [
            'report_name' => 'Report for Aylıq effektivlik',
            'description' => 'Aylıq effektivlik NWC və İCARƏ bloklarını yeniləyir.',
            'required_tables' => ['Engine hours', 'Geofence'],
        ],
    ],
];
