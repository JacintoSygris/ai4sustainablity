<?php

use App\Models\Characterization;
use App\Models\EsrsTopic;
use App\Models\User;
use App\Services\Wp13P8FeedbackExport;
use App\Support\Wp13TrainingSourceFingerprint;

beforeEach(function () {
    $this->seed(\Database\Seeders\EsrsTopicSeeder::class);
    $this->seed(\Database\Seeders\NaceCodeSeeder::class);
    $this->ids = EsrsTopic::where('esrs_code', 'E2')->limit(3)->pluck('id')->all();
    expect($this->ids)->toHaveCount(3);
    $this->source = Characterization::factory()->create([
        'user_id' => User::factory()->create()->id,
        'status' => Characterization::STATUS_COMPLETED,
        'submission_generation' => 7,
        'nace_code' => 'A1.1',
        'esrs_topic_ids' => $this->ids,
        'form_data' => [
            'company_profile' => [
                'company_name' => 'SYNTHETIC_IDENTITY', 'lei' => 'SYNTHETIC_IDENTITY',
                'headquarters_country' => 'Spain', 'reporting_year' => 2026,
                'reporting_scope' => 'individual', 'num_subsidiaries_countries' => 0,
                'stock_listed' => false, 'reporting_currency' => 'EUR',
                'product_service_type' => 'physical_product_manufacturing',
            ],
            'operations' => [
                'employee_count' => 150, 'notes' => 'SYNTHETIC_FREE_TEXT',
                'regions' => ['eu'], 'value_chain' => ['direct_operations'],
                'employee_count_range' => '50_249', 'revenue_range' => '2m_to_10m',
            ],
            'activity_questions' => ['water_use' => 'yes'],
        ],
    ]);
    $form = $this->source->form_data;
    $form['materiality_confirmation'] = [
        'revision' => 2,
        'confirmed_at' => '2026-10-01T09:00:00.000000Z',
        'training_source' => [
            'schema_version' => 1, 'submission_generation' => 7,
            'p5_input_sha256' => Wp13TrainingSourceFingerprint::hash($this->source),
        ],
        'p6_snapshot' => ['topic_ids' => $this->ids, 'captured_at' => '2026-10-01T09:00:00.000000Z'],
        'confirmed_topic_ids' => [$this->ids[0]],
        'guided_answers' => [
            $this->ids[0] => ['final_result' => 'material', 'revisar' => false, 'note' => 'SYNTHETIC_FREE_TEXT'],
            $this->ids[1] => ['final_result' => 'no_material', 'revisar' => false],
        ],
        'change_reason_notes' => [$this->ids[1] => 'SYNTHETIC_FREE_TEXT'],
    ];
    $this->source->update(['form_data' => $form]);
});

it('rejects stale or incomplete provenance instead of exporting a training row', function (string $path, mixed $value) {
    if (str_starts_with($path, 'source.')) {
        $this->source->forceFill([substr($path, 7) => $value]);
    } else {
        $form = $this->source->form_data;
        data_set($form, $path, $value);
        $this->source->forceFill(['form_data' => $form]);
    }
    expect(fn () => (new Wp13P8FeedbackExport)->export(collect([$this->source]), $this->ids))
        ->toThrow(\InvalidArgumentException::class);
})->with([
    'P6 pending' => ['source.status', Characterization::STATUS_PROCESSING],
    'stale generation' => ['source.submission_generation', 8],
    'stale P5' => ['operations.employee_count', 400],
    'stale P6' => ['source.esrs_topic_ids', []],
    'legacy confirmation' => ['materiality_confirmation', null],
    'missing seal' => ['materiality_confirmation.training_source', null],
    'legacy seal' => ['materiality_confirmation.training_source', []],
    'future schema' => ['materiality_confirmation.training_source.schema_version', 2],
    'string schema' => ['materiality_confirmation.training_source.schema_version', '1'],
    'missing generation' => ['materiality_confirmation.training_source.submission_generation', null],
    'invalid hash' => ['materiality_confirmation.training_source.p5_input_sha256', str_repeat('a', 64)],
    'missing revision' => ['materiality_confirmation.revision', null],
    'zero revision' => ['materiality_confirmation.revision', 0],
    'missing confirmation time' => ['materiality_confirmation.confirmed_at', null],
    'invalid confirmation time' => ['materiality_confirmation.confirmed_at', 'yesterday'],
    'missing snapshot' => ['materiality_confirmation.p6_snapshot', null],
    'missing snapshot time' => ['materiality_confirmation.p6_snapshot.captured_at', null],
    'missing snapshot topics' => ['materiality_confirmation.p6_snapshot.topic_ids', null],
    'missing selected set' => ['materiality_confirmation.confirmed_topic_ids', null],
]);

it('exports explicit tri-state decisions with the exact pre-P8 snapshot and hash', function () {
    $rows = (new Wp13P8FeedbackExport)->export(collect([$this->source]), $this->ids);
    $snapshot = [
        'activity_questions' => ['water_use' => 'yes'],
        'company_profile' => [
            'headquarters_country' => 'Spain', 'num_subsidiaries_countries' => 0,
            'product_service_type' => 'physical_product_manufacturing', 'reporting_currency' => 'EUR',
            'reporting_scope' => 'individual', 'reporting_year' => 2026, 'stock_listed' => false,
        ],
        'nace_code' => 'A1.1',
        'operations' => [
            'employee_count' => 150, 'employee_count_range' => '50_249',
            'regions' => ['eu'], 'revenue_range' => '2m_to_10m', 'value_chain' => ['direct_operations'],
        ],
    ];
    expect($rows)->toBe([[
        'characterization_id' => $this->source->id,
        'submission_generation' => 7,
        'p8_revision' => 2,
        'p6_snapshot' => $this->source->form_data['materiality_confirmation']['p6_snapshot'],
        'p5_input_sha256' => hash('sha256', json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
        'p5_snapshot' => $snapshot,
        'topics' => [
            $this->ids[0] => ['value' => 1, 'mask' => 1],
            $this->ids[1] => ['value' => 0, 'mask' => 1],
            $this->ids[2] => ['value' => null, 'mask' => 0],
        ],
    ]]);
    expect($rows[0]['p5_input_sha256'])->toBe(Wp13TrainingSourceFingerprint::hash($this->source));
    expect(json_encode($rows, JSON_THROW_ON_ERROR))->not->toContain('SYNTHETIC_IDENTITY', 'SYNTHETIC_FREE_TEXT', 'user_id');
});

it('rejects contradictory guided decisions even outside the requested columns', function (int $index, string $result, bool $review) {
    $form = $this->source->form_data;
    $form['materiality_confirmation']['guided_answers'][$this->ids[$index]] = ['final_result' => $result, 'revisar' => $review];
    $this->source->forceFill(['form_data' => $form]);
    expect(fn () => (new Wp13P8FeedbackExport)->export(collect([$this->source]), [$this->ids[2]]))
        ->toThrow(\InvalidArgumentException::class);
})->with([
    'selected negative' => [0, 'no_material', false],
    'unselected positive' => [1, 'material', false],
    'review cannot hide selected negative' => [0, 'no_material', true],
    'review cannot hide unselected positive' => [1, 'material', true],
]);

it('masks reviewed absent and ambiguous decisions without inferring negatives', function (mixed $answer) {
    $form = $this->source->form_data;
    $form['materiality_confirmation']['guided_answers'][$this->ids[0]] = $answer;
    $form['materiality_confirmation']['guided_answers'][$this->ids[1]] = $answer;
    $form['materiality_confirmation']['change_reasons'] = [$this->ids[2] => ['threshold']];
    $form['materiality_review'] = ['discarded_topic_ids' => [$this->ids[2]]];
    $this->source->forceFill(['form_data' => $form]);
    $row = (new Wp13P8FeedbackExport)->export(collect([$this->source]), $this->ids)[0];
    foreach ($this->ids as $id) {
        expect($row['topics'][$id])->toBe(['value' => null, 'mask' => 0]);
    }
})->with([
    'reviewed ambiguous' => [['final_result' => 'en_observacion', 'revisar' => true]],
    'ambiguous' => [['final_result' => 'en_observacion', 'revisar' => false]],
    'no explicit result' => [['suggested_result' => 'no_material', 'revisar' => false]],
    'empty answer' => [[]],
]);

it('masks consistent explicit decisions when review is true or missing', function (mixed $review) {
    $form = $this->source->form_data;
    foreach ([$this->ids[0], $this->ids[1]] as $id) {
        $form['materiality_confirmation']['guided_answers'][$id]['revisar'] = $review;
    }
    $this->source->forceFill(['form_data' => $form]);
    $row = (new Wp13P8FeedbackExport)->export(collect([$this->source]), $this->ids)[0];
    foreach ($this->ids as $id) {
        expect($row['topics'][$id])->toBe(['value' => null, 'mask' => 0]);
    }
})->with(['review' => [true], 'missing' => [null], 'ambiguous flag' => ['false']]);

it('rejects malformed guided answer containers', function (mixed $answers) {
    $form = $this->source->form_data;
    $form['materiality_confirmation']['guided_answers'] = $answers;
    $this->source->forceFill(['form_data' => $form]);
    expect(fn () => (new Wp13P8FeedbackExport)->export(collect([$this->source]), $this->ids))
        ->toThrow(\InvalidArgumentException::class);
})->with(['null' => [null], 'string' => ['no_material'], 'non-array answer' => [[1 => 'no_material']]]);

it('rejects noncanonical duplicate and unknown requested topic IDs', function (array $ids) {
    expect(fn () => (new Wp13P8FeedbackExport)->export(collect([$this->source]), $ids))
        ->toThrow(\InvalidArgumentException::class);
})->with([
    'duplicate' => [[1, 1]], 'string' => [['1']], 'leading zero' => [['01']],
    'unknown' => [[999999]], 'negative' => [[-1]], 'zero' => [[0]],
    'boolean' => [[true]], 'float' => [[1.0]], 'nested' => [[[1]]],
    'keyed list' => [[2 => 1]], 'empty columns' => [[]],
]);

it('validates every stored ID set and guided key without normalizing corruption', function (string $field, array $ids) {
    $form = $this->source->form_data;
    // Isolate ID eligibility from guided-selection conflicts.
    $form['materiality_confirmation']['guided_answers'] = [];
    if ($field === 'current') {
        $this->source->esrs_topic_ids = $ids;
        $form['materiality_confirmation']['p6_snapshot']['topic_ids'] = $ids;
    } elseif ($field === 'guided_answers') {
        $form['materiality_confirmation']['guided_answers'] = $ids;
    } else {
        data_set($form['materiality_confirmation'], $field, $ids);
    }
    $this->source->form_data = $form;
    expect(fn () => (new Wp13P8FeedbackExport)->export(collect([$this->source]), $this->ids))
        ->toThrow(\InvalidArgumentException::class);
})->with([
    'duplicate current and snapshot' => ['current', [1, 1]],
    'unknown current and snapshot' => ['current', [999999]],
    'string current and snapshot' => ['current', ['1']],
    'duplicate selected' => ['confirmed_topic_ids', [1, 1]],
    'unknown selected' => ['confirmed_topic_ids', [999999]],
    'noncanonical selected' => ['confirmed_topic_ids', ['01']],
    'noncanonical guided key' => ['guided_answers', ['01' => []]],
    'unknown guided key' => ['guided_answers', [999999 => []]],
    'invalid snapshot' => ['p6_snapshot.topic_ids', ['1']],
]);

it('rejects duplicate records and non-model collection entries', function (string $case) {
    $sources = match ($case) {
        'duplicate' => collect([$this->source, clone $this->source]),
        'non-model' => collect([$this->source, ['id' => 999]]),
        'unsaved' => collect([new Characterization($this->source->getAttributes())]),
    };
    expect(fn () => (new Wp13P8FeedbackExport)->export($sources, $this->ids))
        ->toThrow(\InvalidArgumentException::class);
})->with(['duplicate', 'non-model', 'unsaved']);

it('exports only caller-supplied records across two synthetic users in deterministic order', function () {
    $other = Characterization::factory()->create([
        'user_id' => User::factory()->create()->id,
        'status' => $this->source->status,
        'submission_generation' => 7,
        'nace_code' => $this->source->nace_code,
        'esrs_topic_ids' => array_reverse($this->ids),
        'form_data' => $this->source->form_data,
    ]);
    $export = new Wp13P8FeedbackExport;
    expect(array_column($export->export(collect([$this->source]), $this->ids), 'characterization_id'))->toBe([$this->source->id]);
    expect(array_column($export->export(collect([$other]), $this->ids), 'characterization_id'))->toBe([$other->id]);
    $expected = $export->export(collect([$this->source, $other]), $this->ids);
    $form = $other->form_data;
    $form['operations'] = array_reverse($form['operations'], true);
    $form['materiality_confirmation']['p6_snapshot']['topic_ids'] = array_reverse($this->ids);
    $form['materiality_confirmation']['p6_snapshot']['notes'] = 'SYNTHETIC_FREE_TEXT';
    $other->form_data = array_reverse($form, true);
    $actual = $export->export(collect([$other, $this->source]), array_reverse($this->ids));
    expect(json_encode($actual, JSON_THROW_ON_ERROR))->toBe(json_encode($expected, JSON_THROW_ON_ERROR));
    expect($export->export(collect(), $this->ids))->toBe([]);
});

it('does not query characterizations or write database or audit state on success and rejection', function () {
    $before = \Illuminate\Support\Facades\DB::table('characterizations')->get()->toJson();
    $attributes = $this->source->getAttributes();
    $queries = [];
    \Illuminate\Support\Facades\DB::listen(function ($event) use (&$queries) {
        $queries[] = $event->sql;
    });
    $export = new Wp13P8FeedbackExport;
    $export->export(collect([$this->source]), $this->ids);
    $stale = clone $this->source;
    $stale->submission_generation = 8;
    expect(fn () => $export->export(collect([$stale]), $this->ids))->toThrow(\InvalidArgumentException::class);
    foreach ($queries as $query) {
        expect(strtolower($query))->toStartWith('select')->not->toContain('characterizations', 'audit', 'users');
    }
    expect($this->source->getAttributes())->toBe($attributes);
    expect(\Illuminate\Support\Facades\DB::table('characterizations')->get()->toJson())->toBe($before);
});

it('rejects an empty completed proposal even with a matching empty snapshot', function () {
    $form = $this->source->form_data;
    $form['materiality_confirmation']['p6_snapshot']['topic_ids'] = [];
    $this->source->forceFill(['esrs_topic_ids' => [], 'form_data' => $form]);
    expect(fn () => (new Wp13P8FeedbackExport)->export(collect([$this->source]), $this->ids))
        ->toThrow(\InvalidArgumentException::class);
});

it('treats a direct selection without guided answers as unknown for every column', function () {
    $form = $this->source->form_data;
    unset($form['materiality_confirmation']['guided_answers']);
    $this->source->form_data = $form;
    $row = (new Wp13P8FeedbackExport)->export(collect([$this->source]), $this->ids)[0];
    foreach ($row['topics'] as $decision) {
        expect($decision)->toBe(['value' => null, 'mask' => 0]);
    }
});

dataset('wp13 malformed present P5', function () {
    $cases = [];
    $scalars = [
        'company_profile.headquarters_country', 'company_profile.reporting_year',
        'company_profile.reporting_scope', 'company_profile.num_subsidiaries_countries',
        'company_profile.stock_listed', 'company_profile.reporting_currency',
        'company_profile.product_service_type', 'operations.employee_count_range',
        'operations.revenue_range', 'operations.employee_count', 'operations.revenue',
        ...array_map(fn ($key) => 'activity_questions.'.$key, (new ReflectionClass(Wp13TrainingSourceFingerprint::class))->getConstant('ACTIVITY_FIELDS')),
    ];
    foreach ($scalars as $path) {
        foreach (['array' => [1], 'associative' => ['bad' => 'first'], 'arbitrary string' => 'PERSON <person@example.invalid>', 'invalid number' => -1, 'invalid bool' => true] as $kind => $value) {
            // A native boolean is legitimate only for stock_listed.
            if ($path === 'company_profile.stock_listed' && $kind === 'invalid bool') {
                continue;
            }
            $cases[$path.' '.$kind] = [$path, $value];
        }
    }
    foreach (['regions', 'value_chain'] as $field) {
        foreach (['scalar' => 'eu', 'associative first' => ['bad' => 'first'], 'associative second' => ['bad' => 'second'], 'number list' => [1], 'boolean list' => [true], 'nested list' => [['eu']], 'secret list' => ['SYNTHETIC_SECRET'], 'null' => null] as $kind => $value) {
            $cases['operations.'.$field.' '.$kind] = ['operations.'.$field, $value];
        }
    }
    foreach (['company_profile', 'operations', 'activity_questions'] as $section) {
        foreach (['scalar' => 'SYNTHETIC_SECRET', 'list' => ['eu'], 'null' => null] as $kind => $value) {
            $cases[$section.' '.$kind] = [$section, $value];
        }
    }
    $cases['employee array second'] = ['operations.employee_count', [999]];
    $cases['employee fractional'] = ['operations.employee_count', 1.5];
    $cases['employee zero'] = ['operations.employee_count', 0];
    $cases['revenue nonfinite'] = ['operations.revenue', '1e9999'];
    $cases['year out of range'] = ['company_profile.reporting_year', 1999];
    $cases['countries out of range'] = ['company_profile.num_subsidiaries_countries', 501];
    $cases['bool text'] = ['company_profile.stock_listed', 'false'];
    foreach (['email' => 'person@example.invalid', 'array' => ['A'], 'number' => 1, 'bool' => true, 'bad format' => 'A1.secret'] as $kind => $value) {
        $cases['nace '.$kind] = ['nace_code', $value];
    }

    return $cases;
});

it('rejects malformed P5 export even with a matching legacy collision or leaking seal', function (string $path, mixed $value) {
    $form = $this->source->form_data;
    $snapshot = Wp13TrainingSourceFingerprint::snapshot($this->source);
    // Reconstruct the old projection independently so the regression does not use
    // the fixed hash to manufacture provenance or mask rejection as stale input.
    if ($path === 'nace_code') {
        $this->source->nace_code = $value;
        $snapshot['nace_code'] = $this->source->nace_code;
    } else {
        data_set($form, $path, $value);
        if (! str_contains($path, '.')) {
            $snapshot[$path] = [];
        } elseif (in_array($path, ['operations.regions', 'operations.value_chain'], true)) {
            if (is_array($value) && array_is_list($value) && count(array_filter($value, 'is_string')) === count($value)) {
                data_set($snapshot, $path, $value);
            } else {
                \Illuminate\Support\Arr::forget($snapshot, $path);
            }
        } elseif ($value === null || is_scalar($value)) {
            data_set($snapshot, $path, $value);
        } else {
            \Illuminate\Support\Arr::forget($snapshot, $path);
        }
    }
    $sort = function ($value) use (&$sort) {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map($sort, $value);
    };
    $form['materiality_confirmation']['training_source']['p5_input_sha256'] = hash('sha256', json_encode($sort($snapshot), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    $this->source->form_data = $form;
    $before = $this->source->getAttributes();
    expect(fn () => (new Wp13P8FeedbackExport)->export(collect([$this->source]), $this->ids))->toThrow(\InvalidArgumentException::class);
    expect($this->source->getAttributes())->toBe($before);
})->with('wp13 malformed present P5');

dataset('wp13 required submit inputs', function () {
    $cases = [];
    foreach (\App\Support\CharacterizationOptions::submitRequiredFields() as $field) {
        $path = str_starts_with($field, 'form_data.') ? substr($field, 10) : $field;
        foreach (['missing', 'null', 'empty'] as $kind) {
            $cases[$path.' '.$kind] = [$path, $kind, null];
        }
    }
    foreach (['company_profile', 'operations'] as $path) {
        $cases[$path.' missing section'] = [$path, 'missing', null];
        $cases[$path.' empty section'] = [$path, 'value', []];
    }
    $cases['both required sections missing'] = ['both', 'missing', null];
    foreach (['company_name' => '   ', 'company_name array' => ['bad'], 'company_name number' => 123, 'company_name boolean' => true, 'company_name too long' => str_repeat('X', 256)] as $kind => $value) {
        $cases[$kind] = ['company_profile.company_name', 'value', $value];
    }
    $cases['unknown valid-format NACE'] = ['nace_code', 'value', '99.99'];
    foreach (['regions', 'value_chain'] as $field) {
        foreach (['empty list' => [], 'keyed list' => ['bad' => 'eu'], 'nested list' => [['eu']], 'duplicate list' => $field === 'regions' ? ['eu', 'eu'] : ['direct_operations', 'direct_operations']] as $kind => $value) {
            $cases[$field.' '.$kind] = ['operations.'.$field, 'value', $value];
        }
    }

    return $cases;
});

it('rejects incomplete submit inputs at export despite a matching v1 seal', function (string $path, string $kind, mixed $value) {
    $form = $this->source->form_data;
    $snapshot = Wp13TrainingSourceFingerprint::snapshot($this->source);
    $value = match ($kind) {
        'empty' => '', 'null' => null, default => $value
    };
    if ($path === 'nace_code') {
        $this->source->nace_code = $value;
        $snapshot['nace_code'] = $this->source->nace_code;
    } else {
        foreach ($path === 'both' ? ['company_profile', 'operations'] : [$path] as $target) {
            if ($kind === 'missing') {
                \Illuminate\Support\Arr::forget($form, $target);
                if (str_contains($target, '.')) {
                    \Illuminate\Support\Arr::forget($snapshot, $target);
                } else {
                    $snapshot[$target] = [];
                }
            } else {
                data_set($form, $target, $value);
                if ($target !== 'company_profile.company_name') {
                    data_set($snapshot, $target, $value);
                }
            }
        }
    }
    // Independent v1 byte construction: do not let the fixed validator create
    // the seal or let stale provenance disguise missing-input acceptance.
    $sort = function ($value) use (&$sort) {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map($sort, $value);
    };
    $form['materiality_confirmation']['training_source']['p5_input_sha256'] = hash('sha256', json_encode($sort($snapshot), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    $this->source->form_data = $form;
    $before = $this->source->getAttributes();
    expect(fn () => (new Wp13P8FeedbackExport)->export(collect([$this->source]), $this->ids))
        ->toThrow(\InvalidArgumentException::class);
    expect($this->source->getAttributes())->toBe($before);
})->with('wp13 required submit inputs');

it('keeps optional P5 fields absent and company names outside the v1 bytes and export', function () {
    $form = $this->source->form_data;
    unset($form['operations']['employee_count'], $form['activity_questions']);
    $this->source->form_data = $form;
    $hash = Wp13TrainingSourceFingerprint::hash($this->source);
    $form['materiality_confirmation']['training_source']['p5_input_sha256'] = $hash;
    $this->source->form_data = $form;
    $export = new Wp13P8FeedbackExport;
    $before = $export->export(collect([$this->source]), $this->ids);
    $form['company_profile']['company_name'] = 'SYNTHETIC_RENAMED';
    $this->source->form_data = $form;
    expect(Wp13TrainingSourceFingerprint::hash($this->source))->toBe($hash);
    expect($export->export(collect([$this->source]), $this->ids))->toBe($before);
    expect(json_encode($before, JSON_THROW_ON_ERROR))->not->toContain('SYNTHETIC_IDENTITY', 'SYNTHETIC_RENAMED', 'company_name');
    expect($before[0]['p5_snapshot']['operations'])->not->toHaveKey('employee_count');
    expect($before[0]['p5_snapshot']['activity_questions'])->toBe([]);
});

it('accepts legitimate submit representations without changing their v1 bytes', function (mixed $listed) {
    $form = $this->source->form_data;
    $form['company_profile']['stock_listed'] = $listed;
    $form['company_profile']['num_subsidiaries_countries'] = '0';
    $form['company_profile']['reporting_year'] = '2026';
    $form['company_profile']['reporting_scope'] = 'not_sure';
    $form['operations']['employee_count_range'] = 'not_sure';
    $form['operations']['revenue_range'] = 'not_sure';
    $form['operations']['employee_count'] = null;
    $form['operations']['revenue'] = null;
    $this->source->form_data = $form;
    $snapshot = Wp13TrainingSourceFingerprint::snapshot($this->source);
    $hash = Wp13TrainingSourceFingerprint::hash($this->source);
    $form['materiality_confirmation']['training_source']['p5_input_sha256'] = $hash;
    $this->source->form_data = $form;
    $row = (new Wp13P8FeedbackExport)->export(collect([$this->source]), $this->ids)[0];
    expect($row['p5_snapshot'])->toBe($snapshot);
    expect($row['p5_input_sha256'])->toBe($hash);
    expect($row['p5_snapshot']['company_profile']['stock_listed'])->toBe($listed);
})->with(['native zero' => [0], 'native one' => [1], 'native false' => [false], 'native true' => [true], 'string zero' => ['0'], 'string one' => ['1']]);
