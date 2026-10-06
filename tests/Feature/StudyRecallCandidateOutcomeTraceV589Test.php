<?php

namespace Tests\Feature;

use App\Enums\EvidenceSource;
use App\Intelligence\Adapters\TaskEvidenceAdapter;
use App\Models\Plan;
use App\Models\StudyRecallCandidate;
use App\Models\StudyRecallItem;
use App\Models\StudyRecallReview;
use App\Models\StudyRecallSource;
use App\Models\Task;
use App\Models\TaskEvidence;
use App\Models\User;
use App\Services\StudyRecallCandidateOutcomeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudyRecallCandidateOutcomeTraceV589Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        config([
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
            'native_ai.driver' => 'disabled',
        ]);
    }

    public function test_review_evidence_keeps_all_promoted_candidate_lineages_without_double_counting_item_outcome(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $item = $this->item($plan, $task, 'abandon', '放棄する');
        $sourceA = $this->source($plan, $task, 'page-a.png');
        $sourceB = $this->source($plan, $task, 'page-b.png');

        $candidateA = $this->candidate(
            $sourceA,
            $plan,
            $task,
            $item,
            'abandon',
            '放棄する',
            95,
        );
        $candidateB = $this->candidate(
            $sourceB,
            $plan,
            $task,
            $item,
            'abandon の意味は？',
            '放棄する',
            72,
        );

        $this->actingAs($user)
            ->post(route(
                'plans.tasks.study_recall.items.review',
                [$plan, $task, $item],
            ), [
                'rating' => 'good',
                'review_request_id' => (string) Str::uuid(),
            ])
            ->assertRedirect(
                route('plans.tasks.study_recall.show', [$plan, $task]),
            );

        $evidence = TaskEvidence::query()
            ->where('task_id', $task->id)
            ->where('type', 'study_recall_reviewed')
            ->firstOrFail();

        $this->assertSame(
            [$candidateA->id, $candidateB->id],
            data_get(
                $evidence->metadata,
                'study_recall_candidate_ids',
            ),
        );
        $this->assertSame(
            [$sourceA->id, $sourceB->id],
            data_get(
                $evidence->metadata,
                'study_recall_source_ids',
            ),
        );
        $this->assertSame(
            [95, 72],
            data_get(
                $evidence->metadata,
                'candidate_confidences',
            ),
        );

        $projection = app(StudyRecallCandidateOutcomeService::class)
            ->project($plan, $task);

        $this->assertSame(
            2,
            data_get(
                $projection,
                'aggregate.promoted_candidate_count',
            ),
        );
        $this->assertSame(
            1,
            data_get(
                $projection,
                'aggregate.promoted_item_count',
            ),
        );
        $this->assertSame(
            1,
            data_get(
                $projection,
                'aggregate.observed_item_count',
            ),
        );
        $this->assertSame(
            1,
            data_get(
                $projection,
                'aggregate.review_count',
            ),
        );
    }

    public function test_manual_recall_card_review_uses_empty_candidate_lineage_arrays(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $item = $this->item(
            $plan,
            $task,
            'manual front',
            'manual back',
        );

        $this->actingAs($user)
            ->post(route(
                'plans.tasks.study_recall.items.review',
                [$plan, $task, $item],
            ), [
                'rating' => 'hard',
                'review_request_id' => (string) Str::uuid(),
            ])
            ->assertRedirect();

        $evidence = TaskEvidence::query()
            ->where('task_id', $task->id)
            ->where('type', 'study_recall_reviewed')
            ->firstOrFail();

        $this->assertSame(
            [],
            data_get(
                $evidence->metadata,
                'study_recall_candidate_ids',
            ),
        );
        $this->assertSame(
            [],
            data_get(
                $evidence->metadata,
                'study_recall_source_ids',
            ),
        );
        $this->assertSame(
            [],
            data_get(
                $evidence->metadata,
                'candidate_confidences',
            ),
        );
    }

    public function test_task_evidence_adapter_normalizes_candidate_lineage_without_raw_candidate_content(): void
    {
        [, $plan, $task] = $this->scenario();

        $evidence = TaskEvidence::query()->create([
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'source' => EvidenceSource::Native,
            'type' => 'study_recall_reviewed',
            'external_key' => 'v589-adapter',
            'confidence' => 0.75,
            'occurred_at' => now(),
            'metadata' => [
                'study_recall_review_id' => 20,
                'study_recall_item_id' => 10,
                'study_recall_candidate_ids' => [2, '3', -4],
                'study_recall_source_ids' => [7, 8],
                'candidate_confidences' => [95, 72, 999],
                'rating' => 'good',
                'repetitions' => 1,
                'lapse_count' => 0,
                'interval_days' => 1,
                'mastered' => false,
                'prompt' => 'raw prompt must not cross',
                'source_excerpt' => 'raw excerpt must not cross',
            ],
        ]);

        $observation = app(TaskEvidenceAdapter::class)
            ->adapt($evidence);

        $this->assertSame(
            [2, 3],
            $observation->facts['study_recall_candidate_ids'],
        );
        $this->assertSame(
            [7, 8],
            $observation->facts['study_recall_source_ids'],
        );
        $this->assertSame(
            [95, 72, 100],
            $observation->facts['candidate_confidences'],
        );
        $this->assertArrayNotHasKey(
            'prompt',
            $observation->facts,
        );
        $this->assertArrayNotHasKey(
            'source_excerpt',
            $observation->facts,
        );
    }

    public function test_outcome_projection_distinguishes_unobserved_developing_reinforcement_and_retained(): void
    {
        [, $plan, $task] = $this->scenario();

        $unobserved = $this->item(
            $plan,
            $task,
            'unobserved',
            'U',
        );
        $developing = $this->item(
            $plan,
            $task,
            'developing',
            'D',
            [
                'repetitions' => 1,
                'interval_days' => 1,
                'last_reviewed_at' => now(),
            ],
        );
        $reinforcement = $this->item(
            $plan,
            $task,
            'reinforcement',
            'R',
            [
                'repetitions' => 0,
                'lapse_count' => 2,
                'interval_days' => 0,
                'last_reviewed_at' => now(),
            ],
        );
        $retained = $this->item(
            $plan,
            $task,
            'retained',
            'T',
            [
                'repetitions' => 3,
                'interval_days' => 7,
                'last_reviewed_at' => now(),
                'due_at' => now()->addDays(7),
            ],
        );

        $candidateUnobserved = $this->candidate(
            $this->source($plan, $task, 'u.png'),
            $plan,
            $task,
            $unobserved,
            'unobserved',
            'U',
            92,
        );
        $candidateDeveloping = $this->candidate(
            $this->source($plan, $task, 'd.png'),
            $plan,
            $task,
            $developing,
            'developing',
            'D',
            78,
        );
        $candidateReinforcement = $this->candidate(
            $this->source($plan, $task, 'r.png'),
            $plan,
            $task,
            $reinforcement,
            'reinforcement',
            'R',
            50,
        );
        $candidateRetained = $this->candidate(
            $this->source($plan, $task, 't.png'),
            $plan,
            $task,
            $retained,
            'retained',
            'T',
            88,
        );

        $this->reviewRow($developing, 'good', 1);
        $this->reviewRow($reinforcement, 'again', 1);
        $this->reviewRow($reinforcement, 'again', 2);
        $this->reviewRow($retained, 'good', 1);
        $this->reviewRow($retained, 'good', 2);
        $this->reviewRow($retained, 'easy', 3);

        $projection = app(StudyRecallCandidateOutcomeService::class)
            ->project($plan, $task);
        $rows = collect($projection['recent_candidates'])
            ->keyBy('candidate_id');

        $this->assertSame(
            'unobserved',
            $rows[$candidateUnobserved->id]['outcome_state'],
        );
        $this->assertSame(
            'developing',
            $rows[$candidateDeveloping->id]['outcome_state'],
        );
        $this->assertSame(
            'needs_reinforcement',
            $rows[$candidateReinforcement->id]['outcome_state'],
        );
        $this->assertSame(
            'retained',
            $rows[$candidateRetained->id]['outcome_state'],
        );

        $this->assertSame(
            4,
            data_get(
                $projection,
                'aggregate.promoted_item_count',
            ),
        );
        $this->assertSame(
            3,
            data_get(
                $projection,
                'aggregate.observed_item_count',
            ),
        );
        $this->assertSame(
            1,
            data_get(
                $projection,
                'aggregate.retained_item_count',
            ),
        );
        $this->assertSame(
            1,
            data_get(
                $projection,
                'aggregate.reinforcement_item_count',
            ),
        );
        $this->assertSame(
            6,
            data_get(
                $projection,
                'aggregate.review_count',
            ),
        );
        $this->assertSame(
            4,
            data_get(
                $projection,
                'aggregate.successful_recall_count',
            ),
        );
        $this->assertSame(
            67,
            data_get(
                $projection,
                'aggregate.self_rated_recall_success_percent',
            ),
        );
        $this->assertSame(
            77,
            data_get(
                $projection,
                'aggregate.average_candidate_confidence',
            ),
        );

        $this->assertSame(
            2,
            data_get(
                $projection,
                'confidence_bands.high.candidate_count',
            ),
        );
        $this->assertSame(
            1,
            data_get(
                $projection,
                'confidence_bands.medium.candidate_count',
            ),
        );
        $this->assertSame(
            1,
            data_get(
                $projection,
                'confidence_bands.low.candidate_count',
            ),
        );
    }

    public function test_recall_page_only_shows_candidate_outcome_section_when_promoted_lineage_exists(): void
    {
        [$user, $plan, $task] = $this->scenario();
        Http::fake();

        $this->actingAs($user)
            ->get(route(
                'plans.tasks.study_recall.show',
                [$plan, $task],
            ))
            ->assertOk()
            ->assertDontSee(
                'data-recall-candidate-outcome',
                false,
            );

        $item = $this->item(
            $plan,
            $task,
            'generated',
            '生成済み',
        );
        $this->candidate(
            $this->source($plan, $task, 'generated.png'),
            $plan,
            $task,
            $item,
            'generated',
            '生成済み',
            91,
        );

        $this->actingAs($user)
            ->get(route(
                'plans.tasks.study_recall.show',
                [$plan, $task],
            ))
            ->assertOk()
            ->assertSee(
                'data-recall-candidate-outcome',
                false,
            )
            ->assertSee('AI候補の実Recall結果')
            ->assertSee(
                'この結果だけでCandidate品質を自動判定・再採点はしません。',
            );

        Http::assertNothingSent();
    }

    private function source(
        Plan $plan,
        Task $task,
        string $name,
    ): StudyRecallSource {
        return StudyRecallSource::query()->create([
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'user_id' => $plan->user_id,
            'source_type' => 'image',
            'original_name' => $name,
            'mime_type' => 'image/png',
            'storage_path' => 'test/'.$name,
            'status' => 'ready',
            'candidate_count' => 1,
        ]);
    }

    private function candidate(
        StudyRecallSource $source,
        Plan $plan,
        Task $task,
        StudyRecallItem $item,
        string $prompt,
        string $answer,
        int $confidence,
    ): StudyRecallCandidate {
        return StudyRecallCandidate::query()->create([
            'study_recall_source_id' => $source->id,
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'promoted_item_id' => $item->id,
            'reviewed_by_user_id' => $plan->user_id,
            'prompt' => $prompt,
            'answer' => $answer,
            'tags' => ['test'],
            'source_excerpt' => $prompt.': '.$answer,
            'confidence' => $confidence,
            'status' => 'promoted',
            'fingerprint' => hash(
                'sha256',
                mb_strtolower($prompt)
                    .'|'
                    .mb_strtolower($answer),
            ),
            'reviewed_at' => now(),
        ]);
    }

    private function item(
        Plan $plan,
        Task $task,
        string $prompt,
        string $answer,
        array $overrides = [],
    ): StudyRecallItem {
        return StudyRecallItem::query()->create(array_merge([
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'prompt' => $prompt,
            'answer' => $answer,
            'tags' => [],
            'fingerprint' => hash(
                'sha256',
                mb_strtolower($prompt)
                    .'|'
                    .mb_strtolower($answer),
            ),
            'repetitions' => 0,
            'lapse_count' => 0,
            'interval_days' => 0,
            'ease_factor' => 2.50,
            'due_at' => null,
            'last_reviewed_at' => null,
            'is_active' => true,
        ], $overrides));
    }

    private function reviewRow(
        StudyRecallItem $item,
        string $rating,
        int $sequence,
    ): StudyRecallReview {
        return StudyRecallReview::query()->create([
            'study_recall_item_id' => $item->id,
            'plan_id' => $item->plan_id,
            'task_id' => $item->task_id,
            'user_id' => $item->plan?->user_id,
            'review_request_id' => (string) Str::uuid(),
            'rating' => $rating,
            'interval_before_days' => 0,
            'interval_after_days' => 1,
            'ease_before' => 2.50,
            'ease_after' => 2.50,
            'due_before' => null,
            'due_after' => now()->addDay(),
            'reviewed_at' => now()
                ->subMinutes(10 - $sequence),
        ]);
    }

    private function scenario(): array
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'TOEIC Recall',
            'description' => 'AI候補と実Recall結果を観測する',
            'category' => '資格学習',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);

        $task = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => '英単語を暗記する',
            'description' => '頻出語彙をRecallで定着させる',
            'estimated_minutes' => 60,
            'remaining_minutes' => 60,
            'progress_percent' => 0,
            'status' => 'todo',
            'priority' => 1,
            'activation_cost' => 2,
            'sort_order' => 1,
        ]);

        return [$user, $plan, $task];
    }
}
