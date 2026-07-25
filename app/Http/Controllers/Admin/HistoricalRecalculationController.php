<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreHistoricalRecalculationRequest;
use App\Models\HistoricalRecalculation;
use App\Models\HistoricalRecalculationTask;
use App\Models\Project;
use App\Services\HistoricalRecalculationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HistoricalRecalculationController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('manage-historical-recalculations');

        return view('admin.historical-recalculations.index', [
            'projects' => Project::query()->where('active', true)->orderBy('name')->get(['id', 'name']),
            'runs' => HistoricalRecalculation::query()
                ->with('requestedBy:id,name')
                ->latest()
                ->limit(30)
                ->get(),
            'defaultTimezone' => config('historical_recalculation.timezone', 'Asia/Baku'),
            'today' => now(config('historical_recalculation.timezone', 'Asia/Baku'))->toDateString(),
        ]);
    }

    public function preview(StoreHistoricalRecalculationRequest $request, HistoricalRecalculationService $service): JsonResponse
    {
        return response()->json($service->preview($request->validated()));
    }

    public function store(StoreHistoricalRecalculationRequest $request, HistoricalRecalculationService $service): RedirectResponse
    {
        $run = $service->createRun($request->validated(), $request->user());

        return redirect()
            ->route('admin.historical-recalculations.show', $run)
            ->with('status', 'Tarixi məlumatların yenilənməsi növbəyə əlavə edildi.');
    }

    public function show(HistoricalRecalculation $historicalRecalculation): View
    {
        $this->authorize('manage-historical-recalculations');

        return view('admin.historical-recalculations.show', [
            'run' => $historicalRecalculation->load('requestedBy:id,name'),
            'tasks' => $historicalRecalculation->tasks()
                ->with('project:id,name')
                ->orderBy('stat_date')
                ->orderBy('project_id')
                ->orderBy('ownership_type')
                ->paginate(80),
        ]);
    }

    public function status(HistoricalRecalculation $historicalRecalculation): JsonResponse
    {
        $this->authorize('manage-historical-recalculations');

        $run = $historicalRecalculation->refresh();

        return response()->json([
            'status' => $run->status,
            'total_tasks' => $run->total_tasks,
            'completed_tasks' => $run->completed_tasks,
            'failed_tasks' => $run->failed_tasks,
            'cancelled_tasks' => $run->cancelled_tasks,
            'processed_objects' => $run->processed_objects,
            'progress_percent' => $run->total_tasks > 0
                ? round((($run->completed_tasks + $run->failed_tasks + $run->cancelled_tasks) / $run->total_tasks) * 100, 1)
                : 0,
            'last_heartbeat_at' => optional($run->last_heartbeat_at)->toDateTimeString(),
            'completed_at' => optional($run->completed_at)->toDateTimeString(),
        ]);
    }

    public function cancel(HistoricalRecalculation $historicalRecalculation, HistoricalRecalculationService $service): RedirectResponse
    {
        $this->authorize('manage-historical-recalculations');

        $service->cancel($historicalRecalculation);

        return back()->with('status', 'Gözləyən tapşırıqlar ləğv edildi.');
    }

    public function retry(HistoricalRecalculation $historicalRecalculation, HistoricalRecalculationService $service): RedirectResponse
    {
        $this->authorize('manage-historical-recalculations');

        $service->retryFailed($historicalRecalculation);

        return back()->with('status', 'Uğursuz tapşırıqlar yenidən növbəyə əlavə edildi.');
    }
}
