<?php
namespace Tests\Feature;

use App\Intelligence\Presentation\IntelligenceStateChangeFeedbackService;
use App\Models\IntelligenceDecisionTrace;
use App\Models\IntelligenceStateSnapshot;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class IntelligenceEvidenceArrayDiffV5885Test extends TestCase
{
    use RefreshDatabase;

    public function test_structured_evidence_references_are_compared_without_array_to_string_conversion(): void
    {
        $user = User::factory()->create();
        $plan = Plan::query()->create([
            'user_id' => $user->id, 'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(), 'title' => 'AP学習',
            'description' => 'AP学習', 'category' => '資格学習', 'priority' => 1,
            'priority_mode' => 'manual', 'start_date' => today(),
            'deadline' => today()->addMonth(), 'is_public' => false,
        ]);
        $previous = new IntelligenceDecisionTrace();
        $current = new IntelligenceDecisionTrace();
        $previous->setRelation('stateSnapshot', new IntelligenceStateSnapshot([
            'evidence_references' => [['kind' => 'practice', 'id' => 10], 'external:21'],
        ]));
        $current->setRelation('stateSnapshot', new IntelligenceStateSnapshot([
            'evidence_references' => [
                ['kind' => 'practice', 'id' => 10],
                ['kind' => 'practice', 'id' => 11],
                'external:21', 'external:42',
            ],
        ]));

        $method = new \ReflectionMethod(IntelligenceStateChangeFeedbackService::class, 'addedEvidence');
        $result = $method->invoke(app(IntelligenceStateChangeFeedbackService::class), $plan, $previous, $current);

        $this->assertSame(2, $result['count']);
        $this->assertSame([], $result['labels']);
    }
}
