<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'IA4Sustainability') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans text-gray-900 antialiased bg-slate-50">
        <div class="flex min-h-screen flex-col bg-slate-50 text-slate-950">
            <header class="sticky top-0 z-50 w-full border-b border-slate-200/80 bg-white/95 backdrop-blur">
                <div class="mx-auto flex h-16 w-full max-w-7xl items-center justify-between px-4 sm:px-6 lg:px-8">
                    <a href="/" class="flex items-baseline gap-1" aria-label="Airis">
                        <span class="text-2xl font-bold text-purple-700">Airis</span>
                        <span class="text-xs text-slate-500">By Sygris</span>
                    </a>

                    <div class="flex items-center gap-4">
                        <a href="/help" class="hidden text-sm font-medium text-purple-700 hover:underline sm:inline">
                            ¿Necesitas ayuda?
                        </a>

                        <div class="flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700">
                            <span aria-hidden="true" class="text-slate-500">◎</span>
                            <span>Español</span>
                        </div>
                    </div>
                </div>
            </header>

            <main class="flex flex-1 items-center justify-center px-4 py-12">
                <div class="w-full max-w-md">
                    {{ $slot }}
                </div>
            </main>

            <footer class="border-t border-slate-200 bg-white py-6">
                <div class="mx-auto flex w-full max-w-7xl flex-col items-center justify-between gap-4 px-4 sm:px-6 md:flex-row lg:px-8">
                    <div class="flex items-center gap-2">
                        <span class="text-xl font-bold text-purple-700">Airis</span>
                        <span class="text-sm text-slate-500">©Sygris</span>
                    </div>

                    <p class="max-w-2xl text-center text-sm text-slate-500 md:text-left">
                        Convocatoria de ayudas para el desarrollo de pymes innovadoras. Proyecto IA4SustainabilityReport Referencia:
                        09-PYN1-00054.1/2023.
                    </p>

                    <div class="flex items-center gap-4">
                        <img src="/madrid-region-logo.jpg" alt="Comunidad de Madrid" class="h-8 object-contain">
                        <img src="/european-funds-logo.jpg" alt="Fondos Europeos" class="h-8 object-contain">
                        <img src="/eu-flag-cofinanced.jpg" alt="Cofinanciado por la Unión Europea" class="h-8 object-contain">
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
                    button.setAttribute('aria-label', showing ? 'Mostrar contraseña' : 'Ocultar contraseña');
                    button.querySelector('[data-eye-open]')?.classList.toggle('hidden', !showing);
                    button.querySelector('[data-eye-closed]')?.classList.toggle('hidden', showing);
                });
            });
        </script>
    </body>
</html>
