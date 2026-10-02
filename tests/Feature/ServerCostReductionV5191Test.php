<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ServerCostReductionV5191Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        config([
            'session.driver' => 'array',
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
        ]);
    }

    public function test_home_skips_specialized_relations_when_account_has_no_matching_plan(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user, '生活改善', '生活', false);
        $this->task($plan, '机を片付ける');

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->actingAs($user)->get(route('home'));

        $response->assertOk();

        $queries = collect(DB::getQueryLog())
            ->pluck('query')
            ->map(fn ($sql) => mb_strtolower(trim((string) $sql)))
            ->values();

        DB::disableQueryLog();

        foreach ([
            'career_applications',
            'career_captures',
            'plan_members',
            'plan_activity_logs',
        ] as $table) {
            $this->assertFalse(
                $queries->contains(fn (string $sql) => $this->startsFromTable($sql, $table)),
                "Home should not eager-load {$table} when no relevant Plan can render it.",
            );
        }
    }

    public function test_home_keeps_career_and_collaboration_relations_when_they_can_render(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user, '就活', '就活・キャリア', true);
        $this->task($plan, '面接準備');

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->actingAs($user)->get(route('home'));

        $response->assertOk();

        $queries = collect(DB::getQueryLog())
            ->pluck('query')
            ->map(fn ($sql) => mb_strtolower(trim((string) $sql)))
            ->values();

        DB::disableQueryLog();

        foreach ([
            'career_applications',
            'career_captures',
            'plan_members',
            'plan_activity_logs',
        ] as $table) {
            $this->assertTrue(
                $queries->contains(fn (string $sql) => $this->startsFromTable($sql, $table)),
                "Home must still load {$table} when the matching specialized surface is relevant.",
            );
        }
    }

    public function test_idle_prefetch_does_not_speculatively_pay_home_server_cost(): void
    {
        $source = file_get_contents(resource_path('js/instant-navigation.mjs'));

        $this->assertStringContainsString(
            "const idlePending = pending.filter(",
            $source,
        );
        $this->assertStringContainsString(
            "normalizedUrl(href, windowRef).pathname !== '/'",
            $source,
        );
        $this->assertStringContainsString(
            "if (link) void prefetch(link.href);",
            $source,
        );
    }

    private function startsFromTable(string $sql, string $table): bool
    {
        return preg_match(
            '/^select\s+.+?\s+from\s+["]?'.preg_quote($table, '/').'["]?\b/i',
            $sql,
        ) === 1;
    }

    private function plan(User $user, string $title, string $category, bool $collaborative): Plan
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
            'deadline' => today()->addMonth(),
            'is_public' => false,
            'is_collaborative' => $collaborative,
        ]);
    }

    private function task(Plan $plan, string $title): Task
    {
        return Task::query()->create([
            'plan_id' => $plan->id,
            'title' => $title,
            'description' => $title,
            'next_action_note' => $title.'を進める',
            'estimated_minutes' => 30,
            'remaining_minutes' => 30,
            'progress_percent' => 0,
            'status' => 'todo',
            'priority' => 1,
            'activation_cost' => 1,
            'sort_order' => 1,
        ]);
    }
}
