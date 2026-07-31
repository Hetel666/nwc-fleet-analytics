<?php

namespace Tests\Unit;

use App\Services\WialonService;
use ReflectionMethod;
use Tests\TestCase;

class WialonReportExecutionPayloadTest extends TestCase
{
    public function test_report_execution_payload_uses_configured_timezone_and_language(): void
    {
        config([
            'fleet.wialon.report_timezone_offset' => 134232128,
            'fleet.wialon.report_language' => 'ru',
        ]);

        $method = new ReflectionMethod(WialonService::class, 'reportExecutionPayload');
        $payload = $method->invoke(new WialonService(), [
            'reportResourceId' => 601701680,
            'reportTemplateId' => 18,
        ]);

        $this->assertSame(134232128, $payload['tzOffset']);
        $this->assertSame('ru', $payload['lang']);
    }
}
