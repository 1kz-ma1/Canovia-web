<?php

namespace App\Services;

use App\Models\GoalContext;
use Illuminate\Http\Request;

class GoalContextAccessService
{
    public function authorize(Request $request, GoalContext $context, BehaviorIdentityService $identity): GoalContext
    {
        $user = $request->user();

        if ($user) {
            if ($context->user_id !== null && (int) $context->user_id === (int) $user->id) {
                return $context;
            }

            if ($context->user_id === null && $context->actor_token) {
                $actorToken = $identity->resolve($request);
                if (hash_equals((string) $context->actor_token, $actorToken)) {
                    $context->update([
                        'user_id' => $user->id,
                        'actor_token' => null,
                    ]);

                    return $context->fresh();
                }
            }

            abort(404);
        }

        if ($context->user_id !== null || ! $context->actor_token) {
            abort(404);
        }

        $actorToken = $identity->resolve($request);
        if (! hash_equals((string) $context->actor_token, $actorToken)) {
            abort(404);
        }

        return $context;
    }
}
