<?php

namespace App\Services;

final class PersonalizationConfidenceCalibrationService
{
    public const VERSION = 1;

    /**
     * Calibrate confidence from deterministic evidence without converting
     * confidence into permission to auto-apply a high-impact change.
     *
     * @param array<int,string> $evidence
     * @return array{
     *   version:int,
     *   confidence:string,
     *   signal_strength:int,
     *   evidence_count:int,
     *   basis:array<int,string>
     * }
     */
    public function calibrate(
        string $risk,
        int $signalStrength,
        array $evidence,
    ): array {
        $strength = max(1, min(3, $signalStrength));
        $evidence = array_values(array_unique(array_filter(
            $evidence,
            fn ($value) => is_string($value) && trim($value) !== '',
        )));

        $confidence = match ($strength) {
            3 => 'high',
            2 => 'medium',
            default => 'low',
        };

        return [
            'version' => self::VERSION,
            'confidence' => $confidence,
            'signal_strength' => $strength,
            'evidence_count' => count($evidence),
            'basis' => [
                'deterministic_observed_evidence',
                'signal_strength_'.$strength,
                'risk_'.$this->safeRisk($risk),
            ],
        ];
    }

    private function safeRisk(string $risk): string
    {
        return in_array($risk, ['low', 'medium', 'high'], true)
            ? $risk
            : 'unknown';
    }
}
