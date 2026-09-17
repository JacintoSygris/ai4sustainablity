<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('requires authentication for the double materiality guide', function () {
    $this->getJson('/api/double-materiality-guide')
        ->assertUnauthorized();
});

it('returns a structured P7 double materiality guide for the separate frontend', function () {
    $expectedSpanishBodies = [
        'review_p5_p6' => 'Antes de empezar, repasa lo que ya tienes: la descripción de tu empresa del paso 1 y la lista de temas propuestos del paso 2. Esa lista es tu punto de partida, no la decisión final. Apunta los temas que no tengas claros: son los que más atención necesitan en el análisis.',
        'define_boundaries' => 'Decide qué entra en el análisis: tu propia actividad, lo que pasa antes (proveedores, materias primas) y lo que pasa después (distribución, uso del producto, residuos). No hace falta perfección: anota qué incluyes y qué dejas fuera, y por qué.',
        'iro_inventory' => 'Para cada tema de tu lista, escribe en una tabla sencilla (vale una hoja de cálculo propia o papel): qué impacto causa tu empresa (a quién afecta y cuánto), y qué riesgo u oportunidad económica supone para ti (multas, costes, clientes que lo exigen, ahorros). Una línea por idea concreta, indicando dónde ocurre (tu empresa, proveedores o clientes).',
        'stakeholder_input' => 'Habla con quien conoce la empresa por dentro y por fuera: plantilla, clientes principales, proveedores clave, gestoría, banco si aplica. Pregunta: ¿qué temas de esta lista os preocupan o nos pueden afectar? Apunta quién dijo qué y cuándo. Decide la profundidad de la consulta según tu organización, contexto y riesgo.',
        'impact_materiality' => "Para cada tema, pregunta: ¿cómo de grave es el daño que causamos o podemos causar (o el beneficio)? ¿A cuánta gente o entorno afecta? ¿Se puede revertir? ¿Cómo de probable es? Si la respuesta combinada es 'importante', el tema es material por impacto. Sé conservador: ante la duda, dentro.",
        'financial_materiality' => 'Ahora el otro lado: ¿este tema puede costarnos o hacernos ganar dinero de forma apreciable? Piensa en multas, licencias, clientes que exigen requisitos, costes de energía o materiales, acceso a financiación. Si el efecto posible es apreciable para el tamaño de tu empresa, el tema es material financieramente.',
        'decision_log' => 'Cierra la lista: para cada tema escribe material o no material y una frase de motivo. Si quitas un tema que estaba propuesto, el motivo es obligatorio para tu propia trazabilidad. Esa lista cerrada es lo que confirmarás en el paso 4.',
        'sync_to_laravel' => 'Vuelve a la aplicación con tu lista cerrada y el acta de la reunión (fecha, método, participantes). En el paso 4 registrarás los cambios frente a la propuesta y la aplicación guardará tu hoja de decisión.',
        'example_e2' => "Una empresa industrial de 40 personas reunió a gerencia, el responsable de producción y la administrativa que trata con la gestoría (90 minutos). Repasaron la lista propuesta tema a tema. En contaminación (E2) salió que la línea de pintura genera compuestos volátiles con límites de emisión en su licencia de actividad: impacto hacia fuera claro y riesgo de sanción hacia dentro. Conclusión: E2 material por las dos vías. En su acta apuntaron: fecha, 'taller interno de 90 minutos', y los tres participantes. Ese acta es lo que registrarás en este paso.",
    ];

    $response = $this->actingAs(User::factory()->create())
        ->getJson('/api/double-materiality-guide')
        ->assertOk()
        ->assertJsonPath('data.type', 'double_materiality_guide')
        ->assertJsonPath('data.phase', 'P7')
        ->assertJsonPath('data.content_format', 'structured_prose_v2')
        ->assertJsonPath('data.warning.es', 'La guía acelera la ADM externa; no decide la materialidad.')
        ->assertJsonPath('data.next_step.next_api', '/api/materiality-confirmation')
        ->assertJsonPath('data.next_step.next_phase', 'P8')
        ->assertJsonPath('data.next_step.note.es', 'Cuando termines el análisis, vuelve al paso 4 para confirmar tus temas materiales finales. El paso 5 derivará los datos a reportar de esa selección.');

    expect(collect($response->json('data.sections'))->pluck('key')->all())
        ->toBe([
            'prepare_scope',
            'identify_iros',
            'assess_materiality',
            'document_decision',
            'return_to_p8',
            'worked_example',
        ]);

    expect($response->json('data.sections.0.steps.0.title.es'))
        ->toBe('Revisar la descripción de la organización y la propuesta de temas');

    expect(collect($response->json('data.templates'))->pluck('key')->all())
        ->toBe(['iro_register', 'stakeholder_consultation_log']);
    expect($response->json('data.templates.0.title.es'))->toBe('Registro de impactos, riesgos y oportunidades');
    expect($response->json('data.templates.1.title.es'))->toBe('Registro de consultas a grupos de interés');

    foreach ($response->json('data.templates') as $template) {
        foreach ($template['columns'] as $column) {
            expect($column['label']['es'] ?? '')->not->toBe('');
            expect($column['label']['en'] ?? '')->not->toBe('');
        }
    }

    $steps = collect($response->json('data.sections'))->flatMap(fn (array $section): array => $section['steps']);

    foreach ($steps as $step) {
        expect($step['body']['es'] ?? '')->not->toBe('');
        expect($step['body']['en'] ?? '')->not->toBe('');
        expect($step['body']['es'])->toBe($expectedSpanishBodies[$step['key']]);
    }

    expect($response->json('data.sections.5.key'))->toBe('worked_example');
    expect($response->json('data.sections.5.title'))->toBe([
        'en' => 'Practical example',
        'es' => 'Ejemplo práctico',
    ]);
    expect($response->json('data.sections.5.steps.0.key'))->toBe('example_e2');
    expect($response->json('data.sections.5.steps.0.title'))->toBe([
        'en' => 'How a 40-person company did it',
        'es' => 'Cómo lo hizo una empresa de 40 personas',
    ]);
    expect($response->json('data.sections.5.steps.0.checks'))->toBe([]);

    expect(json_encode($response->json('data')))->not->toContain('**');
});

it('downloads guide template CSVs with localized headers per locale', function () {
    $user = User::factory()->create();

    $spanish = $this->actingAs($user)
        ->get('/api/double-materiality-guide/templates/iro_register.csv')
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename=iro-register-template-es.csv');

    expect($spanish->getContent())
        ->toContain('ID del tema AR16')
        ->toContain('Grupo de interés afectado o canal financiero')
        ->not->toContain('AR16 topic ID');

    $english = $this->actingAs($user)
        ->get('/api/double-materiality-guide/templates/iro_register.csv?locale=en')
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename=iro-register-template-en.csv');

    expect($english->getContent())
        ->toContain('AR16 topic ID')
        ->toContain('Affected stakeholder or financial channel')
        ->not->toContain('ID del tema AR16');

    $consultationSpanish = $this->actingAs($user)
        ->get('/api/double-materiality-guide/templates/stakeholder_consultation_log.csv')
        ->assertOk();

    expect($consultationSpanish->getContent())->toContain('Grupo de interés');

    $this->actingAs($user)
        ->get('/api/double-materiality-guide/templates/unknown_template.csv')
        ->assertNotFound();

    $this->actingAs($user)
        ->getJson('/api/double-materiality-guide/templates/iro_register.csv?locale=fr')
        ->assertUnprocessable();
});

it('requires authentication for guide template downloads', function () {
    $this->getJson('/api/double-materiality-guide/templates/iro_register.csv')
        ->assertUnauthorized();
});
