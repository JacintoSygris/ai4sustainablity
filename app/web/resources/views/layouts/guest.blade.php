<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'IA4Sustainability') }}</title>

        <!-- Fonts -->
        <link rel="stylesheet" href="{{ asset('consent/consent.css') }}">

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans text-gray-900 antialiased bg-slate-50">
        <div id="airis-consent-root"></div>
        <div class="flex min-h-screen flex-col bg-slate-50 text-slate-950">
            <header class="sticky top-0 z-50 w-full border-b border-slate-200/80 bg-white/95 backdrop-blur">
                <div class="mx-auto flex h-16 w-full max-w-7xl items-center justify-between px-4 sm:px-6 lg:px-8">
                    <a href="/" class="flex items-baseline gap-1" aria-label="Airis">
                        <span class="text-2xl font-bold text-purple-700">Airis</span>
                        <span class="text-xs text-slate-500">{{ __('By Sygris') }}</span>
                    </a>

                    <div class="flex items-center gap-4">
                        <a href="/help" class="hidden text-sm font-medium text-purple-700 hover:underline sm:inline">
                            {{ __('¿Necesitas ayuda?') }}
                        </a>

                        <form method="POST" action="{{ route('api.locale.update') }}" class="flex items-center gap-2">
                            @csrf
                            <input type="hidden" name="return_to" value="{{ request()->getPathInfo() }}">
                            <label for="app-language">{{ app()->getLocale() === 'en' ? 'Language' : 'Idioma' }}</label>
                            <select id="app-language" name="locale" class="rounded border-slate-300">
                                <option value="es" @selected(app()->getLocale() === 'es')>Español</option>
                                <option value="en" @selected(app()->getLocale() === 'en')>English</option>
                            </select>
                            <button type="submit" class="rounded border px-2 py-1">{{ app()->getLocale() === 'en' ? 'Apply' : 'Aplicar' }}</button>
                        </form>
                    </div>
                </div>
            </header>

            <main class="flex flex-1 items-center justify-center px-4 py-12">
                <div class="w-full max-w-md">
                    {{ $slot }}
                </div>
            </main>

            <footer class="border-t border-slate-200 bg-white py-6">
                <div class="mx-auto flex w-full max-w-7xl flex-col items-center gap-5 px-4 sm:px-6 lg:px-8">
                    <div class="flex flex-col items-center gap-2 sm:flex-row">
                        <span class="text-xl font-bold text-purple-700">Airis</span>
                        <span class="text-sm text-slate-500">©Sygris</span>
                        <nav class="flex items-center gap-4 text-sm text-slate-600" aria-label="{{ __('Información legal') }}">
                            <a href="/privacy" class="hover:text-purple-700 hover:underline">{{ __('Privacidad') }}</a>
                            <a href="/cookies" class="hover:text-purple-700 hover:underline">Cookies</a>
                            <a href="/terms" class="hover:text-purple-700 hover:underline">{{ __('Términos') }}</a>
                        </nav>
                    </div>

                    <p class="max-w-3xl text-center text-sm text-slate-500">{{ __('Cofinanciación de la Comunidad de Madrid y la Unión Europea (FEDER). Proyecto IA4SustainabilityReport, referencia 09-PYN1-00054.1/2023.') }}</p>

                    <div class="flex w-full flex-wrap items-center justify-center gap-16 bg-white py-16">
                        <img src="/funding/pymes-2023/comunidad-madrid-positivo.png" alt="{{ __('Comunidad de Madrid') }}" class="h-14 w-auto max-w-full object-contain">
                        <img src="/funding/pymes-2023/fondos-europeos-oficial.jpg" alt="{{ __('Fondos Europeos') }}" class="h-auto min-h-8 w-[200px] shrink-0 object-contain">
                        <img src="/funding/pymes-2023/ue-cofinanciado-oficial.png" alt="{{ __('Cofinanciado por la Unión Europea') }}" class="h-auto w-[320px] max-w-full object-contain">
                    </div>
                </div>
            </footer>
        </div>

        <script>
            document.querySelectorAll('[data-password-toggle]').forEach((button) => {
                button.addEventListener('click', () => {
                    const input = document.getElementById(button.getAttribute('aria-controls'));
                    if (!input) {
                        return;
                    }

                    const showing = input.getAttribute('type') === 'text';
                    input.setAttribute('type', showing ? 'password' : 'text');
                    button.setAttribute('aria-label', showing ? @json(__('Mostrar contraseña')) : @json(__('Ocultar contraseña')));
                    button.querySelector('[data-eye-open]')?.classList.toggle('hidden', !showing);
                    button.querySelector('[data-eye-closed]')?.classList.toggle('hidden', showing);
                });
            });
        </script>
    </body>
</html>
