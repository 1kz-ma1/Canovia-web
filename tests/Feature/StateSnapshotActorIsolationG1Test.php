<?php

namespace Tests\Feature;

use App\Intelligence\Data\StateSnapshot;
use App\Intelligence\Enums\IntelligenceDomain;
use App\Intelligence\Presentation\IntelligencePresentationHistoryService;
use App\Intelligence\Presentation\IntelligenceStateChangeFeedbackService;
use App\Intelligence\Services\StateSnapshotStore;
use App\Models\Plan;
use App\Models\PlanMember;
use App\Models\User;
use App\Services\PlanOwnershipService;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class StateSnapshotActorIsolationG1Test extends TestCase
{
    use RefreshDatabase;

    public function test_foreign_actor_cannot_read_snapshot_history_or_feedback(): void
    {
        $owner = User::factory()->create();
        $foreign = User::factory()->create();
        $plan = $this->plan($owner);
        $stored = $this->store()->persist($this->snapshot($plan, 'owner only'), $owner->id, $plan->id);

        $this->assertSame(
            $stored->id,
            $this->store()->latestForPlan(
                $this->actor($owner), $plan, IntelligenceDomain::Study, 'study_plan',
            )?->id,
        );

        $this->assertForbidden(function () use ($foreign, $plan) {
            $this->store()->latestForPlan(
                $this->actor($foreign), $plan, IntelligenceDomain::Study, 'study_plan',
            );
        });
        $this->assertForbidden(function () use ($foreign, $plan) {
            app(IntelligencePresentationHistoryService::class)->forPlan(
                $this->actor($foreign), $plan, IntelligenceDomain::Study,
            );
        });
        $this->assertForbidden(function () use ($foreign, $plan) {
            app(IntelligenceStateChangeFeedbackService::class)->latestForPlan(
                $this->actor($foreign), $plan, IntelligenceDomain::Study,
            );
        });
    }

    public function test_same_actor_plan_scopes_cannot_be_interchanged(): void
    {
        $owner = User::factory()->create();
        $one = $this->plan($owner);
        $two = $this->plan($owner);

        $oneRow = $this->store()->persist($this->snapshot($one, 'first plan'), $owner->id, $one->id);
        $twoRow = $this->store()->persist($this->snapshot($two, 'second plan'), $owner->id, $two->id);

        $this->assertNotSame($oneRow->id, $twoRow->id);
        $this->assertSame(
            $oneRow->id,
            $this->store()->latestForPlan(
                $this->actor($owner), $one, IntelligenceDomain::Study, 'study_plan',
            )?->id,
        );
        $this->assertSame(
            $twoRow->id,
            $this->store()->latestForPlan(
                $this->actor($owner), $two, IntelligenceDomain::Study, 'study_plan',
            )?->id,
        );
        $this->assertNull(
            $this->store()->latestForPlan(
                $this->actor($owner), $one, IntelligenceDomain::Study, 'career_plan',
            ),
        );
    }

    public function test_collaborative_viewer_can_read_then_is_blocked_after_role_revocation(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $plan = $this->plan($owner, collaborative: true);
        $stored = $this->store()->persist($this->snapshot($plan, 'team visible'), $owner->id, $plan->id);

        $membership = PlanMember::query()->create([
            'plan_id' => $plan->id,
            'user_id' => $viewer->id,
            'role' => PlanMember::ROLE_VIEWER,
            'invited_by_user_id' => $owner->id,
            'joined_at' => now(),
        ]);

        $this->assertSame(
            $stored->id,
            $this->store()->latestForPlan(
                $this->actor($viewer), $plan, IntelligenceDomain::Study, 'study_plan',
            )?->id,
        );
        $history = app(IntelligencePresentationHistoryService::class)->forPlan(
            $this->actor($viewer), $plan, IntelligenceDomain::Study,
        );
        $this->assertSame($stored->id, $history['states']->first()?->id);

        $membership->delete();

        // New request + new PlanOwnershipService: no cached membership role.
        $this->assertForbidden(function () use ($viewer, $plan) {
            $this->store()->latestForPlan(
                $this->actor($viewer), $plan, IntelligenceDomain::Study, 'study_plan',
            );
        });
        $this->assertForbidden(function () use ($viewer, $plan) {
            (new IntelligencePresentationHistoryService(new PlanOwnershipService()))->forPlan(
                $this->actor($viewer), $plan, IntelligenceDomain::Study,
            );
        });
    }

    public function test_guest_owner_cookie_is_required_for_guest_plan_snapshot_read(): void
    {
        $plan = $this->plan(null);
        $stored = $this->store()->persist($this->snapshot($plan, 'guest private'), null, $plan->id);

        $this->assertForbidden(function () use ($plan) {
            $this->store()->latestForPlan(
                $this->actor(null), $plan, IntelligenceDomain::Study, 'study_plan',
            );
        });
        $this->assertSame(
            $stored->id,
            $this->store()->latestForPlan(
                $this->actor(null, [
                    'pace_keeper_owner_token_'.$plan->id => $plan->owner_token,
                ]),
                $plan,
                IntelligenceDomain::Study,
                'study_plan',
            )?->id,
        );
    }

    public function test_plan_free_identical_snapshot_reference_cannot_reuse_foreign_actor_row(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $state = new StateSnapshot(
            domain: IntelligenceDomain::Study,
            scopeType: 'actor',
            scopeId: null,
            capturedAt: new DateTimeImmutable('2026-10-12T10:00:00+09:00'),
            metrics: ['evidence_count' => 1],
            facts: ['private' => 'not-for-second-user'],
        );

        $original = $this->store()->persist($state, $first->id);
        $this->expectException(InvalidArgumentException::class);
        try {
            $this->store()->persist($state, $second->id);
        } finally {
            $this->assertSame($first->id, (int) $original->fresh()->user_id);
            $this->assertDatabaseCount('intelligence_state_snapshots', 1);
        }
    }

    private function store(): StateSnapshotStore
    {
        return new StateSnapshotStore(new PlanOwnershipService());
    }

    private function snapshot(Plan $plan, string $private): StateSnapshot
    {
        return new StateSnapshot(
            domain: IntelligenceDomain::Study,
            scopeType: 'study_plan',
            scopeId: $plan->id,
            capturedAt: new DateTimeImmutable('2026-10-12T10:00:00+09:00'),
            metrics: ['evidence_count' => 1],
            facts: ['private' => $private],
        );
    }

    private function actor(?User $user, array $cookies = []): Request
    {
        $request = Request::create('/', 'GET', [], $cookies);
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    private function plan(?User $owner, bool $collaborative = false): Plan
    {
        return Plan::query()->create([
            'user_id' => $owner?->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'Snapshot A01/A02 security gate',
            'category' => '資格学習',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
            'is_collaborative' => $collaborative,
        ]);
    }

    private function assertForbidden(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Foreign or revoked actor must not read private Plan intelligence.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }
}
