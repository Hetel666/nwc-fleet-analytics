<?php

namespace Tests\Unit;

use App\Models\HistoricalRecalculation;
use App\Services\DashboardModuleRegistry;
use Tests\TestCase;

class DashboardModuleRegistryTest extends TestCase
{
    public function test_dashboard_module_registry_has_valid_contracts(): void
    {
        $registry = app(DashboardModuleRegistry::class);

        $this->assertSame([], $registry->validationErrors());
        $this->assertContains('overview', $registry->codes());
        $this->assertContains('efficiency', $registry->codes());
        $this->assertContains('monthly_efficiency', $registry->codes());
        $this->assertContains('geofence_violations', $registry->codes());
    }

    public function test_registry_maps_historical_sections_to_modules(): void
    {
        $registry = app(DashboardModuleRegistry::class);

        $this->assertTrue(
            $registry->forHistoricalSection(HistoricalRecalculation::SECTION_DAYTIME_EFFICIENCY)
                ->has('daytime_efficiency')
        );
        $this->assertTrue(
            $registry->forHistoricalSection(HistoricalRecalculation::SECTION_GEOFENCE_VIOLATIONS)
                ->has('geofence_violations')
        );
        $this->assertTrue(
            $registry->forHistoricalSection(null)
                ->has('monthly_efficiency')
        );
    }

    public function test_monthly_object_efficiency_is_marked_partially_isolated(): void
    {
        $module = app(DashboardModuleRegistry::class)->get('monthly_efficiency');

        $this->assertFalse($module['writes_shared_tables']);
        $this->assertSame('partially_isolated', $module['safe_resync_scope']['status']);
        $this->assertContains('monthly_efficiency_unit_geofence_facts', $module['result_tables']);
    }
}
