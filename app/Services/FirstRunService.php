<?php

namespace App\Services;

use Illuminate\Http\Request;

final class FirstRunService
{
    public const COOKIE = 'canovia_first_run_passed';

    public function requiresGate(Request $request): bool
    {
        if ($request->user()) {
            return $request->user()->first_run_completed_at === null;
        }

        if ($this->hasBrowserPass($request)) {
            return false;
        }

        return ! $this->hasGuestPlanCookie($request);
    }

    public function hasBrowserPass(Request $request): bool
    {
        return (bool) $request->session()->get('canovia.first_run.passed', false)
            || $request->cookie(self::COOKIE) === '1';
    }

    public function requireForNewAccount(Request $request): void
    {
        $request->session()->forget('canovia.first_run.passed');
        $request->session()->put('canovia.first_run.required', true);

        $user = $request->user();
        if ($user && $user->first_run_completed_at !== null) {
            $user->forceFill(['first_run_completed_at' => null])->save();
        }
    }

    public function markPassed(Request $request): void
    {
        $request->session()->forget('canovia.first_run.required');
        $request->session()->put('canovia.first_run.passed', true);

        $user = $request->user();
        if ($user && $user->first_run_completed_at === null) {
            $user->forceFill(['first_run_completed_at' => now()])->save();
        }

        cookie()->queue(
            self::COOKIE,
            '1',
            60 * 24 * 365,
            '/',
            null,
            app()->environment('production') || $request->isSecure(),
            true,
            false,
            'lax',
        );
    }

    private function hasGuestPlanCookie(Request $request): bool
    {
        foreach (array_keys($request->cookies->all()) as $name) {
            if (preg_match('/^pace_keeper_owner_token_\d+$/', (string) $name)) {
                return true;
            }
        }

        return false;
    }
}
