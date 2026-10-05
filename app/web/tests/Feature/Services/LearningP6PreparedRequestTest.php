<?php

use App\Models\Characterization;
use App\Models\NaceCode;
use App\Models\User;
use App\Services\ApiCharacterizationGateway;
use App\Services\CharacterizationPredictionMapper;
use App\Services\LearningP6PreparedRequest;
use App\Exceptions\CharacterizationCapacityException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    config([
        'services.learning_p6_prepared_request.enabled' => true,
        'services.characterization.api.base_url' => 'https://synthetic.invalid',
        'services.characterization.api.token' => null,
        'services.characterization.api.model_profile' => 'synthetic_profile',
        'services.characterization.defaults.headquarters_country' => 'SYNTHETIC_COUNTRY',
        'services.characterization.defaults.reporting_currency' => 'SYNTHETIC_CURRENCY',
    ]);
    $this->gateway = new ApiCharacterizationGateway(new class extends CharacterizationPredictionMapper
    {
        public function candidateTopics(array $rawPrediction): array
        {
            return $rawPrediction['synthetic_key'] === 1
                ? [['python_esrs_keys' => ['synthetic_key'], 'suggested' => true]] : [];
        }

        public function reviewRequiredKeys(array $rawPrediction): array
        {
            return ['synthetic_review'];
        }

        public function mappingMetadata(): array
        {
            return ['mapping_key_count' => 1, 'synthetic' => true];
        }
    });
    $this->envelope = [
        'esrs' => ['synthetic_key' => 1, 'synthetic_zero' => 0],
        'model_profile' => 'synthetic_serving', 'model_key_count' => 2,
        'mapped_key_count' => 99,
        'feature_metadata' => ['derived_fields' => ['synthetic'], 'defaulted_fields' => [], 'missing_required_fields' => []],
        'mapping_metadata' => ['industry_basis_by_key' => ['synthetic_key' => ['basis' => 'synthetic']]],
        'evidence_refs' => ['synthetic_evidence'],
    ];
    $this->fakeBody = $this->envelope;
    $this->fakeStatus = 200;
    $this->fakeHeaders = [];
    Http::fake(['https://synthetic.invalid/*' => fn () => Http::response($this->fakeBody, $this->fakeStatus, $this->fakeHeaders)]);
});

it('keeps captured account profile operations defaults model and NACE values after source mutation', function () {
    $source = preparedSyntheticCharacterization();
    $prepared = $this->gateway->prepare($source);
    $original = $prepared->payload();
    $digest = $prepared->digest();
    $source->user->update(['name' => 'SYNTHETIC_CHANGED_ACCOUNT']);
    $source->update(['nace_code' => 'C', 'form_data' => [
        'company_profile' => ['company_name' => 'SYNTHETIC_CHANGED_COMPANY', 'headquarters_country' => 'CHANGED', 'reporting_currency' => 'CHANGED', 'stock_listed' => true],
        'operations' => ['employee_count' => 999, 'revenue' => 9000000, 'regions' => ['asia']],
    ]]);
    NaceCode::where('code', 'Z')->update(['title_en' => 'SYNTHETIC_CHANGED_SECTOR']);
    config([
        'services.characterization.api.model_profile' => 'synthetic_changed_nace2',
        'services.characterization.defaults.company_name' => 'SYNTHETIC_CHANGED_DEFAULT',
        'services.characterization.defaults.headquarters_country' => 'CHANGED',
        'services.characterization.defaults.reporting_currency' => 'CHANGED',
    ]);
    $copy = $prepared->payload();
    $copy['sector_list'][0] = 'ACCESSOR_CHANGED';
    DB::enableQueryLog();
    DB::flushQueryLog();
    $result = $this->gateway->submitPrepared($prepared);
    expect(DB::getQueryLog())->toBe([]);
    Http::assertSent(fn ($request) => $request->data() === $original);
    expect($prepared->digest())->toBe($digest);
    expect($result['request_payload'])->toBe($original);
});

it('detaches nested producer references and accessor references while preserving exact types and order', function () {
    $leaf = ['float' => 1.0, 'fraction' => 0.25, 'zero' => 0, 'false' => false, 'null' => null, 'token' => '007', 'utf8' => 'á'];
    $input = ['map' => &$leaf, 'list' => [3, 2, 1], 'empty' => [], 'sparse' => [2 => 'two', 0 => 'zero']];
    $expected = ['map' => $leaf, 'list' => [3, 2, 1], 'empty' => [], 'sparse' => [2 => 'two', 0 => 'zero']];
    $prepared = new LearningP6PreparedRequest($input);
    $digest = $prepared->digest();
    $leaf['float'] = 9;
    $input['list'][0] = 8;
    $copy = $prepared->payload();
    $alias = &$copy['map']['token'];
    $alias = 'changed';
    expect($prepared->payload())->toBe($expected);
    expect($digest)->toBe(hash('sha256', json_encode($expected, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION, 512)));
    expect($prepared->digest())->toBe($digest);
    expect((new LearningP6PreparedRequest(['n' => 1]))->digest())->not->toBe((new LearningP6PreparedRequest(['n' => 1.0]))->digest());
    Http::assertNothingSent();
});

it('rejects unsupported or malformed values before any serialization blessing', function ($invalid) {
    expect(fn () => new LearningP6PreparedRequest(['value' => $invalid]))->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
})->with([
    'object' => [fn () => new stdClass],
    'closure' => [fn () => fn () => null],
    'nan' => [fn () => NAN],
    'infinity' => [fn () => INF],
    'utf8' => [fn () => "\xFF"],
    'serializer' => [fn () => new class implements JsonSerializable {
        public function jsonSerialize(): mixed { throw new LogicException('Must never serialize objects.'); }
    }],
]);

it('rejects resources cycles invalid keys and depth overflow with a depth 512 positive control', function () {
    $resource = fopen('php://memory', 'r+');
    try {
        expect(fn () => new LearningP6PreparedRequest(['resource' => $resource]))->toThrow(InvalidArgumentException::class);
    } finally {
        fclose($resource);
    }
    $cycle = [];
    $cycle['self'] = &$cycle;
    expect(fn () => new LearningP6PreparedRequest($cycle))->toThrow(InvalidArgumentException::class);
    expect(fn () => new LearningP6PreparedRequest(["\xFF" => 1]))->toThrow(InvalidArgumentException::class);
    $deep = ['leaf' => true];
    for ($i = 1; $i < 512; $i++) { $deep = ['nested' => $deep]; }
    expect((new LearningP6PreparedRequest($deep))->payload())->toBe($deep);
    expect(fn () => new LearningP6PreparedRequest(['overflow' => $deep]))->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
});

it('refuses both new methods before queries and sends unless strictly enabled in isolated testing', function ($mode) {
    $source = new Characterization(['user_id' => 999, 'nace_code' => 'Z']);
    $prepared = new LearningP6PreparedRequest(['company_name' => 'SYNTHETIC']);
    if ($mode === 'production') {
        app()->instance('env', 'production');
    } elseif ($mode === 'disk') {
        DB::connection()->setDatabaseName('synthetic-forbidden.sqlite');
    } else {
        config(['services.learning_p6_prepared_request.enabled' => $mode]);
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        expect(fn () => $this->gateway->prepare($source))->toThrow(RuntimeException::class);
        expect(fn () => $this->gateway->submitPrepared($prepared))->toThrow(RuntimeException::class);
        expect(DB::getQueryLog())->toBe([]);
        Http::assertNothingSent();
    } finally {
        app()->instance('env', 'testing');
        DB::connection()->setDatabaseName(':memory:');
    }
})->with([null, false, 'true', 1, 'production', 'disk']);

it('validates ordinary submit URL before lazy account and NACE reads even with prepared mode disabled', function () {
    config(['services.learning_p6_prepared_request.enabled' => false, 'services.characterization.api.base_url' => '']);
    DB::enableQueryLog();
    DB::flushQueryLog();
    expect(fn () => $this->gateway->submit(new Characterization(['user_id' => 999, 'nace_code' => 'Z'])))
        ->toThrow(RuntimeException::class, 'Characterization API base URL is not configured.');
    expect(DB::getQueryLog())->toBe([]);
    Http::assertNothingSent();
});

it('preserves typed capacity and ordinary nonsuccess errors for both send paths', function ($preparedMode, $capacity) {
    $source = preparedSyntheticCharacterization();
    $prepared = $this->gateway->prepare($source);
    $this->fakeBody = $capacity ? ['detail' => ['code' => 'prediction_capacity_busy']] : ['detail' => 'synthetic failure'];
    $this->fakeStatus = $capacity ? 503 : 422;
    $this->fakeHeaders = $capacity ? ['Retry-After' => '600'] : [];
    try {
        $preparedMode ? $this->gateway->submitPrepared($prepared) : $this->gateway->submit($source);
        $this->fail('Expected a transport failure.');
    } catch (RuntimeException $exception) {
        if ($capacity) {
            expect($exception)->toBeInstanceOf(CharacterizationCapacityException::class);
            expect($exception->retryAfterSeconds)->toBe(300);
        } else {
            expect($exception)->not->toBeInstanceOf(CharacterizationCapacityException::class);
            expect($exception->getMessage())->toBe('External characterization API responded with an error (HTTP 422): synthetic failure');
        }
    }
    Http::assertSentCount(1);
})->with([false, true])->with([false, true]);

function preparedSyntheticCharacterization(): Characterization
{
    $user = User::factory()->create(['name' => 'SYNTHETIC_ACCOUNT', 'email' => 'prepared@example.invalid']);
    NaceCode::create(['code' => 'Z', 'level' => 1, 'title_en' => 'SYNTHETIC_SECTOR', 'title_es' => 'SYNTHETIC_SECTOR']);

    return Characterization::factory()->create([
        'user_id' => $user->id, 'nace_code' => 'Z',
        'form_data' => ['company_profile' => ['stock_listed' => false], 'operations' => ['employee_count' => 7, 'revenue' => 1250000, 'regions' => ['eu']]],
    ]);
}

it('sends the exact prepared payload through the real gateway and shared envelope parser', function () {
    $source = preparedSyntheticCharacterization();
    $prepared = $this->gateway->prepare($source);
    $payload = $prepared->payload();
    $result = $this->gateway->submitPrepared($prepared);
    Http::assertSent(fn ($request) => $request->data() === $payload);
    Http::assertSentCount(1);
    expect($payload)->toMatchArray(['company_name' => 'SYNTHETIC_ACCOUNT', 'sector_list' => ['SYNTHETIC_SECTOR'], 'employees_total' => 7]);
    expect($result)->toBe([
        'status' => 'completed', 'score' => null,
        'summary' => 'AI proposed 1 candidate ESRS topic. 1 predicted ESRS key needs manual review.',
        'candidate_topics' => [['python_esrs_keys' => ['synthetic_key'], 'suggested' => true, 'industry_basis' => ['basis' => 'synthetic']]],
        'review_required_prediction_keys' => ['synthetic_review'],
        'raw_prediction' => $this->envelope['esrs'], 'request_payload' => $payload,
        'model_profile' => 'synthetic_serving', 'model_key_count' => 2, 'mapped_key_count' => 1,
        'feature_metadata' => $this->envelope['feature_metadata'],
        'mapping_metadata' => ['python' => $this->envelope['mapping_metadata'], 'laravel' => ['mapping_key_count' => 1, 'synthetic' => true]],
        'evidence_refs' => ['synthetic_evidence'],
    ]);
    expect($this->gateway->submit($source))->toBe($result);
    Http::assertSentCount(2);
});

it('denies unsafe effective named connections before lazy account PDO or explicit name bypass', function ($driver, $explicitName) {
    DB::connection();
    $name = 'prepared_r1_forbidden';
    $attempts = 0;
    config(["database.connections.$name" => [
        'driver' => $driver, 'database' => 'synthetic-forbidden.sqlite', 'prefix' => '',
    ]]);
    DB::extend($driver, function (array $config, string $connectionName) use (&$attempts, $driver) {
        $pdo = function () use (&$attempts) {
            $attempts++;
            throw new LogicException('SYNTHETIC_FORBIDDEN_PDO_GETTER');
        };
        $class = $driver === 'sqlite' ? \Illuminate\Database\SQLiteConnection::class : \Illuminate\Database\PostgresConnection::class;

        return new $class($pdo, $config['database'], '', $config);
    });
    try {
        $connection = DB::connection($name);
        $connection->enableQueryLog();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $source = (new Characterization([
            'user_id' => 1, 'nace_code' => 'C',
            'form_data' => $explicitName ? ['company_profile' => ['company_name' => 'SYNTHETIC_EXPLICIT']] : [],
        ]))->setConnection($name);
        expect(fn () => $this->gateway->prepare($source))
            ->toThrow(RuntimeException::class, 'Prepared characterization requests require SQLite :memory:.');
        expect($attempts)->toBe(0);
        expect($connection->getQueryLog())->toBe([]);
        expect(DB::getQueryLog())->toBe([]);
        Http::assertNothingSent();
    } finally {
        DB::purge($name);
        DB::forgetExtension($driver);
    }
})->with(['sqlite', 'pgsql'])->with([false, true]);

it('accepts a named SQLite memory source and reads its inherited lazy account connection', function () {
    DB::connection();
    $name = 'prepared_r1_memory';
    config(["database.connections.$name" => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    try {
        $connection = DB::connection($name);
        $connection->getSchemaBuilder()->create('users', function ($table) {
            $table->integer('id')->primary();
            $table->string('name');
        });
        $connection->table('users')->insert(['id' => 1, 'name' => 'SYNTHETIC_NAMED_ACCOUNT']);
        $source = (new Characterization(['user_id' => 1, 'nace_code' => 'C', 'form_data' => []]))->setConnection($name);
        expect($this->gateway->prepare($source)->payload()['company_name'])->toBe('SYNTHETIC_NAMED_ACCOUNT');
        Http::assertNothingSent();
    } finally {
        DB::purge($name);
    }
});