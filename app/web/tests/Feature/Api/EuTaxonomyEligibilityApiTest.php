<?php

use App\Models\Characterization;
use App\Models\EuTaxonomyDeclaration;
use App\Models\User;
use App\Services\EuTaxonomyEligibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.private_dev.auto_login' => false, 'eu_taxonomy.enabled' => true]);
    $this->user = User::factory()->create();
    $this->company = Characterization::factory()->create(['user_id' => $this->user->id, 'nace_code' => 'E36.0.0']);
});

function taxonomyDeclarationPayload(Characterization $company): array
{
    $screen = app(EuTaxonomyEligibility::class)->screen($company);

    return [
        'source_hash' => $screen['source_hash'], 'revision' => $screen['revision'],
        'activities' => [['activity_id' => '32021R2139-I-5.1', 'turnover_share' => 12.5, 'capex_share' => null, 'opex_share' => 0]],
    ];
}

it('requires authentication and disables both endpoints behind the default off flag', function () {
    $this->getJson('/api/report/eu-taxonomy')->assertUnauthorized();
    $this->putJson('/api/report/eu-taxonomy', [])->assertUnauthorized();
    config(['eu_taxonomy.enabled' => false]);
    $this->actingAs($this->user)->getJson('/api/report/eu-taxonomy')->assertNotFound();
    $this->putJson('/api/report/eu-taxonomy', [])->assertNotFound();
    $this->getJson('/api/auth/session')->assertJsonPath('data.eu_taxonomy_eligibility_enabled', false);
    expect(EuTaxonomyDeclaration::count())->toBe(0);
});

it('exposes separate authenticated read and write throttles', function () {
    foreach (['show' => 'throttle:30,1,eu-taxonomy-read', 'update' => 'throttle:10,1,eu-taxonomy-write'] as $action => $throttle) {
        expect(Route::getRoutes()->getByName('api.report.eu-taxonomy.'.$action)->gatherMiddleware())
            ->toContain('auth', 'web', $throttle);
    }
});

it('scopes reads and writes to the authenticated owner with no caller selected company', function () {
    $other = User::factory()->create();
    $otherCompany = Characterization::factory()->create(['user_id' => $other->id, 'nace_code' => 'I55.1.0']);
    $this->actingAs($this->user)->putJson('/api/report/eu-taxonomy', taxonomyDeclarationPayload($this->company))->assertOk();
    $this->actingAs($other)->getJson('/api/report/eu-taxonomy?characterization_id='.$this->company->id)
        ->assertJsonPath('data.characterization_id', $otherCompany->id)
        ->assertJsonPath('data.confirmed_activities', []);
    $payload = taxonomyDeclarationPayload($otherCompany);
    $payload['characterization_id'] = $this->company->id;
    $this->putJson('/api/report/eu-taxonomy', $payload)->assertUnprocessable();
    expect(EuTaxonomyDeclaration::where('characterization_id', $otherCompany->id)->exists())->toBeFalse();
});

it('does not create a company from taxonomy requests', function () {
    $this->actingAs(User::factory()->create())->getJson('/api/report/eu-taxonomy')->assertOk()->assertJsonPath('data', null);
    $this->putJson('/api/report/eu-taxonomy', taxonomyDeclarationPayload($this->company))->assertNotFound();
});

it('stores nullable declared shares and produces an explicitly unverified evidence annex', function () {
    $original = $this->company->form_data;
    $this->actingAs($this->user)->putJson('/api/report/eu-taxonomy', taxonomyDeclarationPayload($this->company))
        ->assertOk()->assertJsonPath('data.revision', 1)
        ->assertJsonPath('data.confirmed_activities.0.capex_share', null)
        ->assertJsonPath('data.confirmed_activities.0.opex_share', 0);
    $evidence = app(EuTaxonomyEligibility::class)->evidence($this->company->fresh());
    expect($evidence['alignment_assessed'])->toBeFalse();
    expect($evidence['regulatory_kpis_calculated'])->toBeFalse();
    expect($evidence['scope'])->toBe('current_declarations_outside_approved_report_snapshot');
    expect($evidence['confirmed_activities'][0]['activity']['source']['celex'])->toBe('32021R2139');
    expect($evidence['disclaimer'])->toContain('DNSH');
    expect($this->company->fresh()->form_data)->toBe($original);
});

it('rejects a stale revision instead of overwriting another tab and supports clearing confirmations', function () {
    $payload = taxonomyDeclarationPayload($this->company);
    $this->actingAs($this->user)->putJson('/api/report/eu-taxonomy', $payload)->assertOk();
    $this->putJson('/api/report/eu-taxonomy', $payload)->assertConflict();
    $payload['revision'] = 1;
    $payload['activities'] = [];
    $this->putJson('/api/report/eu-taxonomy', $payload)->assertOk()->assertJsonPath('data.confirmed_activities', []);
});

it('requires reconfirmation after P5 changes and keeps old declarations distinguishable', function () {
    $payload = taxonomyDeclarationPayload($this->company);
    $this->actingAs($this->user)->putJson('/api/report/eu-taxonomy', $payload)->assertOk();
    $this->company->update(['nace_code' => 'E37.0.0']);
    $this->getJson('/api/report/eu-taxonomy')->assertJsonPath('data.declaration_status', 'stale')
        ->assertJsonPath('data.confirmed_activities', [])
        ->assertJsonPath('data.previous_declarations.0.activity_id', '32021R2139-I-5.1');
    $payload['revision'] = 1;
    $this->putJson('/api/report/eu-taxonomy', $payload)->assertConflict();
    expect(app(EuTaxonomyEligibility::class)->evidence($this->company->fresh())['confirmed_activities'])->toBe([]);
});

it('invalidates declarations when company identity or reporting scope changes on the same P5 row', function (string $field, ?string $value) {
    $profile = [
        'company_name' => 'Synthetic taxonomy entity',
        'reporting_year' => 2026,
        'entity_identifier' => 'SYNTHETICENTITY000001',
        'entity_identifier_scheme' => 'https://standards.iso.org/iso/17442',
        'reporting_scope' => 'individual',
    ];
    $this->company->update(['form_data' => ['company_profile' => $profile]]);
    $original = app(EuTaxonomyEligibility::class)->screen($this->company);
    $payload = taxonomyDeclarationPayload($this->company);
    $this->actingAs($this->user)->putJson('/api/report/eu-taxonomy', $payload)->assertOk()
        ->assertJsonPath('data.declared_context', $original['source_context']);
    $declaration = EuTaxonomyDeclaration::where('characterization_id', $this->company->id)->sole();
    $saved = $declaration->getRawOriginal();

    $profile[$field] = $value;
    $this->company->update(['form_data' => ['company_profile' => $profile]]);
    $current = $this->getJson('/api/report/eu-taxonomy')->assertOk()
        ->assertJsonPath('data.declaration_status', 'stale')
        ->assertJsonPath('data.confirmed_activities', [])
        ->assertJsonPath('data.source_context.'.$field, $value)
        ->assertJsonPath('data.declared_context', $original['source_context'])
        ->assertJsonPath('data.previous_declarations.0.turnover_share', 12.5)->json('data');
    expect($current['source_hash'])->not->toBe($original['source_hash']);
    expect($current['characterization_id'])->toBe($original['characterization_id']);
    expect($current['mapping'])->toBe($original['mapping']);
    expect($current['reporting_year'])->toBe($original['reporting_year']);

    // Use the current revision to prove that the old source witness alone is denied.
    $payload['revision'] = $current['revision'];
    $this->putJson('/api/report/eu-taxonomy', $payload)->assertConflict();
    expect($declaration->fresh()->getRawOriginal())->toBe($saved);
    expect(app(EuTaxonomyEligibility::class)->evidence($this->company->fresh())['confirmed_activities'])->toBe([]);

    // Explicit confirmation with the new context is required to make values current.
    $payload['source_hash'] = $current['source_hash'];
    $this->putJson('/api/report/eu-taxonomy', $payload)->assertOk()
        ->assertJsonPath('data.declaration_status', 'user_declared')
        ->assertJsonPath('data.declared_context', $current['source_context'])
        ->assertJsonPath('data.confirmed_activities.0.turnover_share', 12.5);
})->with([
    'company name' => ['company_name', 'Synthetic replacement entity'],
    'LEI' => ['entity_identifier', 'SYNTHETICENTITY000002'],
    'LEI removed' => ['entity_identifier', null],
    'identifier scheme' => ['entity_identifier_scheme', 'urn:synthetic:replacement-scheme'],
    'individual to consolidated group' => ['reporting_scope', 'consolidated_group'],
]);

it('requires explicit reconfirmation of declarations created before identity was bound to the source', function () {
    $screen = app(EuTaxonomyEligibility::class)->screen($this->company);
    $payload = taxonomyDeclarationPayload($this->company);
    $legacyContext = [
        'classification' => 'cnae_2025',
        'input_code' => $screen['mapping']['input_code'],
        'reporting_year' => $screen['reporting_year'],
        'catalogue_sha256' => $screen['catalogue_sha256'],
    ];
    $legacyHash = hash('sha256', json_encode([
        $this->company->id, 'cnae_2025', $this->company->nace_code,
        $screen['reporting_year'], $screen['catalogue_sha256'],
    ], JSON_THROW_ON_ERROR));
    EuTaxonomyDeclaration::create([
        'characterization_id' => $this->company->id, 'revision' => 1,
        'source_hash' => $legacyHash, 'source_context' => $legacyContext,
        'activities' => $payload['activities'],
    ]);
    $this->actingAs($this->user)->getJson('/api/report/eu-taxonomy')->assertOk()
        ->assertJsonPath('data.declaration_status', 'stale')
        ->assertJsonPath('data.confirmed_activities', [])
        ->assertJsonPath('data.declared_context', $legacyContext);
    $payload['revision'] = 1;
    $payload['source_hash'] = $legacyHash;
    $this->putJson('/api/report/eu-taxonomy', $payload)->assertConflict();
    $payload['source_hash'] = $screen['source_hash'];
    $this->putJson('/api/report/eu-taxonomy', $payload)->assertOk()
        ->assertJsonPath('data.declaration_status', 'user_declared')
        ->assertJsonPath('data.declared_context', $screen['source_context']);
});

it('rejects malformed or fabricated activity declarations', function (string $case) {
    $payload = taxonomyDeclarationPayload($this->company);
    match ($case) {
        'negative' => $payload['activities'][0]['turnover_share'] = -1,
        'over' => $payload['activities'][0]['capex_share'] = 101,
        'formula' => $payload['activities'][0]['opex_share'] = '=1+1',
        'html' => $payload['activities'][0]['activity_id'] = '<script>alert(1)</script>',
        'unknown' => $payload['activities'][0]['activity_id'] = 'not-in-the-catalogue',
        'duplicate' => $payload['activities'][] = $payload['activities'][0],
        'extra' => $payload['activities'][0]['alignment'] = true,
        'missing' => $payload['activities'][0] = ['activity_id' => '32021R2139-I-5.1'],
        'object' => $payload['activities'] = ['unexpected' => $payload['activities'][0]],
    };
    $this->actingAs($this->user)->putJson('/api/report/eu-taxonomy', $payload)->assertUnprocessable();
    expect(EuTaxonomyDeclaration::count())->toBe(0);
})->with(['negative', 'over', 'formula', 'html', 'unknown', 'duplicate', 'extra', 'missing', 'object']);

it('localizes the disclaimer and removes declarations through the company cascade', function () {
    app()->setLocale('en');
    expect(app(EuTaxonomyEligibility::class)->screen($this->company)['disclaimer'])->toContain('eligibility is not');
    app()->setLocale('es');
    $this->actingAs($this->user)->putJson('/api/report/eu-taxonomy', taxonomyDeclarationPayload($this->company))->assertOk();
    $this->company->delete();
    expect(EuTaxonomyDeclaration::count())->toBe(0);
});
