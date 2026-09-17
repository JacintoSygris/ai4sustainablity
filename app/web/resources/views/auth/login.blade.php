<x-guest-layout>
    <form method="POST" action="{{ route('login') }}" class="w-full space-y-6">
        @csrf

        <div class="text-center">
            <h1 class="text-2xl font-semibold text-slate-950">Iniciar sesión</h1>
        </div>

        <x-auth-session-status class="rounded-lg bg-emerald-50 p-3 text-sm font-medium text-emerald-700" :status="session('status')" />

        @if ($errors->any())
            <div class="rounded-lg bg-red-50 p-3 text-sm text-red-700">
                {{ __('Error al iniciar sesión. Verifica tus credenciales.') }}
            </div>
        @endif

        @include('auth.partials.social-auth-options', ['mode' => 'login'])

        <div class="space-y-4">
            <div class="space-y-2">
                <label for="email" class="block text-sm font-medium text-slate-800">Email</label>
                <input
                    id="email"
                    class="h-9 w-full min-w-0 rounded-md border border-slate-300 bg-transparent px-3 py-1 text-base text-slate-950 shadow-sm outline-none transition focus:border-purple-700 focus:ring-2 focus:ring-purple-700/30 md:text-sm"
                    type="email"
                    name="email"
                    value="{{ old('email') }}"
                    placeholder="nombre@empresa.com"
                    required
                    autofocus
                    autocomplete="username"
                >
                <x-input-error :messages="$errors->get('email')" class="mt-2" />
            </div>

            <div class="space-y-2">
                <label for="password" class="block text-sm font-medium text-slate-800">Contraseña</label>

                <div class="relative">
                    <input
                        id="password"
                        class="h-9 w-full min-w-0 rounded-md border border-slate-300 bg-transparent px-3 py-1 pr-11 text-base text-slate-950 shadow-sm outline-none transition focus:border-purple-700 focus:ring-2 focus:ring-purple-700/30 md:text-sm"
                        type="password"
                        name="password"
                        placeholder="••••••••••••••"
                        required
                        autocomplete="current-password"
                    >

                    <button
                        type="button"
                        data-password-toggle
                        aria-controls="password"
                        aria-label="Mostrar contraseña"
                        class="absolute right-3 top-1/2 inline-flex h-7 w-7 -translate-y-1/2 items-center justify-center rounded-md text-slate-500 transition hover:text-slate-950 focus:outline-none focus:ring-2 focus:ring-purple-700/30"
                    >
                        <svg data-eye-open class="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        <svg data-eye-closed class="hidden h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M3 3l18 18" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                            <path d="M10.6 10.6A2.9 2.9 0 0 0 12 15a3 3 0 0 0 2.7-1.7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                            <path d="M7.1 7.5C4.2 9.3 2.5 12 2.5 12s3.5 6 9.5 6c1.6 0 3-.4 4.2-1" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M14 6.3c4.6.9 7.5 5.7 7.5 5.7s-.8 1.4-2.3 2.8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </button>
                </div>
                <x-input-error :messages="$errors->get('password')" class="mt-2" />
            </div>
        </div>

        <button
            type="submit"
            class="inline-flex h-9 w-full items-center justify-center gap-2 whitespace-nowrap rounded-md bg-purple-700 px-4 py-2 text-sm font-medium text-white shadow-sm outline-none transition hover:bg-purple-800 focus-visible:ring-2 focus-visible:ring-purple-700/40"
        >
            Iniciar sesión
        </button>

        <div class="text-center">
            @if (Route::has('password.request'))
                <a href="{{ route('password.request') }}" class="text-sm font-medium text-purple-700 hover:underline">
                    ¿Has olvidado la contraseña?
                </a>
            @endif
        </div>

        <div class="text-center text-sm text-slate-500">
            ¿No tienes cuenta?
            <a href="{{ route('register') }}" class="font-medium text-purple-700 hover:underline">
                Regístrate
            </a>
        </div>
    </form>
</x-guest-layout>
