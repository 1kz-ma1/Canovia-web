<?php

namespace App\Services;

use App\Models\Plan;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

/**
 * SHADOW-ONLY source mutation bookkeeping. Never authorizes auto-Watch,
 * notifications, or release of previous historical-hash correctness gates.
 *
 * Contract: caller must already hold the Plan row FOR UPDATE in the active
 * transaction and mutate source data in that *same* transaction. This cannot
 * independently prove all legacy writers are participating.
 */
final class LearningContextSourceEpochService
{
    public const KINDS = [
        'task_created',
        'task_updated',
        'task_deleted',
        'practice_applied',
        'approved_result_changed',
    ];

    public function enabled(): bool
    {
        return config('intelligence.learning_context_watch.epoch_shadow_enabled') === true
            && config('app.env') !== 'staging';
    }

    /**
     * Increment as part of the surrounding Plan-first write transaction.
     * Returns null when the opt-in *shadow instrumentation* gate is OFF.
     * Throws (rollback) when the gate is ON but the transaction/table is wrong.
     */
    public function record(Plan $lockedPlan, string $mutationKind): ?int
    {
        if (! in_array($mutationKind, self::KINDS, true)) {
            throw new InvalidArgumentException('Unrecognized Learning source mutation kind.');
        }
        if (! $this->enabled()) {
            return null;
        }
        if ($lockedPlan->id === null || DB::transactionLevel() < 1) {
            throw new LogicException('Source epoch requires a locked Plan and an active transaction.');
        }

        // Only the Plan acts as the serialization key. Never call this before
        // acquiring its row lock or from a separate independent transaction.
        $current = DB::table('learning_context_source_epochs')
            ->where('plan_id', $lockedPlan->id)
            ->lockForUpdate()
            ->first(['epoch']);

        if ($current === null) {
            DB::table('learning_context_source_epochs')->insert([
                'plan_id' => $lockedPlan->id,
                'epoch' => 1,
                'last_mutation_kind' => $mutationKind,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return 1;
        }

        $epoch = (int) $current->epoch;
        if ($epoch >= PHP_INT_MAX) {
            throw new RuntimeException('Learning source epoch overflow; fail closed.');
        }
        $next = $epoch + 1;
        DB::table('learning_context_source_epochs')
            ->where('plan_id', $lockedPlan->id)
            ->update([
                'epoch' => $next,
                'last_mutation_kind' => $mutationKind,
                'updated_at' => now(),
            ]);

        return $next;
    }

    /** Read-only, diagnostic, NOT a source fingerprint or freshness claim. */
    public function inspect(int $planId): ?array
    {
        if (! $this->enabled() || $planId < 1) {
            return null;
        }
        $row = DB::table('learning_context_source_epochs')
            ->where('plan_id', $planId)
            ->first(['epoch', 'last_mutation_kind']);
        return $row === null ? null : [
            'epoch' => (int) $row->epoch,
            'last_mutation_kind' => (string) $row->last_mutation_kind,
            'authoritative' => false,
        ];
    }
}
