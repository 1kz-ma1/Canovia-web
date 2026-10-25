<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserPersonalizationContext;
use App\Services\PersonalizationContextService;
use App\Services\PrelaunchInterestContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class PrelaunchInterestContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['release_levels.preparation_only' => true, 'release_levels.public_level' => 0]);
        Http::preventStrayRequests();
    }

    private function revision(): string
    {
        return $this->getJson('/preparation/interests')->assertOk()->json('revision');
    }

    private function input(string $revision, array $answers = ['Q01' => '3', 'Q02' => 'unknown']): array
    {
        return ['revision' => $revision, 'answers' => $answers, 'step' => 1, 'action' => 'result', 'save_consent' => true];
    }

    public function test_authenticated_l0_storage_is_preparation_only_and_does_not_grant_l1(): void
    {
        $this->getJson('/preparation/interests')->assertUnauthorized();
        $owner = User::factory()->create();
        $this->actingAs($owner);
        config(['release_levels.preparation_only' => false]);
        $this->getJson('/preparation/interests')->assertNotFound();
        config(['release_levels.preparation_only' => true]);
        $this->getJson('/preparation/interests')->assertHeader('Cache-Control', 'no-store, private');
        $this->postJson('/preparation/interests', $this->input($this->revision()))->assertOk();
        $this->post('/personalization', ['domains' => ['study']])->assertNotFound();
        $this->getJson('/workspace/study')->assertNotFound();
        $this->assertNull($owner->fresh()->release_level_override);
        Http::assertNothingSent();
    }

    public function test_partial_unknown_result_review_and_revisit_use_separate_owner_namespace(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $context = UserPersonalizationContext::create(['user_id' => $owner->id, 'context_revision' => 9,
            'self_reported_context' => ['domains' => ['study'], 'domain_context' => ['study' => ['goal' => 'APを保持']]],
            'observed_context' => ['old' => 1], 'inferred_context' => ['old' => 2], 'feature_readiness' => ['old' => 3],
            'completed_at' => now()->subDay()]);
        $before = $context->getRawOriginal();
        $this->postJson('/preparation/interests', $this->input($this->revision()))->assertOk();
        $state = $this->getJson('/preparation/interests/result')->assertOk()->json('record');
        $this->assertSame('unknown', $state['answers']['Q02']);
        $this->assertSame(1, $state['result']['unknown_count']);
        $this->assertNull($state['result']['categories']['ST']['share_percent']);
        $this->postJson('/preparation/interests/review', ['revision' => $this->revision(),
            'confirmed_categories' => ['CT'], 'requested_support' => ['ST']])->assertOk();
        $after = $context->fresh()->getRawOriginal();
        foreach (['observed_context', 'inferred_context', 'feature_readiness', 'completed_at'] as $column) {
            $this->assertSame($before[$column], $after[$column]);
        }
        $this->assertSame(['study'], $context->fresh()->self_reported_context['domains']);
        $this->assertDatabaseCount('plans', 0);
        $this->assertDatabaseCount('tasks', 0);
        $this->flushSession();
        $this->actingAs($owner);
        $this->getJson('/preparation/interests')->assertJsonPath('record.confirmed_categories.0', 'CT')
            ->assertJsonPath('record.requested_support.0', 'ST');
    }

    public function test_repeat_submission_is_idempotent_and_different_stale_tab_is_rejected(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $input = $this->input($this->revision());
        $first = $this->postJson('/preparation/interests', $input)->assertOk()->json('revision');
        $row = UserPersonalizationContext::where('user_id', $owner->id)->sole()->getRawOriginal();
        $this->postJson('/preparation/interests', $input)->assertOk()->assertJsonPath('revision', $first);
        $this->assertSame($row, UserPersonalizationContext::where('user_id', $owner->id)->sole()->getRawOriginal());
        $input['answers']['Q01'] = '0';
        $this->postJson('/preparation/interests', $input)->assertUnprocessable()->assertJsonValidationErrors('revision');
        $this->getJson('/preparation/interests')->assertJsonPath('record.answers.Q01', 3);
    }

    public function test_other_account_has_no_read_write_or_delete_identifier_for_owner_data(): void
    {
        $a = User::factory()->create();
        $this->actingAs($a);
        $this->postJson('/preparation/interests', $this->input($this->revision()))->assertOk();
        $before = $a->personalizationContext()->sole()->getRawOriginal();
        $this->actingAs(User::factory()->create());
        $this->getJson('/preparation/interests')->assertJsonPath('record', null);
        $this->getJson('/preparation/interests/'.$a->id)->assertNotFound();
        $this->postJson('/preparation/interests', $this->input($this->revision()) + ['user_id' => $a->id])
            ->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->deleteJson('/preparation/interests', ['revision' => $this->revision(), 'confirm_delete' => true])->assertOk();
        $this->assertSame($before, $a->personalizationContext()->sole()->getRawOriginal());
    }

    public function test_invalid_answers_forged_scores_or_missing_storage_consent_never_persist(): void
    {
        $this->actingAs(User::factory()->create());
        $revision = $this->revision();
        foreach ([['Q31' => '3'], ['Q01' => '1e0'], ['Q01' => ['3']]] as $answers) {
            $this->postJson('/preparation/interests', $this->input($revision, $answers))->assertUnprocessable();
        }
        $input = $this->input($revision);
        $input['save_consent'] = false;
        $this->postJson('/preparation/interests', $input)->assertUnprocessable();
        $this->postJson('/preparation/interests', $this->input($revision) + ['scores' => ['ST' => 100]])->assertUnprocessable();
        $this->assertDatabaseCount('user_personalization_contexts', 0);
    }

    public function test_legacy_self_report_and_skip_preserve_diagnostic_but_do_not_forward_it_to_ai_context(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $request = Request::create('/personalization');
        $request->setLaravelSession(app('session.store'));
        $request->setUserResolver(fn () => $owner);
        $contexts = app(PersonalizationContextService::class);
        $stale = $contexts->current($request);
        $this->postJson('/preparation/interests', $this->input($this->revision()))->assertOk();
        $record = $owner->personalizationContext()->sole()->self_reported_context[PrelaunchInterestContextService::KEY];
        $contexts->saveSelfReported($request, $stale, true);
        $contexts->skip($request);
        $this->assertSame($record, $owner->personalizationContext()->sole()->self_reported_context[PrelaunchInterestContextService::KEY]);
        $this->assertArrayNotHasKey(PrelaunchInterestContextService::KEY, $contexts->current($request)['context_sources']['self_reported']);
        $this->deleteJson('/preparation/interests', ['revision' => $this->revision(), 'confirm_delete' => true])->assertOk();
        $contexts->saveSelfReported($request, $stale, true);
        $this->assertArrayNotHasKey(PrelaunchInterestContextService::KEY, $owner->personalizationContext()->sole()->self_reported_context);
    }

    public function test_delete_only_namespace_and_unsupported_version_cannot_be_overwritten(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $this->postJson('/preparation/interests', $this->input($this->revision()))->assertOk();
        $row = $owner->personalizationContext()->sole();
        $payload = $row->self_reported_context;
        $payload['other_namespace'] = ['keep' => true];
        $payload[PrelaunchInterestContextService::KEY]['item_bank_version'] = 'future-unrecognized';
        $row->update(['self_reported_context' => $payload]);
        $this->getJson('/preparation/interests')->assertJsonPath('unsupported', true);
        $this->postJson('/preparation/interests', $this->input($this->revision()))->assertUnprocessable();
        $this->assertSame($payload, $row->fresh()->self_reported_context);
    }

    public function test_json_key_order_does_not_create_false_conflict_and_read_recomputes_scores(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $this->postJson('/preparation/interests', $this->input($this->revision()))->assertOk();
        $revision = $this->revision();
        $row = $owner->personalizationContext()->sole();
        $payload = $row->self_reported_context;
        $payload[PrelaunchInterestContextService::KEY] = array_reverse($payload[PrelaunchInterestContextService::KEY], true);
        $row->update(['self_reported_context' => $payload]);
        $this->assertSame($revision, $this->revision());
        $this->postJson('/preparation/interests', $this->input($revision, ['Q01' => 2]))->assertOk();
        $payload = $row->fresh()->self_reported_context;
        $payload[PrelaunchInterestContextService::KEY]['result'] = ['status' => 'forged'];
        $row->update(['self_reported_context' => $payload]);
        $this->getJson('/preparation/interests/result')->assertOk()->assertJsonPath('record.result.status', 'insufficient_evidence');
    }
}
