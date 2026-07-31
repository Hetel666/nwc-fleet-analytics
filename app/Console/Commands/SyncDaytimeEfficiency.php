<?php

namespace App\Console\Commands;

use App\Models\Equipment;
use App\Models\ProjectWialonGroup;
use App\Services\DaytimeEfficiencySyncService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class SyncDaytimeEfficiency extends Command
{
    protected $signature = 'fleet:sync-daytime-efficiency
        {--date= : Date in YYYY-MM-DD format}
        {--project= : Project ID or exact project name}
        {--ownership= : nwc or icare}
        {--group= : Exact Wialon group ID}
        {--force : Re-run existing facts}';

    protected $description = 'Synchronize the standalone daytime efficiency dashboard from Qrup report daytime (api).';

    public function handle(DaytimeEfficiencySyncService $sync): int
    {
        if (! config('daytime_efficiency.enabled', true)) {
            $this->warn('Daytime efficiency is disabled.');

            return self::SUCCESS;
        }

        $date = CarbonImmutable::parse(
            (string) ($this->option('date') ?: now($this->timezone())->subDay()->toDateString()),
            $this->timezone()
        )->startOfDay();
        $groups = $this->groups();

        if ($groups->isEmpty()) {
            $this->warn('No matching active Wialon project groups found.');

            return self::SUCCESS;
        }

        $failed = 0;
        $totals = ['equipment_count' => 0, 'report_rows' => 0, 'saved_rows' => 0, 'unmatched_rows' => 0, 'duplicate_rows' => 0, 'malformed_rows' => 0];

        foreach ($groups as $group) {
            try {
                $result = $sync->syncGroup($group, $date);

                foreach (array_keys($totals) as $key) {
                    $totals[$key] += (int) ($result[$key] ?? 0);
                }

                $this->line(sprintf(
                    '%s | %s | %s | equipment=%d report=%d saved=%d unmatched=%d duplicates=%d malformed=%d',
                    $group->wialon_group_id,
                    $group->project?->name ?? '-',
                    strtoupper((string) $group->ownership_type),
                    $result['equipment_count'],
                    $result['report_rows'],
                    $result['saved_rows'],
                    $result['unmatched_rows'],
                    $result['duplicate_rows'],
                    $result['malformed_rows'],
                ));
            } catch (Throwable $exception) {
                $failed++;
                $this->error($group->wialon_group_id.' | '.$exception->getMessage());
                Log::warning('Daytime efficiency synchronization failed', [
                    'date' => $date->toDateString(),
                    'group_id' => $group->wialon_group_id,
                    'message' => $exception->getMessage(),
                ]);
            }

            $sleepMs = max(0, (int) config('fleet.wialon.shift_report_sleep_ms', 250));

            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }

        $this->table(['Metric', 'Value'], collect($totals)->map(fn (int $value, string $key): array => [$key, $value])->values()->all());

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function groups()
    {
        $ownership = mb_strtolower(trim((string) $this->option('ownership')));
        $ownership = match ($ownership) {
            'nwc' => Equipment::OWNERSHIP_NWC,
            'icare', 'icarə' => Equipment::OWNERSHIP_ICARE,
            default => null,
        };

        return ProjectWialonGroup::query()
            ->with('project:id,name,active')
            ->whereHas('project', fn (Builder $query) => $query->where('active', true))
            ->when(Schema::hasColumn('project_wialon_groups', 'is_active'), fn (Builder $query) => $query->where('is_active', true))
            ->when($this->option('group'), fn (Builder $query, string $group) => $query->where('wialon_group_id', trim($group)))
            ->when($ownership, fn (Builder $query, string $value) => $query->where('ownership_type', $value))
            ->when($this->option('project'), function (Builder $query, string $project): void {
                $query->whereHas('project', function (Builder $query) use ($project): void {
                    ctype_digit(trim($project))
                        ? $query->whereKey((int) $project)
                        : $query->where('name', trim($project));
                });
            })
            ->orderBy('project_id')
            ->orderBy('ownership_type')
            ->get();
    }

    private function timezone(): string
    {
        return (string) config('daytime_efficiency.timezone', 'Asia/Baku');
    }
}
