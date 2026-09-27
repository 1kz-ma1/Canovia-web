<?php

namespace App\Services;

use Illuminate\Http\Request;

final class FirstRunService
{
    public const COOKIE = 'canovia_first_run_passed';

    public function requiresGate(Request $request): bool
    {
        if ($request->user()) {
            return (bool) $request->session()->get('canovia.first_run.required', false);
        }

        if ((bool) $request->session()->get('canovia.first_run.passed', false)) {
            return false;
        }

        if ($request->cookie(self::COOKIE) === '1') {
            return false;
        }

        return ! $this->hasGuestPlanCookie($request);
    }

    public function requireForNewAccount(Request $request): void
    {
        $request->session()->forget('canovia.first_run.passed');
        $request->session()->put('canovia.first_run.required', true);
    }

    public function markPassed(Request $request): void
    {
        $request->session()->forget('canovia.first_run.required');
        $request->session()->put('canovia.first_run.passed', true);

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
