<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class PasswordController extends Controller
{
    /**
     * Update the user's password.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validateWithBag('updatePassword', [
            'current_password' => ['required', 'current_password'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        $user = DB::transaction(function () use ($request, $validated) {
            $user = $request->user()->newQuery()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            if ((int) $request->session()->get('auth_version', -1) !== (int) $user->auth_version
                || ! Hash::check($validated['current_password'], $user->password)) {
                throw ValidationException::withMessages([
                    'current_password' => __('auth.password'),
                ])->errorBag('updatePassword');
            }

            $user->forceFill([
                'password' => Hash::make($validated['password']),
                'auth_version' => (int) $user->auth_version + 1,
                'remember_token' => Str::random(60),
                'password_reset_generation' => null,
            ])->save();
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();

            return $user;
        }, 3);

        Auth::setUser($user);
        $request->setUserResolver(static fn () => $user);
        $request->session()->put('auth_version', (int) $user->auth_version);

        return back()->with('status', 'password-updated');
    }
}
