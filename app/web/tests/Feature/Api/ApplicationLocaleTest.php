<?php

use App\Models\Characterization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.private_dev.auto_login' => false]);
});

it('defaults to Spanish without using Accept-Language and rejects invalid locale changes', function () {
    $this->withHeader('Accept-Language', 'en-US,en;q=0.9')->getJson('/api/locale')
        ->assertOk()->assertJsonPath('data.locale', 'es')->assertHeader('Content-Language', 'es');
    $this->putJson('/api/locale', ['locale' => 'fr'])->assertUnprocessable();
    $this->getJson('/api/locale')->assertJsonPath('data.locale', 'es');
    $this->withSession(['app_locale' => '../en'])->getJson('/api/locale')->assertJsonPath('data.locale', 'es');
});

it('persists a guest preference and exposes it through the authenticated session', function () {
    $this->putJson('/api/locale', ['locale' => 'en'])->assertOk()
        ->assertJsonPath('data.locale', 'en')->assertCookie('app_locale', 'en');
    $this->getJson('/api/locale')->assertJsonPath('data.locale', 'en');
    $this->actingAs(User::factory()->create())->getJson('/api/auth/session')->assertJsonPath('data.locale', 'en');
    $this->putJson('/api/locale', ['locale' => 'es'])->assertOk();
    $this->getJson('/api/auth/session')->assertJsonPath('data.locale', 'es');
});

it('resolves the encrypted preference cookie when a session has no preference', function () {
    $this->withCredentials()->withCookie('app_locale', 'en')->getJson('/api/locale')->assertJsonPath('data.locale', 'en');
});

it('uses the active locale for every guide template and prevents stale query overrides', function () {
    $this->actingAs(User::factory()->create());
    $this->get('/api/double-materiality-guide/templates/iro_register.csv')
        ->assertOk()->assertHeader('Content-Language', 'es')->assertSee('ID del tema AR16');
    foreach (['es', 'en'] as $locale) {
        $this->putJson('/api/locale', ['locale' => $locale])->assertOk();
        foreach (\App\Support\DoubleMaterialityGuide::toArray()['templates'] as $template) {
            $response = $this->get('/api/double-materiality-guide/templates/'.$template['key'].'.csv?locale='.($locale === 'es' ? 'en' : 'es'));
            $response->assertOk()->assertHeader('Content-Language', $locale);
            expect($response->getContent())->toBe(\App\Support\DoubleMaterialityGuide::templateCsv($template['key'], $locale)['content']);
            $rows = localizedCsvRows($response->getContent());
            expect($rows)->toHaveCount(1);
            expect($rows[0])->toBe(array_map(fn ($column) => $column['label'][$locale], $template['columns']));
            expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
            expect($response->headers->get('Vary'))->toContain('Cookie');
        }
    }
});

it('projects P9 display fields and explicit localized CSV exports without changing canonical or user content', function () {
    $user = User::factory()->create();
    $characterization = Characterization::factory()->create([
        'user_id' => $user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [],
        'form_data' => ['esrs_datapoint_responses' => ['responses' => ['BP-1_01' => [
            'status' => 'completed', 'value' => "Texto libre unchanged, \"quoted\"\nsegunda línea", 'evidence_reference' => 'Evidence sin traducir',
            'note' => '=1+1', 'updated_at' => '2026-09-28T00:00:00Z',
        ]]]],
    ]);
    $original = $characterization->form_data;
    $this->actingAs($user);
    $this->getJson('/api/esrs-datapoints')->assertOk()->assertJsonPath('data.locale', 'es');
    foreach (['es' => 'Bases para la elaboración del estado de sostenibilidad', 'en' => 'Basis for preparation of sustainability statement'] as $locale => $name) {
        $this->putJson('/api/locale', ['locale' => $locale])->assertOk();
        $json = $this->getJson('/api/esrs-datapoints')->assertOk()->assertHeader('Content-Language', $locale);
        $dp = collect($json->json('data.blocks.always_required.datapoints'))->firstWhere('id', 'BP-1_01');
        expect($dp['name'])->toBe('Basis for preparation of sustainability statement');
        expect($dp['display']['name'])->toBe($name);
        expect($dp['display']['locale'])->toBe($locale);
        foreach (['export.localized.csv', 'responses/export.localized.csv'] as $path) {
            $csv = $this->get('/api/esrs-datapoints/'.$path)->assertOk()->assertHeader('Content-Language', $locale);
            expect($csv->getContent())->toContain($name);
            $rows = localizedCsvRows($csv->getContent());
            $headers = array_shift($rows);
            $idHeader = $locale === 'es' ? 'ID del dato' : 'Datapoint ID';
            $nameHeader = $locale === 'es' ? 'Nombre del dato' : 'Datapoint name';
            expect($headers)->toContain($idHeader, $nameHeader,
                $locale === 'es' ? 'Código del estándar' : 'Standard code',
                $locale === 'es' ? 'Código del requisito de información específico' : 'Specific disclosure requirement code',
                $locale === 'es' ? 'Códigos de motivo de selección' : 'Selection reason codes',
                $locale === 'es' ? 'Motivos de selección' : 'Selection reasons');
            $reasonCodeIndex = array_search($locale === 'es' ? 'Códigos de motivo de selección' : 'Selection reason codes', $headers, true);
            $reasonLabelIndex = array_search($locale === 'es' ? 'Motivos de selección' : 'Selection reasons', $headers, true);
            $reasonRow = collect($rows)->first(fn (array $row) => $row[$reasonCodeIndex] !== '');
            expect($reasonRow)->not->toBeNull();
            expect($reasonRow[$reasonLabelIndex])->not->toBe($reasonRow[$reasonCodeIndex]);
            foreach (explode(' | ', $reasonRow[$reasonCodeIndex]) as $code) {
                expect($reasonRow[$reasonLabelIndex])->toContain((new \App\Support\EsrsDisplayCatalogue)->text($code, $locale));
            }
            $idColumn = array_search($idHeader, $headers, true);
            $row = collect($rows)->first(fn (array $row) => $row[$idColumn] === 'BP-1_01');
            expect($row[array_search($nameHeader, $headers, true)])->toBe($name);
            expect($row[0])->toBe('always_required');
            $reasons = array_search($locale === 'es' ? 'Motivos de selección' : 'Selection reasons', $headers, true);
            $codes = array_search($locale === 'es' ? 'Códigos de motivo de selección' : 'Selection reason codes', $headers, true);
            $catalogue = new \App\Support\EsrsDisplayCatalogue;
            expect($row[$reasons])->toBe(implode(' | ', array_map(
                fn ($code) => $catalogue->text($code, $locale),
                array_filter(explode(' | ', $row[$codes])),
            )));
            $selectedHeader = $locale === 'es' ? 'Seleccionado por defecto' : 'Selected by default';
            expect($row[array_search($selectedHeader, $headers, true)])->toBe($locale === 'es' ? 'Sí' : 'Yes');
            $filename = str_starts_with($path, 'responses')
                ? ($locale === 'es' ? 'respuestas-datos-neis.csv' : 'esrs-datapoint-responses.csv')
                : ($locale === 'es' ? 'datos-neis.csv' : 'esrs-datapoints.csv');
            expect($csv->headers->get('Content-Disposition'))->toBe('attachment; filename='.$filename);
            expect($csv->headers->get('Cache-Control'))->toContain('private', 'no-store');
            expect($csv->headers->get('Vary'))->toContain('Cookie');
            if (str_starts_with($path, 'responses')) {
                expect($csv->getContent())->toContain('Texto libre unchanged', "'=1+1", 'Evidence sin traducir');
                $valueHeader = $locale === 'es' ? 'Valor de la respuesta' : 'Response value';
                $noteHeader = $locale === 'es' ? 'Nota' : 'Note';
                expect($row[array_search($valueHeader, $headers, true)])->toBe("Texto libre unchanged, \"quoted\"\nsegunda línea");
                expect($row[array_search($noteHeader, $headers, true)])->toBe("'=1+1");
                $statusCodeHeader = $locale === 'es' ? 'Código del estado de la respuesta' : 'Response status code';
                expect($row[array_search($statusCodeHeader, $headers, true)])->toBe('completed');
                $statusHeader = $locale === 'es' ? 'Descripción del estado de la respuesta' : 'Response status label';
                expect($row[array_search($statusHeader, $headers, true)])->toBe($locale === 'es' ? 'Completado' : 'Completed');
                $basisHeader = $locale === 'es' ? 'Código de la base de aplicabilidad' : 'Applicability mapping basis code';
                expect($row[array_search($basisHeader, $headers, true)])->toBe('always_required');
            } else {
                $typeHeader = $locale === 'es' ? 'Tipo de dato' : 'Data type';
                expect($row[array_search($typeHeader, $headers, true)])->toBe($locale === 'es' ? 'Seminarrativo' : 'semi-narrative');
            }
        }
    }
    expect($characterization->fresh()->form_data)->toBe($original);
});

it('keeps machine CSV bytes headers column shapes and filenames identical across locale preferences', function () {
    $user = User::factory()->create();
    $characterization = Characterization::factory()->create([
        'user_id' => $user->id, 'status' => Characterization::STATUS_COMPLETED, 'esrs_topic_ids' => [],
        'form_data' => ['esrs_datapoint_responses' => ['responses' => ['BP-1_01' => [
            'status' => 'completed', 'value' => '9007199254740993',
            'evidence_reference' => "Evidence, \"quoted\"\nsegunda línea", 'note' => '@SUM(1,1)',
            'updated_at' => '2026-09-28T00:00:00Z',
        ]]]],
    ]);
    $original = $characterization->form_data;
    $structuredColumns = [
        'schema_version',
        'reporting_entity_identifier_scheme',
        'reporting_entity_identifier',
        'concept_id',
        'taggable_state',
        'taggable_reason_code',
        'fact_id',
        'fact_value_kind',
        'fact_lexical_value',
        'fact_decimals',
        'fact_unit',
        'fact_period_type',
        'fact_start_date',
        'fact_end_date',
        'fact_instant_date',
        'fact_dimensions',
        'fact_evidence_reference',
    ];
    $headersByPath = [
        'export.csv' => ['block_key', 'block_title', 'disclosure_requirement_key', 'datapoint_id', 'standard', 'dr', 'paragraph', 'related_ar', 'name', 'data_type', 'conditional_or_alternative', 'may_disclose', 'appendix_b', 'phase_in_less_than_750', 'phase_in_all_undertakings', 'default_selected', 'selection_reasons'],
        'responses/export.csv' => ['block_key', 'disclosure_requirement_key', 'datapoint_id', 'standard', 'dr', 'name', 'applicability_reason_code', 'applicability_reason', 'applicability_mapping_basis', 'applicability_limitations', 'default_selected', 'selection_reasons', 'response_status', 'response_value', 'evidence_reference', 'note', 'response_updated_at', ...$structuredColumns],
    ];
    $spanishBytes = [];
    $this->actingAs($user);
    foreach (['es', 'en'] as $locale) {
        $this->putJson('/api/locale', ['locale' => $locale])->assertOk();
        foreach ($headersByPath as $path => $expectedHeaders) {
            $filename = $path === 'export.csv' ? 'esrs-datapoints.csv' : 'esrs-datapoint-responses.csv';
            $response = $this->get('/api/esrs-datapoints/'.$path)->assertOk()
                ->assertHeader('Content-Disposition', 'attachment; filename='.$filename);
            $bytes = $response->getContent();
            if ($locale === 'es') $spanishBytes[$path] = $bytes;
            else expect($bytes)->toBe($spanishBytes[$path]);
            $rows = localizedCsvRows($bytes);
            expect(array_shift($rows))->toBe($expectedHeaders);
            foreach ($rows as $row) expect($row)->toHaveCount(count($expectedHeaders));
            $row = collect($rows)->first(fn (array $row) => $row[array_search('datapoint_id', $expectedHeaders, true)] === 'BP-1_01');
            $record = array_combine($expectedHeaders, $row);
            expect($record['name'])->toBe('Basis for preparation of sustainability statement');
            expect($record['default_selected'])->toBe('true');
            if ($path === 'responses/export.csv') {
                expect($record['response_value'])->toBe('9007199254740993');
                expect($record['evidence_reference'])->toBe("Evidence, \"quoted\"\nsegunda línea");
                expect($record['note'])->toBe("'@SUM(1,1)");
                expect($record['response_status'])->toBe('completed');
                expect($record['response_updated_at'])->toBe('2026-09-28T00:00:00Z');
                expect($record['schema_version'])->toBe('v1');
                foreach (array_slice($structuredColumns, 1) as $column) {
                    expect($record[$column])->toBe('');
                }
            } else {
                expect($record['data_type'])->toBe('semi-narrative');
            }
        }
    }
    expect($characterization->fresh()->form_data)->toBe($original);
});

it('enforces authentication verification and characterization boundaries on explicit localized CSV routes', function () {
    $paths = ['/api/esrs-datapoints/export.localized.csv', '/api/esrs-datapoints/responses/export.localized.csv'];
    foreach ($paths as $path) $this->getJson($path)->assertUnauthorized();
    config(['services.auth_hardening.require_email_verification' => true]);
    $this->actingAs(User::factory()->unverified()->create());
    foreach ($paths as $path) $this->getJson($path)->assertStatus(409)->assertJsonPath('code', 'email_unverified');
    $this->actingAs(User::factory()->create());
    foreach ($paths as $path) $this->getJson($path)->assertNotFound();
});

it('prefers the session to the cookie and limits form redirects to known local entry routes', function () {
    $this->withCookie('app_locale', 'en')->withSession(['app_locale' => 'es'])
        ->getJson('/api/locale')->assertJsonPath('data.locale', 'es');
    $this->post('/api/locale', ['locale' => 'en', 'return_to' => 'https://example.invalid/'])
        ->assertRedirect('/login');
    $this->post('/api/locale', ['locale' => 'es', 'return_to' => '/register'])
        ->assertRedirect('/register');
    $this->get('/login')->assertOk()->assertSee('name="locale"', false)->assertSee('lang="es"', false);
});

/** Parse quoted multiline user values without relying on another test file. */
function localizedCsvRows(string $csv): array
{
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $csv);
    rewind($stream);
    $rows = [];
    while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
        $rows[] = $row;
    }
    fclose($stream);

    return $rows;
}

it('renders active guest authentication views in both languages without translating entered values', function () {
    foreach (['es', 'en'] as $locale) {
        $this->putJson('/api/locale', ['locale' => $locale])->assertOk();
        $this->get('/login')->assertOk()->assertSee('lang="'.$locale.'"', false)
            ->assertSee($locale === 'es' ? 'Iniciar sesión' : 'Sign in')
            ->assertSee('name="_token"', false)
            ->assertSee($locale === 'es' ? 'Por Sygris' : 'By Sygris')
            ->assertDontSee($locale === 'es' ? 'By Sygris' : 'Por Sygris');
        $this->get('/forgot-password')->assertOk()
            ->assertSee($locale === 'es' ? 'contraseña' : 'password')
            ->assertSee($locale === 'es' ? 'Por Sygris' : 'By Sygris')
            ->assertDontSee($locale === 'es' ? 'By Sygris' : 'Por Sygris');
        $this->get('/register')->assertOk()
            ->assertSee($locale === 'es' ? 'Contraseña' : 'Password')
            ->assertSee($locale === 'es' ? 'Por Sygris' : 'By Sygris')
            ->assertDontSee($locale === 'es' ? 'By Sygris' : 'Por Sygris');
    }
});

it('localizes guide checks and unauthenticated API messages without granting access', function () {
    $this->getJson('/api/report')->assertUnauthorized()->assertJsonPath('message', 'Debes iniciar sesión.');
    $this->putJson('/api/locale', ['locale' => 'en'])->assertOk();
    $this->getJson('/api/report')->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
    $this->actingAs(User::factory()->create());
    foreach (['es', 'en'] as $locale) {
        $this->putJson('/api/locale', ['locale' => $locale])->assertOk();
        $response = $this->getJson('/api/double-materiality-guide')->assertOk()->assertJsonPath('data.locale', $locale);
        expect($response->json('data.sections.0.steps.0.checks.0'))->toStartWith($locale === 'es' ? 'Confirma perímetro' : 'Confirm company perimeter');
    }
});

it('returns locale-native multi-field route errors with stable technical keys and no state write', function () {
    $user = User::factory()->create();
    $characterization = Characterization::factory()->create(['user_id' => $user->id, 'status' => Characterization::STATUS_COMPLETED, 'esrs_topic_ids' => [], 'form_data' => []]);
    $original = $characterization->getRawOriginal('form_data');
    $this->actingAs($user);
    foreach (['es', 'en'] as $locale) {
        $this->putJson('/api/locale', ['locale' => $locale])->assertOk();
        $response = $this->putJson('/api/esrs-datapoints/responses', ['responses' => [['datapoint_id' => 'BP-1_01', 'status' => 'invented_status']]])->assertUnprocessable();
        expect(array_keys($response->json('errors')))->toBe(['expected_revision', 'responses.0.status']);
        expect($response->json('errors.expected_revision.0'))->toBe($locale === 'es' ? 'El campo revisión esperada es obligatorio.' : 'The expected revision field is required.');
        expect($response->json('errors')['responses.0.status'][0])->toBe($locale === 'es' ? 'El valor seleccionado para estado de la respuesta no es válido.' : 'The selected response status is invalid.');
        expect($response->json('message'))->toContain($locale === 'es' ? '(y 1 error más)' : '(and 1 more error)');
        foreach (['/api/report/facts' => ['facts' => [['datapoint_id' => 'BP-1_01']]], '/api/double-materiality-guide/state' => ['checklist' => ['assessed_impacts' => 'wrong'], 'acta' => ['completed_on' => 'wrong']]] as $path => $payload) {
            $errors = $this->putJson($path, $payload)->assertUnprocessable()->json('errors');
            expect($errors)->not->toBeEmpty();
            if ($locale === 'es') foreach ($errors as $messages) foreach ($messages as $message) {
                expect($message)->not->toContain('The ', 'field', 'expected_revision', 'facts.', 'checklist.', 'acta.');
            }
        }
        expect($characterization->fresh()->getRawOriginal('form_data'))->toBe($original);
    }
});

it('keeps missing and foreign report snapshots unavailable with localized model-free JSON', function () {
    config(['app.debug' => false]);
    $user = User::factory()->create();
    $other = User::factory()->create();
    $characterization = Characterization::factory()->create(['user_id' => $other->id]);
    // The row is intentionally unavailable to this user; no reporting or approval authority is created.
    $snapshot = \App\Models\ReportSnapshot::create(['user_id' => $other->id, 'characterization_id' => $characterization->id, 'profile_id' => 'synthetic_unavailable', 'profile_hash' => str_repeat('1', 64), 'facts_hash' => str_repeat('2', 64), 'characterization_hash' => str_repeat('3', 64), 'snapshot_hash' => str_repeat('4', 64), 'source_manifest' => [], 'snapshot_json' => [], 'stale_state' => 'fresh', 'stale_reasons' => []]);
    $this->actingAs($user);
    foreach (['es', 'en'] as $locale) {
        $this->putJson('/api/locale', ['locale' => $locale])->assertOk();
        foreach ([$snapshot->id, $snapshot->id + 1000000] as $id) {
            $response = $this->postJson('/api/report/snapshots/'.$id.'/approve', [])->assertNotFound();
            expect($response->json())->toBe(['message' => $locale === 'es' ? 'No se ha encontrado el recurso solicitado.' : 'Not Found']);
            expect($response->getContent())->not->toContain('App\\', 'ReportSnapshot', 'exception', 'trace', 'file');
        }
    }
    expect(\App\Models\ReportApproval::count())->toBe(0);
});

it('localizes all four P9 review rejections on the real route without accepting fabricated evidence', function () {
    $user = User::factory()->create();
    $characterization = Characterization::factory()->create(['user_id' => $user->id, 'status' => Characterization::STATUS_COMPLETED, 'esrs_topic_ids' => [], 'form_data' => []]);
    $original = $characterization->getRawOriginal('form_data');
    $this->actingAs($user);
    $feedback = ['schema_version' => 'datapoint-feedback-v1', 'authority_digest' => str_repeat('0', 64), 'reviewed_datapoint_ids' => [], 'decisions' => []];
    $cases = [
        [['learning_feedback' => [...$feedback, 'reviewed_datapoint_ids' => (object) []]], 'learning_feedback', 'El universo revisado y las decisiones deben ser listas JSON.', 'Review universes and decisions must be JSON lists.'],
        [['learning_feedback' => [...$feedback, 'decisions' => [['reason_codes' => (object) []]]]], 'learning_feedback', 'Las decisiones de revisión deben ser objetos con listas de códigos de motivo.', 'Review decisions must be objects with reason-code lists.'],
        [['responses' => [['datapoint_id' => 'BP-1_01', 'status' => 'draft', 'selected_to_answer' => true]]], 'responses', 'Usa la revisión explícita versionada para seleccionar los datos revisados.', 'Use explicit versioned learning_feedback for review selection.'],
        [['learning_feedback' => $feedback], 'learning_feedback', 'Las decisiones binarias explícitas deben cubrir exactamente los datos revisados del catálogo actual y su autoridad vigente.', 'Explicit binary decisions must exactly cover reviewed current catalog ids and the current authority.'],
    ];
    foreach (['es', 'en'] as $locale) {
        $this->putJson('/api/locale', ['locale' => $locale])->assertOk();
        foreach ($cases as [$payload, $key, $spanish, $english]) {
            $response = $this->putJson('/api/esrs-datapoints/responses', [...['expected_revision' => 0, 'responses' => []], ...$payload])->assertUnprocessable();
            expect($response->json('errors.'.$key))->toBe([$locale === 'es' ? $spanish : $english]);
            expect($response->json('message'))->toBe($locale === 'es' ? $spanish : $english);
            expect($characterization->fresh()->getRawOriginal('form_data'))->toBe($original);
        }
    }
});

it('localizes bounded P8 review errors without changing integer membership and payload guards', function () {
    $user = User::factory()->create();
    $characterization = Characterization::factory()->create(['user_id' => $user->id, 'status' => Characterization::STATUS_COMPLETED, 'esrs_topic_ids' => [], 'form_data' => []]);
    $this->actingAs($user);
    $this->putJson('/api/materiality-confirmation', ['expected_revision' => 0, 'confirmed_topic_ids' => array_fill(0, 90, 1)])
        ->assertUnprocessable()->assertJsonPath('errors.confirmed_topic_ids.0', 'La lista de temas materiales confirmados no puede contener más de 89 temas.');
    $this->putJson('/api/materiality-confirmation', ['expected_revision' => 0, 'confirmed_topic_ids' => [], 'reviewed_topic_ids' => []])
        ->assertUnprocessable()->assertJsonPath('errors.reviewed_topic_ids.0', 'El universo de temas revisados y su declaración deben enviarse juntos.');
    expect($characterization->fresh()->form_data)->toBe([]);
});
