@props(['mode' => 'login'])

@php
    $providers = [
        [
            'key' => 'google',
            'loginLabel' => 'Iniciar sesión con Google',
            'registerLabel' => 'Regístrate con Google',
        ],
        [
            'key' => 'microsoft',
            'loginLabel' => 'Iniciar sesión con Microsoft',
            'registerLabel' => 'Regístrate con Microsoft',
        ],
    ];
@endphp

<div class="space-y-4">
    <div class="space-y-2">
        @foreach ($providers as $provider)
            @php
                $label = $mode === 'register' ? $provider['registerLabel'] : $provider['loginLabel'];
                $configured = config('services.social_login.enabled')
                    && class_exists('Laravel\\Socialite\\Facades\\Socialite')
                    && filled(config("services.{$provider['key']}.client_id"))
                    && filled(config("services.{$provider['key']}.client_secret"))
                    && filled(config("services.{$provider['key']}.redirect"));
                $controlClasses = 'inline-flex h-10 w-full items-center justify-center gap-3 rounded-md border border-slate-300 bg-white px-4 text-sm font-medium text-slate-800 shadow-sm transition focus:outline-none focus:ring-2 focus:ring-purple-700/30';
            @endphp

            @if ($configured)
                <a
                    href="{{ route('social.redirect', ['provider' => $provider['key']]) }}"
                    class="{{ $controlClasses }} hover:bg-slate-50"
                >
            @else
                <button
                    type="button"
                    disabled
                    aria-disabled="true"
                    class="{{ $controlClasses }} cursor-not-allowed opacity-70"
                >
            @endif
                @if ($provider['key'] === 'google')
                    <svg class="h-4 w-4" viewBox="0 0 24 24" aria-hidden="true">
                        <path fill="#4285F4" d="M21.6 12.2c0-.7-.1-1.4-.2-2H12v3.8h5.4a4.6 4.6 0 0 1-2 3v2.5h3.2c1.9-1.7 3-4.2 3-7.3Z"/>
                        <path fill="#34A853" d="M12 22c2.7 0 5-0.9 6.6-2.5L15.4 17c-.9.6-2 1-3.4 1-2.6 0-4.8-1.8-5.6-4.1H3.1v2.6A10 10 0 0 0 12 22Z"/>
                        <path fill="#FBBC05" d="M6.4 13.9a6 6 0 0 1 0-3.8V7.5H3.1a10 10 0 0 0 0 9l3.3-2.6Z"/>
                        <path fill="#EA4335" d="M12 5.9c1.5 0 2.8.5 3.8 1.5l2.9-2.9A9.7 9.7 0 0 0 12 2a10 10 0 0 0-8.9 5.5l3.3 2.6C7.2 7.7 9.4 5.9 12 5.9Z"/>
                    </svg>
                @else
                    <svg class="h-4 w-4" viewBox="0 0 24 24" aria-hidden="true">
                        <path fill="#F25022" d="M3 3h8.5v8.5H3z"/>
                        <path fill="#7FBA00" d="M12.5 3H21v8.5h-8.5z"/>
                        <path fill="#00A4EF" d="M3 12.5h8.5V21H3z"/>
                        <path fill="#FFB900" d="M12.5 12.5H21V21h-8.5z"/>
                    </svg>
                @endif

                <span>{{ $label }}</span>
            @if ($configured)
                </a>
            @else
                </button>
            @endif
        @endforeach
    </div>

    <div class="flex items-center gap-4">
        <div class="h-px flex-1 bg-slate-200"></div>
        <span>O</span>
        <div class="h-px flex-1 bg-slate-200"></div>
    </div>
</div>
