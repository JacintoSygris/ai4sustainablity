<?php

use App\Models\EsrsTopic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
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

it('returns a docx once all inputs are ready', function () {
    fullyReadyReportCharacterization($this->user, $this->e2); // helper: all 6 sections ready
    $response = $this->actingAs($this->user)->get('/api/guided-report/docx')->assertOk();
    expect($response->headers->get('content-type'))
        ->toContain('officedocument.wordprocessingml.document');
});

it('returns an evidence bundle once all inputs are ready', function () {
    fullyReadyReportCharacterization($this->user, $this->e2);
    $this->actingAs($this->user)->getJson('/api/guided-report/evidence-bundle')
        ->assertOk()
        ->assertJsonPath('data.type', 'report_evidence_bundle')
        ->assertJsonStructure(['data' => ['version', 'ir_version_hash', 'asset_versions', 'unmapped_concepts', 'guidance_provenance']]);
});
