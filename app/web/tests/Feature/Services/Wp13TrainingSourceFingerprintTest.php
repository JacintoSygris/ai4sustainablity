<?php

use App\Models\Characterization;
use App\Models\EsrsTopic;
use App\Models\User;
use App\Support\Wp13TrainingSourceFingerprint;

beforeEach(function () {
    config(['services.private_dev.auto_login' => false]);
    $this->seed(\Database\Seeders\EsrsTopicSeeder::class);
    $this->user = User::factory()->create();
    $this->topic = EsrsTopic::where('esrs_code', 'E2')->firstOrFail();
    $this->source = Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'submission_generation' => 7,
        'nace_code' => '10.1',
        'esrs_topic_ids' => [$this->topic->id],
        'form_data' => [
            'company_profile' => ['headquarters_country' => 'Spain', 'stock_listed' => false],
            'operations' => ['employee_count' => 150, 'regions' => ['eu']],
            'activity_questions' => ['physical_operations' => 'yes', 'water_use' => 'not_sure'],
        ],
    ]);
    $this->actingAs($this->user);
});

it('excludes identity notes unknown fields and P6 P8 outputs from the fingerprint', function () {
    $original = Wp13TrainingSourceFingerprint::hash($this->source);
    $form = $this->source->form_data;
    $form['company_profile'] += ['company_name' => 'FICTITIOUS_ENTITY', 'entity_identifier' => 'SYNTHETIC_ID', 'secret' => 'SYNTHETIC_SECRET'];
    $form['operations'] += ['notes' => 'SYNTHETIC_NOTE', 'extra' => ['secret' => 'SYNTHETIC_SECRET']];
    $form['activity_questions']['unknown_question'] = 'SYNTHETIC_SECRET';
    $form['notes'] = 'SYNTHETIC_NOTE';
    $form['materiality_confirmation'] = ['confirmed_topic_ids' => [], 'revision' => 99];
    $this->source->forceFill(['form_data' => $form, 'result_data' => ['raw_prediction' => ['output' => true]], 'esrs_topic_ids' => []]);
    expect(Wp13TrainingSourceFingerprint::hash($this->source))->toBe($original);
});

it('keeps the pure provider activity keys in parity with localized booted options', function () {
    $keys = (new ReflectionClass(Wp13TrainingSourceFingerprint::class))->getConstant('ACTIVITY_FIELDS');
    expect($keys)->toHaveCount(9);
    $originalLocale = app()->getLocale();
    foreach (['es', 'en'] as $locale) {
        app()->setLocale($locale);
        expect(array_keys(\App\Support\CharacterizationOptions::activityQuestions()))->toBe($keys);
    }
    app()->setLocale($originalLocale);
});

// Every case must fail closed in both sealing and export, including forged legacy seals.
dataset('wp13 fingerprint malformed present P5', function () {
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

it('rejects malformed present P5 before snapshot or sealing', function (string $path, mixed $value) {
    $form = $this->source->form_data;
    if ($path === 'nace_code') {
        $this->source->nace_code = $value;
    } else {
        data_set($form, $path, $value);
        $this->source->form_data = $form;
    }
    $before = $this->source->getAttributes();
    expect(fn () => Wp13TrainingSourceFingerprint::snapshot($this->source))->toThrow(\InvalidArgumentException::class);
    expect(fn () => Wp13TrainingSourceFingerprint::hash($this->source))->toThrow(\InvalidArgumentException::class);
    expect($this->source->getAttributes())->toBe($before);
})->with('wp13 fingerprint malformed present P5');

it('preserves absent optional fields and exact valid form bytes in schema one', function () {
    $form = [
        'company_profile' => ['headquarters_country' => 'Spain', 'reporting_year' => 2026, 'reporting_scope' => 'individual', 'num_subsidiaries_countries' => 0, 'stock_listed' => false, 'reporting_currency' => 'EUR', 'product_service_type' => 'agrifood'],
        'operations' => ['employee_count_range' => '50_249', 'revenue_range' => 'lte_2m', 'employee_count' => 150, 'revenue' => 1000000, 'regions' => ['eu', 'asia'], 'value_chain' => ['upstream', 'direct_operations']],
        'activity_questions' => array_fill_keys(array_keys(\App\Support\CharacterizationOptions::activityQuestions()), 'not_sure'),
    ];
    $this->source->form_data = $form;
    $snapshot = Wp13TrainingSourceFingerprint::snapshot($this->source);
    expect($snapshot['operations']['regions'])->toBe(['eu', 'asia']);
    $hash = Wp13TrainingSourceFingerprint::hash($this->source);
    foreach ($form as &$section) {
        $section = array_reverse($section, true);
    }
    unset($section);
    $this->source->form_data = array_reverse($form, true);
    expect(Wp13TrainingSourceFingerprint::hash($this->source))->toBe($hash);
    $this->source->form_data = [];
    expect(Wp13TrainingSourceFingerprint::snapshot($this->source))->toBe(['activity_questions' => [], 'company_profile' => [], 'nace_code' => '10.1', 'operations' => []]);
    expect(Wp13TrainingSourceFingerprint::SCHEMA_VERSION)->toBe(1);
});

it('accepts every current form enum without changing its bytes', function () {
    $enums = [
        'company_profile.headquarters_country' => 'headquartersCountries',
        'company_profile.reporting_scope' => 'reportingScopes',
        'company_profile.reporting_currency' => 'reportingCurrencies',
        'company_profile.product_service_type' => 'productServiceTypes',
        'operations.employee_count_range' => 'employeeCountRanges',
        'operations.revenue_range' => 'revenueRanges',
        'operations.regions' => 'regions', 'operations.value_chain' => 'valueChainPositions',
    ];
    foreach (\App\Support\CharacterizationOptions::activityQuestions() as $key => $_) {
        $enums['activity_questions.'.$key] = 'yesNoUnknown';
    }
    foreach ($enums as $path => $method) {
        foreach (array_keys(\App\Support\CharacterizationOptions::$method()) as $key) {
            $value = in_array($path, ['operations.regions', 'operations.value_chain'], true) ? [$key] : $key;
            $this->source->form_data = [];
            $form = [];
            data_set($form, $path, $value);
            $this->source->form_data = $form;
            expect(data_get(Wp13TrainingSourceFingerprint::snapshot($this->source), $path))->toBe($value);
        }
    }
    foreach (['A', 'A1', 'A1.1', 'A1.1.0', 'C10.1.1', '10.1', '10.11', null] as $code) {
        $this->source->nace_code = $code;
        expect(Wp13TrainingSourceFingerprint::snapshot($this->source)['nace_code'])->toBe($code);
    }
});

it('retains nullable optional scalars and legitimate form numeric and boolean representations', function () {
    foreach ([
        ['company_profile.reporting_year', '2026'], ['company_profile.num_subsidiaries_countries', '0'],
        ['company_profile.stock_listed', 0], ['company_profile.stock_listed', '1'],
        ['operations.employee_count', '150'], ['operations.revenue', '1000.25'],
        ['operations.revenue', 1000.25], ['operations.employee_count', null],
        ['operations.revenue', null], ['activity_questions.water_use', null],
    ] as [$path, $value]) {
        $form = [];
        data_set($form, $path, $value);
        $this->source->form_data = $form;
        expect(data_get(Wp13TrainingSourceFingerprint::snapshot($this->source), $path))->toBe($value);
    }
});
