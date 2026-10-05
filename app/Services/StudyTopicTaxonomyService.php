<?php

namespace App\Services;

use App\Models\Question;

final class StudyTopicTaxonomyService
{
    /**
     * @param array<int,string> $labels
     */
    public function parentForLabels(array $labels): ?string
    {
        foreach ($labels as $label) {
            $parent = $this->parentForLabel($label);
            if ($parent !== null) {
                return $parent;
            }
        }

        return null;
    }

    public function parentForLabel(?string $label): ?string
    {
        $normalized = $this->normalize((string) $label);
        if ($normalized === '') {
            return null;
        }

        foreach ($this->aliases() as $parent => $aliases) {
            foreach ($aliases as $alias) {
                $alias = $this->normalize((string) $alias);
                if ($alias === '') {
                    continue;
                }

                if ($this->matchesAlias($normalized, $alias)) {
                    return (string) $parent;
                }
            }
        }

        return null;
    }

    public function parentForQuestion(Question $question): ?string
    {
        $metadata = is_array($question->learning_metadata)
            ? $question->learning_metadata
            : [];

        return $this->parentForLabels(
            collect($metadata['weakness_targets'] ?? [])
                ->merge($metadata['concepts'] ?? [])
                ->merge($metadata['keywords'] ?? [])
                ->filter(fn ($item) => is_string($item) && trim($item) !== '')
                ->map(fn ($item) => trim((string) $item))
                ->values()
                ->all(),
        );
    }

    public function subtopicForQuestion(Question $question): ?string
    {
        $metadata = is_array($question->learning_metadata)
            ? $question->learning_metadata
            : [];

        $ignored = [
            '科目a',
            'テクノロジ',
            'マネジメント',
            'ストラテジ',
            'ipa公式過去問',
        ];

        $weaknessTarget = collect($metadata['weakness_targets'] ?? [])
            ->filter(fn ($item) => is_string($item) && trim($item) !== '')
            ->map(fn ($item) => trim((string) $item))
            ->first();

        if (is_string($weaknessTarget) && $weaknessTarget !== '') {
            return $weaknessTarget;
        }

        $concept = collect($metadata['concepts'] ?? [])
            ->filter(fn ($item) => is_string($item) && trim($item) !== '')
            ->map(fn ($item) => trim((string) $item))
            ->reverse()
            ->first(fn (string $item) => ! in_array(
                mb_strtolower($item),
                $ignored,
                true,
            ));

        return is_string($concept) && $concept !== ''
            ? $concept
            : null;
    }

    public function key(?string $value): string
    {
        return $this->normalize((string) $value);
    }

    /**
     * @return array<string,array<int,string>>
     */
    private function aliases(): array
    {
        $aliases = config('study.practice_routing.parent_topic_aliases', []);

        return is_array($aliases) ? $aliases : [];
    }

    private function matchesAlias(string $value, string $alias): bool
    {
        if ($value === $alias) {
            return true;
        }

        // Short ASCII abbreviations such as IP / OS / AI must not match
        // arbitrary substrings (e.g. "script"). Longer terms and Japanese
        // labels can safely use phrase containment.
        if (preg_match('/^[a-z0-9.+#-]{1,4}$/', $alias) === 1) {
            $pattern = '/(^|[^a-z0-9])'.preg_quote($alias, '/').'([^a-z0-9]|$)/u';

            return preg_match($pattern, $value) === 1;
        }

        return str_contains($value, $alias);
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(trim(
            preg_replace('/[\s　]+/u', ' ', $value) ?? $value,
        ));
    }
}
