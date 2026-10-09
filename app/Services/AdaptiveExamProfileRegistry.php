<?php
namespace App\Services;

use Illuminate\Support\Collection;

final class AdaptiveExamProfileRegistry
{
    /** @return Collection<int,array<string,mixed>> */
    public function available(): Collection
    {
        return collect(config('adaptive_exam_profiles.profiles', []))
            ->map(function ($raw, $key) {
                if (! is_array($raw)) return null;
                $p = array_merge($raw, ['key' => (string) $key]);
                if (($p['status'] ?? '') !== 'verified'
                    || ! preg_match('/^[a-zA-Z0-9._-]{2,120}$/', (string) $p['key'])
                    || trim((string) ($p['version'] ?? '')) === ''
                    || trim((string) ($p['exam_code'] ?? '')) === ''
                    || trim((string) ($p['subject'] ?? '')) === ''
                    || trim((string) ($p['source_reference'] ?? '')) === ''
                    || ! preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', (string) ($p['verified_at'] ?? ''))
                    || ($p['response_format'] ?? '') !== 'single_choice'
                    || ! is_numeric($p['question_count'] ?? null)
                    || (int) $p['question_count'] < 1
                    || (int) $p['question_count'] > 200
                    || ! is_numeric($p['duration_minutes'] ?? null)
                    || (int) $p['duration_minutes'] < 1
                    || (int) $p['duration_minutes'] > 360
                    || (isset($p['choices_per_question'])
                        && (! is_int($p['choices_per_question'])
                            || $p['choices_per_question'] < 2
                            || $p['choices_per_question'] > 8))) return null;
                return $p;
            })->filter()->values();
    }

    /** @return array<string,mixed> */
    public function requireVerified(string $key): array
    {
        $profile = $this->available()->firstWhere('key', $key);
        abort_unless(is_array($profile), 404);
        return $profile;
    }
}
