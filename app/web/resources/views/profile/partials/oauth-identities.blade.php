@php
    $providers = [
        'google' => 'Google',
        'microsoft' => 'Microsoft',
    ];
@endphp

<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Identidades sociales</h2>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
            Vincula una identidad solo desde esta sesión autenticada. Se solicitará confirmar la contraseña.
        </p>
    </header>

    @if (session('status'))
        <p class="mt-4 text-sm text-gray-600 dark:text-gray-400">{{ session('status') }}</p>
    @endif

    <div class="mt-6 space-y-3">
        @foreach ($providers as $provider => $label)
            @php
                $configured = config('services.social_login.enabled')
                    && filled(config("services.{$provider}.client_id"))
                    && filled(config("services.{$provider}.client_secret"))
                    && filled(config("services.{$provider}.redirect"));
                $linked = $user->oauthIdentities->contains('provider', $provider);
            @endphp

            @if ($configured)
                <div class="flex items-center justify-between gap-4">
                    <span class="text-sm text-gray-700 dark:text-gray-300">{{ $label }}</span>
                    @if ($linked)
                        <span class="text-sm font-medium text-emerald-700">Vinculada</span>
                    @else
                        <a
                            href="{{ route('social.link', ['provider' => $provider]) }}"
                            class="rounded-md bg-gray-800 px-3 py-2 text-sm font-semibold text-white hover:bg-gray-700"
                        >
                            Vincular {{ $label }}
                        </a>
                    @endif
                </div>
            @endif
        @endforeach
    </div>
</section>
