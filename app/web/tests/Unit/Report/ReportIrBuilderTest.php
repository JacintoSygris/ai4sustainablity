<?php

use App\Models\Characterization;
use App\Models\EsrsTopic;
use App\Models\ReportApproval;
use App\Models\ReportingFact;
use App\Models\User;
use App\Services\Report\DocxRenderer;
use App\Services\Report\HtmlReportRenderer;
use App\Services\Report\ReportIrBuilder;
use App\Services\Report\ReportSnapshotBuilder;

// Unit tests are plain PHPUnit\Framework\TestCase by default (see tests/Pest.php,
// which only binds Tests\TestCase for the Feature suite); this test needs the
// database and container, so it opts into Tests\TestCase + RefreshDatabase here.
uses(Tests\TestCase::class, \Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\EsrsTopicSeeder::class);
    $this->user = User::factory()->create();
    $this->e2 = EsrsTopic::where('esrs_code', 'E2')->firstOrFail();
});

it('builds a versioned IR with node-bound slots and stable version hash', function () {
    $characterization = reportReadyCharacterization($this->user, $this->e2);

    $ir = app(ReportIrBuilder::class)->build($characterization);

    expect($ir['schema_version'])->toBe('p10_ir_v1');
    expect($ir['version_hash'])->toBeString()->toHaveLength(64);
    expect($ir['chapters'])->not->toBeEmpty();

    $slot = collect($ir['chapters'])->flatMap(fn ($c) => $c['sections'])
        ->flatMap(fn ($s) => $s['blocks'])->flatMap(fn ($b) => $b['slots'])->first();
    expect($slot)->toHaveKeys(['node_id', 'label', 'xbrl_concept', 'taggable_state']);

    // Determinism: same characterization → identical version hash.
    expect(app(ReportIrBuilder::class)->build($characterization)['version_hash'])->toBe($ir['version_hash']);
});

it('surfaces the mandatory E1 not-material explanation as a chapter block', function () {
    $e1Topic = EsrsTopic::where('esrs_code', 'E1')->firstOrFail();
    $explanation = 'E1 no material por ubicación';

    // P6 proposed E1 (esrs_topic_ids includes it), but P8 excluded it
    // (materiality_confirmation.confirmed_topic_ids does not include it) —
    // this is exactly the "applies=true" path of
    // EsrsDatapointCorpusBuilder::e1ExceptionBlock().
    $characterization = Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'nace_code' => 'A',
        'esrs_topic_ids' => [$this->e2->id, $e1Topic->id],
        'submitted_at' => now()->subDay(),
        'completed_at' => now(),
        'form_data' => [
            'company_profile' => [
                'company_name' => 'Entidad Demo',
                'reporting_year' => 2025,
                'product_service_type' => 'software_digital_services',
            ],
            'operations' => [
                'employee_count_range' => '50_249',
                'revenue_range' => '2m_to_10m',
                'regions' => ['eu'],
            ],
            'materiality_confirmation' => [
                'confirmed_topic_ids' => [$this->e2->id],
                'confirmed_at' => now()->toJSON(),
                'e1_not_material_explanation' => $explanation,
            ],
        ],
    ]);

    $ir = app(ReportIrBuilder::class)->build($characterization);

    $e1Chapter = collect($ir['chapters'])->firstWhere('block_key', 'e1_not_material_explanation');
    expect($e1Chapter)->not->toBeNull();
    expect($e1Chapter['sections'])->not->toBeEmpty();

    $assertions = collect($e1Chapter['sections'])
        ->flatMap(fn ($section) => $section['blocks'])
        ->flatMap(fn ($block) => $block['assertions']);

    expect($assertions->filter(fn ($text) => str_contains($text, $explanation)))->not->toBeEmpty();
});

it('pins the floor_prose shape as a string or null on every chapter', function () {
    $characterization = reportReadyCharacterization($this->user, $this->e2);

    $ir = app(ReportIrBuilder::class)->build($characterization);

    foreach ($ir['chapters'] as $chapter) {
        expect($chapter)->toHaveKey('floor_prose');
        expect($chapter['floor_prose'] === null || is_string($chapter['floor_prose']))->toBeTrue();
    }
});

it('still builds a valid IR when no material topics are confirmed', function () {
    $characterization = Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'nace_code' => 'A',
        'esrs_topic_ids' => [$this->e2->id],
        'submitted_at' => now()->subDay(),
        'completed_at' => now(),
        'form_data' => [
            'company_profile' => [
                'company_name' => 'Entidad Demo',
                'reporting_year' => 2025,
                'product_service_type' => 'software_digital_services',
            ],
            'operations' => [
                'employee_count_range' => '50_249',
                'revenue_range' => '2m_to_10m',
                'regions' => ['eu'],
            ],
            'materiality_confirmation' => [
                'confirmed_topic_ids' => [],
                'confirmed_at' => now()->toJSON(),
            ],
        ],
    ]);

    $ir = app(ReportIrBuilder::class)->build($characterization);

    expect($ir['schema_version'])->toBe('p10_ir_v1');
    expect($ir['version_hash'])->toBeString()->toHaveLength(64);

    // No confirmed material topics → no topical chapters; the always-required
    // ESRS 2 chapter still applies, so this is a valid (non-empty-schema) IR,
    // not an error.
    $blockKeys = collect($ir['chapters'])->pluck('block_key');
    expect($blockKeys)->toContain('always_required');
    expect($blockKeys)->not->toContain('topical');
});

it('carries an omission section with grade-correct statements', function (string $grade) {
    $e3 = EsrsTopic::where('esrs_code', 'E3')->firstOrFail();
    $e4 = EsrsTopic::where('esrs_code', 'E4')->firstOrFail();
    $isMixed = $grade === 'mixed';
    $hasDirect = $grade !== 'inferred-only';
    $omittedIds = $isMixed ? [$e3->id, $e4->id] : [$e3->id];
    $privateNote = 'Synthetic internal change_reason_note must not appear in visible output.';

    $characterization = reportReadyCharacterization($this->user, $this->e2);
    $formData = $characterization->form_data;
    $formData['materiality_confirmation']['p6_snapshot'] = [
        'topic_ids' => [$this->e2->id, ...$omittedIds],
        'captured_at' => '2026-01-01T00:00:00Z',
    ];
    if ($hasDirect) {
        // A recorded no_material verdict takes precedence over the snapshot delta.
        $formData['materiality_confirmation']['guided_answers'] = [
            (string) $e3->id => ['final_result' => 'no_material'],
        ];
    }
    $formData['materiality_confirmation']['change_reason_notes'] = array_fill_keys($omittedIds, $privateNote);
    $characterization->update(['form_data' => $formData]);

    $builder = app(ReportIrBuilder::class);
    $ir = $builder->build($characterization->fresh());
    $statements = $ir['omission_section']['statements'];
    expect($statements)->not->toBeEmpty()->toHaveCount(count($omittedIds));
    expect(collect($statements)->filter(fn ($text) => str_contains($text, 'se evaluó y no se consideró material.')))
        ->toHaveCount($hasDirect ? 1 : 0);
    expect(collect($statements)->filter(fn ($text) => str_contains($text, 'no fue confirmado como material')))
        ->toHaveCount($grade === 'direct' ? 0 : 1);
    expect($ir['omission_section']['declaration'])->toBeNull();

    reportIrBuilderFrozenFact($characterization, [
        'fact_id' => 'rf_omission_title',
        'value' => ['text' => 'Synthetic approved disclosure for the omission-title regression.'],
    ]);
    $snapshot = app(ReportSnapshotBuilder::class)->create($characterization->fresh());
    ReportApproval::create([
        'report_snapshot_id' => $snapshot->id,
        'user_id' => $this->user->id,
        'role_mode' => ReportApproval::ROLE_MODE_SINGLE_PERSON_DECLARED,
        'preparer_user_id' => $this->user->id,
        'reviewer_user_id' => $this->user->id,
        'approver_user_id' => $this->user->id,
        'single_person_declaration' => true,
        'snapshot_hash' => $snapshot->snapshot_hash,
        'approved_at' => now(),
    ]);
    $frozenIr = $builder->buildFromApprovedSnapshot($snapshot->fresh());
    expect($frozenIr['schema_version'])->toBe('report_ir_v1');
    expect($frozenIr['claims'])->toHaveCount(1);
    expect(collect($frozenIr['materiality_trace']['omitted_topics'])->pluck('evidence_grade')->all())->toBe(match ($grade) {
        'inferred-only' => ['inferred_from_snapshot_delta'],
        'direct' => ['direct_guided_answer'],
        'mixed' => ['direct_guided_answer', 'inferred_from_snapshot_delta'],
    });
    expect($frozenIr['omission_section']['statements'])->toBe($statements);
    expect($frozenIr['omission_section']['declaration'])->toBeNull();

    $htmlText = html_entity_decode(strip_tags((new HtmlReportRenderer())->render($frozenIr)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $docxText = docxVisibleText((new DocxRenderer())->render($frozenIr));
    foreach ([$htmlText, $docxText] as $visibleText) {
        expect($visibleText)->not->toContain($privateNote);
        foreach ($statements as $statement) {
            expect(substr_count($visibleText, $statement))->toBe(1);
        }
    }

    $neutralTitle = 'Temas no confirmados como materiales';
    expect($ir['omission_section']['title'])->toBe($neutralTitle);
    expect($frozenIr['omission_section']['title'])->toBe($neutralTitle);
    foreach ([$htmlText, $docxText] as $visibleText) {
        expect(substr_count($visibleText, $neutralTitle))->toBe(1);
        expect($visibleText)->not->toContain('Temas evaluados y no considerados materiales');
    }
})->with(['inferred-only', 'direct', 'mixed']);

it('declares the provenance gap when the p6 snapshot is absent', function () {
    // reportReadyCharacterization() stores no p6_snapshot.
    $ir = app(ReportIrBuilder::class)->build(reportReadyCharacterization($this->user, $this->e2));

    expect($ir['omission_section']['statements'])->toBe([]);
    expect($ir['omission_section']['declaration'])->toContain('No consta registro de la propuesta inicial');
});

it('adds a stale disclaimer when the proposal drifted after confirmation', function () {
    $e3 = EsrsTopic::where('esrs_code', 'E3')->firstOrFail();

    $characterization = reportReadyCharacterization($this->user, $this->e2);
    $formData = $characterization->form_data;
    $formData['materiality_confirmation']['p6_snapshot'] = [
        'topic_ids' => [$this->e2->id, $e3->id],
        'captured_at' => '2026-01-01T00:00:00Z',
    ];
    $characterization->update(['form_data' => $formData]);   // live esrs_topic_ids = [e2] only

    $ir = app(ReportIrBuilder::class)->build($characterization->fresh());

    expect(collect($ir['disclaimers'])->contains(fn ($d) => str_contains($d, 'cambió después de la confirmación')))->toBeTrue();
});

it('exposes rendered cross-reference sentences alongside the machine edges', function () {
    $ir = app(ReportIrBuilder::class)->build(reportReadyCharacterization($this->user, $this->e2));

    $section = collect($ir['chapters'])->flatMap(fn ($c) => $c['sections'])->first();
    expect($section)->toHaveKey('cross_ref_sentences');
    expect($section['cross_ref_sentences'])->toBeArray();
    expect(count($section['cross_ref_sentences']))->toBe(count($section['cross_refs']));
});

it('declares an omission exactly once when its standard also has a confirmed material topic', function () {
    // Owner decision 2026-07-09: dedup the omission statement. Before this, a standard
    // with BOTH a confirmed topic (-> a topical chapter with floor_prose) AND a
    // rejected topic of the SAME standard rendered the rejected topic's sentence
    // twice: once inside chapterIntro()'s notMaterial clause, once in the
    // consolidated root omission_section. Two distinct E3 topics (E3 has five) give
    // one confirmed + one rejected sibling under the same standard.
    $e3Topics = EsrsTopic::where('esrs_code', 'E3')->orderBy('id')->take(2)->get();
    expect($e3Topics)->toHaveCount(2);
    $e3Confirmed = $e3Topics[0];
    $e3Rejected = $e3Topics[1];

    $characterization = reportReadyCharacterization($this->user, $e3Confirmed);
    configureApprovedReportDrMapForKeys($e3Confirmed, ['E3-1']);

    $formData = $characterization->form_data;
    $formData['materiality_confirmation']['p6_snapshot'] = [
        'topic_ids' => [$e3Confirmed->id, $e3Rejected->id],
        'captured_at' => '2026-01-01T00:00:00Z',
    ];
    $characterization->update(['form_data' => $formData]);

    $ir = app(ReportIrBuilder::class)->build($characterization->fresh());

    $topical = collect($ir['chapters'])->firstWhere('block_key', 'topical');
    expect($topical)->not->toBeNull();
    expect($topical['floor_prose'])->not->toBeEmpty();

    expect($ir['omission_section']['statements'])->toHaveCount(1);
    $omissionStatement = $ir['omission_section']['statements'][0];

    // Not repeated inside the chapter's own prose...
    expect($topical['floor_prose'])->not->toContain($omissionStatement);

    // ...and declared exactly once in the whole rendered document.
    $text = docxVisibleText((new DocxRenderer())->render($ir));
    expect(substr_count($text, $omissionStatement))->toBe(1);
});

it('builds report_ir_v1 claims only from an approved frozen snapshot', function () {
    $characterization = reportReadyCharacterization($this->user, $this->e2);
    $formData = $characterization->form_data;
    $formData['company_profile']['entity_identifier'] = 'TESTFROZENID00000000';
    $formData['company_profile']['entity_identifier_scheme'] = 'https://standards.iso.org/iso/17442';
    $characterization->update(['form_data' => $formData]);

    $fact = reportIrBuilderFrozenFact($characterization, [
        'fact_id' => 'rf_frozen',
        'datapoint_id' => 'BP-1_01',
        'value' => ['text' => 'Frozen approved fact'],
    ]);
    reportIrBuilderFrozenFact($characterization, [
        'fact_id' => 'rf_not_applicable',
        'datapoint_id' => 'BP-1_02',
        'applicability' => 'not_applicable',
        'value_type' => 'nil',
        'value' => null,
        'language' => null,
        'nil' => false,
    ]);
    reportIrBuilderFrozenFact($characterization, [
        'fact_id' => 'rf_historical_orphan',
        'datapoint_id' => 'BP-1_02',
        'value' => ['text' => 'Historical fact must not become a claim.'],
    ]);

    $snapshot = app(ReportSnapshotBuilder::class)->create($characterization);
    $approval = ReportApproval::create([
        'report_snapshot_id' => $snapshot->id,
        'user_id' => $this->user->id,
        'role_mode' => ReportApproval::ROLE_MODE_SINGLE_PERSON_DECLARED,
        'preparer_user_id' => $this->user->id,
        'reviewer_user_id' => $this->user->id,
        'approver_user_id' => $this->user->id,
        'single_person_declaration' => true,
        'snapshot_hash' => $snapshot->snapshot_hash,
        'approved_at' => now(),
    ]);

    $ir = app(ReportIrBuilder::class)->buildFromApprovedSnapshot($snapshot->fresh());

    expect($ir['schema_version'])->toBe('report_ir_v1');
    expect($ir['source'])->toMatchArray([
        'report_snapshot_id' => $snapshot->id,
        'report_approval_id' => $approval->id,
        'snapshot_hash' => $snapshot->snapshot_hash,
        'profile_id' => $snapshot->profile_id,
        'profile_hash' => $snapshot->profile_hash,
    ]);
    expect($ir['source']['approved_at'])->toBe($approval->approved_at->toJSON());
    expect($ir['claims'])->toHaveCount(1);
    expect($ir['claims'][0]['fact_id'])->toBe('rf_frozen');
    expect($ir['claims'][0]['value'])->toBe(['text' => 'Frozen approved fact']);
    expect($ir['fact_decisions'])->toHaveCount(3);
    expect(collect($ir['fact_decisions'])->firstWhere('fact_id', 'rf_frozen')['output_section'])->toBe('body');
    expect(collect($ir['fact_decisions'])->firstWhere('fact_id', 'rf_not_applicable')['output_section'])->toBe('not_applicable_appendix');
    expect(collect($ir['fact_decisions'])->firstWhere('fact_id', 'rf_historical_orphan')['output_section'])->toBe('not_applicable_appendix');
    expect($ir['company'])->toMatchArray([
        'entity_identifier' => 'TESTFROZENID00000000',
        'entity_identifier_scheme' => 'https://standards.iso.org/iso/17442',
    ]);

    $attachedClaimIds = collect($ir['chapters'])
        ->flatMap(fn ($chapter) => $chapter['sections'])
        ->flatMap(fn ($section) => $section['blocks'])
        ->firstWhere('datapoint_id', 'BP-1_01')['claims'] ?? [];
    expect($attachedClaimIds)->toContain($ir['claims'][0]['claim_id']);

    $firstHash = $ir['version_hash'];

    $fact->update(['value' => ['text' => 'Live mutation must not leak']]);
    $liveFormData = $characterization->fresh()->form_data;
    $liveFormData['company_profile']['entity_identifier'] = 'TESTLIVEID0000000000';
    $liveFormData['company_profile']['entity_identifier_scheme'] = 'https://example.com/live-must-not-leak';
    $characterization->update(['form_data' => $liveFormData]);
    $rebuilt = app(ReportIrBuilder::class)->buildFromApprovedSnapshot($snapshot->fresh());

    expect($rebuilt['claims'][0]['value'])->toBe(['text' => 'Frozen approved fact']);
    expect(json_encode($rebuilt, JSON_THROW_ON_ERROR))->not->toContain('Live mutation must not leak');
    expect($rebuilt['company'])->toMatchArray([
        'entity_identifier' => 'TESTFROZENID00000000',
        'entity_identifier_scheme' => 'https://standards.iso.org/iso/17442',
    ]);
    expect(json_encode($rebuilt, JSON_THROW_ON_ERROR))->not->toContain('TESTLIVEID0000000000')
        ->not->toContain('https://example.com/live-must-not-leak');
    expect($rebuilt['version_hash'])->toBe($firstHash);

    $snapshotWithoutApproval = app(ReportSnapshotBuilder::class)->create($characterization->fresh());
    expect(fn () => app(ReportIrBuilder::class)->buildFromApprovedSnapshot($snapshotWithoutApproval->fresh()))
        ->toThrow(DomainException::class);

    $approval->update(['snapshot_hash' => 'wrong_hash']);
    expect(fn () => app(ReportIrBuilder::class)->buildFromApprovedSnapshot($snapshot->fresh()))
        ->toThrow(DomainException::class);

    $approval->update(['snapshot_hash' => $snapshot->snapshot_hash]);
    $snapshot->update(['profile_hash' => 'wrong_profile_hash']);
    expect(fn () => app(ReportIrBuilder::class)->buildFromApprovedSnapshot($snapshot->fresh()))
        ->toThrow(DomainException::class);
});

it('rejects a frozen snapshot payload whose contents no longer match its stored commitments', function () {
    $characterization = reportReadyCharacterization($this->user, $this->e2);
    reportIrBuilderFrozenFact($characterization, [
        'fact_id' => 'rf_tamper_guard',
        'datapoint_id' => 'BP-1_01',
        'value' => ['text' => 'Approved value'],
    ]);

    $snapshot = app(ReportSnapshotBuilder::class)->create($characterization);
    ReportApproval::create([
        'report_snapshot_id' => $snapshot->id,
        'user_id' => $this->user->id,
        'role_mode' => ReportApproval::ROLE_MODE_SINGLE_PERSON_DECLARED,
        'preparer_user_id' => $this->user->id,
        'reviewer_user_id' => $this->user->id,
        'approver_user_id' => $this->user->id,
        'single_person_declaration' => true,
        'snapshot_hash' => $snapshot->snapshot_hash,
        'approved_at' => now(),
    ]);

    $payload = $snapshot->snapshot_json;
    $payload['facts'][0]['value'] = ['text' => 'Tampered after approval'];
    $snapshot->update(['snapshot_json' => $payload]);

    expect(fn () => app(ReportIrBuilder::class)->buildFromApprovedSnapshot($snapshot->fresh()))
        ->toThrow(DomainException::class, 'report_snapshot_facts_hash_mismatch');
});

it('adds entity identifier fields to report_ir_v1 only from explicit frozen company profile values', function () {
    $characterization = reportReadyCharacterization($this->user, $this->e2);

    $withoutExplicitId = app(ReportIrBuilder::class)->build($characterization);
    expect($withoutExplicitId['company'])->not->toHaveKey('entity_identifier')
        ->not->toHaveKey('entity_identifier_scheme');

    $formData = $characterization->form_data;
    $formData['company_profile']['entity_identifier'] = 'TESTENTITYID00000000';
    $formData['company_profile']['entity_identifier_scheme'] = 'https://standards.iso.org/iso/17442';
    $characterization->update(['form_data' => $formData]);

    $snapshot = app(ReportSnapshotBuilder::class)->create($characterization->fresh());
    $formData['company_profile']['entity_identifier'] = 'TESTLIVEID0000000000';
    $formData['company_profile']['entity_identifier_scheme'] = 'https://example.com/live-must-not-leak';
    $characterization->update(['form_data' => $formData]);

    ReportApproval::create([
        'report_snapshot_id' => $snapshot->id,
        'user_id' => $this->user->id,
        'role_mode' => ReportApproval::ROLE_MODE_SINGLE_PERSON_DECLARED,
        'preparer_user_id' => $this->user->id,
        'reviewer_user_id' => $this->user->id,
        'approver_user_id' => $this->user->id,
        'single_person_declaration' => true,
        'snapshot_hash' => $snapshot->snapshot_hash,
        'approved_at' => now(),
    ]);

    $ir = app(ReportIrBuilder::class)->buildFromApprovedSnapshot($snapshot->fresh());

    expect($ir['schema_version'])->toBe('report_ir_v1');
    expect($ir['company'])->toMatchArray([
        'entity_identifier' => 'TESTENTITYID00000000',
        'entity_identifier_scheme' => 'https://standards.iso.org/iso/17442',
    ]);
    expect(json_encode($ir, JSON_THROW_ON_ERROR))->not->toContain('TESTLIVEID0000000000')
        ->not->toContain('https://example.com/live-must-not-leak');
});

it('projects factual claims into deterministic slots and removes empty inherited factual slots', function () {
    $characterization = reportReadyCharacterization($this->user, $this->e2);
    reportIrBuilderFrozenFact($characterization, [
        'fact_id' => 'rf_frozen',
        'datapoint_id' => 'BP-1_01',
        'value' => ['text' => 'Frozen approved fact'],
    ]);

    $snapshot = app(ReportSnapshotBuilder::class)->create($characterization);
    ReportApproval::create([
        'report_snapshot_id' => $snapshot->id,
        'user_id' => $this->user->id,
        'role_mode' => ReportApproval::ROLE_MODE_SINGLE_PERSON_DECLARED,
        'preparer_user_id' => $this->user->id,
        'reviewer_user_id' => $this->user->id,
        'approver_user_id' => $this->user->id,
        'single_person_declaration' => true,
        'snapshot_hash' => $snapshot->snapshot_hash,
        'approved_at' => now(),
    ]);

    $ir = app(ReportIrBuilder::class)->buildFromApprovedSnapshot($snapshot->fresh());

    $claimedBlock = collect($ir['chapters'])
        ->flatMap(fn ($chapter) => $chapter['sections'])
        ->flatMap(fn ($section) => $section['blocks'])
        ->firstWhere('datapoint_id', 'BP-1_01');

    expect($claimedBlock['claims'])->toHaveCount(1);
    expect($claimedBlock['slots'])->toHaveCount(1);
    expect($claimedBlock['slots'][0])->toMatchArray([
        'node_id' => 'slot_BP-1_01_rf_frozen',
        'claim_id' => $ir['claims'][0]['claim_id'],
        'fact_id' => 'rf_frozen',
        'label' => $claimedBlock['name'],
        'taggable_state' => 'mapped',
    ]);

    $unclaimedBlocksWithSlots = collect($ir['chapters'])
        ->flatMap(fn ($chapter) => $chapter['sections'])
        ->flatMap(fn ($section) => $section['blocks'])
        ->filter(fn ($block) => ($block['claims'] ?? []) === [] && ($block['slots'] ?? []) !== []);

    expect($unclaimedBlocksWithSlots)->toBeEmpty();

    $factualBlocks = collect($ir['chapters'])
        ->flatMap(fn ($chapter) => $chapter['sections'])
        ->flatMap(fn ($section) => $section['blocks']);

    expect($factualBlocks)->toHaveCount(1);
    expect($factualBlocks->first())->toMatchArray([
        'datapoint_id' => 'BP-1_01',
        'name' => 'Información reportada',
        'assertions' => [],
        'guidance' => [],
    ]);
});

function reportIrBuilderFrozenFact(Characterization $characterization, array $overrides = []): ReportingFact
{
    return ReportingFact::create(array_replace([
        'characterization_id' => $characterization->id,
        'fact_id' => 'rf_default',
        'schema_version' => ReportingFact::SCHEMA_VERSION,
        'profile_id' => ReportingFact::PROFILE_ID,
        'datapoint_id' => 'BP-1_01',
        'applicability' => 'applicable',
        'value_type' => 'text',
        'value' => ['text' => 'Default fact.'],
        'unit' => null,
        'decimals' => null,
        'dimensions' => [],
        'language' => 'en',
        'nil' => false,
        'nil_reason' => null,
        'evidence_refs' => [['type' => 'note', 'value' => 'Internal support.']],
        'provenance' => 'api',
        'approval_status' => 'reviewed',
        'blocking_reasons' => [],
    ], $overrides));
}
