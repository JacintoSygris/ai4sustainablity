<x-guest-layout>
    <form method="POST" action="{{ route('register') }}" class="w-full space-y-6">
        @csrf

        <div class="text-center">
            <h1 class="text-2xl font-semibold text-slate-950">Te damos la bienvenida a Airis</h1>
        </div>

        <x-auth-session-status class="rounded-lg bg-emerald-50 p-3 text-sm font-medium text-emerald-700" :status="session('status')" />

        @if ($errors->any())
            <div class="rounded-lg bg-red-50 p-3 text-sm text-red-700">
                {{ __('Error al crear la cuenta. Revisa los campos e inténtalo de nuevo.') }}
            </div>
        @endif

        @include('auth.partials.social-auth-options', ['mode' => 'register'])

        <div class="space-y-4">
            <div class="space-y-2">
                <label for="name" class="block text-sm font-medium text-slate-800">Nombre completo</label>
                <input
                    id="name"
                    class="h-9 w-full min-w-0 rounded-md border border-slate-300 bg-transparent px-3 py-1 text-base text-slate-950 shadow-sm outline-none transition focus:border-purple-700 focus:ring-2 focus:ring-purple-700/30 md:text-sm"
                    type="text"
                    name="name"
                    value="{{ old('name') }}"
                    placeholder="Juan Hernández"
                    required
                    autofocus
                    autocomplete="name"
                >
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>

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
                    autocomplete="username"
                >
                <x-input-error :messages="$errors->get('email')" class="mt-2" />
            </div>

            <div class="space-y-2">
                <label for="password" class="block text-sm font-medium text-slate-800">Contraseña</label>
                <input
                    id="password"
                    class="h-9 w-full min-w-0 rounded-md border border-slate-300 bg-transparent px-3 py-1 text-base text-slate-950 shadow-sm outline-none transition focus:border-purple-700 focus:ring-2 focus:ring-purple-700/30 md:text-sm"
                    type="password"
                    name="password"
                    placeholder="Mínimo 8 caracteres"
                    required
                    autocomplete="new-password"
                >
                <x-input-error :messages="$errors->get('password')" class="mt-2" />
            </div>

            <div class="space-y-2">
                <label for="password_confirmation" class="block text-sm font-medium text-slate-800">Confirmar contraseña</label>
                <input
                    id="password_confirmation"
                    class="h-9 w-full min-w-0 rounded-md border border-slate-300 bg-transparent px-3 py-1 text-base text-slate-950 shadow-sm outline-none transition focus:border-purple-700 focus:ring-2 focus:ring-purple-700/30 md:text-sm"
                    type="password"
                    name="password_confirmation"
                    placeholder="Repite tu contraseña"
                    required
                    autocomplete="new-password"
                >
                <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
            </div>
        </div>

        <button
            type="submit"
            class="inline-flex h-9 w-full items-center justify-center gap-2 whitespace-nowrap rounded-md bg-purple-700 px-4 py-2 text-sm font-medium text-white shadow-sm outline-none transition hover:bg-purple-800 focus-visible:ring-2 focus-visible:ring-purple-700/40"
        >
            Regístrate
        </button>

        <div class="space-y-1 text-center text-sm text-slate-500">
            <p>
                A continuar, aceptas las
                <span class="font-medium text-purple-700">Condiciones de uso</span>
                y la
                <span class="font-medium text-purple-700">Política de privacidad</span>
            </p>

            <p>
                ¿Ya tienes cuenta?
                <a href="{{ route('login') }}" class="font-medium text-purple-700 hover:underline">
                    Iniciar sesión
                </a>
            </p>
        </div>
    </form>
</x-guest-layout>
