<?php

namespace App\Intelligence\Contracts;

use App\Intelligence\Data\ActionProposal;
use App\Intelligence\Data\EvidenceObservation;
use App\Intelligence\Data\OutcomeObservation;

interface OutcomeInterpreter
{
    /**
     * @param iterable<EvidenceObservation> $evidence
     */
    public function interpret(
        ActionProposal $action,
        iterable $evidence,
        array $context = [],
    ): OutcomeObservation;
}
