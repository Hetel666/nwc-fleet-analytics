<?php

namespace Tests\Feature;

use App\Services\GeofenceViolationTelemetryValidator;
use App\Services\WialonService;
use Mockery;
use Tests\TestCase;

class GeofenceViolationTelemetryValidatorTest extends TestCase
{
    private function row(): array
    {
        return ['wialon_unit_id' => '601734366', 'exited_at' => '2026-09-06 00:51:32',
            'last_confirmed_at' => '2026-09-06 23:51:32', 'report_period_from' => '2026-09-06 00:00:00',
            'report_period_to' => '2026-09-06 23:59:59', 'source_payload' => []];
    }

    public function test_no_messages_is_not_a_confirmed_violation(): void
    {
        $w = Mockery::mock(WialonService::class);
        $w->shouldReceive('getDataMessageCount')->once()->andReturn(0);
        $this->assertSame([], app(GeofenceViolationTelemetryValidator::class)->filter([$this->row()], $w, 'isolated'));
    }

    public function test_positive_count_is_recorded_and_duplicate_lookup_is_cached(): void
    {
        $w = Mockery::mock(WialonService::class);
        $w->shouldReceive('getDataMessageCount')->once()->andReturn(24);
        $rows = app(GeofenceViolationTelemetryValidator::class)->filter([$this->row(), $this->row()], $w, 'isolated');
        $this->assertCount(2, $rows);
        $this->assertSame(24, $rows[0]['source_payload']['telemetry_validation']['message_count']);
    }

    public function test_api_failure_does_not_become_an_empty_successful_snapshot(): void
    {
        $w = Mockery::mock(WialonService::class);
        $w->shouldReceive('getDataMessageCount')->once()->andThrow(new \RuntimeException('timeout'));
        $this->expectException(\RuntimeException::class);
        app(GeofenceViolationTelemetryValidator::class)->filter([$this->row()], $w, 'isolated');
    }
}
