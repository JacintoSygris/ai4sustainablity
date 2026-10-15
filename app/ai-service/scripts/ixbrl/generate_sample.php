<?php
// Offline synthetic fixture driver. Loads the product classes from THIS checkout, not Composer's shared classmap.
$web = realpath(__DIR__.'/../../../web');
function base_path(string $path = ''): string { global $web; return $web.($path === '' ? '' : '/'.$path); }
function env(string $key, mixed $default = null): mixed { return $default; }
function config(string $key, mixed $default = null): mixed {
    static $config;
    $config ??= ['ixbrl' => require base_path('config/ixbrl.php')];
    $value = $config;
    foreach (explode('.', $key) as $part) { $value = $value[$part] ?? $default; }
    return $value;
}
spl_autoload_register(function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        $path = base_path('app/'.str_replace('\\', '/', substr($class, 4)).'.php');
        if (is_file($path)) require_once $path;
    }
});
use App\Services\Report\ReportingProfileRepository;
use App\Services\Report\TaxonomyProfileRegistry;
use App\Services\Report\XbrlConceptMap;
use App\Services\Report\XhtmlIxbrlCandidateRenderer;
use App\Services\Report\IxbrlReportPackage;
use App\Services\Report\EvidenceBundleBuilder;

if ($argc !== 3) { fwrite(STDERR, "Usage: php generate_sample.php external-manifest.json output.zip\n"); exit(2); }
$manifest = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$profile = (new ReportingProfileRepository)->load();
$registry = new TaxonomyProfileRegistry;
$taxonomyId = 'esrs_set1_2023-12-22';
$claims = [];
foreach ([['SBM-1_06', '-1234.50', 'EUR', 2], ['E1-6_07', '12.75', 'tCO2e', 2]] as [$id, $value, $unit, $decimals]) {
    $claims[] = ['claim_id' => 'synthetic_'.$id, 'datapoint_id' => $id, 'value_type' => 'number', 'value' => ['value' => $value], 'unit' => $unit, 'decimals' => $decimals, 'dimensions' => [], 'evidence_refs' => [['type' => 'note', 'value' => 'SYNTHETIC TEST ONLY']]];
}
$ir = [
    'schema_version' => 'report_ir_v1',
    'company' => [
        'name' => 'INFORME SINTÉTICO DE PRUEBA — A & B <ensayo>',
        // Synthetically constructed MOD-97-valid LEI, not a claim of a registered entity.
        'entity_identifier' => 'TEST0000000000000075', 'entity_identifier_scheme' => 'http://standards.iso.org/iso/17442',
        'reporting_year' => 2026, 'reporting_period_start' => '2025-07-01', 'reporting_period_end' => '2026-06-30',
    ],
    'claims' => $claims,
    'taxonomy_profile' => $registry->candidate($taxonomyId, $profile),
];
$xhtml = (new XhtmlIxbrlCandidateRenderer(new XbrlConceptMap))->render($ir, $profile, $manifest, 'es', $taxonomyId);
$evidence = (new EvidenceBundleBuilder)->build($ir);
$evidence['synthetic'] = true;
$evidence['taxonomy_package_checksum'] = $manifest['taxonomy_package_checksum'];
$evidence['validation_status'] = 'not_yet_validated';
file_put_contents($argv[2], (new IxbrlReportPackage)->render($xhtml, $evidence));
