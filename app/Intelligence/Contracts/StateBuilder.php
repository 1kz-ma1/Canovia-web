<?php

namespace App\Intelligence\Contracts;

use App\Intelligence\Data\EvidenceObservation;
use App\Intelligence\Data\StateSnapshot;
use App\Intelligence\Enums\IntelligenceDomain;

interface StateBuilder
{
    /**
     * @param iterable<EvidenceObservation> $evidence
     */
    public function build(
        IntelligenceDomain $domain,
        array $context,
        iterable $evidence,
    ): StateSnapshot;
}
