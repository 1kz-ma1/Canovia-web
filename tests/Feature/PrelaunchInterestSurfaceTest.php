<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrelaunchInterestSurfaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['release_levels.preparation_only' => true, 'release_levels.public_level' => 0]);
    }

    public function test_l0_html_has_exact_bank_and_no_implicit_completion_or_external_ai(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get('/preparation/interests')->assertOk()->assertSee('診断をスキップして目標を入力する')
            ->assertSee('心理学的な妥当性は未検証')->assertSee('answers[Q30]', false)
            ->assertDontSee('name="save_consent" value="1" required checked', false);
        $this->assertDatabaseCount('user_personalization_contexts', 0);
        $this->assertDatabaseCount('plans', 0);
        $this->get('/preparation/interests/result')->assertRedirect('/preparation/interests');
    }

    public function test_html_form_result_self_correction_delete_and_plan_remain_separate(): void
    {
        $this->actingAs(User::factory()->create());
        $revision = $this->getJson('/preparation/interests')->json('revision');
        $this->post('/preparation/interests', ['revision' => $revision, 'action' => 'result', 'step' => 1,
            'save_consent' => true, 'answers' => ['Q01' => '3', 'Q02' => '3', 'Q03' => 'unknown']])
            ->assertRedirect('/preparation/interests/result');
        $this->get('/preparation/interests/result')->assertOk()->assertSee('100%')->assertSee('わからない 1問')
            ->assertSee('判断材料が足りません')->assertSee('グラフの点数は書き換えません');
        $this->assertDatabaseCount('plans', 0);
        $this->get('/feedback?from=interest-diagnostic')->assertOk()->assertSee('関心のふりかえりへ戻る');
        $this->get('/feedback?from=https://example.test')->assertOk()->assertDontSee('関心のふりかえりへ戻る');
    }

    public function test_explicit_empty_correction_does_not_fall_back_to_inferred_development_interest(): void
    {
        $this->actingAs(User::factory()->create());
        $revision = $this->getJson('/preparation/interests')->json('revision');
        $this->postJson('/preparation/interests', ['revision' => $revision, 'action' => 'result', 'step' => 1,
            'save_consent' => true, 'answers' => ['Q04' => 3, 'Q05' => 3]])->assertOk();
        $this->get('/preparation/interests/result')->assertSee('外部アプリの作業記録との連携');
        $record = $this->getJson('/preparation/interests/result');
        $this->postJson('/preparation/interests/review', ['revision' => $record->json('revision'),
            'confirmed_categories' => [], 'requested_support' => []])->assertOk();
        $this->get('/preparation/interests/result')->assertDontSee('外部アプリの作業記録との連携');
        $this->getJson('/preparation/interests/result')->assertJsonPath('record.result.categories.DV.share_percent', 100);
        $revision = $this->getJson('/preparation/interests')->json('revision');
        $this->postJson('/preparation/interests', ['revision' => $revision, 'action' => 'result', 'step' => 1,
            'save_consent' => true, 'answers' => ['Q04' => 2, 'Q05' => 3]])->assertOk();
        $this->get('/preparation/interests/result')->assertDontSee('外部アプリの作業記録との連携');
    }

    public function test_validation_return_keeps_explicitly_empty_review_separate_from_saved_choices(): void
    {
        $this->actingAs(User::factory()->create());
        $revision = $this->getJson('/preparation/interests')->json('revision');
        $this->postJson('/preparation/interests', ['revision' => $revision, 'action' => 'result', 'step' => 1,
            'save_consent' => true, 'answers' => ['Q01' => 3, 'Q02' => 3]])->assertOk();
        $revision = $this->getJson('/preparation/interests')->json('revision');
        $this->postJson('/preparation/interests/review', ['revision' => $revision,
            'confirmed_categories' => ['CT'], 'requested_support' => ['ST']])->assertOk();
        // A validation return with all choices cleared must not reselect saved values.
        $response = $this->withSession(['_old_input' => ['review_present' => '1']])
            ->get('/preparation/interests/result')->assertOk();
        $this->assertDoesNotMatchRegularExpression('/name="(?:confirmed_categories|requested_support)\[\]"[^>]*checked/', $response->getContent());
        $this->getJson('/preparation/interests')->assertJsonPath('record.confirmed_categories', ['CT'])
            ->assertJsonPath('record.requested_support', ['ST']);
    }
}
