<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\StudyScoreObservation;
use App\Models\Task;
use App\Models\User;
use App\Services\BookkeepingJournalEntryGrader;
use App\Services\BookkeepingJournalPracticeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BookkeepingJournalPracticeV5862Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config([
            'session.driver' => 'array',
            'native_ai.driver' => 'disabled',
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
        ]);
    }

    public function test_bookkeeping_study_plan_shows_direct_journal_form_with_ungraded_questions(): void
    {
        [$user, $plan] = $this->scenario();

        $this->actingAs($user)
            ->get(route('workspace.study.index', [
                'plan_id' => $plan->id,
                'surface' => 'preparation',
            ]))
            ->assertOk()
            ->assertSee('data-study-bookkeeping-journal-link', false)
            ->assertSee(route('plans.bookkeeping_journal.show', $plan), false);

        $this->actingAs($user)
            ->get(route('plans.bookkeeping_journal.show', $plan))
            ->assertOk()
            ->assertSee('data-bookkeeping-journal-practice', false)
            ->assertSee('data-bookkeeping-journal-form', false)
            ->assertSee('name="answers[j01][0][side]"', false)
            ->assertSee('name="answers[j02][2][amount]"', false)
            ->assertSee('売掛金')
            ->assertDontSee('data-bookkeeping-journal-result', false);

        $this->assertDatabaseCount('study_score_observations', 0);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_multi_line_journal_is_graded_and_saved_without_changing_task_progress(): void
    {
        [$user, $plan] = $this->scenario();
        $task = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => '3級の復習',
            'status' => 'doing',
            'estimated_minutes' => 60,
            'remaining_minutes' => 40,
            'progress_percent' => 20,
            'priority' => 1,
            'activation_cost' => 1,
            'sort_order' => 1,
        ]);

        $answers = $this->correctAnswers();
        // A single amount error makes debits and credits unbalanced.
        $answers['j04'][1]['amount'] = 9000;
        $requestId = (string) Str::uuid();

        $this->actingAs($user)
            ->post(route('plans.bookkeeping_journal.store', $plan), [
                'request_id' => $requestId,
                'answers' => $answers,
            ])
            ->assertRedirect(route('plans.bookkeeping_journal.show', $plan));

        $observation = StudyScoreObservation::query()->sole();
        $this->assertSame($plan->id, $observation->plan_id);
        $this->assertSame($user->id, $observation->user_id);
        $this->assertSame(BookkeepingJournalPracticeService::METRIC, $observation->metric_key);
        $this->assertSame(BookkeepingJournalPracticeService::SOURCE, $observation->source_label);
        $this->assertSame(75.0, $observation->score_value);
        $this->assertSame('unbalanced', $observation->components['result']['details'][3]['error_type']);
        $this->assertSame(['fixed_assets'], $observation->components['result']['weak_topics']);

        $this->actingAs($user)
            ->get(route('plans.bookkeeping_journal.show', $plan))
            ->assertOk()
            ->assertSee('data-bookkeeping-journal-result', false)
            ->assertSee('3 / 4問正解')
            ->assertSee('data-bookkeeping-journal-feedback="j04"', false)
            ->assertSee('data-bookkeeping-journal-error="unbalanced"', false)
            ->assertSee('勘定科目')
            ->assertSee('あなたの回答')
            ->assertSee('正解例')
            ->assertSee('固定資産・未払金を復習し');

        $this->assertSame(20, $task->fresh()->progress_percent);
        $this->assertDatabaseCount('task_evidences', 0);

        // Safari/PWA may resend exactly the same POST; it must not duplicate.
        $this->actingAs($user)
            ->post(route('plans.bookkeeping_journal.store', $plan), [
                'request_id' => $requestId,
                'answers' => $answers,
            ])
            ->assertRedirect(route('plans.bookkeeping_journal.show', $plan));
        $this->assertDatabaseCount('study_score_observations', 1);
    }

    public function test_internal_journal_score_is_not_reported_as_external_exam_baseline(): void
    {
        [$user, $plan] = $this->scenario();
        $this->actingAs($user)
            ->post(route('plans.bookkeeping_journal.store', $plan), [
                'request_id' => (string) Str::uuid(),
                'answers' => $this->correctAnswers(),
            ])
            ->assertRedirect();

        $observation = StudyScoreObservation::query()->sole();
        $this->assertSame(100.0, $observation->score_value);

        $this->actingAs($user)
            ->get(route('plans.study_scores.index', $plan))
            ->assertOk()
            ->assertDontSee('data-study-score-observation="'.$observation->id.'"', false)
            ->assertSee('まだ外部スコアEvidenceはありません。');

        $this->actingAs($user)
            ->get(route('workspace.study.index', [
                'plan_id' => $plan->id,
                'surface' => 'preparation',
            ]))
            ->assertOk()
            ->assertDontSee('簿記仕訳入力演習');
    }

    public function test_journal_entry_grader_accepts_split_rows_and_order_independent_equivalent_entry(): void
    {
        $grader = app(BookkeepingJournalEntryGrader::class);
        $expected = [
            ['side' => 'debit', 'account' => '現金', 'amount' => 8000],
            ['side' => 'debit', 'account' => '売掛金', 'amount' => 12000],
            ['side' => 'credit', 'account' => '売上', 'amount' => 20000],
        ];
        $split = [
            ['side' => 'credit', 'account' => '売上', 'amount' => '20000'],
            ['side' => 'debit', 'account' => '現金', 'amount' => '3000'],
            ['side' => 'debit', 'account' => '売掛金', 'amount' => '12000'],
            ['side' => 'debit', 'account' => '現金', 'amount' => '5000'],
        ];

        $result = $grader->grade($split, $expected, BookkeepingJournalPracticeService::ACCOUNTS);
        $this->assertTrue($result['correct']);
        $this->assertSame('none', $result['error_type']);
    }

    public function test_journal_entry_grader_distinguishes_wrong_side_account_amount_and_balance(): void
    {
        $grader = app(BookkeepingJournalEntryGrader::class);
        $accounts = BookkeepingJournalPracticeService::ACCOUNTS;
        $expected = [
            ['side' => 'debit', 'account' => '仕入', 'amount' => 10000],
            ['side' => 'credit', 'account' => '現金', 'amount' => 10000],
        ];

        $this->assertSame('side_mismatch', $grader->grade([
            ['side' => 'credit', 'account' => '仕入', 'amount' => 10000],
            ['side' => 'debit', 'account' => '現金', 'amount' => 10000],
        ], $expected, $accounts)['error_type']);

        $this->assertSame('account_mismatch', $grader->grade([
            ['side' => 'debit', 'account' => '備品', 'amount' => 10000],
            ['side' => 'credit', 'account' => '現金', 'amount' => 10000],
        ], $expected, $accounts)['error_type']);

        $this->assertSame('unbalanced', $grader->grade([
            ['side' => 'debit', 'account' => '仕入', 'amount' => 10000],
            ['side' => 'credit', 'account' => '現金', 'amount' => 9000],
        ], $expected, $accounts)['error_type']);

        $expectedComposite = [
            ['side' => 'debit', 'account' => '現金', 'amount' => 8000],
            ['side' => 'debit', 'account' => '売掛金', 'amount' => 12000],
            ['side' => 'credit', 'account' => '売上', 'amount' => 20000],
        ];
        $this->assertSame('amount_mismatch', $grader->grade([
            ['side' => 'debit', 'account' => '現金', 'amount' => 5000],
            ['side' => 'debit', 'account' => '売掛金', 'amount' => 15000],
            ['side' => 'credit', 'account' => '売上', 'amount' => 20000],
        ], $expectedComposite, $accounts)['error_type']);
    }

    public function test_incomplete_unknown_or_overlong_entries_cannot_be_saved(): void
    {
        [$user, $plan] = $this->scenario();
        $partial = $this->correctAnswers();
        $partial['j02'][1]['amount'] = '';

        $this->actingAs($user)
            ->post(route('plans.bookkeeping_journal.store', $plan), [
                'request_id' => (string) Str::uuid(),
                'answers' => $partial,
            ])
            ->assertSessionHasErrors('answers.j02');

        $invalid = $this->correctAnswers();
        $invalid['j02'][1]['account'] = '偽の勘定科目';
        $this->actingAs($user)
            ->post(route('plans.bookkeeping_journal.store', $plan), [
                'request_id' => (string) Str::uuid(),
                'answers' => $invalid,
            ])
            ->assertSessionHasErrors('answers.j02.1.account');

        $overlong = $this->correctAnswers();
        $overlong['j01'] = array_merge($overlong['j01'], array_fill(0, 3, [
            'side' => 'credit',
            'account' => '現金',
            'amount' => 1,
        ]));
        $this->actingAs($user)
            ->post(route('plans.bookkeeping_journal.store', $plan), [
                'request_id' => (string) Str::uuid(),
                'answers' => $overlong,
            ])
            ->assertSessionHasErrors('answers.j01');

        $this->assertDatabaseCount('study_score_observations', 0);
    }

    public function test_owner_scope_and_cross_plan_request_ids_are_enforced(): void
    {
        [$owner, $plan] = $this->scenario();
        $other = User::factory()->create();
        $another = $this->plan($owner, '簿記2級も進める', '資格学習');
        $notBookkeeping = $this->plan($owner, '応用情報技術者対策', '資格学習');
        $notStudy = $this->plan($owner, '簿記支援アプリ開発', '個人開発');
        $payload = ['request_id' => (string) Str::uuid(), 'answers' => $this->correctAnswers()];

        $this->actingAs($other)
            ->get(route('plans.bookkeeping_journal.show', $plan))
            ->assertForbidden();
        $this->actingAs($other)
            ->post(route('plans.bookkeeping_journal.store', $plan), $payload)
            ->assertForbidden();
        $this->actingAs($owner)
            ->get(route('plans.bookkeeping_journal.show', $notBookkeeping))
            ->assertNotFound();
        $this->actingAs($owner)
            ->post(route('plans.bookkeeping_journal.store', $notStudy), $payload)
            ->assertNotFound();

        $this->actingAs($owner)
            ->post(route('plans.bookkeeping_journal.store', $plan), $payload)
            ->assertRedirect();
        $this->actingAs($owner)
            ->post(route('plans.bookkeeping_journal.store', $another), $payload)
            ->assertStatus(409);

        $this->assertDatabaseCount('study_score_observations', 1);
        $this->assertSame($plan->id, StudyScoreObservation::query()->sole()->plan_id);
    }

    /** @return array{0:User,1:Plan} */
    private function scenario(): array
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);

        return [$user, $this->plan($user, '簿記3級を復習しながら2級も先取りする', '資格学習')];
    }

    private function plan(User $user, string $title, string $category): Plan
    {
        return Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => $title,
            'description' => $title,
            'category' => $category,
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addDays(45),
            'is_public' => false,
        ]);
    }

    /** @return array<string,array<int,array<string,mixed>>> */
    private function correctAnswers(): array
    {
        return [
            'j01' => [
                ['side' => 'debit', 'account' => '仕入', 'amount' => 10000],
                ['side' => 'credit', 'account' => '現金', 'amount' => 10000],
            ],
            'j02' => [
                ['side' => 'debit', 'account' => '現金', 'amount' => 8000],
                ['side' => 'debit', 'account' => '売掛金', 'amount' => 12000],
                ['side' => 'credit', 'account' => '売上', 'amount' => 20000],
            ],
            'j03' => [
                ['side' => 'debit', 'account' => '借入金', 'amount' => 20000],
                ['side' => 'debit', 'account' => '支払利息', 'amount' => 500],
                ['side' => 'credit', 'account' => '現金', 'amount' => 20500],
            ],
            'j04' => [
                ['side' => 'debit', 'account' => '備品', 'amount' => 30000],
                ['side' => 'credit', 'account' => '現金', 'amount' => 10000],
                ['side' => 'credit', 'account' => '未払金', 'amount' => 20000],
            ],
        ];
    }
}
