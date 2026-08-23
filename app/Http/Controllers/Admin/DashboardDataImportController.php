<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDashboardDataImportRequest;
use App\Models\DashboardDataImport;
use App\Models\DashboardDataImportRow;
use App\Models\Equipment;
use App\Models\Project;
use App\Services\DashboardManualImportService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class DashboardDataImportController extends Controller
{
    public function index(): View
    {
        return view('admin.dashboard-data-imports.index', [
            'modules' => DashboardDataImport::modules(),
            'moduleSettings' => config('dashboard_manual_import.modules', []),
            'projects' => Project::query()->where('active', true)->excludeFromOperationalDashboard()->orderBy('name')->get(),
            'ownerships' => [Equipment::OWNERSHIP_NWC => 'NWC', Equipment::OWNERSHIP_ICARE => 'İCARƏ'],
            'imports' => DashboardDataImport::query()->with(['project', 'creator'])->latest()->limit(30)->get(),
            'today' => now(config('app.timezone'))->toDateString(),
        ]);
    }

    public function store(StoreDashboardDataImportRequest $request, DashboardManualImportService $service): RedirectResponse
    {
        $validated = $request->validated();
        $uuid = (string) Str::uuid();
        $directory = trim((string) config('dashboard_manual_import.directory', 'dashboard-imports'), '/').'/'.$uuid;
        $absoluteDirectory = storage_path('app/private/'.str_replace('/', DIRECTORY_SEPARATOR, $directory));
        $storedFiles = [];
        $originalNames = [];
        $checksums = [];

        try {
            File::ensureDirectoryExists($absoluteDirectory);

            foreach ($request->file('files', []) as $index => $file) {
                $storedName = sprintf('%03d.xlsx', $index + 1);
                $storedPath = $directory.'/'.$storedName;
                $absolutePath = $absoluteDirectory.DIRECTORY_SEPARATOR.$storedName;
                $originalName = $file->getClientOriginalName();
                $file->move($absoluteDirectory, $storedName);
                $checksum = hash_file('sha256', $absolutePath);

                if ($checksum === false) {
                    throw ValidationException::withMessages(['files' => 'Faylın yoxlama kodu hesablana bilmədi.']);
                }

                if (in_array($checksum, $checksums, true)) {
                    throw ValidationException::withMessages(['files' => 'Eyni XLSX faylı bir əməliyyatda iki dəfə seçilib.']);
                }

                $storedFiles[] = $storedPath;
                $originalNames[] = $originalName;
                $checksums[] = $checksum;
            }

            $sortedChecksums = $checksums;
            sort($sortedChecksums);
            $combinedChecksum = hash('sha256', implode('|', $sortedChecksums));
            $duplicate = DashboardDataImport::query()
                ->where('module', $validated['module'])
                ->whereDate('date_from', $validated['date_from'])
                ->whereDate('date_to', $validated['date_to'])
                ->where('project_id', $validated['project_id'] ?? null)
                ->where('ownership_type', $validated['ownership_type'] ?? null)
                ->where('checksum', $combinedChecksum)
                ->where('status', DashboardDataImport::STATUS_COMPLETED)
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages(['files' => 'Bu fayl eyni Dashboard, layihə və dövr üçün artıq uğurla import edilib.']);
            }

            $import = DashboardDataImport::query()->create([
                'uuid' => $uuid,
                'module' => $validated['module'],
                'date_from' => $validated['date_from'],
                'date_to' => $validated['date_to'],
                'project_id' => $validated['project_id'] ?? null,
                'ownership_type' => $validated['ownership_type'] ?? null,
                'status' => DashboardDataImport::STATUS_VALIDATING,
                'original_file_names' => $originalNames,
                'stored_files' => $storedFiles,
                'checksum' => $combinedChecksum,
                'created_by' => $request->user()?->id,
            ]);

            $files = collect($storedFiles)->map(fn (string $path, int $index): array => [
                'path' => storage_path('app/private/'.str_replace('/', DIRECTORY_SEPARATOR, $path)),
                'name' => $originalNames[$index],
            ])->all();

            $service->stage($import, $files);

            return redirect()->route('admin.dashboard-data-imports.show', $import);
        } catch (Throwable $exception) {
            if (! isset($import)) {
                File::deleteDirectory($absoluteDirectory);
            }

            if ($exception instanceof ValidationException) {
                throw $exception;
            }

            throw ValidationException::withMessages(['files' => $exception->getMessage()]);
        }
    }

    public function show(DashboardDataImport $dashboardDataImport): View
    {
        $dashboardDataImport->load(['project', 'creator']);
        $limit = max(20, (int) config('dashboard_manual_import.preview_limit', 100));

        return view('admin.dashboard-data-imports.show', [
            'import' => $dashboardDataImport,
            'acceptedRows' => $dashboardDataImport->rows()->where('status', DashboardDataImportRow::STATUS_ACCEPTED)->orderBy('id')->limit($limit)->get(),
            'problemRows' => $dashboardDataImport->rows()->whereIn('status', [DashboardDataImportRow::STATUS_REJECTED, DashboardDataImportRow::STATUS_DUPLICATE])->orderBy('id')->limit($limit)->get(),
            'moduleSettings' => config('dashboard_manual_import.modules.'.$dashboardDataImport->module, []),
        ]);
    }

    public function confirm(DashboardDataImport $dashboardDataImport, DashboardManualImportService $service): RedirectResponse
    {
        try {
            $service->commit($dashboardDataImport);

            return redirect()
                ->route('admin.dashboard-data-imports.show', $dashboardDataImport)
                ->with('success', 'XLSX-də yoxlanmış obyektlərin seçilmiş dövr məlumatları uğurla əvəz edildi.');
        } catch (Throwable $exception) {
            return redirect()
                ->route('admin.dashboard-data-imports.show', $dashboardDataImport)
                ->with('error', $exception->getMessage());
        }
    }

    public function destroy(DashboardDataImport $dashboardDataImport): RedirectResponse
    {
        if (in_array($dashboardDataImport->status, [DashboardDataImport::STATUS_IMPORTING, DashboardDataImport::STATUS_COMPLETED], true)) {
            return back()->with('error', 'İcra edilmiş və ya icrada olan import silinə bilməz.');
        }

        foreach ($dashboardDataImport->stored_files ?? [] as $file) {
            File::delete(storage_path('app/private/'.str_replace('/', DIRECTORY_SEPARATOR, $file)));
        }

        $directory = trim((string) config('dashboard_manual_import.directory', 'dashboard-imports'), '/').'/'.$dashboardDataImport->uuid;
        File::deleteDirectory(storage_path('app/private/'.str_replace('/', DIRECTORY_SEPARATOR, $directory)));

        $dashboardDataImport->delete();

        return redirect()->route('admin.dashboard-data-imports.index')->with('success', 'Import qaralaması silindi.');
    }
}
