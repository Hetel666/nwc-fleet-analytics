<?php

namespace App\Http\Controllers;

use App\Http\Requests\DaytimeEfficiencyDashboardRequest;
use App\Services\DaytimeEfficiencyDashboardService;
use Illuminate\Http\JsonResponse;

final class DaytimeEfficiencyApiController extends Controller
{
    public function __invoke(
        DaytimeEfficiencyDashboardRequest $request,
        DaytimeEfficiencyDashboardService $dashboard
    ): JsonResponse {
        abort_unless(config('daytime_efficiency.enabled', true), 404);
        $filters = $request->validated();
        $data = $dashboard->data($filters);

        return response()->json([
            'period' => [
                'from' => $filters['date_from'],
                'to' => $filters['date_to'],
                'timezone' => config('daytime_efficiency.timezone', 'Asia/Baku'),
            ],
            'source' => config('daytime_efficiency.report_template_name'),
            'allowed_equipment_types' => config('daytime_efficiency.allowed_equipment_types', []),
            'nwc' => $data['summaries']['nwc'],
            'icare' => $data['summaries']['icare'],
            'facts' => $data['facts'],
            'last_updated_at' => $data['last_updated_at'],
        ]);
    }
}
