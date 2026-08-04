<?php

use App\Models\HistoricalRecalculationTask;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'historical_recalculation_tasks';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $orphanProjectCount = $this->assertNoOrphanRuns();
        $this->assertNoDuplicateScopes();

        if (! Schema::hasColumn(self::TABLE, 'scope_key')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->char('scope_key', 64)->nullable()->after('ownership_type');
            });
        }

        $this->backfillScopeKeys();

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `'.self::TABLE.'` MODIFY `scope_key` CHAR(64) NOT NULL');
        }

        $hasScopeUnique = Schema::hasIndex(
            self::TABLE,
            ['historical_recalculation_id', 'scope_key'],
            'unique'
        );
        $hasDispatchIndex = Schema::hasIndex(self::TABLE, [
            'historical_recalculation_id',
            'operation',
            'status',
            'stat_date',
            'project_id',
            'ownership_type',
            'id',
        ]);
        $hasProjectDateIndex = Schema::hasIndex(
            self::TABLE,
            ['project_id', 'ownership_type', 'stat_date']
        );
        $hasStatusDateIndex = Schema::hasIndex(self::TABLE, ['status', 'stat_date']);
        $hasRunForeignKey = $this->hasForeignKeyFor('historical_recalculation_id');
        $hasProjectForeignKey = $this->hasForeignKeyFor('project_id');
        $shouldAddProjectForeignKey = ! $hasProjectForeignKey && $orphanProjectCount === 0;

        if ($orphanProjectCount > 0 && ! $hasProjectForeignKey) {
            Log::warning('Historical task project foreign key was deferred because legacy references exist.', [
                'orphan_project_references' => $orphanProjectCount,
            ]);
        }

        Schema::table(self::TABLE, function (Blueprint $table) use (
            $hasScopeUnique,
            $hasDispatchIndex,
            $hasProjectDateIndex,
            $hasStatusDateIndex,
            $hasRunForeignKey,
            $shouldAddProjectForeignKey
        ): void {
            if (! $hasScopeUnique) {
                $table->unique(
                    ['historical_recalculation_id', 'scope_key'],
                    'hrt_run_scope_key_unique'
                );
            }

            if (! $hasDispatchIndex) {
                $table->index([
                    'historical_recalculation_id',
                    'operation',
                    'status',
                    'stat_date',
                    'project_id',
                    'ownership_type',
                    'id',
                ], 'hrt_run_dispatch_idx');
            }

            if (! $hasProjectDateIndex) {
                $table->index(
                    ['project_id', 'ownership_type', 'stat_date'],
                    'hrt_repair_project_owner_date_idx'
                );
            }

            if (! $hasStatusDateIndex) {
                $table->index(['status', 'stat_date'], 'hrt_repair_status_date_idx');
            }

            if (! $hasRunForeignKey) {
                $table->foreign('historical_recalculation_id', 'hrt_repair_run_fk')
                    ->references('id')
                    ->on('historical_recalculations')
                    ->cascadeOnDelete();
            }

            if ($shouldAddProjectForeignKey) {
                $table->foreign('project_id', 'hrt_repair_project_fk')
                    ->references('id')
                    ->on('projects')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            if ($this->hasForeignKeyNamed('hrt_repair_run_fk')) {
                $table->dropForeign('hrt_repair_run_fk');
            }

            if ($this->hasForeignKeyNamed('hrt_repair_project_fk')) {
                $table->dropForeign('hrt_repair_project_fk');
            }

            foreach ([
                'hrt_run_scope_key_unique',
                'hrt_run_dispatch_idx',
                'hrt_repair_project_owner_date_idx',
                'hrt_repair_status_date_idx',
            ] as $index) {
                if (Schema::hasIndex(self::TABLE, $index)) {
                    $table->dropIndex($index);
                }
            }
        });

        if (Schema::hasColumn(self::TABLE, 'scope_key')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropColumn('scope_key');
            });
        }
    }

    private function assertNoOrphanRuns(): int
    {
        $orphanRuns = DB::table(self::TABLE.' as tasks')
            ->leftJoin('historical_recalculations as runs', 'runs.id', '=', 'tasks.historical_recalculation_id')
            ->whereNull('runs.id')
            ->count();
        $orphanProjects = DB::table(self::TABLE.' as tasks')
            ->leftJoin('projects', 'projects.id', '=', 'tasks.project_id')
            ->whereNotNull('tasks.project_id')
            ->whereNull('projects.id')
            ->count();

        if ($orphanRuns > 0) {
            throw new RuntimeException(
                "Historical task schema repair stopped: {$orphanRuns} orphan runs found."
            );
        }

        return $orphanProjects;
    }

    private function assertNoDuplicateScopes(): void
    {
        $seen = [];
        $tasks = DB::table(self::TABLE)
            ->select(['id', 'historical_recalculation_id', 'operation', 'stat_date', 'project_id', 'ownership_type'])
            ->orderBy('id')
            ->lazyById(1000);

        foreach ($tasks as $task) {
            $scopeKey = HistoricalRecalculationTask::makeScopeKey(
                (string) $task->operation,
                $task->stat_date,
                $task->project_id,
                $task->ownership_type
            );
            $runScopeKey = $task->historical_recalculation_id.':'.$scopeKey;

            if (isset($seen[$runScopeKey])) {
                throw new RuntimeException(
                    "Historical task schema repair stopped: duplicate task scope IDs {$seen[$runScopeKey]} and {$task->id}."
                );
            }

            $seen[$runScopeKey] = $task->id;
        }
    }

    private function backfillScopeKeys(): void
    {
        DB::table(self::TABLE)
            ->select(['id', 'operation', 'stat_date', 'project_id', 'ownership_type'])
            ->orderBy('id')
            ->chunkById(500, function ($tasks): void {
                $caseBindings = [];
                $ids = [];
                $cases = [];

                foreach ($tasks as $task) {
                    $cases[] = 'WHEN ? THEN ?';
                    $caseBindings[] = $task->id;
                    $caseBindings[] = HistoricalRecalculationTask::makeScopeKey(
                        (string) $task->operation,
                        $task->stat_date,
                        $task->project_id,
                        $task->ownership_type
                    );
                    $ids[] = $task->id;
                }

                if ($ids === []) {
                    return;
                }

                $placeholders = implode(', ', array_fill(0, count($ids), '?'));
                DB::update(
                    'UPDATE '.self::TABLE.' SET scope_key = CASE id '.implode(' ', $cases)
                    .' END WHERE id IN ('.$placeholders.')',
                    [...$caseBindings, ...$ids]
                );
            });

        $missingKeys = DB::table(self::TABLE)->whereNull('scope_key')->count();
        $duplicateKeys = DB::table(self::TABLE)
            ->selectRaw('historical_recalculation_id, scope_key, COUNT(*) as task_count')
            ->groupBy('historical_recalculation_id', 'scope_key')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        if ($missingKeys > 0 || $duplicateKeys > 0) {
            throw new RuntimeException(
                "Historical task scope backfill failed: {$missingKeys} missing and {$duplicateKeys} duplicate keys."
            );
        }
    }

    private function hasForeignKeyFor(string $column): bool
    {
        foreach (Schema::getForeignKeys(self::TABLE) as $foreignKey) {
            if (($foreignKey['columns'] ?? []) === [$column]) {
                return true;
            }
        }

        return false;
    }

    private function hasForeignKeyNamed(string $name): bool
    {
        foreach (Schema::getForeignKeys(self::TABLE) as $foreignKey) {
            if (($foreignKey['name'] ?? null) === $name) {
                return true;
            }
        }

        return false;
    }
};
