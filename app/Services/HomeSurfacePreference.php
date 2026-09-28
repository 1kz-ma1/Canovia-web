<?php

namespace App\Services;

use Illuminate\Http\Request;

final class HomeSurfacePreference
{
    public const COOKIE = 'canovia_home_surface';
    public const CLASSIC = 'classic';
    public const MAP = 'map';

    public function value(Request $request): string
    {
        return $request->cookie(self::COOKIE) === self::MAP
            ? self::MAP
            : self::CLASSIC;
    }

    public function url(Request $request): string
    {
        return $this->value($request) === self::MAP
            ? route('map.index')
            : route('home');
    }
}
