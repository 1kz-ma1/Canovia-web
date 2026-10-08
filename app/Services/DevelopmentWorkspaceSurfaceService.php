<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\PlanArtifact;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class DevelopmentWorkspaceSurfaceService
{
    public function __construct(
        private readonly DevelopmentTeamTaskProjectionService $teamTasks,
    ) {}

    public const SURFACES = [
        'work',
        'repository',
        'roadmap',
        'team',
        'improvements',
        'preview',
    ];

    public function selected(Request $request): string
    {
        $surface = mb_strtolower(trim((string) $request->query(
            'surface',
            'work',
        )));

        return in_array($surface, self::SURFACES, true)
            ? $surface
            : 'work';
    }

    /**
     * Existing surfaces are grouped by lifecycle category so the global
     * navigation can grow without turning into an ever-wider tab row.
     *
     * Future categories/surfaces are not rendered until a real surface exists.
     *
     * @return array<int,array{
     *   key:string,
     *   label:string,
     *   description:string,
     *   category_key:string,
     *   category_label:string,
     *   category_order:int,
     *   surface_order:int
     * }>
     */
    public function tabs(): array
    {
        return [
            [
                'key' => 'work',
                'label' => '今やること',
                'description' => 'Next Actionと現在Task',
                'category_key' => 'execution',
                'category_label' => '実行',
                'category_order' => 10,
                'surface_order' => 10,
            ],
            [
                'key' => 'improvements',
                'label' => '改善',
                'description' => '改善候補と技術的な詰まり',
                'category_key' => 'design',
                'category_label' => '設計',
                'category_order' => 20,
                'surface_order' => 10,
            ],
            [
                'key' => 'repository',
                'label' => 'リポジトリ',
                'description' => '構成・Activity・Release',
                'category_key' => 'project',
                'category_label' => 'プロジェクト',
                'category_order' => 30,
                'surface_order' => 10,
            ],
            [
                'key' => 'roadmap',
                'label' => 'ロードマップ',
                'description' => 'GitHub仕様書から現在の計画を確認',
                'category_key' => 'project',
                'category_label' => 'プロジェクト',
                'category_order' => 30,
                'surface_order' => 15,
            ],
            [
                'key' => 'team',
                'label' => 'チーム',
                'description' => 'メンバー・役割・状態',
                'category_key' => 'project',
                'category_label' => 'プロジェクト',
                'category_order' => 30,
                'surface_order' => 20,
            ],
            [
                'key' => 'preview',
                'label' => 'プレビュー',
                'description' => 'サイト確認',
                'category_key' => 'observation',
                'category_label' => '観測',
                'category_order' => 40,
                'surface_order' => 10,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function team(Plan $plan): array
    {
        $plan->loadMissing([
            'user:id,name,email',
            'memberships.user:id,name,email',
        ]);

        $activities = $plan->activityLogs()
            ->with('user:id,name')
            ->limit(80)
            ->get();

        $artifacts = $plan->artifacts()
            ->with([
                'assignedUser:id,name',
                'tasks:id,title,status,progress_percent',
            ])
            ->whereNotNull('assigned_user_id')
            ->latest('updated_at')
            ->limit(80)
            ->get();

        $members = collect();

        if ($plan->user) {
            $members->push($this->memberProjection(
                $plan,
                (int) $plan->user->id,
                (string) $plan->user->name,
                (string) $plan->user->email,
                'owner',
                $activities,
                $artifacts,
            ));
        }

        foreach ($plan->memberships as $membership) {
            if (! $membership->user) {
                continue;
            }

            $members->push($this->memberProjection(
                $plan,
                (int) $membership->user->id,
                (string) $membership->user->name,
                (string) $membership->user->email,
                (string) $membership->role,
                $activities,
                $artifacts,
            ));
        }

        return [
            'members' => $members->values(),
            'member_count' => $members->count(),
            'is_collaborative' => (bool) $plan->is_collaborative,
            'task_overview' => $this->teamTasks->project($plan),
        ];
    }

    /**
     * @param object|null $adaptive
     * @param Collection<int,mixed> $unresolvedActivity
     * @param Collection<int,mixed> $activeTasks
     * @param array<string,mixed> $githubConnection
     * @return array<int,array<string,mixed>>
     */
    public function improvements(
        mixed $adaptive,
        Collection $unresolvedActivity,
        Collection $activeTasks,
        array $githubConnection,
    ): array {
        $items = collect();
        $connectionState = (string) data_get(
            $githubConnection,
            'state',
            'repository_install',
        );

        if ($connectionState !== 'ready') {
            $items->push([
                'key' => 'github_connection',
                'severity' => $connectionState === 'capability_blocked'
                    ? 'blocked'
                    : 'high',
                'title' => (string) data_get(
                    $githubConnection,
                    'label',
                    'GitHub接続を確認する',
                ),
                'detail' => (string) data_get(
                    $githubConnection,
                    'detail',
                    'Repository接続を確認してください。',
                ),
                'surface' => 'repository',
            ]);
        }

        if ($unresolvedActivity->isNotEmpty()) {
            $items->push([
                'key' => 'unresolved_activity',
                'severity' => 'high',
                'title' => 'GitHub ActivityをTaskへ結ぶ',
                'detail' => $unresolvedActivity->count()
                    .'件のActivityがTaskへ未関連付けです。Evidence判断の前に整理できます。',
                'surface' => 'repository',
            ]);
        }

        $gates = data_get(
            $adaptive?->intelligence?->readiness?->components,
            'gates',
            [],
        );
        $gates = is_array($gates) ? $gates : [];
        $labels = [
            'implementation' => '実装',
            'ci' => 'CI / Test',
            'review' => 'Review',
            'merge' => 'Merge',
            'deploy' => 'Production Deploy',
            'verification' => '実機・本番確認',
            'spec_sync' => '仕様同期',
        ];

        foreach ($gates as $gate => $payload) {
            $status = is_array($payload)
                ? (string) ($payload['status'] ?? 'unknown')
                : (string) $payload;

            if ($status === 'passed') {
                continue;
            }

            $items->push([
                'key' => 'gate_'.$gate,
                'severity' => $status === 'failed' ? 'high' : 'medium',
                'title' => ($labels[$gate] ?? $gate).'を'
                    .($status === 'failed' ? '修正する' : '確認する'),
                'detail' => match ($status) {
                    'failed' => '現在のReleaseで問題が観測されています。',
                    'pending' => '現在のReleaseで確認待ちです。',
                    default => '現在のReleaseではまだ十分なEvidenceがありません。',
                },
                'surface' => 'repository',
            ]);
        }

        $paused = $activeTasks->filter(
            fn ($task) => (string) ($task->status ?? '') === 'paused',
        );

        if ($paused->isNotEmpty()) {
            $items->push([
                'key' => 'paused_tasks',
                'severity' => 'medium',
                'title' => '保留中Taskを見直す',
                'detail' => $paused->count()
                    .'件の開発Taskが保留中です。不要なら整理し、必要なら再開条件を明確にできます。',
                'surface' => 'work',
            ]);
        }

        return $items
            ->unique('key')
            ->take(8)
            ->values()
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    public function preview(Plan $plan): array
    {
        $artifact = $plan->artifacts()
            ->where('external_id', 'canovia:development-preview')
            ->latest('updated_at')
            ->first();

        return [
            'artifact' => $artifact,
            'url' => $artifact instanceof PlanArtifact
                ? $this->safeHttpUrl($artifact->url)
                : null,
            'updated_at' => $artifact?->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param Collection<int,mixed> $activities
     * @param Collection<int,PlanArtifact> $artifacts
     * @return array<string,mixed>
     */
    private function memberProjection(
        Plan $plan,
        int $userId,
        string $name,
        string $email,
        string $role,
        Collection $activities,
        Collection $artifacts,
    ): array {
        $lastActivity = $activities->first(
            fn ($activity) => (int) ($activity->user_id ?? 0) === $userId,
        );
        $assigned = $artifacts
            ->filter(fn (PlanArtifact $artifact) =>
                (int) $artifact->assigned_user_id === $userId
            )
            ->take(5)
            ->values();

        $taskTitles = $assigned
            ->flatMap(fn (PlanArtifact $artifact) =>
                $artifact->tasks->pluck('title')
            )
            ->filter()
            ->unique()
            ->take(4)
            ->values();

        $activityMeta = is_array($lastActivity?->metadata)
            ? $lastActivity->metadata
            : [];
        $activityTitle = $activityMeta['task_title']
            ?? $activityMeta['artifact_title']
            ?? $activityMeta['resource_title']
            ?? $activityMeta['member_name']
            ?? null;

        return [
            'user_id' => $userId,
            'name' => $name !== '' ? $name : 'メンバー',
            'email' => $email,
            'role' => $role,
            'role_label' => match ($role) {
                'owner' => 'オーナー',
                'editor' => '編集者',
                default => '閲覧者',
            },
            'assigned_artifacts' => $assigned->map(
                fn (PlanArtifact $artifact) => [
                    'id' => (int) $artifact->id,
                    'title' => (string) $artifact->title,
                    'state' => $artifact->collaborationStateLabel(),
                    'url' => (string) $artifact->url,
                ],
            )->all(),
            'task_titles' => $taskTitles->all(),
            'last_activity' => $lastActivity ? [
                'action' => (string) $lastActivity->action,
                'target_title' => is_string($activityTitle)
                    ? mb_substr($activityTitle, 0, 200)
                    : null,
                'created_at' => $lastActivity->created_at?->toIso8601String(),
                'relative' => $lastActivity->created_at?->diffForHumans(),
            ] : null,
        ];
    }

    private function safeHttpUrl(mixed $value): ?string
    {
        $url = trim((string) $value);
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $scheme = mb_strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true)
            ? mb_substr($url, 0, 2048)
            : null;
    }
}
