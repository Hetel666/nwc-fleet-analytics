<?php

namespace Tests\Unit;

use App\Services\WialonService;
use Illuminate\Support\Facades\Http;
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

    public function test_result_rows_retry_temporary_wialon_error_five(): void
    {
        config([
            'fleet.wialon.base_url' => 'https://wialon.test',
            'fleet.wialon.report_rows_attempts' => 3,
            'fleet.wialon.report_rows_delay_ms' => 1,
        ]);
        Http::fakeSequence()
            ->push(['error' => 5])
            ->push(['error' => 5])
            ->push([['uid' => 6001, 'c' => ['Unit 6001']]]);

        $rows = (new WialonService())->getReportResultRows(0, 0, 0, 'test-sid');

        $this->assertSame(6001, $rows[0]['uid']);
        Http::assertSentCount(3);
    }
}
