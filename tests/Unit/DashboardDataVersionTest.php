<?php

namespace Tests\Unit;

use App\Services\DashboardDataVersion;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DashboardDataVersionTest extends TestCase
{
    public function test_current_initializes_default_version_and_bump_increments_it(): void
    {
        Cache::forget(DashboardDataVersion::KEY);

        $version = app(DashboardDataVersion::class);

        $this->assertSame(1, $version->current());
        $this->assertSame(2, $version->bump());
        $this->assertSame(3, $version->bump());
        $this->assertSame(3, $version->current());
    }
}
