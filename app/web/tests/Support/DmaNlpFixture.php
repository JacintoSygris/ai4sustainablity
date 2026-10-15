<?php
namespace Tests\Support;

use App\Models\Characterization;
use App\Models\User;
use App\Services\Dma\DmaNlpRecommendations;
use App\Support\DoubleMaterialityGuide;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Synthetic contract responses; retrieval quality is tested separately in Python. */
final class DmaNlpFixture
{
    public static function cases(): array
    {
        return json_decode(file_get_contents(base_path('../ai-service/src/tests/fixtures/dma_processing/heldout.json')), true)['cases'];
    }
    public static function case(string $id): array { return collect(self::cases())->firstWhere('id', $id); }
    public static function fake(?callable $alter = null): void
    {
        Http::preventStrayRequests();
        Http::fake(['http://127.0.0.1:8001/dma/process' => function ($request) use ($alter) {
            $manifest = app(\App\Services\Dma\DmaNlpGateway::class)->manifest();
            $result = array_intersect_key($manifest, array_flip(['version', 'model_sha256', 'catalogue_version', 'catalogue_sha256']));
            $results = [];
            foreach ($request['iros'] as $source) {
                $case = collect(self::cases())->firstWhere('description', $source['description']);
                if (! $case) { throw new \RuntimeException('Unknown synthetic description'); }
                $results[] = self::responseRow($source, $case, $request['locale'], $manifest);
            }
            $result['iros'] = $results;
            if ($alter) { $result = $alter($result); }
            return Http::response($result, 200);
        }]);
    }
    private static function responseRow(array $source, array $case, string $locale, array $manifest): array
    {
        $topics = json_decode(file_get_contents(base_path('data/esrs_topics.json')), true);
        $labels = json_decode(file_get_contents(base_path('data/esrs_datapoint_labels_es_v1.json')), true);
        $points = array_column(json_decode(file_get_contents(base_path('data/esrs_datapoints_ig3.json')), true)['datapoints'], null, 'id');
        $row = ['iro_id' => $source['iro_id'], 'description_sha256' => hash('sha256', $source['description']),
            'model_version' => $manifest['version'], 'model_sha256' => $manifest['model_sha256'],
            'catalogue_version' => $manifest['catalogue_version'], 'catalogue_sha256' => $manifest['catalogue_sha256'], 'levels' => []];
        foreach (['topic', 'dr', 'datapoint'] as $level) {
            $candidates = [];
            foreach ($case['expected'][$level] as $index => $id) {
                if ($level === 'topic') {
                    $topic = $topics[(int) $id - 1];
                    $label = implode(' — ', array_map(fn ($key) => $topic[$key][$locale],
                        array_values(array_filter(['theme', 'subtheme', 'subtopic'], fn ($key) => ! empty($topic[$key])))));
                    $reference = 'esrs_topics.json#/'.((int) $id - 1);
                } elseif ($level === 'dr') {
                    $label = $labels['disclosure_requirements'][$id][$locale];
                    $reference = 'esrs_datapoint_labels_es_v1.json#/disclosure_requirements/'.$id;
                } else {
                    $label = $locale === 'es' ? $labels['labels'][$id] : $points[$id]['name'];
                    $reference = 'esrs_datapoints_ig3.json#/datapoints/'.$id;
                }
                $candidates[] = ['id' => $id, 'label' => $label, 'score' => 0.9 - $index * 0.1,
                    'spans' => [['start' => 0, 'end' => mb_strlen($source['description'], 'UTF-8'),
                        'quote' => $source['description'], 'catalogue_ref' => $reference]]];
            }
            $row['levels'][$level] = ['candidates' => $candidates, 'abstention' => $candidates === [] ? 'insufficient_evidence' : null];
        }
        return $row;
    }

    public static function make(TestCase $test, string $caseId = 'consumer_repair_es', array $caseOverrides = []): array
    {
        config(['services.private_dev.auto_login' => false, 'services.dma_import.enabled' => true,
            'services.dma_import.iro_traceability_enabled' => true, 'services.dma_nlp.enabled' => true,
            'services.characterization.api.base_url' => 'http://127.0.0.1:8001', 'services.characterization.api.token' => 'synthetic-test-token',
            'services.esrs_datapoints.matter_dr_mapping_path' => base_path('data/ar16_to_esrs_dr_mapping_esrs2023_v1.json')]);
        $test->seed(\Database\Seeders\EsrsTopicSeeder::class); $case = array_replace(self::case($caseId), $caseOverrides);
        $user = User::factory()->create();
        $record = Characterization::factory()->create(['user_id' => $user->id, 'status' => Characterization::STATUS_COMPLETED,
            'esrs_topic_ids' => [(int) $case['declared_topic']], 'submission_generation' => 1,
            'form_data' => ['company_profile' => ['company_name' => 'Synthetic NLP test', 'reporting_year' => 2026]]]);
        $test->actingAs($user);
        $import = self::upload($test, $record, $case);
        return compact('user', 'record', 'case', 'import');
    }
    public static function upload(TestCase $test, Characterization $record, array $case): array
    {
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, array_column(DoubleMaterialityGuide::template('iro_register')['columns'], 'key'), ',', '"', '');
        fputcsv($stream, [$case['declared_topic'], 'Synthetic topic', $case['description'], 'impact', '', '', 'material', 'material', 'material', 'Synthetic evidence'], ',', '"', '');
        rewind($stream); $csv = stream_get_contents($stream); fclose($stream);
        return $test->postJson('/api/characterizations/'.$record->id.'/dma-imports', ['iro_file' => UploadedFile::fake()->createWithContent('synthetic-nlp.csv', $csv)])
            ->assertCreated()->json('data');
    }
    public static function payload(TestCase $test, array $f, array $topics = []): array
    {
        $topics = $topics ?: [(int) $f['case']['declared_topic']];
        $preview = $test->postJson('/api/materiality-confirmation/preview', ['candidate_topic_ids' => $topics])->assertOk()->json('data');
        $id = (int) $f['case']['declared_topic'];
        $points = array_values(array_filter($preview['iro_candidates'][$id], fn ($point) => ! $point['transversal']));
        return ['expected_revision' => $f['record']->fresh()->form_data['materiality_confirmation']['revision'] ?? 0,
            'confirmed_topic_ids' => $topics, 'dma_import_refs' => [(string) $id => $f['import']['id']],
            'iro_reviews' => [['import_id' => $f['import']['id'], 'iro_id' => $f['import']['iro_rows'][0]['iro_id'],
                'decision' => 'material', 'type' => 'impact', 'dimension' => 'impact', 'locations' => ['own_operations'], 'groups' => ['internal'],
                'description' => $f['case']['description'], 'group' => 'Synthetic group', 'channel' => 'Synthetic channel', 'reason' => null,
                'focus_datapoint_ids' => [$points[0]['id']]]]];
    }
    public static function confirm(TestCase $test, array $f, array $topics = []): array
    {
        $test->putJson('/api/materiality-confirmation', self::payload($test, $f, $topics))->assertOk();
        return $test->getJson('/api/esrs-datapoints')->assertOk()->json('data');
    }
    public static function input(array $f, string $stage): array
    {
        $record = $f['record']->fresh();
        return ['stage' => $stage, 'locale' => $f['case']['locale'], 'iro_ids' => array_column($f['import']['iro_rows'], 'iro_id'),
            'expected_context' => app(DmaNlpRecommendations::class)->context($record, $stage),
            'expected_revision' => $record->form_data['materiality_confirmation']['revision'] ?? 0];
    }
    public static function analyse(TestCase $test, array $f, string $stage = 'p9', int $expectedStatus = 201): array
    {
        return $test->postJson('/api/characterizations/'.$f['record']->id.'/dma-imports/'.$f['import']['id'].'/nlp-runs', self::input($f, $stage))->assertStatus($expectedStatus)->json('data');
    }
    public static function review(TestCase $test, array $f, array $run, string $id, string $decision = 'accepted')
    {
        $candidate = collect($run['candidates'])->firstWhere('id', $id);
        return $test->postJson('/api/characterizations/'.$f['record']->id.'/dma-nlp-runs/'.$run['id'].'/reviews',
            ['candidate_id' => $candidate['candidate_id'], 'decision' => $decision,
                'expected_context' => $run['context_digest'], 'expected_revision' => $run['p8_revision']]);
    }

    /** A synthetic completed response and reviewed fact make approval reach the frozen-evidence checks. */
    public static function prepareReport(TestCase $test, array $f): void
    {
        $corpus = $test->getJson('/api/esrs-datapoints')->assertOk()->json('data');
        $test->putJson('/api/esrs-datapoints/responses', ['expected_revision' => 0,
            'expected_authority_digest' => $corpus['p9_authority_digest'],
            'responses' => [['datapoint_id' => 'BP-1_01', 'status' => 'completed', 'value' => 'Synthetic reporting basis.']]])->assertOk();
        \App\Models\ReportingFact::create([
            'characterization_id' => $f['record']->id, 'fact_id' => 'rf_nlp_frozen',
            'schema_version' => \App\Models\ReportingFact::SCHEMA_VERSION,
            'profile_id' => \App\Models\ReportingFact::PROFILE_ID,
            'datapoint_id' => 'BP-1_01', 'applicability' => 'applicable', 'value_type' => 'text',
            'value' => ['text' => 'Synthetic reporting basis.'], 'unit' => null, 'decimals' => null,
            'dimensions' => [], 'language' => 'en', 'nil' => false, 'nil_reason' => null,
            'evidence_refs' => [['type' => 'note', 'value' => 'Synthetic test support.']],
            'provenance' => 'api', 'approval_status' => 'reviewed', 'blocking_reasons' => [],
        ]);
    }
}
