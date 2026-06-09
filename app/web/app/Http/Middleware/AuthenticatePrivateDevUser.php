<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AuthenticatePrivateDevUser
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('services.private_dev.auto_login') || Auth::check()) {
            return $next($request);
        }

        $email = config('services.private_dev.user_email');

        if (! is_string($email) || trim($email) === '') {
            return $next($request);
        }

        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => config('services.private_dev.user_name', 'I4S Dev'),
                'password' => Hash::make(Str::random(40)),
            ],
        );

        if ($request->hasSession()) {
            Auth::login($user);
        } else {
            Auth::onceUsingId($user->id);
        }

        return $next($request);
    }
}
