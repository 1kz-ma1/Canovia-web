<?php

namespace App\Intelligence\Services;

final class ReasoningCostEstimator
{
    public function estimateUsd(
        ?int $inputTokens,
        ?int $outputTokens,
    ): ?float {
        $inputRate = config(
            'intelligence.reasoning.cost.input_usd_per_million_tokens',
        );
        $outputRate = config(
            'intelligence.reasoning.cost.output_usd_per_million_tokens',
        );

        if (! is_numeric($inputRate) || ! is_numeric($outputRate)) {
            return null;
        }

        if ($inputTokens === null && $outputTokens === null) {
            return null;
        }

        $cost = ((max(0, (int) ($inputTokens ?? 0)) / 1_000_000) * (float) $inputRate)
            + ((max(0, (int) ($outputTokens ?? 0)) / 1_000_000) * (float) $outputRate);

        return round(max(0, $cost), 8);
    }
}
