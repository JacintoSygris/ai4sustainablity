<?php

use App\Models\EsrsTopic;
use App\Models\User;
use App\Services\Report\DocxRenderer;
use App\Services\Report\ReportIrBuilder;

uses(Tests\TestCase::class, \Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * Non-visual IR keys. DENYLIST: every string leaf of the IR is required to appear in
 * the rendered document UNLESS its key is listed here. An allowlist ("check only these
 * keys") would let a NEW visual field go unrendered without failing — exactly the
 * Stage-1 defect this test exists to prevent. Adding a key here is a reviewable act.
 */
const NON_VISUAL_IR_KEYS = [
    'schema_version',   // machine version tag
    'version_hash',     // content hash
    'asset_versions',   // subtree: vendored asset version ids
    'block_key',        // internal corpus block identifier
    'datapoint_id',     // identifier; the human-readable `name` is rendered
    'standard',         // duplicates the rendered `dr_key`
    'node_id',          // rendered structurally as w:sdt/w:tag, asserted separately
    'xbrl_concept',     // rendered structurally in customXml, asserted separately
    'taggable_state',   // machine tagging state
    'provenance_tier',  // machine provenance tier; surfaced in the evidence bundle
    'authoritative',    // machine flag; drives the "[orientación general]" prefix
    'cross_refs',       // subtree: machine edges, mirrored by rendered cross_ref_sentences
];

/** @return list<string> every string leaf not under a non-visual key */
function irVisualLeaves(mixed $node, string $key = ''): array
{
    if (in_array($key, NON_VISUAL_IR_KEYS, true)) {
        return [];
    }

    if (is_string($node)) {
        return trim($node) === '' ? [] : [$node];
    }

    if (! is_array($node)) {
        return [];
    }

    $leaves = [];
    foreach ($node as $childKey => $child) {
        $leaves = [...$leaves, ...irVisualLeaves($child, is_string($childKey) ? $childKey : $key)];
    }

    return $leaves;
}

/**
 * Configure an approved AR16 matter -> Disclosure Requirement map that binds a topic
 * to specific DR keys. The stock `configureApprovedReportDrMap()` hardcodes `E2.IRO-1`,
 * which carries NO related-DR edges, so it can never exercise `cross_ref_sentences`.
 * Passing a DR key that DOES have edges (e.g. `S1-1` -> `MDR-P`) is what makes the
 * topical chapter emit a non-empty cross-reference sentence.
 *
 * @param  list<string>  $drKeys
 */
function configureApprovedReportDrMapForKeys(EsrsTopic $topic, array $drKeys): void
{
    $mappingPath = tempnam(sys_get_temp_dir(), 'i4s-dr-map-');

    file_put_contents($mappingPath, json_encode([
        'version' => 'v0',
        'source' => [
            'name' => 'Approved test AR16 matter to DR map (cross-ref)',
            'status' => 'approved',
        ],
        'mappings' => [
            [
                'ar16_topic_id' => $topic->id,
                'esrs_code' => $topic->esrs_code,
                'disclosure_requirements' => $drKeys,
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    config(['services.esrs_datapoints.matter_dr_mapping_path' => $mappingPath]);
}

/**
 * Every visual string leaf of the IR must appear in the rendered document. Fails with
 * a message that names the field nobody rendered.
 *
 * @param  array<string, mixed>  $ir
 */
function assertEveryVisualLeafRendered(array $ir): void
{
    $text = docxVisibleText((new DocxRenderer())->render($ir));

    $missing = [];
    foreach (array_unique(irVisualLeaves($ir)) as $leaf) {
        $normalized = preg_replace('/\s+/u', ' ', $leaf);
        if (! str_contains($text, $normalized)) {
            $missing[] = $leaf;
        }
    }

    // PHPUnit's assertSame carries a failure message; Pest's expect()->toBe() does not.
    // The message is the whole point here: it names the field nobody rendered.
    test()->assertSame([], $missing, 'Visual IR leaves missing from the rendered DOCX: '.implode(' | ', $missing));
}

beforeEach(function () {
    $this->seed(\Database\Seeders\EsrsTopicSeeder::class);
    $this->user = User::factory()->create();
    $this->e2 = EsrsTopic::where('esrs_code', 'E2')->firstOrFail();
});

it('renders every visual IR string leaf into the document', function () {
    // This fixture must produce the three fields the Stage-1 defect silently dropped:
    //   1. a topical chapter with non-empty floor_prose  (needs coverage_status=dr_level),
    //   2. a section with non-empty cross_ref_sentences   (needs a DR key that has edges),
    //   3. a non-empty omission_section.statements         (needs an unconfirmed snapshot topic).
    // Using E2 + the stock DR map (E2.IRO-1, edge-less) leaves 1 and 2 as empty non-leaves,
    // so the traversal has nothing to miss and the gate is vacuous. S1 mapped to S1-1
    // (whose related-DR edge points at the in-scope MDR-P) exercises all three.
    $s1 = EsrsTopic::where('esrs_code', 'S1')->firstOrFail();
    $e3 = EsrsTopic::where('esrs_code', 'E3')->firstOrFail();

    $characterization = reportReadyCharacterization($this->user, $s1);
    configureApprovedReportDrMapForKeys($s1, ['S1-1']);

    $formData = $characterization->form_data;
    // p6 proposed S1 + E3; only S1 was confirmed material -> E3 is an inferred omission.
    $formData['materiality_confirmation']['p6_snapshot'] = [
        'topic_ids' => [$s1->id, $e3->id],
        'captured_at' => '2026-01-01T00:00:00Z',
    ];
    $characterization->update(['form_data' => $formData]);

    $ir = app(ReportIrBuilder::class)->build($characterization->fresh());

    // Guard against silent re-vacuuming: assert the fixture actually built the three
    // fields BEFORE reconciling. If the corpus/omission plumbing ever stops producing
    // them, this fails loudly here rather than passing a gate that checks nothing.
    $topical = collect($ir['chapters'])->firstWhere('block_key', 'topical');
    expect($topical)->not->toBeNull();
    expect($topical['floor_prose'])->not->toBeEmpty();
    $crossRefSentences = collect($topical['sections'])->flatMap(fn ($s) => $s['cross_ref_sentences']);
    expect($crossRefSentences)->not->toBeEmpty();
    expect($ir['omission_section']['statements'])->not->toBeEmpty();

    assertEveryVisualLeafRendered($ir);
});

it('renders the omission declaration and unresolved limitation leaves', function () {
    // `declaration` (no p6 snapshot -> not-determinable) and a populated `statements`
    // list (snapshot present) are mutually exclusive, so they get their own case.
    // A guided no_material verdict on a non-existent topic id produces the unresolved
    // `limitation` while the absent snapshot produces the `declaration`.
    $characterization = reportReadyCharacterization($this->user, $this->e2);

    $formData = $characterization->form_data;
    unset($formData['materiality_confirmation']['p6_snapshot']);
    $formData['materiality_confirmation']['guided_answers'] = [
        '999999' => ['final_result' => 'no_material'],
    ];
    $characterization->update(['form_data' => $formData]);

    $ir = app(ReportIrBuilder::class)->build($characterization->fresh());

    expect($ir['omission_section']['declaration'])->not->toBeEmpty();
    expect($ir['omission_section']['limitation'])->not->toBeEmpty();

    assertEveryVisualLeafRendered($ir);
});

it('renders the E1 exception chapter explanation leaf', function () {
    // The E1-was-excluded chapter only exists when p6 proposed E1, E1 was NOT confirmed
    // material, and the mandatory free-text explanation is stored.
    $e1 = EsrsTopic::where('esrs_code', 'E1')->firstOrFail();
    $s1 = EsrsTopic::where('esrs_code', 'S1')->firstOrFail();

    $characterization = reportReadyCharacterization($this->user, $s1);

    $formData = $characterization->form_data;
    $formData['materiality_confirmation']['p6_snapshot'] = [
        'topic_ids' => [$s1->id, $e1->id],
        'captured_at' => '2026-01-01T00:00:00Z',
    ];
    $formData['materiality_confirmation']['e1_not_material_explanation'] =
        'E1 (Cambio climático) se excluyó porque la evaluación de doble materialidad no identificó impactos, riesgos ni oportunidades materiales.';
    $characterization->update(['form_data' => $formData]);

    $ir = app(ReportIrBuilder::class)->build($characterization->fresh());

    $e1Chapter = collect($ir['chapters'])->firstWhere('block_key', 'e1_not_material_explanation');
    expect($e1Chapter)->not->toBeNull();
    $assertion = $e1Chapter['sections'][0]['blocks'][0]['assertions'][0];
    expect($assertion)->toContain('Cambio climático');

    assertEveryVisualLeafRendered($ir);
});

it('binds every slot node id structurally to a w:sdt tag and the custom xml part', function () {
    $ir = app(ReportIrBuilder::class)->build(reportReadyCharacterization($this->user, $this->e2));
    $bytes = (new DocxRenderer())->render($ir);

    $documentXml = docxEntry($bytes, 'word/document.xml');
    $customXml = docxEntry($bytes, 'customXml/item1.xml');

    $nodeIds = collect($ir['chapters'])->flatMap(fn ($c) => $c['sections'])
        ->flatMap(fn ($s) => $s['blocks'])->flatMap(fn ($b) => $b['slots'])
        ->pluck('node_id');

    expect($nodeIds)->not->toBeEmpty();

    foreach ($nodeIds as $nodeId) {
        expect($documentXml)->toContain('w:tag w:val="'.$nodeId.'"');
        expect($customXml)->toContain('node_id="'.$nodeId.'"');
    }
});

it('fails when a new visual IR field is added but never rendered', function () {
    $ir = app(ReportIrBuilder::class)->build(reportReadyCharacterization($this->user, $this->e2));
    $ir['chapters'][0]['a_new_visual_field'] = 'Texto que nadie renderiza jamás';

    $text = docxVisibleText((new DocxRenderer())->render($ir));
    $missing = array_values(array_filter(
        array_unique(irVisualLeaves($ir)),
        fn (string $leaf) => ! str_contains($text, preg_replace('/\s+/u', ' ', $leaf))
    ));

    // The guarantee: an unlisted new key is checked by default and reported missing.
    expect($missing)->toContain('Texto que nadie renderiza jamás');
});
