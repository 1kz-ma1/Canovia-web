<?php

namespace App\Intelligence\Study;

use App\Models\Plan;
use Illuminate\Validation\ValidationException;

final class StudyScoreScaleService
{
    /**
     * @param array<string,mixed> $learningType
     * @return array<string,mixed>
     */
    public function profile(Plan $plan, array $learningType): array
    {
        $text = $this->normalize(
            (string) $plan->title.' '.
            (string) $plan->description.' '.
            (string) $plan->category
        );

        if (str_contains($text, 'toeic')) {
            return $this->bounded(
                'toeic_total',
                'TOEIC',
                'score',
                0,
                990,
                1,
                [
                    $this->component('listening', 'Listening', 0, 495, 1),
                    $this->component('reading', 'Reading', 0, 495, 1),
                ],
            );
        }

        if (str_contains($text, 'ielts')) {
            return $this->bounded(
                'ielts_overall',
                'IELTS',
                'band',
                0,
                9,
                0.5,
                [
                    $this->component('listening', 'Listening', 0, 9, 0.5),
                    $this->component('reading', 'Reading', 0, 9, 0.5),
                    $this->component('writing', 'Writing', 0, 9, 0.5),
                    $this->component('speaking', 'Speaking', 0, 9, 0.5),
                ],
            );
        }

        if (
            (string) ($learningType['key'] ?? '')
            === 'school_test'
        ) {
            return $this->bounded(
                'school_test_score',
                '学校テスト',
                'score',
                0,
                100,
                1,
                [],
            );
        }

        return [
            'metric_key' => 'external_score',
            'label' => '外部スコア',
            'unit' => 'score',
            'min' => null,
            'max' => null,
            'step' => 0.1,
            'components' => [],
            'bounded' => false,
        ];
    }

    /**
     * @param array<string,mixed> $profile
     * @param array<string,mixed> $rawComponents
     * @return array<int,array<string,mixed>>
     */
    public function validateAndNormalizeComponents(
        array $profile,
        array $rawComponents,
    ): array {
        $definitions = collect($profile['components'] ?? [])
            ->filter(fn ($item) => is_array($item))
            ->keyBy('key');

        $unknown = collect(array_keys($rawComponents))
            ->reject(fn ($key) => $definitions->has($key))
            ->values();

        if ($unknown->isNotEmpty()) {
            throw ValidationException::withMessages([
                'components' => 'このスコア形式では未対応の内訳が含まれています。',
            ]);
        }

        return $definitions
            ->map(function (array $definition, string $key) use ($rawComponents) {
                $raw = $rawComponents[$key] ?? null;

                if ($raw === null || $raw === '') {
                    return null;
                }

                if (! is_numeric($raw)) {
                    throw ValidationException::withMessages([
                        "components.{$key}" => '数値で入力してください。',
                    ]);
                }

                $value = (float) $raw;
                $this->assertBoundedValue(
                    $value,
                    $definition,
                    "components.{$key}",
                );

                return [
                    'key' => $key,
                    'label' => $definition['label'],
                    'value' => $value,
                    'min' => $definition['min'],
                    'max' => $definition['max'],
                    'unit' => $profile['unit'] ?? 'score',
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param array<string,mixed> $profile
     */
    public function validateScore(float $value, array $profile): void
    {
        if (($profile['bounded'] ?? false) === true) {
            $this->assertBoundedValue(
                $value,
                $profile,
                'score_value',
            );

            return;
        }

        if ($value < -1000000 || $value > 1000000) {
            throw ValidationException::withMessages([
                'score_value' => '入力できるスコア範囲を超えています。',
            ]);
        }
    }

    /**
     * @param array<string,mixed> $definition
     */
    private function assertBoundedValue(
        float $value,
        array $definition,
        string $field,
    ): void {
        $min = (float) ($definition['min'] ?? 0);
        $max = (float) ($definition['max'] ?? 0);
        $step = max(0.0001, (float) ($definition['step'] ?? 1));

        if ($value < $min || $value > $max) {
            throw ValidationException::withMessages([
                $field => "{$min}〜{$max}の範囲で入力してください。",
            ]);
        }

        $steps = ($value - $min) / $step;
        if (abs($steps - round($steps)) > 0.00001) {
            throw ValidationException::withMessages([
                $field => "{$step}刻みで入力してください。",
            ]);
        }
    }

    /**
     * @param array<int,array<string,mixed>> $components
     * @return array<string,mixed>
     */
    private function bounded(
        string $metricKey,
        string $label,
        string $unit,
        float $min,
        float $max,
        float $step,
        array $components,
    ): array {
        return [
            'metric_key' => $metricKey,
            'label' => $label,
            'unit' => $unit,
            'min' => $min,
            'max' => $max,
            'step' => $step,
            'components' => $components,
            'bounded' => true,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function component(
        string $key,
        string $label,
        float $min,
        float $max,
        float $step,
    ): array {
        return compact('key', 'label', 'min', 'max', 'step');
    }

    private function normalize(string $value): string
    {
        $value = mb_convert_kana(
            mb_strtolower(trim($value)),
            'as',
            'UTF-8',
        );

        return preg_replace('/[\s　]+/u', ' ', $value) ?? $value;
    }
}
