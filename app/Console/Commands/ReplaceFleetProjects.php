<?php

namespace App\Console\Commands;

use App\Models\Equipment;
use App\Models\Project;
use App\Models\ProjectWialonGroup;
use App\Services\WialonGroupClassificationService;
use Database\Seeders\FleetProjectSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class ReplaceFleetProjects extends Command
{
    protected $signature = 'fleet:replace-projects {--force : Required in production}';

    protected $description = 'Replace the local project directory with the configured Wialon project groups.';

    public function handle(WialonGroupClassificationService $classification): int
    {
        if (app()->isProduction() && ! $this->option('force')) {
            $this->error('Operation cancelled. Use --force to replace projects in production.');

            return self::FAILURE;
        }

        $currentProjects = Project::query()->count();
        $this->warn('This command deactivates old projects, resets current equipment project links, and re-syncs units from Wialon.');
        $this->line("Current projects: {$currentProjects}");

        if (! $this->option('force') && ! $this->confirm('Continue replacing fleet projects?')) {
            $this->info('Operation cancelled.');

            return self::SUCCESS;
        }

        $backupPath = $this->backupProjects();
        $this->line("Backup created: {$backupPath}");

        $result = [
            'old_projects' => $currentProjects,
            'active_projects' => 0,
            'deactivated_projects' => 0,
            'detached_equipment' => 0,
            'synced_units' => 0,
            'without_project' => 0,
            'conflicts' => 0,
        ];

        try {
            DB::transaction(function () use (&$result): void {
                $result['detached_equipment'] = Equipment::query()->update([
                    'project_id' => null,
                    'project_wialon_group_id' => null,
                    'matched_wialon_group_id' => null,
                    'matched_wialon_group_name' => null,
                ]);

                ProjectWialonGroup::query()->delete();

                $this->callSilent('db:seed', [
                    '--class' => FleetProjectSeeder::class,
                    '--force' => true,
                ]);
            });
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $syncCode = Artisan::call('fleet:sync-units');
        $this->output->write(Artisan::output());

        if ($syncCode !== self::SUCCESS) {
            $this->error('Wialon unit re-sync failed after project replacement.');

            return self::FAILURE;
        }

        $activeProjectIds = Project::query()->where('active', true)->pluck('id');
        $result['active_projects'] = $activeProjectIds->count();
        $result['deactivated_projects'] = Project::query()->where('active', false)->count();
        $result['synced_units'] = Equipment::query()->whereNotNull('matched_wialon_group_id')->count();
        $result['without_project'] = Equipment::query()
            ->whereNull('project_id')
            ->whereIn('matched_wialon_group_id', $classification->serviceGroupIds())
            ->count();
        $result['conflicts'] = Equipment::query()
            ->whereNull('project_id')
            ->whereNull('matched_wialon_group_id')
            ->count();

        $this->clearProjectCaches();

        $this->table(['Metric', 'Value'], collect($result)->map(fn ($value, $key): array => [$key, $value])->all());
        $this->line('Expected active projects: 30');

        return $result['active_projects'] === 30 ? self::SUCCESS : self::FAILURE;
    }

    private function backupProjects(): string
    {
        $timestamp = now(config('app.timezone'))->format('Y-m-d-His');
        $relativePath = "app/backups/projects-before-replacement-{$timestamp}.json";
        $path = storage_path($relativePath);

        File::ensureDirectoryExists(dirname($path));

        $payload = [
            'backed_up_at' => now(config('app.timezone'))->toIso8601String(),
            'projects' => Project::query()
                ->with('wialonGroups:id,project_id,wialon_group_id,name,ownership_type')
                ->withCount('equipment')
                ->orderBy('id')
                ->get()
                ->map(fn (Project $project): array => [
                    'id' => $project->id,
                    'name' => $project->name,
                    'code' => $project->code,
                    'active' => (bool) $project->active,
                    'equipment_count' => $project->equipment_count,
                    'wialon_groups' => $project->wialonGroups->map(fn (ProjectWialonGroup $group): array => [
                        'id' => $group->id,
                        'wialon_group_id' => $group->wialon_group_id,
                        'name' => $group->name,
                        'ownership_type' => $group->ownership_type,
                    ])->values()->all(),
                    'created_at' => optional($project->created_at)->toIso8601String(),
                    'updated_at' => optional($project->updated_at)->toIso8601String(),
                ])
                ->all(),
        ];

        File::put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $path;
    }

    private function clearProjectCaches(): void
    {
        Cache::forget('dashboard:projects');
        Cache::forget('dashboard:ownership-statistics');
        Cache::forever('dashboard:data-version', ((int) Cache::get('dashboard:data-version', 1)) + 1);
    }
}
