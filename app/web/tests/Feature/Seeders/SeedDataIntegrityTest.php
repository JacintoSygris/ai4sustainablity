<?php

use App\Models\EsrsTopic;
use App\Models\NaceCode;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds all NACE codes from the JSON dataset', function () {
    $dataset = json_decode(file_get_contents(base_path('data/nace_codes.json')), true);

    $this->seed(\Database\Seeders\NaceCodeSeeder::class);

    expect(NaceCode::count())->toBe(count($dataset));
    expect($dataset)->toHaveCount(1060);
    expect(collect($dataset)->countBy('level')->all())->toBe([
        0 => 22,
        1 => 87,
        2 => 287,
        3 => 664,
    ]);
    expect(data_get($dataset[0], 'code'))->toBe('A');
    expect(data_get($dataset[0], 'title.es'))->toBe('AGRICULTURA, GANADERÍA, SILVICULTURA Y PESCA');
    expect(collect($dataset)->filter(fn (array $row) => data_get($row, 'title.en') !== data_get($row, 'title.es'))->count())->toBe(0);
    expect(NaceCode::orderBy('code')->pluck('code')->all())
        ->toEqual(array_column($dataset, 'code'));
});

it('seeds all ESRS topics from the JSON dataset with unique hashes', function () {
    $dataset = json_decode(file_get_contents(base_path('data/esrs_topics.json')), true);

    $this->seed(\Database\Seeders\EsrsTopicSeeder::class);

    expect(EsrsTopic::count())->toBe(count($dataset));
    expect(EsrsTopic::pluck('hash')->unique()->count())->toBe(EsrsTopic::count());

    EsrsTopic::all()->each(function (EsrsTopic $topic, int $index) use ($dataset) {
        $source = implode('|', [
            $dataset[$index]['esrs'] ?? '',
            strtolower($dataset[$index]['theme']['es'] ?? ''),
            strtolower($dataset[$index]['subtheme']['es'] ?? ''),
            strtolower($dataset[$index]['subtopic']['es'] ?? ''),
        ]);

        expect($topic->hash)->toBe(hash('sha256', $source));
        expect($topic->examples_en)->toBeArray();
        expect($topic->examples_es)->toBeArray();
    });
});

it('keeps the IG3 datapoint corpus source invariants stable', function () {
    $source = json_decode(file_get_contents(base_path('data/esrs_datapoints_ig3.json')), true, flags: JSON_THROW_ON_ERROR);
    $datapoints = $source['datapoints'];
    $requiredKeys = [
        'id',
        'sheet',
        'esrs',
        'dr',
        'paragraph',
        'related_ar',
        'name',
        'data_type',
        'conditional_or_alternative',
        'may_disclose',
        'appendix_b',
        'phase_in_less_than_750',
        'phase_in_all',
        'inclusion_type',
    ];

    expect($source['generation']['row_count'])->toBe(1184);
    expect($datapoints)->toHaveCount(1184);
    expect(collect($datapoints)->pluck('id')->unique()->count())->toBe(1184);
    expect(collect($datapoints)->countBy('inclusion_type')->all())->toBe([
        'always_required' => 146,
        'minimum_disclosure_requirement' => 44,
        'materiality_based' => 994,
    ]);

    collect($datapoints)->each(function (array $datapoint) use ($requiredKeys) {
        expect(array_keys($datapoint))->toContain(...$requiredKeys);
        expect($datapoint['id'])->not->toBe('');
        expect($datapoint['esrs'])->not->toBe('');
        expect($datapoint['name'])->not->toBe('');
        expect($datapoint['inclusion_type'])->toBeIn([
            'always_required',
            'materiality_based',
            'minimum_disclosure_requirement',
        ]);
    });
});

it('keeps AR16 topic mappings aligned with seeded ESRS topics', function () {
    $mapping = json_decode(file_get_contents(base_path('data/ar16_to_python_esrs_mapping.json')), true, flags: JSON_THROW_ON_ERROR);

    $this->seed(\Database\Seeders\EsrsTopicSeeder::class);

    collect($mapping['candidate_topics'])
        ->where('mapping_status', 'approved')
        ->each(function (array $row) {
            $topic = EsrsTopic::find($row['ar16_topic_id']);

            expect($topic)->not->toBeNull();
            expect($topic->esrs_code)->toBe($row['web_esrs']);
            expect($topic->theme_en)->toBe($row['web_theme_en']);
            expect($topic->subtheme_en)->toBe($row['web_subtheme_en']);
            expect($topic->subtopic_en)->toBe($row['web_subtopic_en']);
        });
});

it('keeps Python ESRS key inventory covered by the AR16 runtime mapping', function () {
    $inventoryKeys = collect(readCsvRows(base_path('../contracts/mappings/python-esrs-key-inventory.csv')))
        ->pluck('python_esrs_key')
        ->filter()
        ->values();
    $mapping = json_decode(file_get_contents(base_path('data/ar16_to_python_esrs_mapping.json')), true, flags: JSON_THROW_ON_ERROR);
    $candidateKeys = collect($mapping['candidate_topics'])
        ->where('mapping_status', 'approved')
        ->flatMap(fn (array $row) => $row['python_esrs_keys'])
        ->values();
    $statusOnlyKeys = collect(array_keys($mapping['python_key_statuses'] ?? []));
    $coveredKeys = $candidateKeys
        ->merge($statusOnlyKeys)
        ->unique()
        ->values();

    expect($mapping['candidate_topics'])->toHaveCount(89);
    expect($candidateKeys)->toHaveCount(92);
    expect($statusOnlyKeys)->toHaveCount(4);
    expect($coveredKeys)->toHaveCount(96);
    expect($inventoryKeys->diff($coveredKeys)->values()->all())->toBe([]);
    expect($coveredKeys->diff($inventoryKeys)->values()->all())->toBe([]);
});

it('keeps the new-format 732 mapping inventory approved only through local AR16 equivalences', function () {
    $mapping = json_decode(
        file_get_contents(base_path('data/ar16_to_python_esrs_mapping_new_format_732_v1.json')),
        true,
        flags: JSON_THROW_ON_ERROR
    );

    $keys = collect($mapping['keys']);
    $allowedStatuses = ['approved', 'aggregate_only', 'review_only', 'excluded'];

    expect($mapping['schema_version'])->toBe('1.0');
    expect($mapping['mapping_version'])->toBe('new_format_732_v1');
    expect($mapping['status'])->toBe('runtime-approved-for-candidate-suggestions');
    expect($mapping['model_key_count'])->toBe(102);
    expect($mapping['approved_key_count'])->toBe(91);
    expect($mapping['approved_by'])->toBe('local-ar16-equivalence-crosswalk');
    expect($mapping['approved_at'])->toBe('2026-06-08');
    expect($keys)->toHaveCount(102);
    expect($keys->pluck('python_esrs_key')->unique()->count())->toBe(102);
    expect($keys->pluck('status')->countBy()->all())->toBe([
        'aggregate_only' => 10,
        'approved' => 91,
        'review_only' => 1,
    ]);

    $rowsByKey = $keys->keyBy('python_esrs_key');
    expect($rowsByKey['esrs_e1_climate_change_adaptation']['ar16_topic_ids'])->toBe([1]);
    expect($rowsByKey['esrs_e1_energy_use']['ar16_topic_ids'])->toBe([3]);
    expect($rowsByKey['esrs_s4_social_inclusion_access_products']['ar16_topic_ids'])->toBe([81]);
    expect($rowsByKey['esrs_e1_summary']['status'])->toBe('aggregate_only');
    expect($rowsByKey['esrs_e3_other_issues_related_to_esrs_e3']['status'])->toBe('review_only');

    $keys->each(function (array $row) use ($allowedStatuses) {
        expect($row['python_esrs_key'])->not->toBe('');
        expect($row['status'])->toBeIn($allowedStatuses);
        expect($row['ar16_topic_ids'])->toBeArray();
    });
});

/**
 * @return array<int, array<string, string>>
 */
function readCsvRows(string $path): array
{
    $handle = fopen($path, 'r');
    $header = fgetcsv($handle);
    $rows = [];

    while (($row = fgetcsv($handle)) !== false) {
        $rows[] = array_combine($header, $row);
    }

    fclose($handle);

    return $rows;
}
