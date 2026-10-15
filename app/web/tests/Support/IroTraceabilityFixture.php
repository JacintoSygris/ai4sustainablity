<?php

namespace Tests\Support;

use App\Models\Characterization;
use App\Models\EsrsTopic;
use App\Models\User;
use App\Support\DoubleMaterialityGuide;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** Synthetic data only. Exercises the actual upload, preview and confirmation APIs. */
final class IroTraceabilityFixture
{
    public static function make(TestCase $test): array
    {
        config(['services.private_dev.auto_login' => false, 'services.dma_import.enabled' => true,
            'services.dma_import.iro_traceability_enabled' => true,
            'services.esrs_datapoints.matter_dr_mapping_path' => base_path('data/ar16_to_esrs_dr_mapping_esrs2023_v1.json')]);
        $test->seed(\Database\Seeders\EsrsTopicSeeder::class);
        $user = User::factory()->create();
        $topic = EsrsTopic::where('esrs_code', 'E2')->firstOrFail();
        $characterization = Characterization::factory()->create([
            'user_id' => $user->id, 'status' => Characterization::STATUS_COMPLETED,
            'esrs_topic_ids' => [$topic->id], 'submission_generation' => 1,
            'form_data' => ['company_profile' => ['company_name' => 'Synthetic IRO test', 'reporting_year' => 2026]],
        ]);
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, array_column(DoubleMaterialityGuide::template('iro_register')['columns'], 'key'), ',', '"', '');
        foreach (['impact', 'risk'] as $type) {
            fputcsv($stream, [(string) $topic->id, 'Synthetic topic', "Synthetic original\nIRO", $type, '', '', 'material', 'material', 'material', 'Synthetic evidence'], ',', '"', '');
        }
        rewind($stream); $csv = stream_get_contents($stream); fclose($stream);
        $url = '/api/characterizations/'.$characterization->id.'/dma-imports';
        $import = $test->actingAs($user)->postJson($url, ['iro_file' => UploadedFile::fake()->createWithContent('synthetic.csv', $csv)])
            ->assertCreated()->json('data');
        $preview = $test->postJson('/api/materiality-confirmation/preview', ['candidate_topic_ids' => [$topic->id]])
            ->assertOk()->assertJsonPath('data.provisional', true)->json('data');
        $points = $preview['iro_candidates'][$topic->id];
        $topical = array_values(array_filter($points, fn ($point) => ! $point['transversal']));
        $reviews = [];
        foreach ($import['iro_rows'] as $index => $row) {
            $reviews[] = ['import_id' => $import['id'], 'iro_id' => $row['iro_id'], 'decision' => 'material',
                'type' => $index === 0 ? 'impact' : 'risk', 'dimension' => $index === 0 ? 'impact' : 'both',
                'locations' => [$index === 0 ? 'own_operations' : 'upstream'], 'groups' => [$index === 0 ? 'internal' : 'external'],
                'description' => 'Synthetic reviewed <script>alert(1)</script> '.$index, 'group' => 'Synthetic group',
                'channel' => 'Synthetic channel', 'reason' => null, 'focus_datapoint_ids' => [$topical[$index]['id']]];
        }
        $payload = ['expected_revision' => 0, 'confirmed_topic_ids' => [$topic->id],
            'dma_import_refs' => [(string) $topic->id => $import['id']], 'iro_reviews' => $reviews];
        return compact('user', 'topic', 'characterization', 'import', 'preview', 'payload', 'url', 'csv');
    }

    public static function confirm(TestCase $test, array $fixture): array
    {
        $test->putJson('/api/materiality-confirmation', $fixture['payload'])->assertOk();
        return $test->getJson('/api/esrs-datapoints')->assertOk()->json('data');
    }
}
