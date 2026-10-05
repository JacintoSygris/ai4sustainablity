<?php

use App\Models\Characterization;
use App\Services\LearningP5Snapshot;
use App\Support\CharacterizationOptions;

// Fixture namespace: standalone-synthetic-only; synthetic_only=true; promotion_allowed=false.
function t06aCharacterization(mixed $form): Characterization
{
    $model = new Characterization;
    $model->setRawAttributes(['form_data' => json_encode($form, JSON_THROW_ON_ERROR)]);

    return $model;
}

it('projects exact detached values with qualified Python golden digests', function (array $values, string $digest) {
    $model = t06aCharacterization([
        'company_profile' => ['stock_listed' => $values['stock_listed'], 'headquarters_country' => $values['headquarters_country']],
        'operations' => ['employee_count_range' => $values['employee_count_range']],
    ]);
    expect((new LearningP5Snapshot)->project($model))->toBe([
        'schema_version' => 'p5-learning-input-v1', 'digest' => $digest, 'values' => $values,
    ]);
})->with([
    'unknown' => [['employee_count_range' => null, 'headquarters_country' => null, 'stock_listed' => null], '3a5c3ad57d7d5299fce606f8bd23c66ad5132bb33b4a5d191681855fad64b09a'],
    'false and explicit uncertainty' => [['employee_count_range' => 'not_sure', 'headquarters_country' => 'Spain', 'stock_listed' => false], 'd9f55da2c4dbc02b884eca67d7c7c4e4e579b640e9832665de3185b740e4da29'],
    'true and range' => [['employee_count_range' => '50_249', 'headquarters_country' => 'United Kingdom', 'stock_listed' => true], '34ef426925bfe89cb1435c34294398252e66e9edd4c3da909ba4beb99a8a438d'],
]);

it('treats empty missing and null sections as unknown', function (mixed $form) {
    $model = t06aCharacterization($form);
    expect((new LearningP5Snapshot)->project($model)['values'])->toBe([
        'employee_count_range' => null, 'headquarters_country' => null, 'stock_listed' => null,
    ]);
})->with([[null], [[]], [['company_profile' => null, 'operations' => null]], [['company_profile' => [], 'operations' => []]]]);

it('projects a missing stored form as unknown', function () {
    expect((new LearningP5Snapshot)->project(new Characterization)['digest'])
        ->toBe('3a5c3ad57d7d5299fce606f8bd23c66ad5132bb33b4a5d191681855fad64b09a');
});

it('rejects malformed decoded containers with stable errors', function (mixed $form, string $message) {
    expect(fn () => (new LearningP5Snapshot)->project(t06aCharacterization($form)))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    ['scalar', 'learning_p5.form_data_invalid'], [false, 'learning_p5.form_data_invalid'],
    [0, 'learning_p5.form_data_invalid'], [[['company_profile' => []]], 'learning_p5.form_data_invalid'],
    [['company_profile' => 'scalar'], 'learning_p5.company_profile_invalid'],
    [['company_profile' => false], 'learning_p5.company_profile_invalid'],
    [['company_profile' => 0], 'learning_p5.company_profile_invalid'],
    [['company_profile' => ['Spain']], 'learning_p5.company_profile_invalid'],
    [['operations' => 'scalar'], 'learning_p5.operations_invalid'],
    [['operations' => false], 'learning_p5.operations_invalid'],
    [['operations' => 0], 'learning_p5.operations_invalid'],
    [['operations' => ['50_249']], 'learning_p5.operations_invalid'],
]);

it('rejects invalid country values without normalization', function (mixed $value) {
    expect(fn () => (new LearningP5Snapshot)->project(t06aCharacterization(['company_profile' => ['headquarters_country' => $value]])))
        ->toThrow(InvalidArgumentException::class, 'learning_p5.headquarters_country_invalid');
})->with(['España', 'ENTITY_SYNTHETIC_PRIVATE', 'Atlantis', '__missing__', '', ' Spain', 'spain', 0, false, [['Spain']]]);

it('rejects invalid range values without estimation', function (mixed $value) {
    expect(fn () => (new LearningP5Snapshot)->project(t06aCharacterization(['operations' => ['employee_count_range' => $value]])))
        ->toThrow(InvalidArgumentException::class, 'learning_p5.employee_count_range_invalid');
})->with([150, 0, 1.5, 'unknown', '__missing__', '', '50_249 ', false, [['50_249']]]);

it('rejects stock coercion', function (mixed $value) {
    expect(fn () => (new LearningP5Snapshot)->project(t06aCharacterization(['company_profile' => ['stock_listed' => $value]])))
        ->toThrow(InvalidArgumentException::class, 'learning_p5.stock_listed_invalid');
})->with([0, 1, 'false', 'true', '0', '', 0.5, [[false]]]);

it('accepts every existing controlled key exactly', function () {
    foreach (array_keys(CharacterizationOptions::headquartersCountries()) as $country) {
        expect((new LearningP5Snapshot)->project(t06aCharacterization(['company_profile' => ['headquarters_country' => $country]]))['values']['headquarters_country'])->toBe($country);
    }
    foreach (array_keys(CharacterizationOptions::employeeCountRanges()) as $range) {
        expect((new LearningP5Snapshot)->project(t06aCharacterization(['operations' => ['employee_count_range' => $range]]))['values']['employee_count_range'])->toBe($range);
    }
});

it('ignores source key order and all excluded data', function () {
    $base = ['company_profile' => ['headquarters_country' => 'Spain', 'stock_listed' => false], 'operations' => ['employee_count_range' => 'not_sure']];
    $expected = (new LearningP5Snapshot)->project(t06aCharacterization($base));
    $changed = [
        'operations' => ['notes' => 'SYNTHETIC_NOTE', 'employee_count' => 999, 'revenue' => 123, 'employee_count_range' => 'not_sure'],
        'company_profile' => ['stock_listed' => false, 'company_name' => 'ENTITY_SYNTHETIC', 'entity_identifier' => 'SYNTHETIC_LEI', 'reporting_currency' => 'SYNTHETIC', 'reporting_year' => 2025, 'reporting_scope' => 'SYNTHETIC_PERIMETER', 'headquarters_country' => 'Spain'],
        'p6' => ['candidate' => true], 'p8' => ['final' => true], 'p9' => ['datapoints' => ['SYNTHETIC']],
        'answers' => ['SYNTHETIC'], 'metrics' => [999], 'reason' => 'SYNTHETIC_REASON', 'esg_focus' => ['topic_ids' => ['SYNTHETIC']],
    ];
    $model = t06aCharacterization($changed);
    $model->esrs_topic_ids = ['SYNTHETIC_TOPIC'];
    $model->result_data = ['prediction' => 'SYNTHETIC'];
    expect((new LearningP5Snapshot)->project($model))->toBe($expected);
});

it('changes the digest for every allowed value change', function () {
    $service = new LearningP5Snapshot;
    $base = ['company_profile' => ['headquarters_country' => 'Spain', 'stock_listed' => false], 'operations' => ['employee_count_range' => 'not_sure']];
    $digest = $service->project(t06aCharacterization($base))['digest'];
    foreach (['headquarters_country' => [null, 'Portugal'], 'stock_listed' => [null, true], 'employee_count_range' => [null, '1_9']] as $field => $values) {
        foreach ($values as $value) {
            $form = $base;
            $form[$field === 'employee_count_range' ? 'operations' : 'company_profile'][$field] = $value;
            expect($service->project(t06aCharacterization($form))['digest'])->not->toBe($digest);
        }
    }
});

it('returns values detached from the model and later projections', function () {
    $model = t06aCharacterization(['company_profile' => ['headquarters_country' => 'Spain', 'stock_listed' => false]]);
    $before = $model->getAttributes();
    $form = $model->form_data;
    $service = new LearningP5Snapshot;
    $expected = $service->project($model);
    $projection = $service->project($model);
    $projection['values']['stock_listed'] = true;
    $projection['values']['headquarters_country'] = 'Portugal';
    expect($model->getAttributes())->toBe($before)
        ->and($model->form_data)->toBe($form)
        ->and($service->project($model))->toBe($expected);
});

it('rejects decoded nonfinite values using the strict scalar rules', function (string $section, string $field) {
    $model = new Characterization;
    $model->setRawAttributes(['form_data' => '{"'.$section.'":{"'.$field.'":1e999}}']);
    expect(fn () => (new LearningP5Snapshot)->project($model))
        ->toThrow(InvalidArgumentException::class, 'learning_p5.'.$field.'_invalid');
})->with([
    ['company_profile', 'headquarters_country'], ['company_profile', 'stock_listed'], ['operations', 'employee_count_range'],
]);

it('projects real persisted synthetic model storage without writes', function () {
    $user = App\Models\User::factory()->create();
    $model = Characterization::create([
        'user_id' => $user->id, 'status' => Characterization::STATUS_DRAFT,
        'form_data' => ['operations' => ['employee_count_range' => 'not_sure'], 'company_profile' => ['headquarters_country' => 'Spain', 'stock_listed' => false]],
    ])->fresh();
    $before = $model->getAttributes();
    expect((new LearningP5Snapshot)->project($model)['digest'])
        ->toBe('d9f55da2c4dbc02b884eca67d7c7c4e4e579b640e9832665de3185b740e4da29')
        ->and($model->fresh()->getAttributes())->toBe($before);
});

it('rejects corrupt raw stored JSON with exact admission errors and causes', function (string $vector) {
    $raw = match ($vector) {
        'truncated' => '{',
        'empty' => '',
        'section' => '{"company_profile":',
        'utf8' => "{\"notes\":\"\xff\"}",
        'depth' => str_repeat('[', 513).'0'.str_repeat(']', 513),
        'array' => [],
        'integer' => 0,
        'boolean' => false,
    };
    $model = new Characterization;
    $model->setRawAttributes(['form_data' => $raw]);
    try {
        (new LearningP5Snapshot)->project($model);
        $this->fail('Corrupt raw storage was admitted');
    } catch (InvalidArgumentException $exception) {
        expect($exception->getMessage())->toBe('learning_p5.form_data_invalid')
            ->and($exception->getCode())->toBe(0);
        if (is_string($raw)) {
            expect($exception->getPrevious())->toBeInstanceOf(JsonException::class);
        } else {
            expect($exception->getPrevious())->toBeNull();
        }
    }
})->with(['truncated', 'empty', 'section', 'utf8', 'depth', 'array', 'integer', 'boolean']);

it('preserves optional raw storage absence and empty JSON as unknown', function (string $vector) {
    $attributes = match ($vector) {
        'missing' => [],
        'sql-null' => ['form_data' => null],
        'json-null' => ['form_data' => 'null'],
        'object' => ['form_data' => '{}'],
        'list' => ['form_data' => '[]'],
    };
    $model = new Characterization;
    $model->setRawAttributes($attributes);
    expect((new LearningP5Snapshot)->project($model))->toBe([
        'schema_version' => 'p5-learning-input-v1',
        'digest' => '3a5c3ad57d7d5299fce606f8bd23c66ad5132bb33b4a5d191681855fad64b09a',
        'values' => ['employee_count_range' => null, 'headquarters_country' => null, 'stock_listed' => null],
    ]);
})->with(['missing', 'sql-null', 'json-null', 'object', 'list']);

it('projects current unsaved raw JSON rather than the original stored attributes', function () {
    $model = new Characterization;
    $model->setRawAttributes(['form_data' => '{}'], true);
    $model->form_data = [
        'company_profile' => ['headquarters_country' => 'Spain', 'stock_listed' => false],
        'operations' => ['employee_count_range' => 'not_sure'],
    ];
    expect($model->getRawOriginal('form_data'))->toBe('{}')
        ->and((new LearningP5Snapshot)->project($model))->toBe([
            'schema_version' => 'p5-learning-input-v1',
            'digest' => 'd9f55da2c4dbc02b884eca67d7c7c4e4e579b640e9832665de3185b740e4da29',
            'values' => ['employee_count_range' => 'not_sure', 'headquarters_country' => 'Spain', 'stock_listed' => false],
        ]);
});