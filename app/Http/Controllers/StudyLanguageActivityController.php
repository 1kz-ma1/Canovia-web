<?php

namespace App\Http\Controllers;

use App\Enums\EvidenceSource;
use App\Models\Plan;
use App\Models\Task;
use App\Models\TaskEvidence;
use App\Services\BehaviorIdentityService;
use App\Services\PlanCategoryProfileService;
use App\Services\PlanOwnershipService;
use App\Services\StudyActivityPolicyService;
use App\Services\TaskEvidenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class StudyLanguageActivityController extends Controller
{
    private const ACTIVITIES = [
        StudyActivityPolicyService::LISTENING,
        StudyActivityPolicyService::DICTATION,
        StudyActivityPolicyService::SHADOWING,
    ];

    public function __construct(
        private readonly PlanCategoryProfileService $categoryProfiles,
    ) {}

    public function show(
        Request $request,
        Plan $plan,
        Task $task,
        string $activity,
        PlanOwnershipService $ownership,
        StudyActivityPolicyService $activities,
    ) {
        $this->authorizeTask(
            $request,
            $plan,
            $task,
            $ownership,
        );
        $definition = $this->definition(
            $activities,
            $plan,
            $task,
            $activity,
        );

        $plan->loadMissing('resources');
        $task->loadMissing('resources');

        $resources = $task->resources->isNotEmpty()
            ? $task->resources
            : $plan->resources;

        $recentEvidence = $task->evidences()
            ->where(
                'type',
                'study_language_activity_completed',
            )
            ->take(30)
            ->get()
            ->filter(
                fn (TaskEvidence $evidence) =>
                    data_get(
                        $evidence->metadata,
                        'activity_type',
                    ) === $activity,
            )
            ->take(5)
            ->values();

        return view('study_language.show', [
            'plan' => $plan,
            'task' => $task,
            'activityKey' => $activity,
            'definition' => $definition,
            'guidance' => $this->guidance($activity),
            'resources' => $resources,
            'recentEvidence' => $recentEvidence,
            'requestUuid' => (string) Str::uuid(),
        ]);
    }

    public function store(
        Request $request,
        Plan $plan,
        Task $task,
        string $activity,
        PlanOwnershipService $ownership,
        StudyActivityPolicyService $activities,
        BehaviorIdentityService $identity,
        TaskEvidenceService $evidence,
    ) {
        $this->authorizeTask(
            $request,
            $plan,
            $task,
            $ownership,
        );
        $this->definition(
            $activities,
            $plan,
            $task,
            $activity,
        );

        $validated = $request->validate([
            'request_uuid' => [
                'required',
                'uuid',
            ],
            'rounds' => [
                'required',
                'integer',
                'min:1',
                'max:20',
            ],
            'outcome_rating' => [
                'required',
                Rule::in([
                    'struggled',
                    'partial',
                    'comfortable',
                ]),
            ],
            'reflection' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ]);

        $actorToken = $request->user()
            ? null
            : $identity->resolve($request);

        $taskResourceCount = $task
            ->resources()
            ->count();
        $resourceCount = $taskResourceCount > 0
            ? $taskResourceCount
            : $plan->resources()->count();

        $evidence->record(
            $task,
            EvidenceSource::Native,
            'study_language_activity_completed',
            [
                'activity_type' => $activity,
                'rounds' => (int) $validated['rounds'],
                'outcome_rating' =>
                    (string) $validated['outcome_rating'],
                'resource_count' => $resourceCount,
                'reflection' => filled(
                    $validated['reflection'] ?? null,
                )
                    ? mb_substr(
                        trim(
                            (string) $validated['reflection'],
                        ),
                        0,
                        2000,
                    )
                    : null,
            ],
            confidence: 0.6,
            externalKey:
                'study-language:'
                .$activity
                .':'
                .$validated['request_uuid'],
            userId: $request->user()?->id,
            actorToken: $actorToken,
            occurredAt: now(),
        );

        return redirect()
            ->route(
                'plans.tasks.study_language.show',
                [
                    $plan,
                    $task,
                    'activity' => $activity,
                ],
            )
            ->with(
                'success',
                $this->activityLabel($activity)
                .'の実施結果を記録しました。Task進捗は自動変更していません。',
            );
    }

    private function authorizeTask(
        Request $request,
        Plan $plan,
        Task $task,
        PlanOwnershipService $ownership,
    ): void {
        abort_unless(
            (int) $task->plan_id === (int) $plan->id,
            404,
        );
        abort_unless(
            $this->categoryProfiles->forPlan($plan)->key
                === 'study',
            404,
        );

        $ownership->authorizeTask($request, $task);
    }

    /**
     * @return array<string,mixed>
     */
    private function definition(
        StudyActivityPolicyService $activities,
        Plan $plan,
        Task $task,
        string $activity,
    ): array {
        abort_unless(
            in_array($activity, self::ACTIVITIES, true),
            404,
        );

        $definition = collect(
            $activities->forPlanTask($plan, $task)['all']
                ?? [],
        )->firstWhere('key', $activity);

        abort_unless(is_array($definition), 404);

        return $definition;
    }

    /**
     * @return array<int,array{title:string,description:string}>
     */
    private function guidance(string $activity): array
    {
        return match ($activity) {
            StudyActivityPolicyService::DICTATION => [
                [
                    'title' => '短く聞く',
                    'description' =>
                        'まず1文や短い区間だけを聞きます。最初から長時間の音声を全部書こうとしません。',
                ],
                [
                    'title' => '聞こえたまま書く',
                    'description' =>
                        '推測で補わず、聞こえた語・音だけを書き出します。分からない箇所は空けて構いません。',
                ],
                [
                    'title' => '教材と比較する',
                    'description' =>
                        'transcriptや教材と比べ、音の連結・弱形・知らない語のどこで落としたかを確認します。',
                ],
                [
                    'title' => '難しい区間だけ再実施',
                    'description' =>
                        '全文を繰り返すより、聞き落とした短い区間だけを再度書き取ります。',
                ],
            ],
            StudyActivityPolicyService::SHADOWING => [
                [
                    'title' => '最初は聞くだけ',
                    'description' =>
                        '意味だけでなく、話者のリズム・強勢・間の取り方を一度確認します。',
                ],
                [
                    'title' => '少し遅れて追う',
                    'description' =>
                        '音声の直後を追って発話します。単語を読むより、聞こえた音のまとまりを再現します。',
                ],
                [
                    'title' => '難しい区間を分割',
                    'description' =>
                        '追えない場所は短く区切り、音のつながりや速度を確認してから再度つなげます。',
                ],
                [
                    'title' => '安定するまで数周',
                    'description' =>
                        '完璧な発音採点ではなく、同じタイミングで自然に追える感触を目安に反復します。',
                ],
            ],
            default => [
                [
                    'title' => '最初は文字を見ずに聞く',
                    'description' =>
                        'transcriptや答えを先に見ず、まず音だけで主旨・話題・聞き取れる範囲を確認します。',
                ],
                [
                    'title' => '聞けなかった箇所を特定',
                    'description' =>
                        '全部を何度も再生するのではなく、意味が途切れた場所や聞き取れなかった表現を絞ります。',
                ],
                [
                    'title' => '難しい区間だけ再生',
                    'description' =>
                        '対象区間を繰り返し、知識不足なのか音の変化で聞けないのかを切り分けます。',
                ],
                [
                    'title' => '最後に教材と照合',
                    'description' =>
                        '必要ならtranscript・解説を開き、自分の理解との差を確認してからもう一度聞きます。',
                ],
            ],
        };
    }

    private function activityLabel(string $activity): string
    {
        return match ($activity) {
            StudyActivityPolicyService::DICTATION =>
                'Dictation',
            StudyActivityPolicyService::SHADOWING =>
                'Shadowing',
            default => 'Listening',
        };
    }
}
