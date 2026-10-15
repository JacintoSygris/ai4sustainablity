<?php

namespace Tests\Support;

use App\Models\Characterization;
use App\Models\EsrsTopic;
use App\Models\ReportApproval;
use App\Models\ReportingFact;
use App\Models\ReportSnapshot;
use App\Models\User;
use App\Services\EsrsDatapointCorpusBuilder;
use App\Services\Report\ReportSnapshotBuilder;

/** Explicitly synthetic fixtures. No generated files enter the checkout. */
final class ReportScopeFixture
{
    public static function scratch(string $prefix): string
    {
        $dir = getenv('REPORT_SCOPE_TEST_SCRATCH_DIR') ?: sys_get_temp_dir().'/report-scope-tests';
        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new \RuntimeException('Report scope test scratch directory unavailable.');
        }
        $path = tempnam($dir, $prefix);
        if ($path === false) throw new \RuntimeException('Report scope test scratch file unavailable.');
        return $path;
    }

    public static function assets(EsrsTopic $topic): array
    {
        $corpus = json_decode(file_get_contents(base_path('data/esrs_datapoints_ig3.json')), true, flags: JSON_THROW_ON_ERROR);
        $corpus['source']['name'] = 'Synthetic scope fixture';
        $corpus['datapoints'] = array_values(array_filter($corpus['datapoints'], fn ($row) => in_array($row['id'], ['BP-1_01', 'SBM-1_06'], true)
            || ($row['esrs'] === 'E2' && $row['dr'] === 'E2.IRO-1')));
        $map = ['version' => 'synthetic-v1', 'source' => ['name' => 'Synthetic mapping', 'status' => 'approved'], 'mappings' => [[
            'ar16_topic_id' => $topic->id, 'esrs_code' => 'E2', 'disclosure_requirements' => ['E2.IRO-1'],
        ]]];
        $paths = ['corpus' => self::scratch('corpus-'), 'mapping' => self::scratch('map-')];
        file_put_contents($paths['corpus'], json_encode($corpus, JSON_THROW_ON_ERROR));
        file_put_contents($paths['mapping'], json_encode($map, JSON_THROW_ON_ERROR));
        config(['report_scope.enabled' => true, 'report_scope.corpus_path' => $paths['corpus'], 'services.esrs_datapoints.matter_dr_mapping_path' => $paths['mapping']]);
        return $paths;
    }

    public static function characterization(User $user, EsrsTopic $topic): Characterization
    {
        $c = Characterization::factory()->create([
            'user_id' => $user->id, 'status' => Characterization::STATUS_COMPLETED,
            'nace_code' => 'A', 'esrs_topic_ids' => [$topic->id], 'submitted_at' => now()->subDay(), 'completed_at' => now(),
            'form_data' => [
                'company_profile' => ['company_name' => 'Synthetic scope company', 'reporting_year' => 2025, 'product_service_type' => 'software_digital_services',
                    'reporting_period_start' => '2025-01-01', 'reporting_period_end' => '2025-12-31', 'entity_identifier' => 'TEST0000000000000075', 'entity_identifier_scheme' => 'http://standards.iso.org/iso/17442'],
                'operations' => ['employee_count_range' => '50_249', 'revenue_range' => '2m_to_10m', 'regions' => ['eu']],
                'materiality_confirmation' => ['confirmed_topic_ids' => [$topic->id], 'confirmed_at' => now()->toJSON()],
                'double_materiality_process' => ['checklist' => ['identified_stakeholders' => true, 'assessed_impacts' => true, 'assessed_financial_effects' => true, 'reached_conclusions' => true],
                    'acta' => ['completed_on' => now()->toDateString(), 'method' => 'Synthetic assessment', 'participants' => 'Synthetic roles'], 'updated_at' => now()->toJSON()],
            ],
        ]);
        $form = $c->form_data;
        $scope = app(EsrsDatapointCorpusBuilder::class)->build($c);
        $responses = [];
        foreach ($scope['blocks'] as $block) foreach ($block['datapoints'] ?? [] as $row) {
            $responses[$row['id']] = ['datapoint_id' => $row['id'], 'status' => $row['id'] === 'BP-1_01' ? 'completed' : 'not_applicable', 'value' => 'Synthetic response', 'note' => 'Synthetic decision', 'updated_at' => now()->toJSON()];
        }
        $form['esrs_datapoint_responses'] = ['schema_version' => 'v0', 'updated_at' => now()->toJSON(), 'responses' => $responses];
        $c->update(['form_data' => $form]);
        return $c->fresh();
    }

    public static function fact(Characterization $c, array $overrides = []): ReportingFact
    {
        return ReportingFact::create(array_replace([
            'characterization_id' => $c->id, 'fact_id' => 'rf_synthetic_scope', 'schema_version' => ReportingFact::SCHEMA_VERSION,
            'profile_id' => ReportingFact::PROFILE_ID, 'datapoint_id' => 'BP-1_01', 'applicability' => 'applicable', 'value_type' => 'text',
            'value' => ['text' => 'Synthetic approved narrative <script> & evidence'], 'unit' => null, 'decimals' => null, 'dimensions' => [],
            'language' => 'es', 'nil' => false, 'nil_reason' => null, 'evidence_refs' => [['type' => 'note', 'value' => 'Synthetic evidence <script>']],
            'provenance' => 'api', 'approval_status' => 'reviewed', 'blocking_reasons' => [],
        ], $overrides));
    }

    public static function approve(Characterization $c): ReportSnapshot
    {
        $s = app(ReportSnapshotBuilder::class)->create($c);
        ReportApproval::create(['report_snapshot_id' => $s->id, 'user_id' => $c->user_id, 'role_mode' => ReportApproval::ROLE_MODE_SINGLE_PERSON_DECLARED,
            'preparer_user_id' => $c->user_id, 'reviewer_user_id' => $c->user_id, 'approver_user_id' => $c->user_id,
            'single_person_declaration' => 'Synthetic test declaration', 'snapshot_hash' => $s->snapshot_hash, 'approved_at' => now()]);
        return $s;
    }

    public static function presentationIr(): array
    {
        $narrative = ['claim_id' => 'claim_synthetic_text', 'fact_id' => 'rf_synthetic_text', 'datapoint_id' => 'BP-1_01', 'value_type' => 'text',
            'value' => ['text' => 'Narrativa sintética <script>alert(1)</script> & texto'], 'dimensions' => [], 'evidence_refs' => [['type' => 'note', 'value' => 'https://example.invalid/<evidencia>']]];
        $number = ['claim_id' => 'claim_synthetic_number', 'fact_id' => 'rf_synthetic_number', 'datapoint_id' => 'SBM-1_06', 'value_type' => 'number',
            'value' => ['value' => '-1234.567'], 'unit' => 'EUR', 'decimals' => 3, 'dimensions' => [['axis' => 'zona', 'member' => 'Norte <sur>'], ['axis' => 'custom', 'member' => 'A & B']], 'evidence_refs' => []];
        return [
            'schema_version' => 'report_ir_v1', 'source' => ['scope' => ['version' => 'report_scope_v1']],
            'company' => ['name' => 'Entidad sintética & <prueba>', 'reporting_year' => 2025, 'reporting_period_start' => '2025-01-01', 'reporting_period_end' => '2025-12-31',
                'entity_identifier' => 'TEST0000000000000075', 'entity_identifier_scheme' => 'http://standards.iso.org/iso/17442'],
            'claims' => [$narrative, $number], 'disclaimers' => ['Contenido sintético de prueba.'],
            'chapters' => [
                ['block_key' => 'always_required', 'sections' => [
                    ['dr_key' => 'BP-1', 'blocks' => [['datapoint_id' => 'BP-1_01', 'claims' => [$narrative['claim_id']]]]],
                    ['dr_key' => 'SBM-1', 'blocks' => [['datapoint_id' => 'SBM-1_06', 'claims' => [$number['claim_id']]]]],
                ]],
                ['block_key' => 'e1_not_material_explanation', 'sections' => [['dr_key' => 'E1', 'blocks' => [['datapoint_id' => 'e1_not_material_explanation', 'assertions' => ['Explicación climática sintética <conservada>.']]]]]],
            ],
            'fact_decisions' => [array_replace($number, ['fact_id' => 'rf_excluded', 'output_section' => 'not_applicable_appendix', 'approval_status' => 'reviewed', 'applicability' => 'not_applicable',
                'value' => ['value' => '99'], 'dimensions' => [['axis' => 'segmento', 'member' => 'Excluido']], 'nil_reason' => 'Fuera del alcance sintético.']),
                ['fact_id' => 'rf_unavailable', 'datapoint_id' => 'BP-1_01', 'output_section' => 'gap', 'approval_status' => 'approved', 'applicability' => 'unavailable',
                    'value_type' => 'nil', 'value' => null, 'nil' => true, 'nil_reason' => 'Dato no disponible & pendiente.', 'dimensions' => [], 'evidence_refs' => []]],
            'omission_section' => ['title' => 'Temas no confirmados como materiales', 'declaration' => 'Declaración sintética de omisión.', 'statements' => ['Omisión sintética conservada.'], 'limitation' => null],
        ];
    }

    public static function edit(string $path, callable $change): void
    {
        $data = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        file_put_contents($path, json_encode($change($data), JSON_THROW_ON_ERROR));
    }

    /** Parse actual visible paragraphs/headings, never serialized markup. */
    public static function presentationText(string $bytes, string $format): array
    {
        $dom = new \DOMDocument;
        if ($format === 'docx') {
            $path = self::scratch('parity-docx-');
            file_put_contents($path, $bytes);
            $zip = new \ZipArchive;
            if ($zip->open($path) !== true) throw new \RuntimeException('Invalid test DOCX.');
            $xml = $zip->getFromName('word/document.xml');
            $zip->close();
            if (! is_string($xml) || ! $dom->loadXML($xml, LIBXML_NONET)) throw new \RuntimeException('Invalid test OOXML.');
            $xpath = new \DOMXPath($dom);
            $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            $lines = [];
            foreach ($xpath->query('//w:body/w:p') as $paragraph) {
                $line = '';
                foreach ($xpath->query('.//w:t', $paragraph) as $node) $line .= $node->textContent;
                if ($line !== '') $lines[] = $line;
            }
            return $lines;
        }
        $loaded = $format === 'html'
            ? @$dom->loadHTML('<?xml encoding="UTF-8">'.$bytes, LIBXML_NONET)
            : $dom->loadXML($bytes, LIBXML_NONET);
        if (! $loaded) throw new \RuntimeException('Invalid test presentation.');
        $xpath = new \DOMXPath($dom);
        $lines = [];
        foreach ($xpath->query('//*[local-name()="body"]//*[local-name()="h1" or local-name()="h2" or local-name()="h3" or local-name()="h4" or local-name()="p"]') as $node) {
            $lines[] = $node->textContent;
        }
        return $lines;
    }
}
