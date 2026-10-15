<?php

use App\Services\Report\EvidenceBundleBuilder;
use App\Services\Report\IxbrlReportPackage;
use App\Services\Report\ReportingProfileRepository;
use App\Services\Report\TaxonomyConceptMetadata;
use App\Services\Report\TaxonomyProfileRegistry;
use App\Services\Report\XbrlConceptMap;
use App\Services\Report\XhtmlIxbrlCandidateException;
use App\Services\Report\XhtmlIxbrlCandidateRenderer;

uses(Tests\TestCase::class);

it('defaults to final Set 1 and never borrows its mapping for the disabled draft', function () {
    $registry = new TaxonomyProfileRegistry;
    $profile = (new ReportingProfileRepository)->load();
    expect(config('ixbrl.package_ui_enabled'))->toBeFalse();
    expect($registry->selectedId())->toBe('esrs_set1_2023-12-22');
    expect($registry->candidate($registry->selectedId(), $profile)['status'])->toBe('final');
    expect($registry->describe('esrs_2026_draft_2026-08-30'))->toMatchArray(['status' => 'provisional', 'enabled' => false, 'concept_mapping' => null]);
    expect(fn () => $registry->candidate('esrs_2026_draft_2026-08-30', $profile))->toThrow(XhtmlIxbrlCandidateException::class, 'xhtml_ixbrl_taxonomy_profile_disabled');
    config(['ixbrl.profiles.esrs_2026_draft_2026-08-30.enabled' => true]);
    expect(fn () => $registry->candidate('esrs_2026_draft_2026-08-30', $profile))->toThrow(XhtmlIxbrlCandidateException::class, 'xhtml_ixbrl_taxonomy_mapping_unavailable');
    expect(fn () => $registry->describe('../unknown'))->toThrow(XhtmlIxbrlCandidateException::class);
});

it('reads the period type from a checksum bound local schema and refuses tampering or DTDs', function () {
    $profile = (new TaxonomyProfileRegistry)->describe('esrs_set1_2023-12-22');
    $path = tempnam(sys_get_temp_dir(), 'i4s-ixbrl-schema-');
    $zip = new ZipArchive;
    try {
        // Handwritten test schema, not EFRAG taxonomy bytes.
        $xml = '<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema" xmlns:xbrli="http://www.xbrl.org/2003/instance" targetNamespace="'.$profile['namespace'].'"><xs:element name="SyntheticCount" type="xbrli:integerItemType" xbrli:periodType="instant" abstract="false"/></xs:schema>';
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('synthetic/'.$profile['core_schema'], $xml);
        $zip->close();
        $manifest = ['taxonomy_package_path' => $path, 'taxonomy_package_checksum' => hash_file('sha256', $path), 'taxonomy_entrypoint' => $profile['entrypoint']];
        $reader = new TaxonomyConceptMetadata;
        expect($reader->load($profile, $manifest)['esrs:SyntheticCount'])->toMatchArray(['period_type' => 'instant', 'abstract' => false]);
        $manifest['taxonomy_package_checksum'] = str_repeat('0', 64);
        expect(fn () => $reader->load($profile, $manifest))->toThrow(XhtmlIxbrlCandidateException::class);
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('synthetic/'.$profile['core_schema'], '<!DOCTYPE test SYSTEM "file:///etc/passwd">'.$xml);
        $zip->close();
        $manifest['taxonomy_package_checksum'] = hash_file('sha256', $path);
        expect(fn () => $reader->load($profile, $manifest))->toThrow(XhtmlIxbrlCandidateException::class);
    } finally { @unlink($path); }
});

function ixbrlPackageRenderer(): XhtmlIxbrlCandidateRenderer
{
    $map = new class extends XbrlConceptMap {
        public function conceptFor(string $id, ?string $mappingPath = null): ?array { return ['taggable_state' => 'mapped', 'concept_id' => 'esrs:'.($id === 'RevenueFuture' ? 'Revenue' : $id)]; }
    };
    $metadata = new class extends TaxonomyConceptMetadata {
        public function load(array $profile, array $manifest): array {
            $xbrli = '{http://www.xbrl.org/2003/instance}';
            $dtr = '{http://www.xbrl.org/dtr/type/2024-01-31}';
            return [
                'esrs:Revenue' => ['type' => $xbrli.'monetaryItemType', 'period_type' => 'duration', 'abstract' => false],
                'esrs:Count' => ['type' => $xbrli.'integerItemType', 'period_type' => 'instant', 'abstract' => false],
                'esrs:Percent' => ['type' => $dtr.'percentItemType', 'period_type' => 'duration', 'abstract' => false],
                'esrs:Emissions' => ['type' => $dtr.'ghgEmissionsItemType', 'period_type' => 'duration', 'abstract' => false],
                'esrs:Energy' => ['type' => $dtr.'energyItemType', 'period_type' => 'duration', 'abstract' => false],
            ];
        }
    };
    return new XhtmlIxbrlCandidateRenderer($map, $metadata);
}

function ixbrlPackageIr(): array
{
    $claims = [];
    foreach ([['Revenue', '-1234.50', 'EUR', 2], ['Count', '123', 'pure', 0], ['Percent', '25', '%', 0], ['Emissions', '2.25', 'tCO2e', 2], ['Energy', '100', 'MWh', 0]] as [$id, $value, $unit, $decimals]) {
        $claims[] = ['datapoint_id' => $id, 'value_type' => 'number', 'value' => ['value' => $value], 'unit' => $unit, 'decimals' => $decimals, 'dimensions' => []];
    }
    return ['company' => ['name' => 'SINTÉTICO & <script>"</script>', 'entity_identifier' => 'TEST0000000000000075', 'entity_identifier_scheme' => 'http://standards.iso.org/iso/17442', 'reporting_period_start' => '2025-07-01', 'reporting_period_end' => '2026-06-30'], 'claims' => $claims];
}

it('blocks a lone future resource fact using the real mapping even with no submitted dimensions', function (string $datapoint) {
    $map = new XbrlConceptMap;
    $path = 'data/atomizer_xbrl_concepts_v1.json';
    expect($map->conceptFor($datapoint, $path)['required_context'])->toBe(['future_period_or_time_horizon']);
    $ir = ixbrlPackageIr();
    $ir['claims'] = [['datapoint_id' => $datapoint, 'value_type' => 'monetary', 'value' => ['value' => '100'], 'unit' => 'EUR', 'decimals' => 0, 'dimensions' => []]];
    $metadata = new class extends TaxonomyConceptMetadata {
        public function load(array $profile, array $manifest): array {
            return array_fill_keys(['esrs:FinancialResourcesAllocatedToActionPlanCapEx', 'esrs:FinancialResourcesAllocatedToActionPlanOpEx'],
                ['type' => '{http://www.xbrl.org/2003/instance}monetaryItemType', 'period_type' => 'duration', 'abstract' => false]);
        }
    };
    expect(fn () => (new XhtmlIxbrlCandidateRenderer($map, $metadata))->render($ir, (new ReportingProfileRepository)->load(), []))
        ->toThrow(XhtmlIxbrlCandidateException::class, 'xhtml_ixbrl_dimensions_unsupported');
})->with(['MDR-A_11', 'MDR-A_12']);

it('uses fiscal dates, instant and duration contexts, UTR units, percentages, sign and explicit precision', function () {
    $xml = ixbrlPackageRenderer()->render(ixbrlPackageIr(), (new ReportingProfileRepository)->load(), []);
    $doc = new DOMDocument;
    expect($doc->loadXML($xml))->toBeTrue();
    $xpath = new DOMXPath($doc);
    $xpath->registerNamespace('ix', 'http://www.xbrl.org/2013/inlineXBRL');
    $xpath->registerNamespace('xbrli', 'http://www.xbrl.org/2003/instance');
    expect($xpath->evaluate('string(//xbrli:startDate)'))->toBe('2025-07-01');
    expect($xpath->evaluate('string(//xbrli:endDate)'))->toBe('2026-06-30');
    expect($xpath->evaluate('string(//xbrli:instant)'))->toBe('2026-06-30');
    expect($xpath->evaluate('string(//ix:nonFraction[@name="esrs:Count"]/@contextRef)'))->toBe('c_instant');
    expect($xpath->evaluate('string(//ix:nonFraction[@name="esrs:Revenue"]/@sign)'))->toBe('-');
    expect($xpath->evaluate('string(//ix:nonFraction[@name="esrs:Revenue"])'))->toBe('1234.50');
    expect($xpath->evaluate('string(//ix:nonFraction[@name="esrs:Percent"]/@scale)'))->toBe('-2');
    expect($xpath->evaluate('string(//ix:nonFraction[@name="esrs:Percent"]/@decimals)'))->toBe('2');
    expect($xml)->toContain('scheme="http://standards.iso.org/iso/17442"', 'utr:tCO2e', 'utr:MWh', 'style="display:none"', 'esrs_set1_2023-12-22', '&amp; &lt;script&gt;');
    expect($doc->getElementsByTagName('script')->length)->toBe(0);
    expect($doc->documentElement->hasAttribute('lang'))->toBeFalse();
});

it('refuses missing reversed invalid periods, invalid LEI and invalid numeric semantics', function (string $case) {
    $ir = ixbrlPackageIr();
    match ($case) {
        'missing date' => $ir['company']['reporting_period_start'] = null,
        'bad date' => $ir['company']['reporting_period_end'] = '2026-02-30',
        'reversed date' => $ir['company']['reporting_period_end'] = '2025-01-01',
        'bad lei' => $ir['company']['entity_identifier'] = 'TEST0000000000000076',
        'xml control' => $ir['company']['name'] = "bad\x01value",
        'wrong unit' => $ir['claims'][0]['unit'] = 'pure',
        'ambiguous percentage' => $ir['claims'][2]['unit'] = 'pure',
        'fractional count' => $ir['claims'][1]['value']['value'] = '3.5',
        'dimension' => $ir['claims'][0]['dimensions'] = [['axis' => 'a', 'member' => 'b']],
        'injected value' => $ir['claims'][0]['value']['value'] = '<ix:nonFraction>1',
        'no decimals' => $ir['claims'][0]['decimals'] = null,
    };
    expect(fn () => ixbrlPackageRenderer()->render($ir, (new ReportingProfileRepository)->load(), []))->toThrow(XhtmlIxbrlCandidateException::class);
})->with(['missing date', 'bad date', 'reversed date', 'bad lei', 'xml control', 'wrong unit', 'ambiguous percentage', 'fractional count', 'dimension', 'injected value', 'no decimals']);

it('deduplicates identical facts across value types and blocks conflicting facts', function () {
    $ir = ixbrlPackageIr();
    $duplicate = $ir['claims'][0];
    $duplicate['value_type'] = 'monetary';
    $ir['claims'][] = $duplicate;
    $xml = ixbrlPackageRenderer()->render($ir, (new ReportingProfileRepository)->load(), []);
    expect(substr_count($xml, 'name="esrs:Revenue"'))->toBe(1);
    $ir['claims'][5]['value']['value'] = '999';
    expect(fn () => ixbrlPackageRenderer()->render($ir, (new ReportingProfileRepository)->load(), []))->toThrow(XhtmlIxbrlCandidateException::class, 'xhtml_ixbrl_duplicate_fact_conflict');
});

it('packages exactly one report with metadata and evidence without taxonomy or operator paths', function () {
    $ir = ixbrlPackageIr();
    $ir['taxonomy_profile'] = (new TaxonomyProfileRegistry)->describe('esrs_set1_2023-12-22');
    $xml = ixbrlPackageRenderer()->render($ir, (new ReportingProfileRepository)->load(), []);
    $evidence = (new EvidenceBundleBuilder)->build($ir);
    $bytes = (new IxbrlReportPackage)->render($xml, $evidence);
    expect($bytes)->toBe((new IxbrlReportPackage)->render($xml, $evidence));
    $path = tempnam(sys_get_temp_dir(), 'i4s-ixbrl-package-');
    file_put_contents($path, $bytes);
    $zip = new ZipArchive;
    try {
        expect($zip->open($path))->toBeTrue();
        expect($zip->numFiles)->toBe(3);
        expect(json_decode($zip->getFromName('candidate/META-INF/reportPackage.json'), true)['documentInfo']['documentType'])->toBe('https://xbrl.org/report-package/2023');
        expect($zip->getFromName('candidate/reports/candidate.xhtml'))->toBe($xml);
        expect(json_decode($zip->getFromName('candidate/evidence.json'), true)['taxonomy_profile']['id'])->toBe('esrs_set1_2023-12-22');
        expect($zip->getFromName('candidate/evidence.json'))->not->toContain('taxonomy_package_path');
        $zip->close();
    } finally { @unlink($path); }
});

it('escapes a company name in the factual DOCX title as text rather than OOXML', function () {
    $ir = ['schema_version' => 'report_ir_v1', 'company' => ['name' => 'A & B </w:t><w:fldSimple w:instr="INJECT"/>', 'reporting_year' => 2026], 'claims' => [], 'chapters' => []];
    $bytes = app(\App\Services\Report\DocxRenderer::class)->render($ir);
    $path = tempnam(sys_get_temp_dir(), 'i4s-ixbrl-docx-');
    file_put_contents($path, $bytes);
    $zip = new ZipArchive;
    try {
        $zip->open($path);
        $doc = new DOMDocument;
        expect($doc->loadXML($zip->getFromName('word/document.xml')))->toBeTrue();
        expect($doc->textContent)->toContain($ir['company']['name']);
        expect($doc->getElementsByTagNameNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'fldSimple')->length)->toBe(0);
        $zip->close();
    } finally { @unlink($path); }
});

it('blocks distinct current and future datapoints sharing one concept even with equal values', function () {
    $ir = ixbrlPackageIr();
    $future = $ir['claims'][0];
    $future['datapoint_id'] = 'RevenueFuture';
    $ir['claims'][] = $future;
    expect(fn () => ixbrlPackageRenderer()->render($ir, (new ReportingProfileRepository)->load(), []))->toThrow(XhtmlIxbrlCandidateException::class, 'xhtml_ixbrl_duplicate_fact_conflict');
});
