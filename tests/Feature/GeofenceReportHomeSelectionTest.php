<?php

namespace Tests\Feature;

use App\Services\WialonGeozonReportParser;
use App\Services\WialonProjectGeofenceSelector;
use App\Services\WialonService;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class GeofenceReportHomeSelectionTest extends TestCase
{
    public function test_stale_group_is_replaced_and_home_zone_is_added_without_mutating_template(): void
    {
        $original = [
            'tbl' => [[
                'n' => 'unit_group_zones_visit',
                'p' => json_encode([
                    'geozones' => 'gr601701680_10,gz601701680_52:1784006158,',
                    'duration' => ['min' => 10801],
                    'grouping' => 'unchanged',
                ]),
            ]],
        ];

        $result = app(WialonProjectGeofenceSelector::class)->apply(
            $original,
            'gr601701680_31',
            ['601701680:3', '601701680:3'],
            true
        );
        $parameters = json_decode($result['tbl'][0]['p'], true);

        $this->assertSame(
            'gz601701680_52:1784006158,gr601701680_31,601701680_3,',
            $parameters['geozones']
        );
        $this->assertArrayNotHasKey('duration', $parameters);
        $this->assertSame('unchanged', $parameters['grouping']);
        $this->assertArrayHasKey('duration', json_decode($original['tbl'][0]['p'], true));
    }

    public function test_live_project_group_is_resolved_by_name_instead_of_old_id(): void
    {
        $wialon = Mockery::mock(WialonService::class);
        $wialon->shouldReceive('getResource')
            ->once()
            ->with(601701680, 'isolated-session')
            ->andReturn([
                'zg' => [
                    10 => ['id' => 10, 'n' => 'Old group', 'zns' => [1]],
                    31 => ['id' => 31, 'n' => 'projects', 'zns' => [3, 4, 5]],
                ],
            ]);

        $token = app(WialonProjectGeofenceSelector::class)->resolveProjectGroupToken(
            $wialon,
            601701680,
            'isolated-session'
        );

        $this->assertSame('gr601701680_31', $token);
    }

    public function test_missing_live_project_group_fails_instead_of_accepting_corrupt_snapshot(): void
    {
        $wialon = Mockery::mock(WialonService::class);
        $wialon->shouldReceive('getResource')->once()->andReturn(['zg' => []]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('snapshot was not changed');

        app(WialonProjectGeofenceSelector::class)->resolveProjectGroupToken($wialon, 601701680);
    }

    public function test_numeric_time_does_not_inherit_the_display_timezone(): void
    {
        $time = app(WialonGeozonReportParser::class)->parseTimestamp([
            't' => '2026-09-05 20:00:02',
            'v' => 1788638402,
        ]);

        $this->assertSame(1788638402, $time->timestamp);
    }
}
