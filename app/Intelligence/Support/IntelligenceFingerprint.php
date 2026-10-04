<?php

namespace App\Intelligence\Support;

use App\Intelligence\Data\EvidenceObservation;
use App\Intelligence\Data\StateSnapshot;

final class IntelligenceFingerprint
{
    public static function evidence(EvidenceObservation $evidence): string
    {
        return self::hash([
            'reference' => $evidence->reference,
            'source' => $evidence->source->value,
            'type' => $evidence->type,
            'occurred_at' => $evidence->occurredAt,
            'confidence' => $evidence->confidence->value,
            'facts' => $evidence->facts,
            'references' => self::stableList($evidence->references),
        ]);
    }

    public static function evidenceReference(EvidenceObservation $evidence): string
    {
        return 'evidence:'.$evidence->source->value.':'.self::evidence($evidence);
    }

    /**
     * Semantic state identity. capturedAt is intentionally excluded.
     */
    public static function state(StateSnapshot $state): string
    {
        return self::hash([
            'domain' => $state->domain->value,
            'scope_type' => $state->scopeType,
            'scope_id' => $state->scopeId,
            'metrics' => $state->metrics,
            'facts' => $state->facts,
            'evidence_references' => self::stableList($state->evidenceReferences),
        ]);
    }

    /**
     * Snapshot identity. The same semantic State observed at a different time
     * is a different snapshot while retaining the same state fingerprint.
     */
    public static function snapshot(StateSnapshot $state): string
    {
        return self::hash([
            'state_fingerprint' => self::state($state),
            'captured_at' => $state->capturedAt,
        ]);
    }

    public static function stateReference(StateSnapshot $state): string
    {
        return 'state:'.$state->domain->value.':'.self::snapshot($state);
    }

    private static function hash(array $payload): string
    {
        return hash('sha256', CanonicalJson::encode($payload));
    }

    private static function stableList(array $items): array
    {
        $items = array_values($items);

        usort(
            $items,
            fn (mixed $left, mixed $right): int =>
                strcmp(CanonicalJson::encode($left), CanonicalJson::encode($right)),
        );

        return $items;
    }
}
