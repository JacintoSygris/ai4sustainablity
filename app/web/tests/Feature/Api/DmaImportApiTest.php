<?php

use App\Models\Characterization;
use App\Models\DmaImport;
use App\Models\EsrsTopic;
use App\Models\User;
use App\Services\Dma\DmaCsvParser;
use App\Services\Dma\DmaSuggestionBuilder;
use App\Services\Report\EvidenceBundleBuilder;
use App\Services\Report\ReportSnapshotBuilder;
use App\Support\DoubleMaterialityGuide;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    config(['services.private_dev.auto_login' => false, 'services.dma_import.enabled' => true]);
    $this->seed(\Database\Seeders\EsrsTopicSeeder::class);
    $this->user = User::factory()->create();
    $this->topic = EsrsTopic::where('esrs_code', 'E2')->firstOrFail();
    $this->characterization = Characterization::factory()->create([
        'user_id' => $this->user->id, 'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [$this->topic->id],
    ]);
    $this->url = '/api/characterizations/'.$this->characterization->id.'/dma-imports';
});

function dmaTestCsv(array $rows, string $template = 'iro_register', string $locale = 'es', string $delimiter = ','): string
{
    $stream = fopen('php://temp', 'r+');
    fputcsv($stream, array_map(fn ($column) => $column['label'][$locale], DoubleMaterialityGuide::template($template)['columns']), $delimiter, '"', '');
    foreach ($rows as $row) { fputcsv($stream, $row, $delimiter, '"', ''); }
    rewind($stream); $content = stream_get_contents($stream); fclose($stream);
    return $content;
}

function dmaTestRow(int $topic, string $verdict = 'material', string $impact = 'material', string $financial = 'no material'): array
{
    return [(string) $topic, 'Synthetic test topic', 'Synthetic test impact', 'impacto', 'upstream', 'Synthetic stakeholder', $impact, $financial, $verdict, 'Synthetic evidence'];
}

function dmaTestFile(string $content, string $name = 'synthetic-register.csv'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $content);
}

it('parses the exact P7 ES and EN download headers with BOM and comma or semicolon delimiters', function (string $locale, string $delimiter) {
    $parser = new DmaCsvParser;
    $csv = "\xEF\xBB\xBF".dmaTestCsv([dmaTestRow($this->topic->id)], 'iro_register', $locale, $delimiter);
    $rows = $parser->parse($csv, 'iro_register', [$this->topic->id], 'iro_file');
    expect($rows[0]['topic_id'])->toBe($this->topic->id)->and($rows[0]['row'])->toBe(2);
})->with([['es', ','], ['en', ','], ['es', ';'], ['en', ';']]);

it('supports quoted commas, escaped quotes and multiline cells with physical line references', function () {
    $one = dmaTestRow($this->topic->id); $one[2] = "Synthetic \"quote\", first\nsecond";
    $rows = (new DmaCsvParser)->parse(dmaTestCsv([$one, dmaTestRow($this->topic->id)]), 'iro_register', [$this->topic->id], 'iro_file');
    expect($rows[0]['iro_description'])->toBe($one[2])->and($rows[1]['row'])->toBe(4);
});

it('rejects malformed quoting, headers, widths, encoding and empty templates', function (string $case) {
    $csv = dmaTestCsv([dmaTestRow($this->topic->id)]);
    $csv = match ($case) {
        'quotes' => dmaTestCsv([])."\"unterminated",
        'header' => "wrong,headers\n1,2\n",
        'width' => dmaTestCsv([])."1,2\n",
        'utf8' => $csv."\xFF",
        'nul' => $csv."\x00",
        'empty' => dmaTestCsv([]),
    };
    expect(fn () => (new DmaCsvParser)->parse($csv, 'iro_register', [$this->topic->id], 'iro_file'))->toThrow(ValidationException::class);
})->with(['quotes', 'header', 'width', 'utf8', 'nul', 'empty']);

it('reports all precise row-level validation errors atomically', function () {
    $row = dmaTestRow(999999); $row[2] = ''; $row[3] = 'not-an-iro'; $row[6] = '=1+1'; $row[8] = 'maybe';
    $this->actingAs($this->user)->postJson($this->url, ['iro_file' => dmaTestFile(dmaTestCsv([$row]))])
        ->assertUnprocessable()->assertJsonValidationErrors(['iro_file.row.2.ar16_topic_id', 'iro_file.row.2.iro_description',
            'iro_file.row.2.iro_type', 'iro_file.row.2.impact_materiality_score', 'iro_file.row.2.threshold_result']);
    $this->assertDatabaseCount('dma_imports', 0);
});

it('rejects excessive byte size, row count and cell size in the parser', function (string $case) {
    $row = dmaTestRow($this->topic->id);
    $csv = match ($case) {
        'bytes' => str_repeat('x', DmaCsvParser::MAX_BYTES + 1),
        'rows' => dmaTestCsv(array_fill(0, DmaCsvParser::MAX_ROWS + 1, $row)),
        'cell' => dmaTestCsv([[...array_slice($row, 0, 2), str_repeat('x', DmaCsvParser::MAX_CELL_BYTES + 1), ...array_slice($row, 3)]]),
    };
    expect(fn () => (new DmaCsvParser)->parse($csv, 'iro_register', [$this->topic->id], 'iro_file'))->toThrow(ValidationException::class);
})->with(['bytes', 'rows', 'cell']);

it('requires authentication on upload, read and export', function () {
    $this->getJson($this->url)->assertUnauthorized();
    $this->postJson($this->url)->assertUnauthorized();
    $this->getJson($this->url.'/1/iro.csv')->assertUnauthorized();
});

it('hides upload and read and the P8 flag when disabled', function () {
    config(['services.dma_import.enabled' => false]);
    $this->actingAs($this->user)->getJson($this->url)->assertNotFound();
    $this->postJson($this->url)->assertNotFound();
    $this->getJson($this->url.'/1/iro.csv')->assertNotFound();
    $this->getJson('/api/materiality-confirmation')->assertJsonMissingPath('data.dma_import_enabled');
    $this->putJson('/api/materiality-confirmation', ['expected_revision' => 0, 'confirmed_topic_ids' => [$this->topic->id],
        'dma_import_refs' => [(string) $this->topic->id => 1]])->assertUnprocessable()->assertJsonValidationErrors('dma_import_refs');
});

it('prevents IDOR on upload, read and export even for a valid import ID', function () {
    $response = $this->actingAs($this->user)->postJson($this->url, ['iro_file' => dmaTestFile(dmaTestCsv([dmaTestRow($this->topic->id)]))])->assertCreated();
    $id = $response->json('data.id');
    $other = User::factory()->create();
    $this->actingAs($other)->getJson($this->url)->assertNotFound();
    $this->postJson($this->url, ['iro_file' => dmaTestFile(dmaTestCsv([dmaTestRow($this->topic->id)]))])->assertNotFound();
    $this->getJson($this->url.'/'.$id.'/iro.csv')->assertNotFound();
});

it('rejects oversized uploads and disguised non-CSV files without storing records', function () {
    $this->actingAs($this->user)->postJson($this->url, ['iro_file' => UploadedFile::fake()->create('large.csv', 1025, 'text/csv')])
        ->assertUnprocessable()->assertJsonValidationErrors('iro_file');
    $this->postJson($this->url, ['iro_file' => dmaTestFile(dmaTestCsv([dmaTestRow($this->topic->id)]), 'register.html')])
        ->assertUnprocessable()->assertJsonValidationErrors('iro_file');
    $this->assertDatabaseCount('dma_imports', 0);
});

it('stores immutable provenance, scores, counts and linked stakeholder support without changing P8', function (string $locale) {
    $csv = dmaTestCsv([dmaTestRow($this->topic->id), dmaTestRow($this->topic->id, 'no material', '2,5', '1')], 'iro_register', $locale);
    $log = dmaTestCsv([['2026-10-08', 'Synthetic group', 'Synthetic interview', 'IRO:2', 'Synthetic support', 'material', 'Synthetic ref']], 'stakeholder_consultation_log', $locale);
    $response = $this->actingAs($this->user)->postJson($this->url, ['iro_file' => dmaTestFile($csv), 'stakeholder_file' => dmaTestFile($log, 'synthetic-consultations.csv')])
        ->assertCreated()->assertJsonPath('data.sources.iro_file.sha256', hash('sha256', $csv))
        ->assertJsonPath('data.sources.iro_file.original_name', 'synthetic-register.csv')->assertJsonPath('data.user_id', $this->user->id)
        ->assertJsonPath('data.suggestions.0.suggestion', 'material')->assertJsonPath('data.suggestions.0.dimension', 'impact')
        ->assertJsonPath('data.suggestions.0.supporting_iro_rows', [2])->assertJsonPath('data.suggestions.0.iro_count', 2)->assertJsonPath('data.suggestions.0.impact_scores.0', 2.5)
        ->assertJsonPath('data.suggestions.0.stakeholder_support_count', 1)->assertJsonPath('data.suggestions.0.stakeholder_rows', [2]);
    expect($this->characterization->fresh()->form_data['materiality_confirmation'] ?? null)->toBeNull();
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.id', $response->json('data.id'));
    $this->getJson('/api/materiality-confirmation')->assertJsonPath('data.dma_import_enabled', true)->assertJsonPath('data.is_confirmed', false);
})->with(['es', 'en']);

it('maps no-material, incomplete and conflicting IRO assessments conservatively without interpreting numeric scales', function () {
    $parser = new DmaCsvParser; $builder = new DmaSuggestionBuilder($parser);
    foreach ([['no material', '2', '1', 'no_material'], ['', '99', '', 'review'], ['material', 'no material', 'no material', 'review'],
        ['no material', 'material', '', 'review'], ['material', '99', '', 'material']] as [$verdict, $impact, $financial, $expected]) {
        $rows = $parser->parse(dmaTestCsv([dmaTestRow($this->topic->id, $verdict, $impact, $financial)]), 'iro_register', [$this->topic->id], 'iro_file');
        $suggestion = $builder->build($rows, [])['suggestions'][0];
        expect($suggestion['suggestion'])->toBe($expected)->and($suggestion['dimension'])->toBeNull();
    }
});

it('rejects unresolvable or invalid stakeholder links and dates without partial storage', function (string $link, string $date) {
    $log = dmaTestCsv([[$date, 'Synthetic group', 'Synthetic source', $link, 'Synthetic input', '', '']], 'stakeholder_consultation_log');
    $this->actingAs($this->user)->postJson($this->url, ['iro_file' => dmaTestFile(dmaTestCsv([dmaTestRow($this->topic->id)])),
        'stakeholder_file' => dmaTestFile($log)])->assertUnprocessable();
    $this->assertDatabaseCount('dma_imports', 0);
})->with([['IRO:999', '2026-10-08'], ['free prose', '2026-10-08'], ['IRO:2', '2026-02-30'], ['IRO:2', '2999-01-01']]);

it('preserves formula text on ingestion and neutralizes it on every re-exported cell', function () {
    $row = dmaTestRow($this->topic->id); $row[2] = '  =HYPERLINK("https://example.invalid")'; $row[9] = "\t+cmd";
    $response = $this->actingAs($this->user)->postJson($this->url, ['iro_file' => dmaTestFile(dmaTestCsv([$row]))])->assertCreated();
    $response->assertJsonPath('data.iro_rows.0.iro_description', $row[2]);
    $export = $this->getJson($this->url.'/'.$response->json('data.id').'/iro.csv')->assertOk()->getContent();
    $cells = str_getcsv(explode("\n", $export)[1], ',', '"', '');
    expect($cells[2])->toBe("'".$row[2])->and($cells[9])->toBe("'".$row[9]);
    foreach (['=1', '+1', '-1', '@SUM(1)', "\r=1", "\n=1", '  =1', "\xEF\xBB\xBF=1"] as $value) {
        expect(\App\Http\Controllers\Api\DmaImportController::safeCell($value))->toBe("'".$value);
    }
});

it('requires explicit P8 confirmation and freezes owned per-topic import evidence in report bundles', function () {
    $this->characterization->forceFill(['nace_code' => 'A', 'form_data' => reportBaselineFormData()])->save();
    $csv = dmaTestCsv([dmaTestRow($this->topic->id)]);
    $id = $this->actingAs($this->user)->postJson($this->url, ['iro_file' => dmaTestFile($csv)])->assertCreated()->json('data.id');
    $this->putJson('/api/materiality-confirmation', ['expected_revision' => 0, 'confirmed_topic_ids' => [$this->topic->id],
        'dma_import_refs' => [(string) $this->topic->id => $id]])->assertOk()->assertJsonPath('data.is_confirmed', true)
        ->assertJsonPath('data.confirmation.dma_import_refs.'.$this->topic->id, $id);
    $evidence = $this->characterization->fresh()->form_data['materiality_confirmation']['dma_import_evidence'];
    expect($evidence[0]['basis'])->toBe('imported_adm')->and($evidence[0]['sources']['iro_file']['sha256'])->toBe(hash('sha256', $csv));
    $this->getJson('/api/report/evidence-bundle')->assertConflict();
    configureApprovedReportDrMap($this->topic);
    completeDoubleMaterialityProcess($this->characterization->fresh());
    completeReportDatapointResponses($this->characterization->fresh());
    $this->getJson('/api/report')->assertOk()->assertJsonPath('data.workflow_complete', true);
    $this->getJson('/api/report/evidence-bundle')->assertOk()->assertJsonPath('data.bundle.dma_import_evidence.0.import_id', $id);
    $state = app(ReportSnapshotBuilder::class)->buildCanonicalState($this->characterization->fresh());
    expect($state['characterization']['form_data']['materiality_confirmation']['dma_import_evidence'][0]['import_id'])->toBe($id);
    expect($state['characterization']['form_data']['materiality_confirmation']['dma_import_evidence'][0]['sources']['iro_file']['sha256'])->toBe(hash('sha256', $csv));
    $bundle = (new EvidenceBundleBuilder)->build(['dma_import_evidence' => $evidence]);
    expect($bundle['dma_import_evidence'])->toBe($evidence);
    config(['services.dma_import.enabled' => false]);
    $this->getJson('/api/report/evidence-bundle')->assertJsonPath('data.bundle.dma_import_evidence.0.import_id', $id);
});

it('rejects forged import references, wrong topic references and stale confirmation writes', function () {
    $id = $this->actingAs($this->user)->postJson($this->url, ['iro_file' => dmaTestFile(dmaTestCsv([dmaTestRow($this->topic->id)]))])->assertCreated()->json('data.id');
    $other = EsrsTopic::where('id', '!=', $this->topic->id)->firstOrFail();
    foreach ([[(string) $this->topic->id => $id + 999], [(string) $other->id => $id], ['01' => $id]] as $refs) {
        $this->putJson('/api/materiality-confirmation', ['expected_revision' => 0, 'confirmed_topic_ids' => [$this->topic->id], 'dma_import_refs' => $refs])->assertUnprocessable();
    }
    $this->putJson('/api/materiality-confirmation', ['expected_revision' => 10, 'confirmed_topic_ids' => [$this->topic->id],
        'dma_import_refs' => [(string) $this->topic->id => $id]])->assertConflict();
    expect($this->characterization->fresh()->form_data['materiality_confirmation'] ?? null)->toBeNull();
});

it('rate limits repeated ADM uploads', function () {
    $this->actingAs($this->user);
    for ($i = 0; $i < 5; $i++) {
        $this->postJson($this->url, ['iro_file' => dmaTestFile(dmaTestCsv([dmaTestRow($this->topic->id)]))])->assertCreated();
    }
    $this->postJson($this->url)->assertTooManyRequests();
});

it('purges imported company data on characterization deletion', function () {
    $this->actingAs($this->user)->postJson($this->url, ['iro_file' => dmaTestFile(dmaTestCsv([dmaTestRow($this->topic->id)]))])->assertCreated();
    $this->characterization->delete();
    $this->assertDatabaseCount('dma_imports', 0);
});


it('rejects existing imports belonging to another user or characterization at the P8 write boundary', function () {
    $csv = dmaTestCsv([dmaTestRow($this->topic->id)]);
    $id = $this->actingAs($this->user)->postJson($this->url, ['iro_file' => dmaTestFile($csv)])->assertCreated()->json('data.id');
    $original = DmaImport::findOrFail($id);
    $otherOwner = User::factory()->create();
    $otherCharacterization = Characterization::factory()->create(['user_id' => $otherOwner->id]);
    // Exercise both predicates independently without violating one characterization per user.
    // Cross-linked rows simulate corrupt provenance and must also be rejected.
    foreach ([[$otherCharacterization->id, $otherOwner->id],
        [$otherCharacterization->id, $this->user->id], [$this->characterization->id, $otherOwner->id]] as [$characterizationId, $ownerId]) {
        $other = DmaImport::create(['characterization_id' => $characterizationId, 'user_id' => $ownerId,
            'sources' => $original->sources, 'payload' => $original->payload, 'uploaded_at' => now()]);
        $this->putJson('/api/materiality-confirmation', ['expected_revision' => 0, 'confirmed_topic_ids' => [$this->topic->id],
            'dma_import_refs' => [(string) $this->topic->id => $other->id]])->assertUnprocessable()->assertJsonValidationErrors('dma_import_refs.'.$this->topic->id);
    }
    expect($this->characterization->fresh()->form_data['materiality_confirmation'] ?? null)->toBeNull();
});

it('keeps a dimension cleared by an ADM draft absent after explicit P8 confirmation', function () {
    $this->actingAs($this->user)->putJson('/api/materiality-confirmation', [
        'expected_revision' => 0, 'confirmed_topic_ids' => [$this->topic->id],
        'dimensions' => [(string) $this->topic->id => 'financial'],
    ])->assertOk()->assertJsonPath('data.confirmation.dimensions.'.$this->topic->id, 'financial');
    $csv = dmaTestCsv([dmaTestRow($this->topic->id, 'material', '99', '')]);
    $import = $this->postJson($this->url, ['iro_file' => dmaTestFile($csv)])->assertCreated()->json('data');
    expect($import['suggestions'][0]['dimension'])->toBeNull();
    // A reload before saving must still expose the unchanged server confirmation.
    $this->getJson('/api/materiality-confirmation')->assertOk()
        ->assertJsonPath('data.confirmation.dimensions.'.$this->topic->id, 'financial');
    $this->putJson('/api/materiality-confirmation', [
        'expected_revision' => 1, 'confirmed_topic_ids' => [$this->topic->id],
        'reviewed_topic_ids' => [$this->topic->id],
        'universe_attestation' => ['version' => 1, 'reviewed_universe' => true, 'mode' => 'direct'],
        'dma_import_refs' => [(string) $this->topic->id => $import['id']],
    ])->assertOk()->assertJsonPath('data.is_confirmed', true);
    expect($this->characterization->fresh()->form_data['materiality_confirmation']['dimensions'])->toBe([]);
    $this->getJson('/api/materiality-confirmation')->assertOk()
        ->assertJsonMissingPath('data.confirmation.dimensions.'.$this->topic->id)
        ->assertJsonPath('data.confirmation.dma_import_refs.'.$this->topic->id, $import['id']);
});

it('does not query the imports table for even an empty disabled-flag reference map', function () {
    config(['services.dma_import.enabled' => false]);
    $this->actingAs($this->user)->putJson('/api/materiality-confirmation', ['expected_revision' => 0,
        'confirmed_topic_ids' => [$this->topic->id], 'dma_import_refs' => []])->assertUnprocessable()->assertJsonValidationErrors('dma_import_refs');
});


it('freezes ADM evidence in an approved snapshot and excludes uploaded text from HTML and OOXML', function () {
    $row = dmaTestRow($this->topic->id);
    $row[2] = 'SYNTHETIC_DMA_PRIVATE_SENTINEL <script>alert(1)</script>';
    $id = $this->actingAs($this->user)->postJson($this->url, ['iro_file' => dmaTestFile(dmaTestCsv([$row]))])->assertCreated()->json('data.id');
    $this->putJson('/api/materiality-confirmation', ['expected_revision' => 0, 'confirmed_topic_ids' => [$this->topic->id],
        'dma_import_refs' => [(string) $this->topic->id => $id]])->assertOk();
    $snapshot = app(ReportSnapshotBuilder::class)->create($this->characterization->fresh());
    \App\Models\ReportApproval::create([
        'report_snapshot_id' => $snapshot->id, 'user_id' => $this->user->id,
        'role_mode' => \App\Models\ReportApproval::ROLE_MODE_SINGLE_PERSON_DECLARED,
        'preparer_user_id' => $this->user->id, 'reviewer_user_id' => $this->user->id,
        'approver_user_id' => $this->user->id, 'single_person_declaration' => true,
        'snapshot_hash' => $snapshot->snapshot_hash, 'approved_at' => now(),
    ]);
    $live = $this->characterization->fresh()->form_data;
    unset($live['materiality_confirmation']['dma_import_evidence']);
    $this->characterization->update(['form_data' => $live]);
    $ir = app(\App\Services\Report\ReportIrBuilder::class)->buildFromApprovedSnapshot($snapshot->fresh());
    expect($ir['dma_import_evidence'][0]['import_id'])->toBe($id);
    $bundle = (new EvidenceBundleBuilder)->build($ir);
    expect($bundle['dma_import_evidence'][0]['iro_rows'][0]['iro_description'])->toBe($row[2]);
    $html = (new \App\Services\Report\HtmlReportRenderer)->render($ir);
    $docx = (new \App\Services\Report\DocxRenderer)->render($ir);
    expect($html)->not->toContain('SYNTHETIC_DMA_PRIVATE_SENTINEL');
    expect(docxVisibleText($docx))->not->toContain('SYNTHETIC_DMA_PRIVATE_SENTINEL');
});


it('bounds numeric route parameters before scalar controller binding', function () {
    $this->actingAs($this->user)->getJson('/api/characterizations/999999999999999999999999999/dma-imports')->assertNotFound();
    $this->getJson($this->url.'/999999999999999999999999999/iro.csv')->assertNotFound();
});

it('enforces the total import quota while preserving previously stored imports', function () {
    $id = $this->actingAs($this->user)->postJson($this->url, ['iro_file' => dmaTestFile(dmaTestCsv([dmaTestRow($this->topic->id)]))])->assertCreated()->json('data.id');
    $record = DmaImport::findOrFail($id);
    for ($i = 1; $i < 20; $i++) { $record->replicate()->save(); }
    $this->postJson($this->url, ['iro_file' => dmaTestFile(dmaTestCsv([dmaTestRow($this->topic->id)]))])
        ->assertUnprocessable()->assertJsonValidationErrors('iro_file');
    $this->assertDatabaseCount('dma_imports', 20);
});
