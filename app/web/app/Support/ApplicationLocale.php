<?php

namespace App\Support;

use Illuminate\Http\Request;

final class ApplicationLocale
{
    public const SUPPORTED = ['es', 'en'];
    public const KEY = 'app_locale';

    public static function normalize(mixed $value): string
    {
        return in_array($value, self::SUPPORTED, true) ? $value : 'es';
    }

    public static function preference(Request $request): mixed
    {
        return $request->session()->get(self::KEY, $request->cookie(self::KEY));
    }
}
