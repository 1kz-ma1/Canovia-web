<?php

namespace Tests\Feature;

use App\Enums\BehaviorEventType;
use App\Models\BehaviorEvent;
use App\Models\DevelopmentActivityObservation;
use App\Models\Plan;
use App\Models\PlanArtifact;
use App\Models\User;
use App\Models\UserPersonalizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CandidateEvidenceReopenV5832Test extends TestCase
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

    public function test_dismissed_candidate_stays_dismissed_until_signal_strength_increases(): void
    {
        [$user, $plan, $firstRepository] = $this->scenario();

        $this->actingAs($user)
            ->post(route('personalization.updates.refresh'))
            ->assertRedirect(route('personalization.updates.index'));

        $candidate = $this->candidate($user);

        $this->assertSame('pending', data_get($candidate, 'status'));
        $this->assertSame(1, data_get($candidate, 'signal_strength'));
        $this->assertSame(1, data_get($candidate, 'evidence_revision'));
        $this->assertSame('low', data_get($candidate, 'confidence'));
        $this->assertSame(1, data_get($candidate, 'confidence_calibration.version'));
        $this->assertSame(1, data_get($candidate, 'confidence_calibration.signal_strength'));
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            (string) data_get($candidate, 'evidence_fingerprint'),
        );

        $this->actingAs($user)
            ->post(route('personalization.updates.dismiss', [
                'candidateKey' => 'development_advanced_support',
            ]))
            ->assertRedirect(route('personalization.updates.index'));

        $dismissed = $this->candidate($user);

        $this->assertSame('dismissed', data_get($dismissed, 'status'));
        $this->assertSame(
            1,
            data_get($dismissed, 'dismissed_signal_strength'),
        );

        $this->actingAs($user)
            ->post(route('personalization.updates.refresh'))
            ->assertRedirect(route('personalization.updates.index'));

        $sameEvidence = $this->candidate($user);

        $this->assertSame(
            'dismissed',
            data_get($sameEvidence, 'status'),
        );
        $this->assertSame(
            1,
            $this->candidateCreatedEvents()->count(),
        );

        $this->repository(
            $plan,
            $user,
            'https://github.com/example/second-repo',
        );

        $this->actingAs($user)
            ->post(route('personalization.updates.refresh'))
            ->assertRedirect(route('personalization.updates.index'));

        $reopened = $this->candidate($user);

        $this->assertSame('pending', data_get($reopened, 'status'));
        $this->assertSame(2, data_get($reopened, 'signal_strength'));
        $this->assertSame(2, data_get($reopened, 'evidence_revision'));
        $this->assertSame('medium', data_get($reopened, 'confidence'));
        $this->assertSame(2, data_get($reopened, 'confidence_calibration.signal_strength'));
        $this->assertSame(
            'stronger_evidence',
            data_get($reopened, 'reopen_reason'),
        );
        $this->assertNotNull(data_get($reopened, 'reopened_at'));
        $this->assertContains(
            'multiple_connected_repositories',
            data_get($reopened, 'evidence', []),
        );

        $context = $this->context($user);

        $this->assertSame(
            2,
            data_get(
                $context->observed_context,
                'development_behavior.connected_repository_count',
            ),
        );
        $this->assertSame(
            'beginner',
            data_get(
                $context->self_reported_context,
                'domain_context.development.experience',
            ),
        );
        $this->assertSame('guided', $context->guidance_level);

        $this->actingAs($user)
            ->get(route('personalization.updates.index'))
            ->assertOk()
            ->assertSee(
                'data-candidate-reopened="stronger_evidence"',
                false,
            )
            ->assertSee('より強い観測事実が増えたため再確認しています。');

        $events = $this->candidateCreatedEvents();

        $this->assertCount(2, $events);
        $this->assertFalse((bool) data_get(
            $events->first()->metadata,
            'reopened',
        ));
        $this->assertTrue((bool) data_get(
            $events->last()->metadata,
            'reopened',
        ));
        $this->assertSame(
            2,
            data_get(
                $events->last()->metadata,
                'signal_strength',
            ),
        );
    }

    public function test_same_strength_fingerprint_change_does_not_reopen_again(): void
    {
        [$user, $plan, $firstRepository] = $this->scenario();

        $this->actingAs($user)
            ->post(route('personalization.updates.refresh'));

        $this->actingAs($user)
            ->post(route('personalization.updates.dismiss', [
                'candidateKey' => 'development_advanced_support',
            ]));

        $secondRepository = $this->repository(
            $plan,
            $user,
            'https://github.com/example/second-repo',
        );

        $this->actingAs($user)
            ->post(route('personalization.updates.refresh'));

        $this->actingAs($user)
            ->post(route('personalization.updates.dismiss', [
                'candidateKey' => 'development_advanced_support',
            ]));

        $dismissed = $this->candidate($user);

        $this->assertSame('dismissed', data_get($dismissed, 'status'));
        $this->assertSame(
            2,
            data_get($dismissed, 'dismissed_signal_strength'),
        );

        $this->observation(
            $plan,
            $firstRepository,
            'issue',
            'github:issue:1',
        );

        $this->actingAs($user)
            ->post(route('personalization.updates.refresh'));

        $sameStrength = $this->candidate($user);

        $this->assertSame(
            'dismissed',
            data_get($sameStrength, 'status'),
        );
        $this->assertSame(
            2,
            data_get($sameStrength, 'dismissed_signal_strength'),
        );
        $this->assertCount(2, $this->candidateCreatedEvents());

        foreach ([1, 2, 3] as $number) {
            $this->observation(
                $plan,
                $secondRepository,
                'pull_request',
                'github:pr:'.$number,
            );
        }

        $this->actingAs($user)
            ->post(route('personalization.updates.refresh'));

        $stronger = $this->candidate($user);

        $this->assertSame('pending', data_get($stronger, 'status'));
        $this->assertSame(3, data_get($stronger, 'signal_strength'));
        $this->assertSame(3, data_get($stronger, 'evidence_revision'));
        $this->assertSame('high', data_get($stronger, 'confidence'));
        $this->assertSame(3, data_get($stronger, 'confidence_calibration.signal_strength'));
        $this->assertContains(
            'recent_pr_or_commit_activity_2_plus',
            data_get($stronger, 'evidence', []),
        );
        $this->assertCount(3, $this->candidateCreatedEvents());
    }

    public function test_sustained_activity_across_28_days_strengthens_signal_without_reclassifying_experience(): void
    {
        [$user, $plan, $repository] = $this->scenario();

        $this->actingAs($user)
            ->post(route('personalization.updates.refresh'));

        $this->actingAs($user)
            ->post(route('personalization.updates.dismiss', [
                'candidateKey' => 'development_advanced_support',
            ]));

        foreach ([35, 28, 21, 14, 7, 0] as $daysAgo) {
            DevelopmentActivityObservation::query()->create([
                'plan_id' => $plan->id,
                'repository_artifact_id' => $repository->id,
                'provider' => 'github',
                'kind' => 'issue',
                'external_key' => 'github:sustained:'.$daysAgo,
                'last_observed_at' => now()->subDays($daysAgo),
                'occurred_at' => now()->subDays($daysAgo),
                'resolution_status' => 'unlinked',
            ]);
        }

        $this->actingAs($user)
            ->post(route('personalization.updates.refresh'));

        $candidate = $this->candidate($user);
        $context = $this->context($user)->fresh();

        $this->assertSame('pending', data_get($candidate, 'status'));
        $this->assertSame(1, data_get(
            $candidate,
            'dismissed_signal_strength',
        ));
        $this->assertSame(
            2,
            $this->candidateCreatedEvents()->count(),
        );
        $this->assertContains(
            'sustained_development_activity_28d_6_days',
            data_get($candidate, 'evidence', []),
        );
        $this->assertSame(
            6,
            data_get(
                $context->observed_context,
                'development_behavior.active_day_count_90d',
            ),
        );
        $this->assertGreaterThanOrEqual(
            28,
            data_get(
                $context->observed_context,
                'development_behavior.activity_span_days_90d',
            ),
        );
        $this->assertSame(
            'beginner',
            data_get(
                $context->self_reported_context,
                'domain_context.development.experience',
            ),
        );
        $this->assertSame('guided', $context->guidance_level);
    }

    public function test_repository_structure_breadth_is_observed_without_experience_inference(): void
    {
        [$user, $plan, $repository] = $this->scenario();

        $metadata = (array) $repository->metadata;
        $metadata['github_repository_snapshot'] = [
            'version' => 1,
            'repository' => ['language' => 'PHP'],
            'branches' => [
                ['name' => 'main'],
                ['name' => 'feature/example'],
            ],
            'pull_requests' => [
                ['number' => 1],
            ],
            'issues' => [],
            'actions_runs' => [],
        ];
        $repository->update(['metadata' => $metadata]);

        $this->actingAs($user)
            ->post(route('personalization.updates.refresh'));

        $candidate = $this->candidate($user);
        $context = $this->context($user)->fresh();

        $this->assertSame(2, data_get($candidate, 'signal_strength'));
        $this->assertSame('medium', data_get($candidate, 'confidence'));
        $this->assertContains(
            'repository_structure_breadth_3_plus',
            data_get($candidate, 'evidence', []),
        );
        $this->assertSame(
            3,
            data_get(
                $context->observed_context,
                'development_behavior.repository_structure_breadth',
            ),
        );
        $this->assertSame(
            'beginner',
            data_get(
                $context->self_reported_context,
                'domain_context.development.experience',
            ),
        );
        $this->assertSame('guided', $context->guidance_level);
    }

    public function test_pending_candidate_updates_evidence_before_dismissal_without_duplicate_prompt_event(): void
    {
        [$user, $plan] = $this->scenario();

        $this->actingAs($user)
            ->post(route('personalization.updates.refresh'));

        $this->repository(
            $plan,
            $user,
            'https://github.com/example/second-repo',
        );

        $this->actingAs($user)
            ->post(route('personalization.updates.refresh'));

        $pending = $this->candidate($user);

        $this->assertSame('pending', data_get($pending, 'status'));
        $this->assertSame(2, data_get($pending, 'signal_strength'));
        $this->assertSame(2, data_get($pending, 'evidence_revision'));
        $this->assertCount(1, $this->candidateCreatedEvents());

        $this->actingAs($user)
            ->post(route('personalization.updates.dismiss', [
                'candidateKey' => 'development_advanced_support',
            ]));

        $dismissed = $this->candidate($user);

        $this->assertSame(
            2,
            data_get($dismissed, 'dismissed_signal_strength'),
        );

        $this->actingAs($user)
            ->post(route('personalization.updates.refresh'));

        $this->assertSame(
            'dismissed',
            data_get($this->candidate($user), 'status'),
        );
    }

    public function test_confirmed_candidate_never_reopens_when_signal_becomes_stronger(): void
    {
        [$user, $plan, $repository] = $this->scenario();

        $this->actingAs($user)
            ->post(route('personalization.updates.refresh'))
            ->assertRedirect(route('personalization.updates.index'));

        $this->actingAs($user)
            ->post(route('personalization.updates.confirm', [
                'candidateKey' => 'development_advanced_support',
            ]))
            ->assertRedirect(route('personalization.updates.index'));

        $this->repository(
            $plan,
            $user,
            'https://github.com/example/second-repo',
        );

        foreach ([1, 2, 3] as $number) {
            $this->observation(
                $plan,
                $repository,
                'pull_request',
                'github:confirmed-pr:'.$number,
            );
        }

        $this->actingAs($user)
            ->post(route('personalization.updates.refresh'))
            ->assertRedirect(route('personalization.updates.index'));

        $candidate = $this->candidate($user);

        $this->assertSame(
            'confirmed',
            data_get($candidate, 'status'),
        );
        $this->assertTrue((bool) data_get(
            $this->context($user)->feature_readiness,
            'development.advanced_support.enabled',
        ));
        $this->assertSame(
            0,
            $this->candidateCreatedEvents()
                ->where('metadata.reopened', true)
                ->count(),
        );
    }

    public function test_legacy_dismissed_candidate_without_fingerprint_reopens_only_when_signal_is_stronger(): void
    {
        [$user, $plan] = $this->scenario();

        $context = $this->context($user);
        $context->forceFill([
            'inferred_context' => [
                'update_candidates' => [
                    'development_advanced_support' => [
                        'key' => 'development_advanced_support',
                        'domain' => 'development',
                        'kind' => 'advanced_support_offer',
                        'risk' => 'high',
                        'confidence' => 'medium',
                        'status' => 'dismissed',
                        'confirmation_required' => true,
                        'trigger' => 'capability_readiness',
                        'evidence' => [
                            'github_integration_connected',
                        ],
                        'proposal' => [
                            'feature_readiness_key' =>
                                'development.advanced_support',
                        ],
                        'created_at' =>
                            now()->subDay()->toIso8601String(),
                        'resolved_at' =>
                            now()->subDay()->toIso8601String(),
                    ],
                ],
            ],
        ])->save();

        $this->actingAs($user)
            ->post(route('personalization.updates.refresh'));

        $this->assertSame(
            'dismissed',
            data_get($this->candidate($user), 'status'),
        );

        $this->repository(
            $plan,
            $user,
            'https://github.com/example/second-repo',
        );

        $this->actingAs($user)
            ->post(route('personalization.updates.refresh'));

        $reopened = $this->candidate($user);

        $this->assertSame('pending', data_get($reopened, 'status'));
        $this->assertSame(2, data_get($reopened, 'signal_strength'));
        $this->assertSame(2, data_get($reopened, 'evidence_revision'));
    }

    public function test_guidance_suggestion_requires_confirmation_and_rejects_stale_level(): void
    {
        [$user, $plan, $repository] = $this->scenario();
        $this->context($user)->forceFill(['guidance_level' => 'guided'])->save();
        $this->repository($plan, $user, 'https://github.com/example/second-repo');
        $this->observation($plan, $repository, 'pull_request', 'pr-1');
        $this->observation($plan, $repository, 'commit', 'commit-1');
        $this->observation($plan, $repository, 'commit', 'commit-2');

        $this->actingAs($user)->post(route('personalization.updates.refresh'));
        $candidate = data_get($this->context($user)->inferred_context, 'update_candidates.development_guidance_level');
        $this->assertSame('pending', data_get($candidate, 'status'));
        $this->assertSame('guided', $this->context($user)->guidance_level);
        $direction = data_get($this->context($user)->inferred_context, 'update_candidates.development_plan_direction_review');
        $this->assertSame('pending', data_get($direction, 'status'));
        $this->assertFalse(data_get($direction, 'proposal.automatic_plan_mutation'));

        $this->context($user)->forceFill(['guidance_level' => 'standard'])->save();
        $this->actingAs($user)->post(route('personalization.updates.confirm', [
            'candidateKey' => 'development_guidance_level',
        ]));
        $this->assertSame('pending', data_get($this->context($user)->inferred_context, 'update_candidates.development_guidance_level.status'));
        $this->assertSame('standard', $this->context($user)->guidance_level);
    }

    public function test_feature_recommendations_do_not_promote_unconfirmed_development_capabilities(): void
    {
        [$user] = $this->scenario();
        $user->forceFill(['workspace_mode_preference' => 'development'])->save();
        $this->actingAs($user)->post(route('personalization.updates.refresh'));
        $context = $this->context($user);
        $priority = (array) data_get($context->inferred_context, 'feature_recommendation_priority', []);
        $this->assertContains('workspace.development', $priority);
        $this->assertNotContains('development.advanced_support', $priority);
        $this->assertNotContains('development.plan_review', $priority);
    }

    public function test_growth_experience_separates_observed_milestones_and_confirmed_choices(): void
    {
        [$user] = $this->scenario();
        $this->actingAs($user)->post(route('personalization.updates.refresh'));
        $response = $this->actingAs($user)->get(route('personalization.updates.index'));
        $response->assertOk()->assertSee('data-growth-experience-summary', false);
        $summary = app(\\App\\Services\\PersonalizationLivingProfileService::class)
            ->summary(request()->setUserResolver(fn () => $user));
        $this->assertIsArray(data_get($summary, 'growth_experience.observed_milestones'));
        $this->assertIsArray(data_get($summary, 'growth_experience.confirmed_choices'));
        $this->assertIsInt(data_get($summary, 'growth_experience.pending_choices'));
    }

    /**
     * @return array{0:User,1:Plan,2:PlanArtifact}
     */
    private function scenario(): array
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('personalization.store'), [
                'domains' => ['development'],
                'weekly_capacity' => '5_10',
                'development_goal' => 'Canovia',
                'development_experience' => 'beginner',
                'development_stage' => 'existing',
                'github_usage' => 'yes',
                'repository_ready' => 'yes',
            ])
            ->assertRedirect(route('personalization.result'));

        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'Living Profile Development',
            'description' => 'V58.32 stronger evidence',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);

        $repository = $this->repository(
            $plan,
            $user,
            'https://github.com/example/first-repo',
        );

        $context = $this->context($user);
        $context->forceFill([
            'observed_context' => [
                ...(array) $context->observed_context,
                'github_integration' => [
                    'status' => 'connected',
                    'observed_at' => now()->toIso8601String(),
                ],
            ],
        ])->save();

        return [$user, $plan, $repository];
    }

    private function repository(
        Plan $plan,
        User $user,
        string $url,
    ): PlanArtifact {
        return PlanArtifact::query()->create([
            'plan_id' => $plan->id,
            'created_by_user_id' => $user->id,
            'provider' => 'github',
            'artifact_type' => 'repository',
            'title' => basename($url),
            'url' => $url,
            'metadata' => [
                'github_app_connection' => [
                    'status' => 'connected',
                    'installation_id' => 777,
                    'read_ready' => true,
                    'write_ready' => false,
                ],
            ],
        ]);
    }

    private function observation(
        Plan $plan,
        PlanArtifact $repository,
        string $kind,
        string $externalKey,
    ): DevelopmentActivityObservation {
        return DevelopmentActivityObservation::query()->create([
            'plan_id' => $plan->id,
            'repository_artifact_id' => $repository->id,
            'provider' => 'github',
            'kind' => $kind,
            'external_key' => $externalKey,
            'last_observed_at' => now(),
            'occurred_at' => now(),
            'resolution_status' => 'unlinked',
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function candidate(User $user): array
    {
        $context = $this->context($user);

        return (array) data_get(
            $context->inferred_context,
            'update_candidates.development_advanced_support',
            [],
        );
    }

    private function context(
        User $user,
    ): UserPersonalizationContext {
        return UserPersonalizationContext::query()
            ->where('user_id', $user->id)
            ->firstOrFail();
    }

    private function candidateCreatedEvents()
    {
        return BehaviorEvent::query()
            ->where(
                'event_type',
                BehaviorEventType::ContextUpdateCandidateCreated->value,
            )
            ->get()
            ->filter(
                fn (BehaviorEvent $event) =>
                    data_get($event->metadata, 'candidate_key')
                        === 'development_advanced_support',
            )
            ->values();
    }
}
