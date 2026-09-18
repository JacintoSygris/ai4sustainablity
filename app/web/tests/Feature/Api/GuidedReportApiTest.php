<?php

use App\Models\EsrsTopic;
use App\Models\ReportApproval;
use App\Models\ReportingFact;
use App\Models\User;
use App\Services\Report\ReportSnapshotBuilder;
use App\Services\Report\ReportStalenessDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.private_dev.auto_login' => false]);
    config(['services.report.external_taxonomy_manifest_path' => null]);
    config(['services.report.arelle_command' => null]);
    $this->seed(\Database\Seeders\EsrsTopicSeeder::class);
    $this->user = User::factory()->create();
    $this->e2 = EsrsTopic::where('esrs_code', 'E2')->firstOrFail();
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
        'services.report.arelle_command' => $arelle,
    ]);

    $response = $this->actingAs($this->user)->get('/api/report/xhtml-ixbrl-candidate')->assertOk();

    expect($response->headers->get('content-type'))->toContain('application/xhtml+xml; charset=UTF-8');
    expect($response->headers->get('content-disposition'))
        ->toBe('attachment; filename="informe-esrs-candidato.xhtml"');
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
    guidedReportApiApproveSnapshot($this->user, $characterization);

    $response = $this->actingAs($this->user)->get('/api/guided-report/docx')->assertOk();
    expect($response->headers->get('content-type'))
        ->toContain('officedocument.wordprocessingml.document');

    expect(docxVisibleText($response->getContent()))->toContain('Frozen approved fact');
    expect($response->getContent())->not->toContain('Evidence sentinel must never render');

    $this->actingAs($this->user)->getJson('/api/guided-report/evidence-bundle')
        ->assertOk()
        ->assertJsonPath('data.type', 'report_evidence_bundle')
        ->assertJsonPath('data.approved_snapshot.profile_id', ReportingFact::PROFILE_ID)
        ->assertJsonPath('data.claims.0.value.text', 'Frozen approved fact')
        ->assertJsonStructure(['data' => ['version', 'ir_version_hash', 'asset_versions', 'unmapped_concepts', 'guidance_provenance', 'approved_snapshot', 'claims', 'claim_evidence_index']]);
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
        ->toBe('attachment; filename="informe-esrs-borrador.html"');
    expect($response->getContent())->toContain('Borrador factual basado en snapshot aprobado');
    expect($response->getContent())->toContain('No es una presentación oficial, aseguramiento ni filing');
    expect($response->getContent())->toContain('Frozen approved fact');
    expect($response->getContent())->not->toContain('Evidence sentinel must never render');
    expect($response->getContent())->not->toContain('rf_guided_snapshot');
    expect($response->getContent())->not->toContain($snapshot->snapshot_hash);
    expect($response->getContent())->not->toContain($snapshot->profile_hash);
});

it('blocks the guided docx when approved snapshots are stale after live fact mutation', function () {
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
        'services.report.arelle_command' => $arelle,
    ]);

    $fact->update(['value' => ['value' => '999.99']]);

    $response = $this->actingAs($this->user)->getJson('/api/report/xhtml-ixbrl-candidate')
        ->assertStatus(409)
        ->assertJsonPath('data.type', 'xhtml_ixbrl_candidate_blocked')
        ->assertJsonPath('data.reason_code', 'approved_snapshot_stale');

    expect($response->getContent())->not->toContain($packagePath)
        ->not->toContain('fatal');
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
        'services.report.arelle_command' => $arelle,
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
        'services.report.arelle_command' => $arelle,
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
    chmod($binary, 0700);
    putenv('I4S_GUIDED_ARELLE_CAPTURE='.$capturePath);
    putenv('I4S_GUIDED_ARELLE_OUTPUT='.$output);
    putenv('I4S_GUIDED_ARELLE_EXIT='.$exitCode);

    return [$binary, $capturePath];
}
