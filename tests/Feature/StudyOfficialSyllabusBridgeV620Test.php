<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Question;
use App\Models\QuestionPack;
use App\Models\Task;
use App\Models\User;
use App\Services\StudyOfficialSyllabusBridgeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StudyOfficialSyllabusBridgeV620Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config([
            'session.driver' => 'array', 'cache.default' => 'array',
            'native_ai.driver' => 'disabled',
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
        ]);
    }

    private function fixture(string $title = '応用情報技術者 科目A 試験対策'): array
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = Plan::create([
            'user_id' => $owner->id, 'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(), 'title' => $title,
            'category' => '資格学習', 'priority' => 3, 'priority_mode' => 'manual',
            'start_date' => today(), 'deadline' => today()->addMonth(),
        ]);
        $task = Task::create([
            'plan_id' => $plan->id, 'title' => 'ネットワーク',
            'estimated_minutes' => 60, 'remaining_minutes' => 60,
            'progress_percent' => 0, 'status' => 'doing',
            'priority' => 2, 'sort_order' => 1, 'activation_cost' => 1,
            'next_action_note' => '元の本人Action',
        ]);
        $pack = QuestionPack::create([
            'slug' => 'v620-synthetic-'.Str::lower(Str::random(12)),
            'title' => 'V620 試験範囲照合用合成問題',
            'exam_code' => 'AP', 'subject' => '科目A',
            'version' => '1', 'status' => 'published',
            'metadata' => ['match_terms' => ['AP']],
        ]);
        $question = Question::create([
            'question_pack_id' => $pack->id, 'external_key' => 'v620-q-one',
            'source_type' => 'canovia_original',
            'prompt' => 'テスト専用ネットワーク問題',
            'response_schema' => [[
                'id' => 'answer', 'type' => 'single_choice', 'label' => '回答',
                'required' => true,
                'choices' => [
                    ['id' => 'A', 'label' => '正答'],
                    ['id' => 'B', 'label' => '誤答'],
                ],
            ]],
            'grading_rule' => [
                'type' => 'exact_choice', 'field_id' => 'answer', 'answer' => 'A',
            ],
            'learning_metadata' => ['concepts' => ['ネットワーク']],
            'explanation' => '試験用説明',
            'is_active' => true, 'sort_order' => 1,
        ]);
        return [$owner, $plan, $task, $pack, $question];
    }

    private function bridge(User $actor, Plan $plan, bool $canEdit = true,
        bool $sampleAvailable = true): array
    {
        $request = Request::create('/workspace/study', 'GET');
        $request->setUserResolver(fn () => $actor);
        return app(StudyOfficialSyllabusBridgeService::class)->forWorkspace(
            $request, $plan, null, $canEdit,
            ['available' => $sampleAvailable]
        );
    }

    private function reviewedManifest(QuestionPack $pack, Question $question): array
    {
        $base = config('study_official_syllabus.ap_subject_a_2026');
        $node = [
            'code' => 'SYNTHETIC-NW-1',
            'label' => '合成ネットワーク項目（公式項目ではない）',
            'source_version' => '7.2',
            'source_page' => 1,
            'review_state' => 'reviewed',
            'reviewer_id' => 'fixture-reviewer',
            'reviewed_at' => '2026-10-15',
        ];
        $link = [
            'scope_code' => $node['code'],
            'source_version' => '7.2',
            'pack_slug' => $pack->slug, 'pack_version' => '1',
            'question_external_key' => $question->external_key,
            'question_snapshot_sha256' =>
                app(StudyOfficialSyllabusBridgeService::class)->snapshotHash($question),
            'review_state' => 'reviewed', 'reviewer_id' => 'fixture-reviewer',
            'reviewed_at' => '2026-10-15',
        ];
        return array_replace($base, [
            'catalog_state' => 'reviewed_partial',
            'scope_nodes' => [$node],
            'question_links' => [$link],
        ]);
    }

    public function test_known_official_v72_source_does_not_become_fabricated_full_coverage(): void
    {
        [$owner, $plan, $task] = $this->fixture();
        $data = $this->bridge($owner, $plan);
        $this->assertTrue($data['available']);
        $this->assertTrue($data['source_identified']);
        $this->assertSame('7.2', $data['source']['version']);
        $this->assertSame('2026-01-08', $data['source']['published_date']);
        $this->assertSame('2026-07-06', $data['source']['label_update_date']);
        $this->assertSame('source_only', $data['index_state']);
        $this->assertSame([], $data['nodes']);
        $this->assertSame(0, $data['reviewed_link_count']);
        $this->assertNull($data['coverage_percent']);
        $this->assertNull($data['coverage_denominator']);
        $this->assertFalse($data['index_complete']);

        $response = $this->actingAs($owner)->get(route('workspace.study.index', [
            'plan_id' => $plan->id, 'surface' => 'preparation',
        ]))->assertOk()
            ->assertSee('data-study-official-syllabus-bridge', false)
            ->assertSee('data-study-syllabus-source-version', false)
            ->assertSee('data-study-syllabus-review-pending', false)
            ->assertSee('data-study-syllabus-no-percentage', false)
            ->assertSee('2027年度以降に向けた別の新制度シラバス案');
        $response->assertDontSee('公式試験範囲のカバー率：100%');
        $this->actingAs($owner)->get(route('workspace.study.index', [
            'plan_id' => $plan->id, 'surface' => 'analysis',
        ]))->assertOk()->assertSee('data-study-official-syllabus-bridge', false);
        $this->actingAs($owner)->get(route('workspace.study.index', [
            'plan_id' => $plan->id, 'surface' => 'work',
        ]))->assertOk()
            ->assertDontSee('data-study-official-syllabus-bridge', false)
            ->assertSee('data-study-work-evidence-links', false);
        $this->actingAs($owner)->get(route('workspace.study.index', [
            'plan_id' => $plan->id, 'surface' => 'analysis',
        ]))->assertOk()
            ->assertDontSee('data-study-official-syllabus-bridge', false);
        $this->assertSame(0, $task->fresh()->progress_percent);
        $this->assertSame('元の本人Action', $task->fresh()->next_action_note);
        $this->assertDatabaseCount('task_evidences', 0);
    }

    public function test_synthetic_reviewed_node_and_exact_question_hash_can_link_but_never_show_percentage(): void
    {
        [$owner, $plan, $task, $pack, $question] = $this->fixture();
        config(['study_official_syllabus.ap_subject_a_2026' =>
            $this->reviewedManifest($pack, $question)]);
        $data = $this->bridge($owner, $plan);
        $this->assertSame('reviewed_partial', $data['index_state']);
        $this->assertSame(1, $data['reviewed_link_count']);
        $this->assertSame(0, $data['rejected_links']);
        $this->assertSame(1, $data['nodes'][0]['linked_question_count']);
        $this->assertNull($data['coverage_percent']);
        $this->actingAs($owner)->get(route('workspace.study.index', [
            'plan_id' => $plan->id, 'surface' => 'preparation',
        ]))->assertOk()->assertSee('data-study-syllabus-partial-index', false)
            ->assertSee('SYNTHETIC-NW-1')->assertSee('算出対象外');
        $question->update(['prompt' => '元の出題から変更した本文']);
        $after = $this->bridge($owner, $plan);
        $this->assertSame(0, $after['reviewed_link_count']);
        $this->assertSame(1, $after['rejected_links']);
    }

    public function test_unknown_version_duplicate_node_unreviewed_link_and_wrong_pack_fail_closed(): void
    {
        [$owner, $plan, $task, $pack, $question] = $this->fixture();
        $valid = $this->reviewedManifest($pack, $question);
        config(['study_official_syllabus.ap_subject_a_2026' =>
            array_replace($valid, ['source_version' => '2027-draft'])]);
        $this->assertFalse($this->bridge($owner, $plan)['source_identified']);
        config(['study_official_syllabus.ap_subject_a_2026' =>
            array_replace($valid, ['scope_nodes' => [$valid['scope_nodes'][0], $valid['scope_nodes'][0]]])]);
        $duplicate = $this->bridge($owner, $plan);
        $this->assertSame('invalid_duplicate_node', $duplicate['index_state']);
        $this->assertSame(0, $duplicate['reviewed_link_count']);
        config(['study_official_syllabus.ap_subject_a_2026' =>
            array_replace($valid, ['question_links' =>
                [array_replace($valid['question_links'][0], ['review_state' => 'pending'])]])]);
        $this->assertSame(0, $this->bridge($owner, $plan)['reviewed_link_count']);
        $pack->update(['status' => 'review']);
        config(['study_official_syllabus.ap_subject_a_2026' => $valid]);
        $this->assertSame(0, $this->bridge($owner, $plan)['reviewed_link_count']);
        $this->assertSame(1, $this->bridge($owner, $plan)['rejected_links']);
        $this->assertNull($this->bridge($owner, $plan)['coverage_percent']);
    }

    public function test_owner_boundary_unrelated_exam_and_no_visible_ap_bank_sample(): void
    {
        [$owner, $plan] = $this->fixture();
        $other = User::factory()->create(['first_run_completed_at' => now()]);
        $this->assertFalse($this->bridge($owner, $plan, false)['available']);
        $this->assertFalse($this->bridge($owner, $plan, true, false)['available']);
        $this->actingAs($other)->get(route('workspace.study.index', [
            'plan_id' => $plan->id, 'surface' => 'preparation',
        ]))->assertNotFound();
        $this->assertFalse($this->bridge($other, $plan, false)['available']);
        [$otherOwner, $sectionB] = $this->fixture('応用情報 科目B');
        $this->actingAs($otherOwner)->get(route('workspace.study.index', [
            'plan_id' => $sectionB->id, 'surface' => 'preparation',
        ]))->assertOk()->assertDontSee('data-study-official-syllabus-bridge', false);
        $this->assertDatabaseCount('task_evidences', 0);
    }
}
