<?php

use App\Support\EsrsDisplayCatalogue;

it('has an explicit Spanish display label and metadata in both locales for every canonical record', function () {
    $source = json_decode(file_get_contents(base_path('data/esrs_datapoints_ig3.json')), true, 512, JSON_THROW_ON_ERROR);
    $catalogue = json_decode(file_get_contents(base_path('data/esrs_datapoint_labels_es_v1.json')), true, 512, JSON_THROW_ON_ERROR);
    expect($catalogue['version'])->toBe(1);
    expect($catalogue['canonical_sha256'])->toBe(hash_file('sha256', base_path('data/esrs_datapoints_ig3.json')));
    expect($catalogue['labels'])->toHaveCount(1184);
    expect($catalogue['labels']['BP-2_20'])->toBe('Requisitos de información o puntos de datos incorporados por referencia');
    expect($catalogue['labels']['IRO-2_02'])->toBe('Requisitos de información NEIS aplicados tras la evaluación de materialidad');
    expect(array_keys($catalogue['labels']))->toBe(array_column($source['datapoints'], 'id'));
    $display = new EsrsDisplayCatalogue;
    foreach ($source['datapoints'] as $record) {
        expect($catalogue['labels'][$record['id']])->toBeString()->not->toBeEmpty()->not->toBe($record['name']);
        foreach (['data_type', 'conditional_or_alternative', 'phase_in_less_than_750', 'phase_in_all', 'inclusion_type'] as $key) {
            if ($record[$key]) {
                foreach (['es', 'en'] as $locale) {
                    expect($display->text($record[$key], $locale))->toBeString()->not->toBeEmpty();
                }
            }
        }
    }
    expect(array_diff_key($catalogue['system']['strings'], $catalogue['system']['english']))->toBe([]);
    expect(array_diff_key($catalogue['system']['english'], $catalogue['system']['strings']))->toBe([]);
    foreach ($catalogue['system']['strings'] as $key => $label) {
        expect($display->text($key, 'es'))->toBe($label);
        expect($display->text($key, 'en'))->toBe($catalogue['system']['english'][$key]);
    }
});

it('fails closed for unknown system labels in either locale while preserving explicit identity labels', function () {
    $display = new EsrsDisplayCatalogue;
    expect($display->text('semi-narrative', 'en'))->toBe('semi-narrative');
    expect($display->text('completed', 'en'))->toBe('Completed');
    foreach (['es' => 'Spanish', 'en' => 'English'] as $locale => $language) {
        expect($display->text(null, $locale))->toBe('');
        expect($display->text('', $locale))->toBe('');
        expect(fn () => $display->text('Uncatalogued system label', $locale))
            ->toThrow(LogicException::class, 'Missing '.$language.' system label: Uncatalogued system label');
    }
});

it('fails closed on a missing Spanish datapoint label instead of falling back to English', function () {
    $corpus = ['blocks' => ['test' => ['datapoints' => [['id' => 'missing-id', 'name' => 'Raw English']]]]];
    expect(fn () => (new EsrsDisplayCatalogue)->project($corpus, 'es'))->toThrow(LogicException::class);
});

it('covers every canonical claim and disclosure heading through the shared report projection without generic overrides', function () {
    $source = json_decode(file_get_contents(base_path('data/esrs_datapoints_ig3.json')), true, 512, JSON_THROW_ON_ERROR);
    $catalogue = json_decode(file_get_contents(base_path('data/esrs_datapoint_labels_es_v1.json')), true, 512, JSON_THROW_ON_ERROR);
    $report = new \App\Services\Report\ReportDisplayProjection('es');
    $drs = array_values(array_unique(array_filter(array_column($source['datapoints'], 'dr'))));
    expect($drs)->toHaveCount(99);
    foreach ($drs as $dr) {
        expect($report->sectionTitle($dr))->toBe($catalogue['disclosure_requirements'][$dr]['es']);
    }
    foreach ($source['datapoints'] as $record) {
        expect($report->claimLabel($record['id']))->toBe($catalogue['labels'][$record['id']]);
    }
});

it('honestly projects missing and inconsistent source datatypes while leaving canonical metadata intact', function () {
    $source = json_decode(file_get_contents(base_path('data/esrs_datapoints_ig3.json')), true, 512, JSON_THROW_ON_ERROR);
    $rows = array_values(array_filter($source['datapoints'], fn ($row) => $row['data_type'] === null || $row['id'] === 'E1.SBM-3_04'));
    expect($rows)->toHaveCount(30);
    foreach (['es', 'en'] as $locale) {
        $corpus = ['blocks' => ['test' => ['datapoints' => $rows]]];
        $projected = (new EsrsDisplayCatalogue)->project($corpus, $locale);
        foreach ($projected['blocks']['test']['datapoints'] as $i => $row) {
            expect($row['data_type'])->toBe($rows[$i]['data_type']);
            if ($row['data_type'] === null) {
                expect($row['display']['data_type'])->toBe($locale === 'es' ? 'No especificado en catálogo' : 'Unspecified in catalogue');
            } else {
                expect($row['data_type'])->toBe('date');
                expect($row['display']['qualification'])->toContain($locale === 'es' ? 'fecha' : 'date');
            }
        }
    }
});

it('covers every public structured response CSV column in both locales', function () {
    $columns = (new ReflectionClass(\App\Support\EsrsDatapointResponseCsvExporter::class))->getConstant('COLUMNS');
    expect($columns)->toHaveCount(34);
    $display = new EsrsDisplayCatalogue;
    foreach (['es', 'en'] as $locale) {
        $headers = $display->headers($columns, $locale);
        expect($headers)->toHaveCount(count($columns));
        foreach ($headers as $header) expect($header)->toBeString()->not->toBeEmpty();
    }
    expect(fn () => $display->headers(['unknown_public_column'], 'es'))->toThrow(LogicException::class);
    expect($display->text('ESRS 2 General Disclosures', 'es'))->toBe('Información general de NEIS 2');
    expect($display->text('ESRS 2 General Disclosures', 'en'))->toBe('ESRS 2 General Disclosures');
});

it('localizes structured CSV presentation while preserving each fact and its entity concept context and legacy text', function () {
    $canonicalPath = base_path('data/esrs_datapoints_ig3.json');
    $beforeHash = hash_file('sha256', $canonicalPath);
    $record = json_decode(file_get_contents($canonicalPath), true, 512, JSON_THROW_ON_ERROR)['datapoints'][0];
    $id = $record['id'];
    $corpus = ['blocks' => ['always_required' => ['key' => 'always_required', 'datapoints' => [$record]]]];
    $facts = [
        ['fact_id' => 'synthetic-fact-1', 'value_kind' => 'decimal', 'value' => '9007199254740993.123456789', 'decimals' => 9, 'unit' => ['measure' => 'pure']],
        ['fact_id' => 'synthetic-fact-2', 'value_kind' => 'boolean', 'value' => false, 'decimals' => null, 'unit' => null],
    ];
    foreach ($facts as &$fact) {
        $fact['concept'] = ['concept_id' => 'synthetic:Concept', 'taggable_state' => 'mapped', 'reason_code' => 'synthetic'];
        $fact['context'] = ['period_type' => 'duration', 'start_date' => '2025-01-01', 'end_date' => '2025-12-31', 'dimensions' => [['axis' => 'synthetic:Axis', 'member' => 'synthetic:Member']]];
        $fact['evidence_reference'] = '=synthetic evidence';
    }
    unset($fact);
    $state = ['schema_version' => 'synthetic-v1', 'reporting_entity' => ['identifier_scheme' => 'synthetic', 'identifier' => 'SYNTHETIC_ONLY'], 'responses' => [$id => ['status' => 'draft', 'legacy_value' => '=legacy user text', 'facts' => $facts]]];
    $exporter = new \App\Support\EsrsDatapointResponseCsvExporter;
    $parse = function (string $csv): array {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $csv); rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream)) !== false) $rows[] = $row;
        fclose($stream);
        return $rows;
    };
    $machine = $parse($exporter->toCsv($corpus, $state));
    foreach (['es' => 'No', 'en' => 'No'] as $locale => $falseLabel) {
        $localized = $parse($exporter->toCsv($corpus, $state, $locale));
        expect($localized)->toHaveCount(3);
        expect($localized[0])->toHaveCount(37);
        expect($localized[0])->toBe((new EsrsDisplayCatalogue)->headers([...$machine[0], 'applicability_mapping_basis_label', 'response_status_label', 'selection_reason_labels'], $locale));
        foreach (['schema_version', 'reporting_entity_identifier_scheme', 'reporting_entity_identifier', 'concept_id', 'taggable_state', 'taggable_reason_code', 'fact_id', 'fact_value_kind', 'fact_decimals', 'fact_unit', 'fact_period_type', 'fact_start_date', 'fact_end_date', 'fact_instant_date', 'fact_dimensions', 'fact_evidence_reference', 'response_value'] as $column) {
            $index = array_search($column, $machine[0], true);
            foreach ([1, 2] as $row) expect($localized[$row][$index])->toBe($machine[$row][$index]);
        }
        $valueIndex = array_search('fact_lexical_value', $machine[0], true);
        expect($localized[1][$valueIndex])->toBe('9007199254740993.123456789');
        expect($localized[2][$valueIndex])->toBe($falseLabel);
    }
    expect($machine[2][array_search('fact_lexical_value', $machine[0], true)])->toBe('false');
    expect(hash_file('sha256', $canonicalPath))->toBe($beforeHash);
});
