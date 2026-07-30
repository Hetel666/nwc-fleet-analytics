<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class DashboardTranslationTest extends TestCase
{
    public function test_dashboard_labels_exist_in_every_supported_locale(): void
    {
        $keys = [
            'period_latest_completed',
            'dashboard_tab_overview',
            'dashboard_tab_efficiency',
            'dashboard_tab_geozones',
            'dashboard_sections',
            'data_updated_through',
            'loading_tab',
            'modal_tab_data',
            'modal_tab_summary',
            'modal_tab_filters',
            'search_equipment',
            'pagination',
            'previous_page',
            'next_page',
            'tab_load_failed',
            'tab_load_retry',
            'worked_night_shift_only',
        ];

        foreach (['az', 'ru', 'en'] as $locale) {
            $translations = require dirname(__DIR__, 2)."/lang/{$locale}/app.php";

            foreach ($keys as $key) {
                $this->assertArrayHasKey($key, $translations, "{$locale}.app.{$key} is missing");
                $this->assertNotSame('', trim((string) $translations[$key]));
            }
        }
    }
}
