<?php

namespace App\Http\Controllers;

use App\Models\RoadmapFeature;
use App\Models\RoadmapVote;
use App\Services\BehaviorIdentityService;
use App\Services\PublicRoadmapProjectionService;
use Illuminate\Http\Request;

class CanoviaFutureController extends Controller
{
    public function index(Request $request, BehaviorIdentityService $identity, PublicRoadmapProjectionService $publicRoadmap)
    {
        $voterKey = $this->voterKey($request, $identity);
        $supported = RoadmapVote::query()
            ->where('voter_key', $voterKey)
            ->pluck('roadmap_feature_id')
            ->flip();

        $features = RoadmapFeature::query()
            ->published()
            ->withCount('votes')
            ->get()
            ->map(function (RoadmapFeature $feature) use ($supported): array {
                $threshold = max(1, (int) ($feature->threshold ?: 1));
                $votes = (int) $feature->votes_count;

                return [
                    'model' => $feature,
                    'votes' => $votes,
                    'progress' => min(100, (int) floor(($votes / $threshold) * 100)),
                    'supported' => $supported->has($feature->id),
                ];
            });

        return view('feedback.index', [
            'features' => $features,
            'publicRoadmapSections' => $publicRoadmap->build(),
        ]);
    }

    public function support(Request $request, RoadmapFeature $roadmapFeature, BehaviorIdentityService $identity)
    {
        abort_unless($roadmapFeature->is_published && $roadmapFeature->voting_enabled, 404);

        $actorToken = $identity->resolve($request);
        $voterKey = $this->voterKeyFromResolvedActor($request, $actorToken);

        RoadmapVote::firstOrCreate(
            [
                'roadmap_feature_id' => $roadmapFeature->id,
                'voter_key' => $voterKey,
            ],
            [
                'user_id' => $request->user()?->id,
                'actor_token' => $actorToken,
            ],
        );

        return back()->with('status', 'Supportを受け付けました。Canoviaの優先順位を考える材料として活用します。');
    }

    public function unsupport(Request $request, RoadmapFeature $roadmapFeature, BehaviorIdentityService $identity)
    {
        $voterKey = $this->voterKey($request, $identity);

        RoadmapVote::query()
            ->where('roadmap_feature_id', $roadmapFeature->id)
            ->where('voter_key', $voterKey)
            ->delete();

        return back()->with('status', 'Supportを取り消しました。');
    }

    private function voterKey(Request $request, BehaviorIdentityService $identity): string
    {
        return $this->voterKeyFromResolvedActor($request, $identity->resolve($request));
    }

    private function voterKeyFromResolvedActor(Request $request, string $actorToken): string
    {
        return $request->user()
            ? 'user:'.$request->user()->id
            : 'actor:'.$actorToken;
    }
}
