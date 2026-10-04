<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\StudyScopeCapture;
use DateTimeImmutable;
use DateTimeInterface;

final class StudyExamDateService
{
    /**
     * @return array{exam_date:?string,source:?string,conflict:bool}
     */
    public function resolve(Plan $plan): array
    {
        $dates = StudyScopeCapture::query()
            ->where('plan_id', $plan->id)
            ->where('status', 'confirmed')
            ->whereNotNull('exam_date')
            ->pluck('exam_date')
            ->map(fn ($date) => method_exists($date, 'format')
                ? $date->format('Y-m-d')
                : substr((string) $date, 0, 10))
            ->filter()
            ->unique()
            ->values();

        if ($dates->count() === 1) {
            return [
                'exam_date' => (string) $dates->first(),
                'source' => 'confirmed_scope_capture',
                'conflict' => false,
            ];
        }

        if ($dates->count() > 1) {
            return [
                'exam_date' => null,
                'source' => 'conflicting_scope_captures',
                'conflict' => true,
            ];
        }

        if ($plan->deadline) {
            return [
                'exam_date' => $plan->deadline->format('Y-m-d'),
                'source' => 'plan_deadline',
                'conflict' => false,
            ];
        }

        return [
            'exam_date' => null,
            'source' => null,
            'conflict' => false,
        ];
    }

    public function daysUntil(
        Plan $plan,
        ?DateTimeInterface $capturedAt = null,
    ): ?int {
        $resolved = $this->resolve($plan);
        $examDate = $resolved['exam_date'];

        if (! is_string($examDate) || $examDate === '') {
            return null;
        }

        $captured = $capturedAt
            ? DateTimeImmutable::createFromInterface($capturedAt)
            : new DateTimeImmutable();

        $captured = $captured->setTime(0, 0);
        $exam = (new DateTimeImmutable($examDate))->setTime(0, 0);

        return (int) $captured->diff($exam)->format('%r%a');
    }
}
