<?php

use App\Jobs\TrainLearningFeedback;
use App\Models\Characterization;
use App\Models\EsrsTopic;
use App\Models\LearningCompanyGroup;
use App\Models\LearningCompanyMembership;
use App\Models\User;
use App\Services\ConsentLearningAuthorizationAuthority;
use App\Services\LearningAuthorizationAuthority;
use App\Services\LearningDataLifecycle;
use App\Services\LearningFeedbackDataset;
use App\Services\LearningPythonBridge;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['services.private_dev.auto_login' => false, 'learning_engine.enabled' => true,
        'learning_engine.authority' => 'consent', 'learning_engine.synthetic_only' => false,
        'services.characterization.api.model_profile' => 'new_format_732_v1_gpt41',
        'learning_engine.root' => storage_path('framework/testing/learning-production-'.Str::uuid())]);
    $this->seed(\Database\Seeders\EsrsTopicSeeder::class);
    $this->seed(\Database\Seeders\NaceCodeSeeder::class);
});

afterEach(function () {
    \Illuminate\Support\Facades\File::deleteDirectory(config('learning_engine.root'));
});

function productionLearningSource(): array
{
    $user = User::factory()->create();
    $user->forceFill(['learning_consent_at' => now(), 'learning_consent_version' => config('learning_engine.consent_version'),
        'learning_subject' => (string) Str::uuid()])->save();
    $group = LearningCompanyGroup::forceCreate(['company_group_key' => hash('sha256', (string) Str::uuid())]);
    LearningCompanyMembership::forceCreate(['learning_company_group_id' => $group->id, 'user_id' => $user->id,
        'subject_type' => 'account', 'subject_identifier' => (string) $user->id, 'evidence_digest' => hash('sha256', 'synthetic-test'),
        'evidence_type' => 'synthetic-test', 'verification_status' => 'verified', 'verified_at' => now()]);
    $topics = EsrsTopic::where('esrs_code', 'E2')->limit(2)->pluck('id')->map(fn ($id) => (int) $id)->all();
    $row = Characterization::factory()->create(['user_id' => $user->id, 'status' => Characterization::STATUS_COMPLETED,
        'submission_generation' => 1, 'nace_code' => 'A1.1', 'esrs_topic_ids' => $topics,
        'form_data' => [
            'company_profile' => ['company_name' => 'SYNTHETIC_PRIVATE_NAME', 'headquarters_country' => 'Spain',
                'reporting_year' => 2026, 'reporting_scope' => 'individual', 'num_subsidiaries_countries' => 0,
                'stock_listed' => false, 'reporting_currency' => 'EUR', 'product_service_type' => 'physical_product_manufacturing'],
            'operations' => ['employee_count' => 150, 'regions' => ['eu'], 'value_chain' => ['direct_operations'],
                'employee_count_range' => '50_249', 'revenue_range' => '2m_to_10m'],
            'activity_questions' => ['water_use' => 'yes'],
            'materiality_confirmation' => ['revision' => 1, 'confirmed_at' => now()->toJSON(),
                'p6_snapshot' => ['topic_ids' => $topics, 'captured_at' => now()->toJSON()],
                'confirmed_topic_ids' => [$topics[0]], 'reviewed_topic_ids' => $topics,
                'universe_attestation' => ['version' => 1, 'mode' => 'direct', 'reviewed_universe' => true],
                'guided_answers' => []],
        ]]);
    $form = app(LearningFeedbackDataset::class)->seal($row, $row->form_data, 'materiality_confirmation');
    $row->forceFill(['form_data' => $form])->save();
    return [$user, $row, $topics];
}

it('admits consented production accounts without changing APP_ENV and retains synthetic-only mode', function () {
    [$user] = productionLearningSource();
    $this->app['env'] = 'production';
    expect(app(LearningAuthorizationAuthority::class))->toBeInstanceOf(ConsentLearningAuthorizationAuthority::class);
    expect(app(ConsentLearningAuthorizationAuthority::class)->permits($user))->toBeTrue();
    config(['learning_engine.synthetic_only' => true]);
    expect(app(ConsentLearningAuthorizationAuthority::class)->permits($user))->toBeFalse();
    $user->forceFill(['learning_synthetic' => true])->save();
    expect(app(ConsentLearningAuthorizationAuthority::class)->permits($user))->toBeTrue();
    config(['learning_engine.enabled' => false]);
    expect(app(ConsentLearningAuthorizationAuthority::class)->permits($user))->toBeFalse();
});

it('does not accept client ownership fields or collect consent for another account', function () {
    $user = User::factory()->create(); $other = User::factory()->create();
    $this->put('/profile/learning-consent', ['consent' => 1, 'version' => config('learning_engine.consent_version')])->assertRedirect('/login');
    $this->actingAs($user)->put('/profile/learning-consent', ['consent' => 1,
        'version' => config('learning_engine.consent_version'), 'user_id' => $other->id])->assertRedirect('/profile');
    expect($user->fresh()->learning_subject)->not->toBeNull()->and($other->fresh()->learning_subject)->toBeNull();
    expect($user->fresh()->toArray())->not->toHaveKey('learning_subject');
});

it('requires current consent text and verified account and keeps withdrawal available when disabled', function () {
    [$user] = productionLearningSource(); $subject = $user->learning_subject;
    $this->actingAs($user)->put('/profile/learning-consent', ['consent' => 1, 'version' => 'old'])->assertSessionHasErrors('version');
    config(['learning_engine.enabled' => false]);
    $this->put('/profile/learning-consent', ['consent' => 1, 'version' => config('learning_engine.consent_version')])->assertForbidden();
    $this->put('/profile/learning-consent', ['consent' => 0, 'version' => config('learning_engine.consent_version')])->assertRedirect('/profile');
    expect($user->fresh()->learning_subject)->toBeNull();
    $this->assertDatabaseHas('learning_erasure_requests', ['subject' => $subject]);
    config(['learning_engine.enabled' => true]);
    $user->forceFill(['email_verified_at' => null])->save();
    $this->actingAs($user->fresh())->put('/profile/learning-consent', ['consent' => 1,
        'version' => config('learning_engine.consent_version')])->assertForbidden();
});

it('exports P8 decisions with unknown masks and excludes identity and stale or unconsented sources', function () {
    [$user, $row, $topics] = productionLearningSource();
    $data = app(LearningFeedbackDataset::class)->export();
    expect($data['rows'])->toHaveCount(1);
    $record = $data['rows'][0];
    expect($record['input']['company_name'])->toBe('pseudonymous')
        ->and($record['subject'])->toBe($user->learning_subject)
        ->and(json_encode($data))->not->toContain('SYNTHETIC_PRIVATE_NAME');
    $cells = (array) $record['topics'];
    expect($cells[$topics[0]])->toBe(['value' => 1, 'mask' => 1])
        ->and($cells[$topics[1]])->toBe(['value' => 0, 'mask' => 1]);
    $unknown = EsrsTopic::whereNotIn('id', $topics)->first()->id;
    expect($cells[$unknown])->toBe(['value' => null, 'mask' => 0]);
    $row->forceFill(['submission_generation' => 2])->save();
    expect(app(LearningFeedbackDataset::class)->export()['rows'])->toBe([]);
    $row->forceFill(['submission_generation' => 1])->save();
    $user->forceFill(['learning_consent_at' => null])->save();
    expect(app(LearningFeedbackDataset::class)->export()['rows'])->toBe([]);
});

it('captures the P8 seal through the real authenticated owner route only when enabled', function () {
    [$user, $row, $topics] = productionLearningSource();
    $payload = ['expected_revision' => 1, 'confirmed_topic_ids' => [$topics[0]],
        'reviewed_topic_ids' => $topics, 'universe_attestation' => ['version' => 1, 'mode' => 'direct', 'reviewed_universe' => true]];
    $this->actingAs($user)->putJson('/api/materiality-confirmation', $payload)->assertOk();
    expect($row->fresh()->form_data['materiality_confirmation']['training_source']['p8_revision'])->toBe(2);
    config(['learning_engine.enabled' => false]);
    $payload['expected_revision'] = 2;
    $this->putJson('/api/materiality-confirmation', $payload)->assertOk();
    expect($row->fresh()->form_data['materiality_confirmation'])->not->toHaveKey('training_source');
});

it('exports explicit P9 review independently of report values and rejects stale P9 provenance', function () {
    [$user, $row] = productionLearningSource();
    $state = $this->actingAs($user)->getJson('/api/esrs-datapoints/responses')->assertOk()->json('data');
    $feedback = ['schema_version' => 'datapoint-feedback-v1', 'authority_digest' => $state['learning_authority_digest'],
        'reviewed_datapoint_ids' => ['BP-1_01'], 'decisions' => [['datapoint_id' => 'BP-1_01', 'relevant' => false,
            'selected_to_answer' => false, 'reason_codes' => ['other'], 'note' => 'SYNTHETIC_PRIVATE_NOTE']]];
    $this->putJson('/api/esrs-datapoints/responses', ['expected_revision' => 0, 'responses' => [], 'learning_feedback' => $feedback])->assertOk();
    $export = app(LearningFeedbackDataset::class)->export()['rows'][0];
    expect($export['p9'])->toBe([['datapoint_id' => 'BP-1_01', 'relevant' => false, 'selected_to_answer' => false]])
        ->and(json_encode($export))->not->toContain('SYNTHETIC_PRIVATE_NOTE');
    $form = $row->fresh()->form_data;
    $form['esrs_datapoint_responses']['training_source']['p8_revision'] = 0;
    $row->forceFill(['form_data' => $form])->save();
    expect(app(LearningFeedbackDataset::class)->export()['rows'][0]['p9'])->toBe([]);
});

it('queues training only when explicitly enabled and rechecks on job execution', function () {
    Queue::fake(); config(['learning_engine.enabled' => false]);
    $this->artisan('learning:train')->assertFailed(); Queue::assertNothingPushed();
    $bridge = Mockery::mock(LearningPythonBridge::class); $bridge->shouldNotReceive('run');
    (new TrainLearningFeedback)->handle(app(LearningFeedbackDataset::class), $bridge, app(LearningDataLifecycle::class));
    config(['learning_engine.enabled' => true]);
    $this->artisan('learning:train')->assertSuccessful(); Queue::assertPushed(TrainLearningFeedback::class);
});

it('exports trains and records provenance while removing temporary exports', function () {
    productionLearningSource();
    $bridge = Mockery::mock(LearningPythonBridge::class)->makePartial();
    $bridge->shouldReceive('run')->once()->withArgs(function ($operation, $args) {
        expect($operation)->toBe('train')->and(hash_file('sha256', $args['dataset']))->toBe($args['dataset-sha256']);
        return true;
    })->andReturn(['candidate_id' => str_repeat('a', 32), 'status' => 'registered', 'gate' => ['passed' => false]]);
    (new TrainLearningFeedback)->handle(app(LearningFeedbackDataset::class), $bridge, app(LearningDataLifecycle::class));
    $this->assertDatabaseHas('learning_engine_runs', ['status' => 'registered', 'candidate_id' => str_repeat('a', 32)]);
    expect(glob(config('learning_engine.root').'/dataset-*.json'))->toBe([]);
});

it('failed training records failure and removes sensitive temporary files', function () {
    productionLearningSource();
    $bridge = Mockery::mock(LearningPythonBridge::class)->makePartial();
    $bridge->shouldReceive('run')->once()->andThrow(new RuntimeException('private details'));
    expect(fn () => (new TrainLearningFeedback)->handle(app(LearningFeedbackDataset::class), $bridge,
        app(LearningDataLifecycle::class)))->toThrow(RuntimeException::class, 'learning.training_failed_closed');
    $this->assertDatabaseHas('learning_engine_runs', ['status' => 'failed_closed']);
    expect(glob(config('learning_engine.root').'/dataset-*.json'))->toBe([]);
});

it('requires allowlisted verified admin for promotion and permits base rollback while disabled', function () {
    $user = User::factory()->create();
    $bridge = Mockery::mock(LearningPythonBridge::class)->makePartial();
    $this->app->instance(LearningPythonBridge::class, $bridge);
    $this->artisan('learning:promote', ['candidate' => str_repeat('a', 32), '--admin' => $user->id])->assertFailed();
    config(['learning_engine.admin_ids' => [(string) $user->id], 'learning_engine.enabled' => false]);
    $bridge->shouldReceive('run')->once()->with('rollback', ['candidate' => 'base', 'actor' => 'admin:'.$user->id])->andReturn(['status' => 'rolled_back']);
    $this->artisan('learning:rollback', ['--admin' => $user->id])->assertSuccessful();
});

it('erases account with the engine disabled and keeps a durable erasure request', function () {
    [$user] = productionLearningSource(); $subject = $user->learning_subject;
    config(['learning_engine.enabled' => false]);
    $this->actingAs($user)->delete('/profile', ['password' => 'password'])->assertRedirect('/');
    expect($user->fresh())->toBeNull();
    $this->assertDatabaseHas('learning_erasure_requests', ['subject' => $subject]);
});

it('C04 erases an account against a schema with no learning ledger tables', function () {
    Schema::drop('learning_authorization_records'); Schema::drop('learning_authorization_states');
    $user = User::factory()->create();
    $this->actingAs($user)->delete('/profile', ['password' => 'password'])->assertRedirect('/');
    expect($user->fresh())->toBeNull();
});

it('privacy maintenance consumes erasure requests even when the engine is disabled', function () {
    [$user] = productionLearningSource(); $subject = $user->learning_subject;
    app(LearningDataLifecycle::class)->withdraw($user);
    config(['learning_engine.enabled' => false]);
    $bridge = Mockery::mock(LearningPythonBridge::class);
    $bridge->shouldReceive('run')->once()->with('erase', ['subject' => $subject])->andReturn(['status' => 'erased']);
    $this->app->instance(LearningPythonBridge::class, $bridge);
    $this->artisan('learning:maintain-privacy')->assertSuccessful();
    $this->assertDatabaseMissing('learning_erasure_requests', ['subject' => $subject]);
});

it('normal migrations include the P9 clock and production fields with reversible additions', function () {
    expect(Schema::hasTable('learning_p9_source_revisions'))->toBeTrue()
        ->and(Schema::hasColumn('users', 'learning_consent_at'))->toBeTrue();
    $migration = require database_path('migrations/2026_10_08_000020_create_learning_engine_production.php');
    $migration->down();
    expect(Schema::hasTable('learning_engine_runs'))->toBeFalse()->and(Schema::hasColumn('users', 'learning_subject'))->toBeFalse();
    $migration->up();
    expect(Schema::hasTable('learning_engine_runs'))->toBeTrue()->and(Schema::hasColumn('users', 'learning_subject'))->toBeTrue();
});

it('schedules tick and privacy maintenance independently of training flags', function () {
    foreach ([[false, true], [true, false], [true, true], [false, false]] as [$enabled, $scheduled]) {
        config(['learning_engine.enabled' => $enabled, 'learning_engine.scheduled' => $scheduled]);
        \Illuminate\Support\Facades\Schedule::swap(new \Illuminate\Console\Scheduling\Schedule);
        require base_path('routes/console.php');
        $events = collect(\Illuminate\Support\Facades\Schedule::events());
        foreach (['learning:tick', 'learning:maintain-privacy'] as $command) {
            $event = $events->first(fn ($event) => str_contains($event->command, $command));
            expect($event)->not->toBeNull()->and($event->expression)->toBe('* * * * *')
                ->and($event->withoutOverlapping)->toBeTrue();
        }
        expect($events->pluck('command')->implode('\n'))->not->toContain('learning:train');
    }
});

it('binds exported authority to verified membership and invalidates it after company revocation', function () {
    [$user] = productionLearningSource();
    $record = app(LearningFeedbackDataset::class)->export()['rows'][0];
    mkdir(config('learning_engine.root'), 0700, true);
    app(LearningDataLifecycle::class)->refreshAuthority();
    $path = config('learning_engine.root').'/authority.json';
    $lease = json_decode(file_get_contents($path), true);
    expect($lease['bindings'][$user->learning_subject])->toBe($record['authority_binding']);
    $this->artisan('learning:assign-company', ['action' => 'revoke', 'subject-type' => 'account',
        'subject-id' => (string) $user->id, '--evidence-digest' => hash('sha256', 'synthetic-revoke'),
        '--evidence-type' => 'synthetic-test'])->assertSuccessful();
    expect(is_file($path))->toBeFalse();
    app(LearningDataLifecycle::class)->refreshAuthority();
    $lease = json_decode(file_get_contents($path), true);
    expect($lease['bindings'])->not->toHaveKey($user->learning_subject);
    expect(app(LearningFeedbackDataset::class)->export()['rows'])->toBe([]);
});

it('changes the authorization binding when company group or verification evidence changes', function () {
    [$user] = productionLearningSource();
    $old = app(LearningFeedbackDataset::class)->export()['rows'][0]['authority_binding'];
    $membership = LearningCompanyMembership::where('user_id', $user->id)->sole();
    $other = LearningCompanyGroup::forceCreate(['company_group_key' => hash('sha256', 'synthetic-other-group')]);
    $membership->forceFill(['learning_company_group_id' => $other->id])->save();
    mkdir(config('learning_engine.root'), 0700, true);
    app(LearningDataLifecycle::class)->refreshAuthority();
    $lease = json_decode(file_get_contents(config('learning_engine.root').'/authority.json'), true);
    expect($lease['bindings'][$user->learning_subject])->not->toBe($old);
});

it('withdraws or deletes a shadow requester without training membership and revokes its lease', function ($action) {
    // No characterization or membership: this account could only receive shadow predictions.
    $user = User::factory()->create();
    $subject = (string) Str::uuid();
    $user->forceFill(['learning_subject' => $subject, 'learning_consent_at' => now(),
        'learning_consent_version' => config('learning_engine.consent_version')])->save();
    mkdir(config('learning_engine.root'), 0700, true);
    app(LearningDataLifecycle::class)->refreshAuthority();
    $path = config('learning_engine.root').'/authority.json';
    expect(json_decode(file_get_contents($path), true)['subjects'])->toContain($subject);
    config(['learning_engine.enabled' => false]);
    $this->actingAs($user);
    if ($action === 'withdraw') {
        $this->put('/profile/learning-consent', ['consent' => 0,
            'version' => config('learning_engine.consent_version')])->assertRedirect('/profile');
    } else {
        $this->delete('/profile', ['password' => 'password'])->assertRedirect('/');
    }
    expect(is_file($path))->toBeFalse();
    $this->assertDatabaseHas('learning_erasure_requests', ['subject' => $subject]);
    $bridge = Mockery::mock(LearningPythonBridge::class);
    $bridge->shouldReceive('run')->once()->with('erase', ['subject' => $subject])->andReturn(['status' => 'erased']);
    $this->app->instance(LearningPythonBridge::class, $bridge);
    $this->artisan('learning:maintain-privacy')->assertSuccessful();
})->with(['withdraw', 'delete']);

it('includes a consent pseudonym only in the private shadow request and excludes it from training inputs', function () {
    [$user, $row] = productionLearningSource();
    $gateway = app(\App\Services\ApiCharacterizationGateway::class);
    $method = new ReflectionMethod($gateway, 'predictionPayload');
    $payload = $method->invoke($gateway, $row);
    expect($payload['learning_shadow_authorized'])->toBeTrue()
        ->and($payload['learning_shadow_subject'])->toBe($user->learning_subject);
    expect($gateway->learningPayload($row))->not->toHaveKey('learning_shadow_subject');
    app(LearningDataLifecycle::class)->withdraw($user);
    $payload = $method->invoke($gateway, $row->fresh());
    expect($payload['learning_shadow_authorized'])->toBeFalse()
        ->and($payload)->not->toHaveKey('learning_shadow_subject');
});

it('requires a matching session CSRF token for consent and withdrawal', function () {
    $user = User::factory()->create();
    $this->app['env'] = 'production'; // Exercise CSRF rather than its test bypass.
    $this->actingAs($user)->withSession(['_token' => 'synthetic-csrf']);
    $payload = ['consent' => 1, 'version' => config('learning_engine.consent_version')];
    $this->put('/profile/learning-consent', $payload)->assertStatus(419);
    expect($user->fresh()->learning_subject)->toBeNull();
    $this->put('/profile/learning-consent', $payload + ['_token' => 'synthetic-csrf'])->assertRedirect('/profile');
    $subject = $user->fresh()->learning_subject;
    expect($subject)->not->toBeNull();
    $payload['consent'] = 0;
    $this->put('/profile/learning-consent', $payload + ['_token' => 'wrong'])->assertStatus(419);
    expect($user->fresh()->learning_subject)->toBe($subject);
    $this->put('/profile/learning-consent', $payload + ['_token' => 'synthetic-csrf'])->assertRedirect('/profile');
    expect($user->fresh()->learning_subject)->toBeNull();
});

/** Synthetic fixed-set bytes; never a real-label quality receipt. */
function metricsReservedSet(array $groups): string
{
    $root = config('learning_engine.root');
    if (! is_dir($root)) { mkdir($root, 0700, true); }
    $path = $root.'/synthetic-heldout.json';
    $bytes = json_encode(['schema' => 'learning-heldout-v1', 'unit' => 'keys',
        'cases' => array_map(fn ($group) => ['group' => $group, 'p5_sha256' => hash('sha256', $group),
            'input' => ['company_name' => 'pseudonymous'], 'truth' => ['esrs_e1_energy_use' => 1, 'esrs_e2_pollution_of_air' => 0]], $groups)], JSON_THROW_ON_ERROR);
    file_put_contents($path, $bytes);
    config(['learning_engine.gate_metrics_enabled' => true, 'learning_engine.heldout_path' => $path,
        'learning_engine.heldout_sha256' => hash('sha256', $bytes), 'learning_engine.gold_path' => '', 'learning_engine.gold_sha256' => '']);
    return $path;
}

it('gate metrics excludes both verified accounts of a reserved company and keeps unreserved feedback', function () {
    [$one] = productionLearningSource(); [$two] = productionLearningSource(); [$three] = productionLearningSource();
    $first = LearningCompanyMembership::where('user_id', $one->id)->sole();
    LearningCompanyMembership::where('user_id', $two->id)->update(['learning_company_group_id' => $first->learning_company_group_id]);
    metricsReservedSet([$first->group->company_group_key]);
    $snapshot = app(\App\Services\LearningReservedGroups::class)->snapshot();
    $dataset = app(LearningFeedbackDataset::class);
    $document = $dataset->export($snapshot['groups']);
    expect($dataset->reservedExclusions)->toBe(2)->and($document['rows'])->toHaveCount(1)
        ->and($document['rows'][0]['subject'])->toBe($three->learning_subject)
        ->and(array_keys($document))->toBe(['schema', 'rows', 'rejected_rows']);
    config(['learning_engine.gate_metrics_enabled' => false]);
    expect($dataset->export($snapshot['groups'])['rows'])->toHaveCount(3)->and($dataset->reservedExclusions)->toBe(0);
});

it('gate metrics stops before fitting for absent changed or malformed reserved sets', function (string $kind) {
    productionLearningSource();
    $path = metricsReservedSet([hash('sha256', 'synthetic-reserved')]);
    if ($kind === 'missing') { config(['learning_engine.heldout_path' => $path.'.absent']); }
    if ($kind === 'changed') { file_put_contents($path, '{}'); }
    if ($kind === 'malformed') {
        file_put_contents($path, '{"schema":"learning-heldout-v1","cases":[]}');
        config(['learning_engine.heldout_sha256' => hash_file('sha256', $path)]);
    }
    $bridge = Mockery::mock(LearningPythonBridge::class); $bridge->shouldNotReceive('run');
    expect(fn () => (new TrainLearningFeedback)->handle(app(LearningFeedbackDataset::class), $bridge,
        app(LearningDataLifecycle::class)))->toThrow(RuntimeException::class, 'learning.training_failed_closed');
    $this->assertDatabaseHas('learning_engine_runs', ['status' => 'failed_closed']);
    expect(glob(config('learning_engine.root').'/dataset-*.json'))->toBe([]);
})->with(['missing', 'changed', 'malformed']);

it('gate metrics empty reserved export never fits and records separate exclusions', function () {
    [$user] = productionLearningSource();
    $membership = LearningCompanyMembership::where('user_id', $user->id)->sole();
    metricsReservedSet([$membership->group->company_group_key]);
    $bridge = Mockery::mock(LearningPythonBridge::class); $bridge->shouldNotReceive('run');
    (new TrainLearningFeedback)->handle(app(LearningFeedbackDataset::class), $bridge, app(LearningDataLifecycle::class));
    $run = DB::table('learning_engine_runs')->sole();
    expect($run->status)->toBe('no_eligible_feedback')
        ->and(json_decode($run->receipt, true)['reserved_exclusions'])->toBe(1);
});

it('gate metrics rechecks fixed bytes after export before fitting', function () {
    productionLearningSource();
    $path = metricsReservedSet([hash('sha256', 'synthetic-reserved')]);
    DB::listen(function ($query) use ($path) {
        if (str_contains($query->sql, 'learning_engine_runs') && str_contains($query->sql, 'dataset_sha256')) {
            file_put_contents($path, '{}');
        }
    });
    $bridge = Mockery::mock(LearningPythonBridge::class); $bridge->shouldNotReceive('run');
    expect(fn () => (new TrainLearningFeedback)->handle(app(LearningFeedbackDataset::class), $bridge,
        app(LearningDataLifecycle::class)))->toThrow(RuntimeException::class, 'learning.training_failed_closed');
});

it('gate metrics bridge uses the public services alias and rejects other runtimes', function () {
    $root = config('learning_engine.root');
    $source = $root.'/src';
    mkdir($source, 0700, true);
    // Synthetic PHP child exercises the real Process environment without
    // depending on a native Python installation. Python covers actual imports.
    file_put_contents($source.'/learning_activation_cli.py', '<?php echo json_encode(["path" => getenv("PYTHONPATH"), "runtime" => getenv("LEARNING_SERVING_RUNTIME"), "gate" => getenv("LEARNING_GATE_METRICS_ENABLED")]);');
    config(['learning_engine.source' => $source, 'learning_engine.python' => PHP_BINARY,
        'learning_engine.gate_metrics_enabled' => false, 'learning_engine.serving_runtime' => 'public']);
    $bridge = app(LearningPythonBridge::class);
    $result = $bridge->run('status');
    expect($result)->toBe(['path' => realpath($source),
        'runtime' => 'public', 'gate' => 'false']);
    config(['learning_engine.serving_runtime' => 'private']);
    expect(fn () => $bridge->run('status'))->toThrow(RuntimeException::class, 'learning.serving_runtime');
    config(['learning_engine.serving_runtime' => 'unknown']);
    expect(fn () => $bridge->run('status'))->toThrow(RuntimeException::class, 'learning.serving_runtime');
});
