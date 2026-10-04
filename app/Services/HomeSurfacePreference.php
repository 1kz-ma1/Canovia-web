<?php

namespace App\Services;

use Illuminate\Http\Request;

final class HomeSurfacePreference
{
    public const COOKIE = 'canovia_home_surface';
    public const CLASSIC = 'classic';
    public const MAP = 'map';

    /**
     * Legacy compatibility only.
     *
     * V55.4 retired Map-as-Home. Existing clients may still carry the cookie,
     * but the canonical Home surface is always Classic/Action Home.
     */
    public function value(Request $request): string
    {
        return self::CLASSIC;
    }

    /**
     * Canonical Home is always Action Home.
     *
     * Map remains available explicitly at /map as the Explore surface.
     */
    public function url(Request $request): string
    {
        return route('home');
    }
}
