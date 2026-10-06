<?php

namespace App\Http\Controllers;

use App\Enums\BehaviorEventType;
use App\Services\BehaviorEventLogger;
use App\Services\BehaviorIdentityService;
use App\Services\ReleaseLevelService;
use Illuminate\Http\Request;

final class ProductPreviewController extends Controller
{
    public function __invoke(
        Request $request,
        BehaviorIdentityService $identity,
        BehaviorEventLogger $events,
        ReleaseLevelService $levels,
    ) {
        $events->recordOnceSafely(
            $identity->resolve($request),
            BehaviorEventType::ProductPreviewViewed,
            $request,
            metadata: [
                'authenticated' => (bool) $request->user(),
                'release_level' => $levels->levelFor(
                    $request->user(),
                    $request,
                )->value,
            ],
            withinMinutes: 30,
        );

        return view('product_preview.index', [
            'tiers' => collect((array) config('product_preview.tiers', [])),
            'experiences' => collect((array) config('product_preview.experiences', [])),
            'disclosures' => collect((array) config('product_preview.disclosures', [])),
        ]);
    }
}
