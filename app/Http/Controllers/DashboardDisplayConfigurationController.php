<?php

namespace App\Http\Controllers;

use App\Services\DashboardDisplayConfigurationService;

class DashboardDisplayConfigurationController extends Controller
{
    public function __invoke(DashboardDisplayConfigurationService $displayConfiguration): array
    {
        return $displayConfiguration->getPublicConfiguration();
    }
}
