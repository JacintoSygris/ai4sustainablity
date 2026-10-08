<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ApplicationLocale;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class LocaleController extends Controller
{
    public function show()
    {
        return response()->json(['data' => [
            'locale' => app()->getLocale(),
            'supported_locales' => ApplicationLocale::SUPPORTED,
            'csrf_token' => csrf_token(),
        ]]);
    }

    public function update(Request $request)
    {
        $data = $request->validate(['locale' => ['required', 'string', Rule::in(ApplicationLocale::SUPPORTED)]]);
        $locale = $data['locale'];
        $request->session()->put(ApplicationLocale::KEY, $locale);
        app()->setLocale($locale);

        // Laravel encrypts this HttpOnly cookie. It also survives session rotation/logout.
        $cookie = cookie(ApplicationLocale::KEY, $locale, 60 * 24 * 365, '/', null,
            (bool) config('session.secure'), true, false, 'lax');
        if ($request->expectsJson()) {
            return $this->show()->withCookie($cookie);
        }

        // No arbitrary return URL / Referer redirect at this public endpoint.
        $destination = $request->user() ? '/dashboard' : '/login';
        if (in_array($request->input('return_to'), ['/login', '/register', '/forgot-password', '/laravel/login', '/laravel/register', '/laravel/forgot-password'], true)) {
            $destination = $request->input('return_to');
        }

        return redirect($destination)->withCookie($cookie);
    }
}
