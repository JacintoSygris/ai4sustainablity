<!doctype html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('Paquete de preparación NEIS 2023') }} - {{ $draft['company']['name'] ?: __('Empresa sin nombre') }}</title>
    <style>
        body { color: #172033; font-family: Arial, sans-serif; line-height: 1.5; margin: 32px; }
        header { border-bottom: 2px solid #172033; margin-bottom: 24px; padding-bottom: 16px; }
        h1, h2 { line-height: 1.2; }
        .notice { background: #fff7ed; border: 1px solid #fed7aa; margin: 18px 0; padding: 12px 14px; }
        .metrics { display: grid; gap: 12px; grid-template-columns: repeat(3, minmax(0, 1fr)); margin: 16px 0; }
        .metric { border: 1px solid #d6d9e0; padding: 12px; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #d6d9e0; padding: 8px; text-align: left; vertical-align: top; }
        @media print { body { margin: 18mm; } .notice { break-inside: avoid; } }
    </style>
</head>
<body>
<header>
    <h1>{{ __('Paquete de preparación NEIS 2023') }}</h1>
    <p><strong>{{ $draft['company']['name'] ?: __('Empresa sin nombre') }}</strong> - {{ __('Ejercicio') }} {{ $draft['company']['reporting_year'] ?: '-' }}</p>
</header>
<section class="notice">
    <strong>{{ __('No sustituye la presentación oficial.') }}</strong>
    {{ __('Este paquete organiza la preparación NEIS 2023, las evidencias y la trazabilidad; no sustituye la presentación oficial ni el aseguramiento, no acredita el cumplimiento de la Taxonomía de la UE y no genera formatos electrónicos regulatorios.') }}
</section>
<section class="metrics">
    <div class="metric"><strong>{{ __('Estado') }}</strong><br>{{ $statusLabel }}</div>
    <div class="metric"><strong>{{ __('Temas materiales') }}</strong><br>{{ $draft['materiality']['confirmed_theme_count'] ?? 0 }}</div>
    <div class="metric"><strong>{{ __('Datos normativos decididos') }}</strong><br>{{ round(($draft['datapoints']['completion_ratio'] ?? 0) * 100) }}%</div>
</section>
<section>
    <h2>{{ __('Temas materiales confirmados') }}</h2>
    <ul>
    @forelse ($draft['materiality']['confirmed_themes'] ?? [] as $topic)
        <li><strong>{{ $topic['esrs_code'] }}</strong> - {{ $topic['label'] }}</li>
    @empty
        <li>{{ __('Sin temas materiales confirmados.') }}</li>
    @endforelse
    </ul>
</section>
<section>
    <h2>{{ __('Cobertura de datos normativos') }}</h2>
    <table>
        <thead><tr><th>{{ __('Bloque') }}</th><th>{{ __('Decididos') }}</th><th>{{ __('Total') }}</th></tr></thead>
        <tbody>
        @foreach ($draft['datapoints']['blocks'] ?? [] as $block)
            <tr><td>{{ $block['title'] ?? __('Bloque') }}</td><td>{{ $block['decided_count'] }}</td><td>{{ $block['datapoint_count'] }}</td></tr>
        @endforeach
        </tbody>
    </table>
</section>
<section>
    <h2>{{ __('Limitaciones y alcance') }}</h2>
    <ul>
    @forelse ($draft['limitations'] ?? [] as $limitation)
        <li>{{ $limitation['message'] }}</li>
    @empty
        <li>{{ __('Sin limitaciones registradas.') }}</li>
    @endforelse
    </ul>
</section>
</body>
</html>
