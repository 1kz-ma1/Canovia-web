<?php

namespace App\Services;

final class PersonalizationBootstrapService
{
    /**
     * @param array<string,mixed> $answers
     * @return array<string,mixed>
     */
    public function buildContext(array $answers): array
    {
        $domains = array_values(array_unique(
            (array) ($answers['domains'] ?? []),
        ));

        $study = in_array('study', $domains, true)
            ? [
                'goal' => $this->text($answers['study_goal'] ?? null),
                'kind' => (string) ($answers['study_kind'] ?? 'other'),
                'stage' => (string) ($answers['study_stage'] ?? 'not_started'),
            ]
            : [];

        $development = in_array('development', $domains, true)
            ? [
                'goal' => $this->text($answers['development_goal'] ?? null),
                'experience' => (string) (
                    $answers['development_experience']
                    ?? 'standard'
                ),
                'stage' => (string) (
                    $answers['development_stage']
                    ?? 'new'
                ),
                'github_usage' => (string) (
                    $answers['github_usage']
                    ?? 'unsure'
                ),
                'repository_ready' => (string) (
                    $answers['repository_ready']
                    ?? 'unsure'
                ),
            ]
            : [];

        $guidance = $this->guidanceLevel(
            $domains,
            $study,
            $development,
        );

        $githubEligible = $development !== []
            && ($development['github_usage'] ?? null) === 'yes'
            && ($development['repository_ready'] ?? null) === 'yes';

        return [
            'domains' => $domains,
            'common_context' => [
                'deadline' => $this->text($answers['deadline'] ?? null),
                'weekly_capacity' => (string) (
                    $answers['weekly_capacity']
                    ?? 'unknown'
                ),
            ],
            'domain_context' => [
                'study' => $study,
                'development' => $development,
            ],
            'guidance_level' => $guidance,
            'recommended_surfaces' => array_values(array_filter([
                in_array('study', $domains, true)
                    ? 'workspace.study'
                    : null,
                in_array('development', $domains, true)
                    ? 'workspace.development'
                    : null,
            ])),
            'feature_readiness' => [
                'github' => [
                    'eligible' => $githubEligible,
                    'interest' => null,
                    'reason' => $githubEligible
                        ? 'Development / GitHub / Repository readiness'
                        : 'Not enough readiness signal yet',
                ],
            ],
        ];
    }

    /**
     * @param array<string,mixed> $context
     * @return array<int,array<string,mixed>>
     */
    public function seeds(array $context): array
    {
        $domains = (array) ($context['domains'] ?? []);
        $deadline = $this->text(
            data_get($context, 'common_context.deadline'),
        );
        $seeds = [];

        if (in_array('study', $domains, true)) {
            $kind = (string) data_get(
                $context,
                'domain_context.study.kind',
                'other',
            );
            $goal = $this->text(
                data_get($context, 'domain_context.study.goal'),
            );

            $qualification = $kind === 'qualification';
            $phases = $qualification
                ? [
                    '現在地確認',
                    '弱点探索',
                    '弱点補完',
                    '分野横断演習',
                    '本番形式',
                    '直前調整',
                ]
                : ['範囲確認', '基礎理解', '演習', '復習'];

            $seeds[] = $this->seed(
                key: $qualification
                    ? 'study_qualification'
                    : 'study_general',
                domain: 'study',
                title: $goal ?: (
                    $qualification
                        ? '資格・試験に向けた学習'
                        : '学習を進める'
                ),
                category: '資格学習',
                phases: $phases,
                deadline: $deadline,
            );
        }

        if (in_array('development', $domains, true)) {
            $stage = (string) data_get(
                $context,
                'domain_context.development.stage',
                'new',
            );
            $goal = $this->text(
                data_get($context, 'domain_context.development.goal'),
            );

            $existing = in_array(
                $stage,
                ['existing', 'operating'],
                true,
            );

            $phases = $existing
                ? [
                    'Current State',
                    'Issue整理',
                    '優先順位',
                    '改善',
                    '検証',
                    'Release',
                ]
                : [
                    'Idea',
                    'Requirements',
                    'MVP Scope',
                    'Implementation',
                    'Testing',
                    'Release',
                ];

            $seeds[] = $this->seed(
                key: $existing
                    ? 'development_existing'
                    : 'development_new',
                domain: 'development',
                title: $goal ?: (
                    $existing
                        ? '既存プロダクトを改善する'
                        : '新しいプロダクトを作る'
                ),
                category: '個人開発',
                phases: $phases,
                deadline: $deadline,
            );
        }

        return $seeds;
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>|null
     */
    public function githubPreview(array $context): ?array
    {
        if (! (bool) data_get(
            $context,
            'feature_readiness.github.eligible',
            false,
        )) {
            return null;
        }

        return [
            'key' => 'github_integration',
            'title' => 'GitHubとつなぐと進捗記録を減らせます',
            'steps' => [
                'CanoviaのDevelopment Taskを進める',
                'GitHubでPull Requestをmergeする',
                'CanoviaがRepositoryの事実を進捗Contextへ戻す',
            ],
            'note' => 'Phase 1では接続を強制しません。興味を記録し、Plan作成後の案内に利用します。',
        ];
    }

    /**
     * @param array<string,mixed> $context
     * @return string
     */
    private function guidanceLevel(
        array $domains,
        array $study,
        array $development,
    ): string {
        if (
            in_array('unsure', $domains, true)
            || ($study['stage'] ?? null) === 'not_started'
            || ($development['experience'] ?? null) === 'beginner'
        ) {
            return 'guided';
        }

        if (
            ($development['experience'] ?? null) === 'advanced'
            && ($development['repository_ready'] ?? null) === 'yes'
        ) {
            return 'compact';
        }

        return 'standard';
    }

    /**
     * @param array<int,string> $phases
     * @return array<string,mixed>
     */
    private function seed(
        string $key,
        string $domain,
        string $title,
        string $category,
        array $phases,
        ?string $deadline,
    ): array {
        return [
            'key' => $key,
            'domain' => $domain,
            'workspace_mode' => $domain,
            'title' => $title,
            'category' => $category,
            'deadline' => $deadline,
            'phases' => $phases,
            'description' => "おすすめの初期骨格:\n- "
                .implode("\n- ", $phases),
        ];
    }

    private function text(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }
}
