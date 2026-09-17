<?php

use App\Models\Characterization;
use App\Models\EsrsTopic;
use App\Models\User;
use App\Services\Report\DocxRenderer;
use App\Services\Report\ReportIrBuilder;

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

it('carries an omission section with grade-correct statements', function () {
    $e3 = EsrsTopic::where('esrs_code', 'E3')->firstOrFail();

    $characterization = reportReadyCharacterization($this->user, $this->e2);
    $formData = $characterization->form_data;
    $formData['materiality_confirmation']['p6_snapshot'] = [
        'topic_ids' => [$this->e2->id, $e3->id],
        'captured_at' => '2026-01-01T00:00:00Z',
    ];
    $characterization->update(['form_data' => $formData]);

    $ir = app(ReportIrBuilder::class)->build($characterization->fresh());

    expect($ir['omission_section']['title'])->toBe('Temas evaluados y no considerados materiales');
    expect($ir['omission_section']['statements'])->toHaveCount(1);
    expect($ir['omission_section']['statements'][0])->toContain('no fue confirmado como material');
    expect($ir['omission_section']['declaration'])->toBeNull();
});

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
