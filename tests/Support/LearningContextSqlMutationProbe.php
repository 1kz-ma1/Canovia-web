<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use LogicException;

/**
 * Disposable-CI experiment ONLY. Not a migration, not a runtime service,
 * not an authorization mechanism and not an alternate Source Revision.
 *
 * Deliberately over-inclusive: counts EVERY DML row operation on the three
 * source tables and Plan UPDATE, whether or not it affects a Study snapshot.
 * No source content, user identity, score or answers are stored.
 */
final class LearningContextSqlMutationProbe
{
    private const LEDGER = 'learning_context_probe_epochs';
    private const SOURCES = [
        'plans' => ['UPDATE'],
        'tasks' => ['INSERT', 'UPDATE', 'DELETE'],
        'study_practice_attempts' => ['INSERT', 'UPDATE', 'DELETE'],
        'mcp_study_result_proposals' => ['INSERT', 'UPDATE', 'DELETE'],
    ];

    public static function assertDisposableDatabase(): void
    {
        $driver = DB::getDriverName();
        $cfg = config('database.connections.'.config('database.default'));
        if (getenv('CANOVIA_LEARNING_LOCK_DISPOSABLE_CI') !== '1'
            || config('app.env') !== 'testing'
            || ! in_array($driver, ['mysql', 'pgsql'], true)
            || ! is_array($cfg)
            || ($cfg['host'] ?? null) !== '127.0.0.1'
            || ($cfg['database'] ?? null) !== 'canovia_learning_watch_lock_ci'
            || (int) ($cfg['port'] ?? 0) !== ($driver === 'mysql' ? 3306 : 5432)) {
            throw new LogicException('Mutation probe DDL is forbidden outside pinned disposable CI.');
        }
    }

    public static function install(bool $filterIrrelevantRows = false): void
    {
        self::assertDisposableDatabase();
        if (Schema::hasTable(self::LEDGER)) {
            throw new LogicException('Probe table already exists: refusing implicit destructive reinstall.');
        }

        Schema::create(self::LEDGER, static function (Blueprint $table): void {
            $table->unsignedBigInteger('plan_id')->primary();
            $table->unsignedBigInteger('epoch')->default(0);
            $table->string('last_mutation_kind', 60);
        });

        if (DB::getDriverName() === 'pgsql') {
            self::installPostgres($filterIrrelevantRows);

            return;
        }

        foreach (self::SOURCES as $table => $events) {
            foreach ($events as $event) {
                $name = self::triggerName($table, $event);
                $action = strtolower($table.'_'.$event);
                $newPlanId = $table === 'plans' ? 'NEW.id' : 'NEW.plan_id';
                $oldPlanId = $table === 'plans' ? 'OLD.id' : 'OLD.plan_id';
                $statement = static fn (string $planId): string =>
                    "INSERT INTO ".self::LEDGER." (plan_id, epoch, last_mutation_kind) "
                    ."VALUES ($planId, 1, '$action') "
                    ."ON DUPLICATE KEY UPDATE epoch = epoch + 1, last_mutation_kind = '$action';";

                $body = match ($event) {
                    'INSERT' => $statement($newPlanId),
                    'DELETE' => $statement($oldPlanId),
                    'UPDATE' => $statement($oldPlanId)
                        ."\n IF NOT ($newPlanId <=> $oldPlanId) THEN\n"
                        .$statement($newPlanId)."\n END IF;",
                };
                if ($filterIrrelevantRows) {
                    $predicate = self::relevantPredicate($table, $event, 'mysql');
                    $body = "IF ($predicate) THEN\n $body\n END IF;";
                }
                DB::unprepared("CREATE TRIGGER $name AFTER $event ON $table "
                    ."FOR EACH ROW BEGIN\n $body\nEND");
            }
        }
    }

    private static function installPostgres(bool $filterIrrelevantRows): void
    {
        $guards = '';
        if ($filterIrrelevantRows) {
            foreach (self::SOURCES as $table => $events) {
                foreach ($events as $event) {
                    $predicate = self::relevantPredicate($table, $event, 'pgsql');
                    $return = $event === 'DELETE' ? 'OLD' : 'NEW';
                    $guards .= "    IF TG_TABLE_NAME = '$table' AND TG_OP = '$event' THEN\n"
                        ."        IF NOT ($predicate) THEN RETURN $return; END IF;\n"
                        ."    END IF;\n";
                }
            }
        }
        DB::unprepared(str_replace('/* CI_FILTER_GUARDS */', $guards, <<<'SQL'
CREATE OR REPLACE FUNCTION canovia_probe_source_bump() RETURNS trigger AS $$
DECLARE
    before_plan BIGINT;
    after_plan BIGINT;
    action_kind TEXT;
BEGIN
    action_kind := TG_TABLE_NAME || '_' || LOWER(TG_OP);
    /* CI_FILTER_GUARDS */
    IF TG_TABLE_NAME = 'plans' THEN
        before_plan := OLD.id;
        after_plan := NEW.id;
    ELSE
        IF TG_OP <> 'INSERT' THEN
            before_plan := OLD.plan_id;
        END IF;
        IF TG_OP <> 'DELETE' THEN
            after_plan := NEW.plan_id;
        END IF;
    END IF;

    IF before_plan IS NOT NULL THEN
        INSERT INTO learning_context_probe_epochs (plan_id, epoch, last_mutation_kind)
        VALUES (before_plan, 1, action_kind)
        ON CONFLICT (plan_id) DO UPDATE SET
            epoch = learning_context_probe_epochs.epoch + 1,
            last_mutation_kind = EXCLUDED.last_mutation_kind;
    END IF;
    IF after_plan IS NOT NULL
        AND (before_plan IS NULL OR after_plan IS DISTINCT FROM before_plan) THEN
        INSERT INTO learning_context_probe_epochs (plan_id, epoch, last_mutation_kind)
        VALUES (after_plan, 1, action_kind)
        ON CONFLICT (plan_id) DO UPDATE SET
            epoch = learning_context_probe_epochs.epoch + 1,
            last_mutation_kind = EXCLUDED.last_mutation_kind;
    END IF;
    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL));
        foreach (self::SOURCES as $table => $events) {
            foreach ($events as $event) {
                DB::unprepared('CREATE TRIGGER '.self::triggerName($table, $event)
                    ." AFTER $event ON $table FOR EACH ROW "
                    .'EXECUTE FUNCTION canovia_probe_source_bump()');
            }
        }
    }

    /**
     * Experimental conservative row filter. It is intentionally NOT a
     * complete versioned declaration of the Source Capture schema.
     *
     * Plan UPDATE is always counted (updated_at and eligibility).
     * Task UPDATE checks fields actually used in v0.7's task hash, plus
     * moving between Plans. Native/External UPDATE checks membership only
     * and still overcounts many already-eligible row mutations.
     */
    private static function relevantPredicate(string $table, string $event, string $driver): string
    {
        if ($table === 'plans') {
            return '1 = 1';
        }
        $active = static fn (string $side): string =>
            "$side.status IN ('doing', 'todo', 'paused')";
        $applied = static fn (string $side): string =>
            "$side.applied_at IS NOT NULL";
        $approved = static fn (string $side): string =>
            "($side.status = 'approved' AND $side.decided_at IS NOT NULL)";
        $qualifier = match ($table) {
            'tasks' => $active,
            'study_practice_attempts' => $applied,
            'mcp_study_result_proposals' => $approved,
        };
        if ($event === 'INSERT') {
            return $qualifier('NEW');
        }
        if ($event === 'DELETE') {
            return $qualifier('OLD');
        }

        $membership = '('.$qualifier('OLD').' OR '.$qualifier('NEW').')';
        if ($table !== 'tasks') {
            // Do not mistake a Practice row's applied/approved status for
            // proof of ownership. All eligible-looking changes are counted.
            return $membership;
        }

        $different = static fn (string $column): string =>
            $driver === 'pgsql'
                ? "(OLD.$column IS DISTINCT FROM NEW.$column)"
                : "(NOT (OLD.$column <=> NEW.$column))";
        $fields = ['plan_id', 'title', 'status', 'progress_percent', 'updated_at'];
        $changed = implode(' OR ', array_map($different, $fields));

        return "($membership AND ($changed))";
    }

    public static function inspect(int $planId): ?array
    {
        self::assertDisposableDatabase();
        $row = DB::table(self::LEDGER)->where('plan_id', $planId)
            ->first(['epoch', 'last_mutation_kind']);
        return $row === null ? null : [
            'epoch' => (int) $row->epoch,
            'last_mutation_kind' => (string) $row->last_mutation_kind,
            'authoritative' => false,
        ];
    }

    public static function uninstall(): void
    {
        self::assertDisposableDatabase();
        foreach (self::SOURCES as $table => $events) {
            foreach ($events as $event) {
                $name = self::triggerName($table, $event);
                DB::unprepared(DB::getDriverName() === 'pgsql'
                    ? "DROP TRIGGER IF EXISTS $name ON $table"
                    : "DROP TRIGGER IF EXISTS $name");
            }
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS canovia_probe_source_bump()');
        }
        Schema::dropIfExists(self::LEDGER);
    }

    private static function triggerName(string $table, string $event): string
    {
        return 'canovia_probe_'.match ($table) {
            'plans' => 'plan',
            'tasks' => 'task',
            'study_practice_attempts' => 'native',
            'mcp_study_result_proposals' => 'external',
        }.'_'.strtolower($event);
    }
}
