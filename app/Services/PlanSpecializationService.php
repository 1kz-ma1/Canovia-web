<?php

namespace App\Services;

use App\Models\Plan;

/**
 * The subject of a Plan is independent from its Workspace Domain and whether
 * people collaborate. Existing categories remain legacy compatibility data.
 */
final class PlanSpecializationService
{
    public const OPTIONS = [
        'study' => [
            'bookkeeping' => '簿記',
            'certification' => '資格試験',
            'school_test' => '学校のテスト',
            'skill_learning' => 'スキル学習',
        ],
        'development' => [
            'software_development' => 'ソフトウェア・システム開発',
            'web_development' => 'Webサービス開発',
            'game_development' => 'ゲーム開発',
        ],
        'career' => [
            'job_search' => '就職・転職活動',
            'occupation_exploration' => '職種探索・自己分析',
        ],
        'creative' => [
            'creative_work' => '作品・制作活動',
        ],
        'general' => [],
    ];

    public function __construct(
        private readonly PlanCategoryProfileService $profiles,
        private readonly PlanIntentClassificationService $intent,
    ) {}

    /** @return array<string,string> */
    public function optionsForDomain(string $domain): array
    {
        return self::OPTIONS[$domain] ?? [];
    }

    /**
     * @return array{domain:string,key:?string,label:?string,source:string,allowed:array<string,string>}
     */
    public function forPlan(Plan $plan): array
    {
        $domain = $this->profiles->forPlan($plan)->key;
        $options = $this->optionsForDomain($domain);
        $explicit = (string) ($plan->workspace_specialization_override ?? '');

        if ($explicit !== '' && array_key_exists($explicit, $options)) {
            return [
                'domain' => $domain,
                'key' => $explicit,
                'label' => $options[$explicit],
                'source' => 'owner_confirmed',
                'allowed' => $options,
            ];
        }

        $suggestion = $this->intent->suggest(
            (string) $plan->title,
            $plan->description,
        );
        $candidate = (string) ($suggestion['specialization'] ?? '');
        $matched = ($suggestion['domain'] ?? null) === $domain
            && array_key_exists($candidate, $options);

        return [
            'domain' => $domain,
            'key' => $matched ? $candidate : null,
            'label' => $matched ? $options[$candidate] : null,
            'source' => $matched ? 'inferred' : 'unconfirmed',
            'allowed' => $options,
        ];
    }
}
