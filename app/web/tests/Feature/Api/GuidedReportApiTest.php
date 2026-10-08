<?php

use App\Models\EsrsTopic;
use App\Models\ReportApproval;
use App\Models\ReportingFact;
use App\Models\User;
use App\Services\EsrsDatapointCorpusBuilder;
use App\Services\Report\ReportSnapshotBuilder;
use App\Services\Report\ReportStalenessDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('rate limits synchronous XHTML and iXBRL candidate validation separately', function () {
    $middleware = \Illuminate\Support\Facades\Route::getRoutes()
        ->getByName('api.report.xhtml-ixbrl-candidate')?->gatherMiddleware() ?? [];

    expect($middleware)->toContain('throttle:2,1,report-xhtml-ixbrl');
});

beforeEach(function () {
    $this->guidedReportApiFixturePaths = [];
    $this->guidedReportApiPreviousEnvironment = [];
    foreach (['I4S_GUIDED_ARELLE_CAPTURE', 'I4S_GUIDED_ARELLE_OUTPUT', 'I4S_GUIDED_ARELLE_EXIT'] as $name) {
        $this->guidedReportApiPreviousEnvironment[$name] = getenv($name);
    }
    config(['services.private_dev.auto_login' => false]);
    config(['services.report.external_taxonomy_manifest_path' => null]);
    config(['services.report.arelle_command' => null]);
    $this->seed(\Database\Seeders\EsrsTopicSeeder::class);
    $this->user = User::factory()->create();
    $this->e2 = EsrsTopic::where('esrs_code', 'E2')->firstOrFail();
});

afterEach(function () {
    foreach ($this->guidedReportApiPreviousEnvironment as $name => $value) {
        putenv($value === false ? $name : $name.'='.$value);
    }
    foreach ($this->guidedReportApiFixturePaths as $path) {
        if (in_array(dirname($path), [
            sys_get_temp_dir().'/i4s-guided-report-api-external-taxonomy',
            sys_get_temp_dir().'/i4s-guided-report-api-arelle',
        ], true) && is_file($path)) {
            unlink($path);
        }
    }
});

it('requires authentication for the guided docx', function () {
    $this->getJson('/api/guided-report/docx')->assertUnauthorized();
});

it('requires authentication for the guided evidence bundle', function () {
    $this->getJson('/api/guided-report/evidence-bundle')->assertUnauthorized();
});

it('requires authentication for the factual html report', function () {
    $this->getJson('/api/report/html')->assertUnauthorized();
});

it('preserves an exact integer through fact submission review snapshot claims and localized report downloads', function () {
    $characterization = fullyReadyReportCharacterization($this->user, $this->e2);
    $value = '9007199254740993';
    $this->actingAs($this->user)->putJson('/api/report/facts', ['facts' => [[
        'datapoint_id' => 'BP-1_01', 'applicability' => 'applicable',
        'value_type' => 'integer', 'value' => $value, 'unit' => 'pure', 'decimals' => 0,
        'dimensions' => [], 'language' => null, 'nil' => false, 'nil_reason' => null,
        'evidence_refs' => [['type' => 'note', 'value' => 'Exact integer regression evidence.']],
        'provenance' => 'api', 'approval_status' => 'review_required', 'blocking_reasons' => [],
    ]]])->assertOk()->assertJsonPath('data.persisted_facts.0.value', $value);
    $fact = ReportingFact::where('characterization_id', $characterization->id)->sole();
    expect($fact->value)->toBe($value);
    $this->postJson('/api/report/facts/'.$fact->id.'/review', ['review_declaration' => 'Reviewed exact integer evidence.'])
        ->assertOk()->assertJsonPath('data.approval_status', 'reviewed');
    $snapshot = guidedReportApiApproveSnapshot($this->user, $characterization);
    $frozen = $snapshot->fresh()->snapshot_json;
    expect($frozen['facts'][0]['value'])->toBe($value);
    foreach (['es' => '9.007.199.254.740.993', 'en' => '9,007,199,254,740,993'] as $locale => $display) {
        $this->putJson('/api/locale', ['locale' => $locale])->assertOk();
        $this->get('/api/report/html')->assertOk()->assertHeader('Content-Language', $locale)->assertSee($display);
        $docx = $this->get('/api/guided-report/docx')->assertOk()->assertHeader('Content-Language', $locale);
        expect(docxVisibleText($docx->getContent()))->toContain($display);
        $this->getJson('/api/guided-report/evidence-bundle')->assertOk()->assertJsonPath('data.claims.0.value', $value);
    }
    expect($fact->fresh()->value)->toBe($value);
    expect($snapshot->fresh()->snapshot_json)->toBe($frozen);
});

it('requires authentication for the xhtml ixbrl candidate endpoint', function () {
    $this->getJson('/api/report/xhtml-ixbrl-candidate')->assertUnauthorized();
});

it('blocks the guided docx until inputs are ready', function () {
    reportReadyCharacterization($this->user, $this->e2);
    $this->actingAs($this->user)->get('/api/guided-report/docx')
        ->assertStatus(409)
        ->assertJsonPath('data.type', 'guided_report_blocked');
});

it('blocks the guided evidence bundle until inputs are ready', function () {
    reportReadyCharacterization($this->user, $this->e2);
    $this->actingAs($this->user)->getJson('/api/guided-report/evidence-bundle')
        ->assertStatus(409)
        ->assertJsonPath('data.type', 'guided_report_blocked');
});

it('blocks ready guided output when no approved snapshot exists', function () {
    fullyReadyReportCharacterization($this->user, $this->e2);

    $this->actingAs($this->user)->get('/api/guided-report/docx')
        ->assertStatus(409)
        ->assertJsonPath('data.type', 'guided_report_blocked')
        ->assertJsonPath('data.reason_code', 'approved_snapshot_missing');
});

it('blocks ready factual html when no approved snapshot exists', function () {
    fullyReadyReportCharacterization($this->user, $this->e2);

    $this->actingAs($this->user)->get('/api/report/html')
        ->assertStatus(409)
        ->assertJsonPath('data.type', 'guided_report_blocked')
        ->assertJsonPath('data.reason_code', 'approved_snapshot_missing');
});

it('blocks factual outputs from an approved snapshot with zero claimable facts', function () {
    $characterization = fullyReadyReportCharacterization($this->user, $this->e2);
    guidedReportApiFact($characterization, [
        'fact_id' => 'rf_guided_not_applicable_only',
        'applicability' => 'not_applicable',
        'value_type' => 'nil',
        'value' => null,
        'language' => null,
        'nil' => false,
    ]);
    guidedReportApiApproveSnapshot($this->user, $characterization);

    foreach (['/api/report/html', '/api/guided-report/docx', '/api/guided-report/evidence-bundle'] as $endpoint) {
        $this->actingAs($this->user)->get($endpoint)
            ->assertStatus(409)
            ->assertJsonPath('data.type', 'guided_report_blocked')
            ->assertJsonPath('data.reason_code', 'no_claimable_report_content');
    }
});

it('blocks ready xhtml ixbrl candidate when no approved snapshot exists', function () {
    fullyReadyReportCharacterization($this->user, $this->e2);

    $this->actingAs($this->user)->getJson('/api/report/xhtml-ixbrl-candidate')
        ->assertStatus(409)
        ->assertJsonPath('data.type', 'xhtml_ixbrl_candidate_blocked')
        ->assertJsonPath('data.reason_code', 'approved_snapshot_missing');
});

it('blocks xhtml ixbrl candidate when the external taxonomy manifest is not configured', function () {
    $characterization = fullyReadyReportCharacterization($this->user, $this->e2);
    guidedReportApiApproveSnapshot($this->user, $characterization);

    $this->actingAs($this->user)->getJson('/api/report/xhtml-ixbrl-candidate')
        ->assertStatus(409)
        ->assertJsonPath('data.type', 'xhtml_ixbrl_candidate_blocked')
        ->assertJsonPath('data.reason_code', 'external_taxonomy_manifest_missing');
});

it('blocks xhtml ixbrl candidate after a valid manifest when Arelle is not configured', function () {
    $characterization = fullyReadyReportCharacterization($this->user, $this->e2);
    guidedReportApiSetEntityIdentifier($characterization);
    guidedReportApiFact($characterization, [
        'fact_id' => 'rf_guided_numeric',
        'value_type' => 'number',
        'value' => ['value' => '123.45'],
        'unit' => 'EUR',
        'decimals' => 2,
    ]);
    guidedReportApiApproveSnapshot($this->user, $characterization);
    [$manifestPath] = guidedReportApiExternalTaxonomyManifestFixture();
    config(['services.report.external_taxonomy_manifest_path' => $manifestPath]);

    $this->actingAs($this->user)->getJson('/api/report/xhtml-ixbrl-candidate')
        ->assertStatus(409)
        ->assertJsonPath('data.type', 'xhtml_ixbrl_candidate_blocked')
        ->assertJsonPath('data.reason_code', 'xhtml_ixbrl_arelle_unavailable');
});

it('returns a validated xhtml ixbrl candidate attachment from a fresh approved snapshot', function () {
    $characterization = fullyReadyReportCharacterization($this->user, $this->e2);
    guidedReportApiSetEntityIdentifier($characterization);
    guidedReportApiFact($characterization, [
        'fact_id' => 'rf_guided_numeric',
        'value_type' => 'number',
        'value' => ['value' => '123.45'],
        'unit' => 'EUR',
        'decimals' => 2,
    ]);
    guidedReportApiApproveSnapshot($this->user, $characterization);
    [$manifestPath] = guidedReportApiExternalTaxonomyManifestFixture();
    [$arelle, $capturePath] = guidedReportApiArelleFixture(0, 'info: validation successful');
    config([
        'services.report.external_taxonomy_manifest_path' => $manifestPath,
        'services.report.arelle_command' => [PHP_BINARY, '-n', $arelle],
    ]);

    $response = $this->actingAs($this->user)->get('/api/report/xhtml-ixbrl-candidate')->assertOk();

    expect($response->headers->get('content-type'))->toContain('application/xhtml+xml; charset=UTF-8');
    expect($response->headers->get('content-disposition'))
        ->toBe('attachment; filename="informe-neis-candidato.xhtml"');
    expect($response->headers->get('X-Report-Publication-State'))->toBe('validated_candidate');
    expect($response->getContent())->toContain('<ix:nonFraction')
        ->and($response->getContent())->toContain('123.45')
        ->and($response->getContent())->not->toContain('rf_guided_numeric');

    $doc = new DOMDocument();
    expect($doc->loadXML($response->getContent()))->toBeTrue();

    $capture = json_decode(file_get_contents($capturePath), true, flags: JSON_THROW_ON_ERROR);
    expect($capture['argv'])->toContain('--internetConnectivity=offline')
        ->and($capture['argv'])->toContain('--validate')
        ->and($capture['package_exists'])->toBeTrue();
});

function guidedReportApiSetEntityIdentifier($characterization): void
{
    $formData = $characterization->form_data;
    $formData['company_profile']['entity_identifier'] = 'TESTGUIDEDID00000000';
    $formData['company_profile']['entity_identifier_scheme'] = 'https://standards.iso.org/iso/17442';
    $characterization->update(['form_data' => $formData]);
}

it('returns docx and factual evidence bundle from a fresh approved snapshot', function () {
    $characterization = fullyReadyReportCharacterization($this->user, $this->e2);
    guidedReportApiFact($characterization, [
        'fact_id' => 'rf_guided_snapshot',
        'value' => ['text' => 'Frozen approved fact'],
        'evidence_refs' => [['type' => 'note', 'value' => 'Evidence sentinel must never render']],
    ]);
    guidedReportApiFact($characterization, [
        'fact_id' => 'rf_guided_not_applicable',
        'datapoint_id' => 'BP-1_02',
        'applicability' => 'not_applicable',
        'value_type' => 'nil',
        'value' => null,
        'language' => null,
        'nil' => false,
        'evidence_refs' => [['type' => 'note', 'value' => 'Documented not-applicable decision']],
    ]);
    guidedReportApiApproveSnapshot($this->user, $characterization);

    $response = $this->actingAs($this->user)->get('/api/guided-report/docx')->assertOk();
    expect($response->headers->get('content-type'))
        ->toContain('officedocument.wordprocessingml.document');

    expect(docxVisibleText($response->getContent()))->toContain('Frozen approved fact');
    expect($response->getContent())->not->toContain('Evidence sentinel must never render');

    $bundleResponse = $this->actingAs($this->user)->getJson('/api/guided-report/evidence-bundle')
        ->assertOk()
        ->assertJsonPath('data.type', 'report_evidence_bundle')
        ->assertJsonPath('data.approved_snapshot.profile_id', ReportingFact::PROFILE_ID)
        ->assertJsonPath('data.claims.0.value.text', 'Frozen approved fact')
        ->assertJsonStructure(['data' => ['version', 'ir_version_hash', 'asset_versions', 'unmapped_concepts', 'guidance_provenance', 'materiality_trace', 'approved_snapshot', 'claims', 'fact_decisions', 'claim_evidence_index']]);

    $decisions = collect($bundleResponse->json('data.fact_decisions'))->keyBy('fact_id');
    expect($decisions['rf_guided_snapshot']['output_section'])->toBe('body');
    expect($decisions['rf_guided_not_applicable']['output_section'])->toBe('not_applicable_appendix');
});

it('returns factual html from a fresh approved snapshot without evidence or internal ids', function () {
    $characterization = fullyReadyReportCharacterization($this->user, $this->e2);
    guidedReportApiFact($characterization, [
        'fact_id' => 'rf_guided_snapshot',
        'value' => ['text' => 'Frozen approved fact'],
        'evidence_refs' => [['type' => 'note', 'value' => 'Evidence sentinel must never render']],
    ]);
    $snapshot = guidedReportApiApproveSnapshot($this->user, $characterization);

    $response = $this->actingAs($this->user)->get('/api/report/html')->assertOk();

    expect($response->headers->get('content-type'))->toContain('text/html; charset=UTF-8');
    expect($response->headers->get('content-disposition'))
        ->toBe('attachment; filename="informe-neis-borrador.html"');
    expect($response->getContent())->toContain('Borrador factual basado en una versión aprobada');
    expect($response->getContent())->toContain('No constituye una presentación oficial ni un trabajo de aseguramiento');
    expect($response->getContent())->toContain('ni genera el formato electrónico regulatorio');
    expect($response->getContent())->not->toContain('filing');
    expect($response->getContent())->not->toContain('iXBRL');
    expect($response->getContent())->toContain('Frozen approved fact');
    expect($response->getContent())->not->toContain('Evidence sentinel must never render');
    expect($response->getContent())->not->toContain('rf_guided_snapshot');
    expect($response->getContent())->not->toContain($snapshot->snapshot_hash);
    expect($response->getContent())->not->toContain($snapshot->profile_hash);
});

it('ignores completed orphan responses when approving and rendering factual outputs', function () {
    $characterization = fullyReadyReportCharacterization($this->user, $this->e2);
    guidedReportApiFact($characterization, [
        'fact_id' => 'rf_active_with_orphan',
        'value' => ['text' => 'Hecho activo aunque exista una respuesta huérfana.'],
        'language' => 'es',
    ]);

    $corpus = app(EsrsDatapointCorpusBuilder::class)->build($characterization);
    $responses = collect($corpus['blocks'])
        ->flatMap(fn (array $block): array => $block['datapoints'] ?? [])
        ->pluck('id')
        ->filter()
        ->mapWithKeys(fn (string $id): array => [
            $id => ['datapoint_id' => $id, 'status' => $id === 'BP-1_01' ? 'completed' : 'not_applicable'],
        ])
        ->all();
    $responses['ORPHAN-COMPLETED'] = [
        'datapoint_id' => 'ORPHAN-COMPLETED',
        'status' => 'completed',
    ];
    $formData = $characterization->form_data;
    $formData['esrs_datapoint_responses']['responses'] = $responses;
    $characterization->update(['form_data' => $formData]);

    $snapshotId = $this->actingAs($this->user)
        ->postJson('/api/report/snapshot')
        ->assertCreated()
        ->json('data.id');
    $this->actingAs($this->user)
        ->postJson("/api/report/snapshots/{$snapshotId}/approve", [
            'single_person_declaration' => 'I prepared, reviewed and approve this snapshot.',
        ])
        ->assertCreated();

    $html = $this->actingAs($this->user)->get('/api/report/html')->assertOk()->getContent();
    expect($html)->toContain('Hecho activo aunque exista una respuesta huérfana.')
        ->not->toContain('ORPHAN-COMPLETED');

    $docx = $this->actingAs($this->user)->get('/api/guided-report/docx')->assertOk()->getContent();
    expect(docxVisibleText($docx))->toContain('Hecho activo aunque exista una respuesta huérfana.')
        ->not->toContain('ORPHAN-COMPLETED');

    $bundle = $this->actingAs($this->user)
        ->getJson('/api/guided-report/evidence-bundle')
        ->assertOk()
        ->json('data');
    expect($bundle['claims'])->toHaveCount(1)
        ->and(json_encode($bundle, JSON_THROW_ON_ERROR))->not->toContain('ORPHAN-COMPLETED');
});

it('blocks the guided docx when approved snapshots are stale after live fact mutations', function () {
    $characterization = fullyReadyReportCharacterization($this->user, $this->e2);
    $fact = guidedReportApiFact($characterization, [
        'fact_id' => 'rf_guided_stale',
        'value' => ['text' => 'Frozen approved fact'],
    ]);
    guidedReportApiApproveSnapshot($this->user, $characterization);

    $fact->update(['value' => ['text' => 'Live mutation must not leak']]);

    $this->actingAs($this->user)->get('/api/guided-report/docx')
        ->assertStatus(409)
        ->assertJsonPath('data.type', 'guided_report_blocked')
        ->assertJsonPath('data.reason_code', 'approved_snapshot_stale');
});

it('blocks factual html when approved snapshots are stale after live fact mutation', function () {
    $characterization = fullyReadyReportCharacterization($this->user, $this->e2);
    $fact = guidedReportApiFact($characterization, [
        'fact_id' => 'rf_guided_stale',
        'value' => ['text' => 'Frozen approved fact'],
    ]);
    guidedReportApiApproveSnapshot($this->user, $characterization);

    $fact->update(['value' => ['text' => 'Live mutation must not leak']]);

    $this->actingAs($this->user)->get('/api/report/html')
        ->assertStatus(409)
        ->assertJsonPath('data.type', 'guided_report_blocked')
        ->assertJsonPath('data.reason_code', 'approved_snapshot_stale');
});

it('does not use another users approved snapshot', function () {
    fullyReadyReportCharacterization($this->user, $this->e2);

    $otherUser = User::factory()->create();
    $otherCharacterization = fullyReadyReportCharacterization($otherUser, $this->e2);
    guidedReportApiFact($otherCharacterization, [
        'fact_id' => 'rf_other_user',
        'value' => ['text' => 'Other user fact must not leak'],
    ]);
    guidedReportApiApproveSnapshot($otherUser, $otherCharacterization);

    $this->actingAs($this->user)->getJson('/api/guided-report/evidence-bundle')
        ->assertStatus(409)
        ->assertJsonPath('data.reason_code', 'approved_snapshot_missing');
});

it('does not use another users approved snapshot for factual html', function () {
    fullyReadyReportCharacterization($this->user, $this->e2);

    $otherUser = User::factory()->create();
    $otherCharacterization = fullyReadyReportCharacterization($otherUser, $this->e2);
    guidedReportApiFact($otherCharacterization, [
        'fact_id' => 'rf_other_user',
        'value' => ['text' => 'Other user fact must not leak'],
    ]);
    guidedReportApiApproveSnapshot($otherUser, $otherCharacterization);

    $this->actingAs($this->user)->get('/api/report/html')
        ->assertStatus(409)
        ->assertJsonPath('data.reason_code', 'approved_snapshot_missing');
});

it('does not use another users snapshot or manifest for xhtml ixbrl candidate', function () {
    fullyReadyReportCharacterization($this->user, $this->e2);

    $otherUser = User::factory()->create();
    $otherCharacterization = fullyReadyReportCharacterization($otherUser, $this->e2);
    guidedReportApiApproveSnapshot($otherUser, $otherCharacterization);
    [$manifestPath] = guidedReportApiExternalTaxonomyManifestFixture();
    config(['services.report.external_taxonomy_manifest_path' => $manifestPath]);

    $this->actingAs($this->user)->getJson('/api/report/xhtml-ixbrl-candidate')
        ->assertStatus(409)
        ->assertJsonPath('data.type', 'xhtml_ixbrl_candidate_blocked')
        ->assertJsonPath('data.reason_code', 'approved_snapshot_missing');
});

it('blocks stale xhtml ixbrl candidate without leaking Arelle paths or output', function () {
    $characterization = fullyReadyReportCharacterization($this->user, $this->e2);
    $fact = guidedReportApiFact($characterization, [
        'fact_id' => 'rf_guided_stale_numeric',
        'value_type' => 'number',
        'value' => ['value' => '123.45'],
        'unit' => 'EUR',
        'decimals' => 2,
    ]);
    guidedReportApiApproveSnapshot($this->user, $characterization);
    [$manifestPath, $packagePath] = guidedReportApiExternalTaxonomyManifestFixture();
    [$arelle] = guidedReportApiArelleFixture(0, 'fatal: '.$packagePath.' must not leak');
    config([
        'services.report.external_taxonomy_manifest_path' => $manifestPath,
        'services.report.arelle_command' => [PHP_BINARY, '-n', $arelle],
    ]);

    $fact->update(['value' => ['value' => '999.99']]);

    $response = $this->actingAs($this->user)->getJson('/api/report/xhtml-ixbrl-candidate')
        ->assertStatus(409)
        ->assertJsonPath('data.type', 'xhtml_ixbrl_candidate_blocked')
        ->assertJsonPath('data.reason_code', 'approved_snapshot_stale');

    expect($response->getContent())->not->toContain($packagePath)
        ->not->toContain('fatal');
});

it('blocks publication when facts change while Arelle is validating the candidate', function () {
    $characterization = fullyReadyReportCharacterization($this->user, $this->e2);
    guidedReportApiSetEntityIdentifier($characterization);
    $fact = guidedReportApiFact($characterization, [
        'fact_id' => 'rf_guided_raced_numeric',
        'value_type' => 'number',
        'value' => ['value' => '123.45'],
        'unit' => 'EUR',
        'decimals' => 2,
    ]);
    guidedReportApiApproveSnapshot($this->user, $characterization);
    [$manifestPath] = guidedReportApiExternalTaxonomyManifestFixture();
    config(['services.report.external_taxonomy_manifest_path' => $manifestPath]);

    app()->bind(\App\Services\Report\ArelleXhtmlIxbrlValidator::class, fn () => new class($fact->id) extends \App\Services\Report\ArelleXhtmlIxbrlValidator {
        public function __construct(private readonly int $factId) {}

        public function validate(string $xhtml, \App\Services\Report\ReportingProfile $profile, array $internalManifest): void
        {
            ReportingFact::query()->whereKey($this->factId)->update(['value' => json_encode(['value' => '999.99'])]);
        }
    });

    $this->actingAs($this->user)->getJson('/api/report/xhtml-ixbrl-candidate')
        ->assertStatus(409)
        ->assertJsonPath('data.type', 'xhtml_ixbrl_candidate_blocked')
        ->assertJsonPath('data.reason_code', 'approved_snapshot_stale');
});

it('returns Arelle validation failure without leaking paths, command or output', function () {
    $characterization = fullyReadyReportCharacterization($this->user, $this->e2);
    guidedReportApiSetEntityIdentifier($characterization);
    guidedReportApiFact($characterization, [
        'fact_id' => 'rf_guided_numeric',
        'value_type' => 'number',
        'value' => ['value' => '123.45'],
        'unit' => 'EUR',
        'decimals' => 2,
    ]);
    guidedReportApiApproveSnapshot($this->user, $characterization);
    [$manifestPath, $packagePath] = guidedReportApiExternalTaxonomyManifestFixture();
    [$arelle] = guidedReportApiArelleFixture(0, 'error: secret '.$packagePath);
    config([
        'services.report.external_taxonomy_manifest_path' => $manifestPath,
        'services.report.arelle_command' => [PHP_BINARY, '-n', $arelle],
    ]);

    $response = $this->actingAs($this->user)->getJson('/api/report/xhtml-ixbrl-candidate')
        ->assertStatus(409)
        ->assertJsonPath('data.type', 'xhtml_ixbrl_candidate_blocked')
        ->assertJsonPath('data.reason_code', 'xhtml_ixbrl_arelle_validation_failed');

    expect($response->getContent())->not->toContain($packagePath)
        ->not->toContain($arelle)
        ->not->toContain('secret');
});

it('uses only snapshot claims for xhtml ixbrl output even when live facts are later changed', function () {
    $characterization = fullyReadyReportCharacterization($this->user, $this->e2);
    guidedReportApiSetEntityIdentifier($characterization);
    $fact = guidedReportApiFact($characterization, [
        'fact_id' => 'rf_guided_numeric',
        'value_type' => 'number',
        'value' => ['value' => '123.45'],
        'unit' => 'EUR',
        'decimals' => 2,
    ]);
    guidedReportApiApproveSnapshot($this->user, $characterization);
    [$manifestPath] = guidedReportApiExternalTaxonomyManifestFixture();
    [$arelle] = guidedReportApiArelleFixture(0, 'info: validation successful');
    config([
        'services.report.external_taxonomy_manifest_path' => $manifestPath,
        'services.report.arelle_command' => [PHP_BINARY, '-n', $arelle],
    ]);

    $fact->update(['value' => ['value' => '999.99']]);
    app()->bind(ReportStalenessDetector::class, fn () => new class(app(ReportSnapshotBuilder::class)) extends ReportStalenessDetector {
        public function refreshState(\App\Models\ReportSnapshot $snapshot): array
        {
            return ['is_stale' => false, 'stale_state' => \App\Models\ReportSnapshot::STALE_FRESH, 'reasons' => [], 'hashes' => []];
        }
    });

    $response = $this->actingAs($this->user)->get('/api/report/xhtml-ixbrl-candidate')->assertOk();

    expect($response->getContent())->toContain('123.45')
        ->not->toContain('999.99');
});

function guidedReportApiFact($characterization, array $overrides = []): ReportingFact
{
    return ReportingFact::create(array_replace([
        'characterization_id' => $characterization->id,
        'fact_id' => 'rf_guided_default',
        'schema_version' => ReportingFact::SCHEMA_VERSION,
        'profile_id' => ReportingFact::PROFILE_ID,
        'datapoint_id' => 'BP-1_01',
        'applicability' => 'applicable',
        'value_type' => 'text',
        'value' => ['text' => 'Default guided fact.'],
        'unit' => null,
        'decimals' => null,
        'dimensions' => [],
        'language' => 'en',
        'nil' => false,
        'nil_reason' => null,
        'evidence_refs' => [['type' => 'note', 'value' => 'Default evidence.']],
        'provenance' => 'api',
        'approval_status' => 'reviewed',
        'blocking_reasons' => [],
    ], $overrides));
}

function guidedReportApiApproveSnapshot(User $user, $characterization)
{
    $applicableDatapointIds = ReportingFact::query()
        ->where('characterization_id', $characterization->id)
        ->where('applicability', 'applicable')
        ->pluck('datapoint_id')
        ->all();
    $formData = $characterization->form_data;
    $responses = $formData['esrs_datapoint_responses']['responses'] ?? [];

    foreach ($responses as $datapointId => &$response) {
        if (! is_array($response)) {
            continue;
        }

        $response['status'] = in_array((string) $datapointId, $applicableDatapointIds, true)
            ? 'completed'
            : 'not_applicable';
    }
    unset($response);

    $formData['esrs_datapoint_responses']['responses'] = $responses;
    $characterization->update(['form_data' => $formData]);
    $snapshot = app(ReportSnapshotBuilder::class)->create($characterization->fresh());

    ReportApproval::create([
        'report_snapshot_id' => $snapshot->id,
        'user_id' => $user->id,
        'role_mode' => ReportApproval::ROLE_MODE_SINGLE_PERSON_DECLARED,
        'preparer_user_id' => $user->id,
        'reviewer_user_id' => $user->id,
        'approver_user_id' => $user->id,
        'single_person_declaration' => true,
        'snapshot_hash' => $snapshot->snapshot_hash,
        'approved_at' => now(),
    ]);

    return $snapshot;
}

function guidedReportApiExternalTaxonomyManifestFixture(): array
{
    $dir = sys_get_temp_dir().'/i4s-guided-report-api-external-taxonomy';
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    $packagePath = $dir.'/'.uniqid('', true).'-esrs-taxonomy-package.zip';
    file_put_contents($packagePath, 'external taxonomy package bytes');
    test()->guidedReportApiFixturePaths = [...test()->guidedReportApiFixturePaths, $packagePath];
    $checksum = hash_file('sha256', $packagePath);

    $manifestPath = $dir.'/'.uniqid('', true).'-manifest.json';
    file_put_contents($manifestPath, json_encode([
        'schema_version' => 'external_taxonomy_manifest_v1',
        'profile_id' => 'esrs-2023-preparatory-v1',
        'taxonomy_entrypoint' => 'https://xbrl.efrag.org/taxonomy/esrs/2023-12-22/esrs_all.xsd',
        'taxonomy_package_path' => $packagePath,
        'taxonomy_package_checksum' => $checksum,
        'external_taxonomy_package_confirmed' => true,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

    test()->guidedReportApiFixturePaths = [...test()->guidedReportApiFixturePaths, $manifestPath];

    return [$manifestPath, $packagePath];
}

function guidedReportApiArelleFixture(int $exitCode, string $output): array
{
    $dir = sys_get_temp_dir().'/i4s-guided-report-api-arelle';
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    $capturePath = $dir.'/'.uniqid('', true).'-capture.json';
    $binary = $dir.'/'.uniqid('', true).'-arelle-fixture.php';
    file_put_contents($binary, <<<'PHP'
#!/usr/bin/env php
<?php
$argvList = $argv;
$fileIndex = array_search('--file', $argvList, true);
$packageIndex = array_search('--packages', $argvList, true);
$xhtmlPath = is_int($fileIndex) ? ($argvList[$fileIndex + 1] ?? '') : '';
$packagePath = is_int($packageIndex) ? ($argvList[$packageIndex + 1] ?? '') : '';
file_put_contents(getenv('I4S_GUIDED_ARELLE_CAPTURE'), json_encode([
    'argv' => $argvList,
    'xhtml_exists_during_run' => is_file($xhtmlPath),
    'package_exists' => is_file($packagePath),
], JSON_THROW_ON_ERROR));
fwrite(STDOUT, getenv('I4S_GUIDED_ARELLE_OUTPUT'));
exit((int) getenv('I4S_GUIDED_ARELLE_EXIT'));
PHP);
    test()->guidedReportApiFixturePaths = [...test()->guidedReportApiFixturePaths, $binary];
    test()->guidedReportApiFixturePaths = [...test()->guidedReportApiFixturePaths, $capturePath];
    chmod($binary, 0700);
    putenv('I4S_GUIDED_ARELLE_CAPTURE='.$capturePath);
    putenv('I4S_GUIDED_ARELLE_OUTPUT='.$output);
    putenv('I4S_GUIDED_ARELLE_EXIT='.$exitCode);

    return [$binary, $capturePath];
}
