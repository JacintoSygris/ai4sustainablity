<?php

use App\Jobs\RunLearningAdminAction;
use App\Models\Characterization;
use App\Models\User;
use App\Services\LearningActivationStatus;
use App\Services\LearningBanditFeedback;
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
        'learning_activation.bandit_mode' => 'active', 'learning_activation.admin_ids' => [],
        'services.characterization.prediction_mapping_path' => base_path('data/ar16_to_python_esrs_mapping_new_format_732_v1.json'),
        'learning_engine.root' => sys_get_temp_dir().'/activation-test-'.Str::uuid()]);
});

afterEach(function () { \Illuminate\Support\Facades\File::deleteDirectory(config('learning_engine.root')); });

function activationProjection(): array
{
    $state = ['is_confirmed' => true, 'is_stale' => false, 'confirmation_status' => 'confirmed',
        'p6_snapshot' => ['topic_ids' => [1, 2]], 'confirmed_topic_ids' => [1, 3],
        'learning_topic_labels' => [1 => 1, 2 => 0, 3 => 1, 4 => 0], 'confirmation' => ['guided_answers' => []]];
    $basis = ['encoder' => 'p5-hash-tanh-v1', 'context' => array_fill(0, 16, 0.0),
        'model_sha256' => str_repeat('a', 64), 'feature_schema_sha256' => str_repeat('b', 64),
        'base_scores' => ['esrs_e1_a' => .8, 'esrs_e1_b' => .8, 'esrs_e1_c' => .4, 'esrs_e1_d' => .1]];
    $mapping = ['keys' => array_map(fn ($n, $k) => ['status' => 'approved', 'ar16_topic_ids' => [$n], 'python_esrs_key' => 'esrs_e1_'.$k], [1, 2, 3, 4], ['a', 'b', 'c', 'd'])];
    return [$state, $basis, $mapping];
}

function activationReady(): array
{
    return ['checks' => array_fill_keys(['migrations', 'state_directory', 'python', 'scheduler', 'queue', 'queue_configuration', 'authority_lease', 'heldout', 'code_commit'], true),
        'engine_enabled' => true, 'bandit_mode' => 'active', 'wp13' => ['active_sha256' => null, 'previous' => null, 'candidates' => []],
        'wp23' => ['candidate' => ['id' => str_repeat('c', 32), 'eligible' => true], 'active' => null]];
}

function activationSource(): array
{
    test()->seed(\Database\Seeders\EsrsTopicSeeder::class);
    test()->seed(\Database\Seeders\NaceCodeSeeder::class);
    $user = User::factory()->create(['learning_consent_at' => now(), 'learning_consent_version' => config('learning_engine.consent_version'), 'learning_subject' => (string) Str::uuid()]);
    $topic = \App\Models\EsrsTopic::where('esrs_code', 'E2')->orderBy('id')->firstOrFail()->id;
    $map = json_decode(file_get_contents(config('services.characterization.prediction_mapping_path')), true);
    $entry = collect($map['keys'])->first(fn ($m) => ($m['status'] ?? '') === 'approved' && ($m['ar16_topic_ids'] ?? []) === [$topic]);
    $key = $entry['python_esrs_key'];
    $basis = activationProjection()[1]; $basis['base_scores'] = [$key => .8];
    $row = Characterization::factory()->create(['user_id' => $user->id, 'status' => Characterization::STATUS_COMPLETED,
        'submission_generation' => 1, 'nace_code' => 'A1.1', 'esrs_topic_ids' => [$topic],
        'result_data' => ['raw_prediction' => [$key => 1], 'mapping_metadata' => ['python' => ['feedback_bandit' => ['basis' => $basis]]]],
        'form_data' => ['company_profile' => ['company_name' => 'SYNTHETIC TEST', 'headquarters_country' => 'Spain',
            'reporting_year' => 2026, 'reporting_scope' => 'individual', 'num_subsidiaries_countries' => 0,
            'stock_listed' => false, 'reporting_currency' => 'EUR', 'product_service_type' => 'physical_product_manufacturing'],
            'operations' => ['employee_count' => 150, 'regions' => ['eu'], 'value_chain' => ['direct_operations'],
                'employee_count_range' => '50_249', 'revenue_range' => '2m_to_10m'], 'activity_questions' => ['water_use' => 'yes'],
            'materiality_confirmation' => ['revision' => 1, 'confirmed_at' => now()->toJSON(),
                'p6_snapshot' => ['topic_ids' => [$topic], 'captured_at' => now()->toJSON()], 'confirmed_topic_ids' => [$topic],
                'reviewed_topic_ids' => [$topic], 'universe_attestation' => ['version' => 1, 'mode' => 'direct', 'reviewed_universe' => true], 'guided_answers' => []]]]);
    $row->forceFill(['form_data' => app(LearningFeedbackDataset::class)->seal($row, $row->form_data, 'materiality_confirmation')])->save();
    return [$user, $row, $topic];
}

it('projects acceptance rejection and addition but never unproposed negatives', function () {
    $events = app(LearningBanditFeedback::class)->project(...activationProjection());
    expect(array_keys($events))->toBe(['esrs_e1_a', 'esrs_e1_b', 'esrs_e1_c'])
        ->and($events['esrs_e1_a']['proposed'])->toBeTrue()->and($events['esrs_e1_a']['accepted'])->toBeTrue()
        ->and($events['esrs_e1_b']['accepted'])->toBeFalse()->and($events['esrs_e1_c']['proposed'])->toBeFalse();
});

it('excludes revisar missing labels ambiguous mappings and stale or missing bases', function () {
    [$state, $basis, $mapping] = activationProjection();
    $state['confirmation']['guided_answers'][2] = ['revisar' => true, 'final_result' => 'no_material'];
    unset($state['learning_topic_labels'][3]);
    $events = app(LearningBanditFeedback::class)->project($state, $basis, $mapping);
    expect(array_keys($events))->toBe(['esrs_e1_a']);
    $mapping['keys'][] = $mapping['keys'][0];
    expect(app(LearningBanditFeedback::class)->project($state, $basis, $mapping))->toBe([]);
    [$state, $basis, $mapping] = activationProjection(); $state['is_stale'] = true;
    expect(app(LearningBanditFeedback::class)->project($state, $basis, $mapping))->toBe([]);
    $state['is_stale'] = false;
    expect(app(LearningBanditFeedback::class)->project($state, [], $mapping))->toBe([]);
    $basis['context'][0] = INF;
    expect(app(LearningBanditFeedback::class)->project($state, $basis, $mapping))->toBe([]);
});

it('captures idempotently only after recorded consent and withdraws pending events', function () {
    [$user, $row] = activationSource();
    $feedback = app(LearningBanditFeedback::class);
    $feedback->capture($row); $feedback->capture($row);
    expect(DB::table('learning_bandit_events')->count())->toBe(1);
    $event = DB::table('learning_bandit_events')->first();
    expect($event->subject)->toBe($user->learning_subject)->and($event->event)->not->toContain('SYNTHETIC TEST')->not->toContain('user_id');
    app(LearningDataLifecycle::class)->withdraw($user);
    $feedback->capture($row->fresh());
    expect(DB::table('learning_bandit_events')->count())->toBe(0);
});

it('does not capture without consent or with a changed P5 seal', function () {
    [$user, $row] = activationSource();
    $user->forceFill(['learning_consent_version' => 'old'])->save();
    app(LearningBanditFeedback::class)->capture($row);
    expect(DB::table('learning_bandit_events')->count())->toBe(0);
    $user->forceFill(['learning_consent_version' => config('learning_engine.consent_version')])->save();
    $form = $row->form_data; $form['operations']['employee_count'] = 151; $row->forceFill(['form_data' => $form])->save();
    app(LearningBanditFeedback::class)->capture($row);
    expect(DB::table('learning_bandit_events')->count())->toBe(0);
});

it('captures the real P8 write without accepting another users events', function () {
    [$user, $row, $topic] = activationSource();
    $this->actingAs($user)->putJson('/api/materiality-confirmation', ['expected_revision' => 1, 'confirmed_topic_ids' => [$topic],
        'reviewed_topic_ids' => [$topic], 'universe_attestation' => ['version' => 1, 'mode' => 'direct', 'reviewed_universe' => true]])->assertOk();
    expect(DB::table('learning_bandit_events')->count())->toBe(1);
    $other = User::factory()->create();
    $this->actingAs($other)->putJson('/api/materiality-confirmation', ['expected_revision' => 2, 'confirmed_topic_ids' => [$topic], 'user_id' => $user->id])->assertNotFound();
    expect(DB::table('learning_bandit_events')->count())->toBe(1);
});

it('keeps P8 usable if the event migration is missing', function () {
    [$user, $row, $topic] = activationSource();
    Schema::drop('learning_bandit_events');
    $this->actingAs($user)->putJson('/api/materiality-confirmation', ['expected_revision' => 1, 'confirmed_topic_ids' => [$topic]])->assertOk();
    expect(app(LearningActivationStatus::class)->snapshot()['checks']['migrations'])->toBeFalse();
});

it('requires authenticated verified allowlisted admins and rate limits actions', function () {
    $this->getJson('/api/admin/learning')->assertUnauthorized();
    $user = User::factory()->create();
    $this->actingAs($user)->getJson('/api/admin/learning')->assertForbidden();
    config(['learning_activation.admin_ids' => [(string) $user->id]]);
    $this->getJson('/api/admin/learning')->assertOk()->assertJsonPath('data.checks.state_directory', false);
    $user->forceFill(['email_verified_at' => null])->save();
    $this->actingAs($user->fresh())->getJson('/api/admin/learning')->assertForbidden();
    $user->forceFill(['email_verified_at' => now()])->save();
    $this->actingAs($user->fresh());
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/admin/learning/actions', ['loop' => 'wp23', 'action' => 'process', 'reason' => 'synthetic test'])->assertStatus(503);
    }
    $this->postJson('/api/admin/learning/actions', ['loop' => 'wp23', 'action' => 'process', 'reason' => 'synthetic test'])->assertStatus(429);
});

it('requires CSRF for administrative actions in the normal session stack', function () {
    $user = User::factory()->create(); config(['learning_activation.admin_ids' => [(string) $user->id]]);
    $this->app['env'] = 'production';
    $this->actingAs($user)->postJson('/api/admin/learning/actions', ['loop' => 'wp23', 'action' => 'process', 'reason' => 'synthetic'])->assertStatus(419);
});

it('records a durable action before dispatch and rejects failed gates', function () {
    Queue::fake(); $user = User::factory()->create(); config(['learning_activation.admin_ids' => [(string) $user->id]]);
    $status = Mockery::mock(LearningActivationStatus::class)->makePartial(); $status->shouldReceive('snapshot')->andReturn(activationReady()); $this->instance(LearningActivationStatus::class, $status);
    $this->actingAs($user)->postJson('/api/admin/learning/actions', ['loop' => 'wp23', 'action' => 'promote', 'candidate' => str_repeat('c', 32), 'reason' => 'synthetic review'])->assertAccepted();
    $this->assertDatabaseHas('learning_admin_actions', ['admin_id' => $user->id, 'action' => 'promote', 'reason' => 'synthetic review', 'status' => 'queued']);
    Queue::assertPushedOn('learning', RunLearningAdminAction::class);
    $this->postJson('/api/admin/learning/actions', ['loop' => 'wp13', 'action' => 'promote', 'candidate' => str_repeat('d', 32), 'reason' => 'synthetic review'])->assertStatus(409);
});

it('checks every prerequisite and permits rollback with loops disabled', function () {
    $status = app(LearningActivationStatus::class); $state = activationReady();
    foreach (array_keys($state['checks']) as $key) {
        $bad = $state; $bad['checks'][$key] = false;
        expect($status->ready($bad, 'wp13', 'train'))->toBeFalse();
    }
    $state['engine_enabled'] = false;
    expect($status->ready($state, 'wp13', 'rollback'))->toBeTrue()->and($status->ready($state, 'wp23', 'process'))->toBeFalse();
});

it('processes events only through the local bridge and records before after hashes', function () {
    [$user, $row] = activationSource();
    config(['learning_activation.admin_ids' => [(string) $user->id]]);
    app(LearningBanditFeedback::class)->capture($row);
    mkdir(config('learning_engine.root'), 0700, true);
    $id = (string) Str::uuid();
    DB::table('learning_admin_actions')->insert(['id' => $id, 'admin_id' => $user->id, 'loop' => 'wp23', 'action' => 'process', 'reason' => 'synthetic', 'status' => 'queued', 'created_at' => now(), 'updated_at' => now()]);
    $before = activationReady(); $before['wp23']['candidate']['sha256'] = str_repeat('a', 64);
    $after = activationReady(); $after['wp23']['candidate']['sha256'] = str_repeat('b', 64);
    $status = Mockery::mock(LearningActivationStatus::class)->makePartial(); $status->shouldReceive('snapshot')->twice()->andReturn($before, $after);
    $bridge = Mockery::mock(LearningPythonBridge::class);
    $bridge->shouldReceive('run')->once()->with('bandit-sync', Mockery::on(function ($args) {
        $data = json_decode(file_get_contents($args['dataset']), true);
        return count($data) === 1 && hash_file('sha256', $args['dataset']) === $args['digest'] && ! isset($data[0]['user_id']);
    }))->andReturn(['candidate' => ['id' => 'synthetic']]);
    (new RunLearningAdminAction($id))->handle($status, $bridge, app(LearningDataLifecycle::class), app(LearningBanditFeedback::class));
    $this->assertDatabaseHas('learning_admin_actions', ['id' => $id, 'status' => 'completed',
        'admin_id' => $user->id, 'reason' => 'synthetic', 'before_sha256' => str_repeat('a', 64), 'after_sha256' => str_repeat('b', 64)]);
    expect(DB::table('learning_bandit_events')->whereNotNull('processed_at')->count())->toBe(1)
        ->and(glob(config('learning_engine.root').'/bandit-dataset-*.json'))->toBe([]);
});

it('does not mark events processed when the private update fails', function () {
    [$user, $row] = activationSource(); config(['learning_activation.admin_ids' => [(string) $user->id]]);
    app(LearningBanditFeedback::class)->capture($row); mkdir(config('learning_engine.root'), 0700, true);
    $id = (string) Str::uuid();
    DB::table('learning_admin_actions')->insert(['id' => $id, 'admin_id' => $user->id, 'loop' => 'wp23', 'action' => 'process', 'reason' => 'synthetic failure', 'status' => 'queued', 'created_at' => now(), 'updated_at' => now()]);
    $status = Mockery::mock(LearningActivationStatus::class)->makePartial(); $status->shouldReceive('snapshot')->once()->andReturn(activationReady());
    $bridge = Mockery::mock(LearningPythonBridge::class); $bridge->shouldReceive('run')->once()->andThrow(new RuntimeException('synthetic'));
    (new RunLearningAdminAction($id))->handle($status, $bridge, app(LearningDataLifecycle::class), app(LearningBanditFeedback::class));
    $this->assertDatabaseHas('learning_admin_actions', ['id' => $id, 'status' => 'failed_closed', 'error_code' => 'learning.action_failed_closed']);
    expect(DB::table('learning_bandit_events')->whereNull('processed_at')->count())->toBe(1)
        ->and(glob(config('learning_engine.root').'/bandit-dataset-*.json'))->toBe([]);
});

it('rechecks admin authority when queued work starts', function () {
    $user = User::factory()->create(); $id = (string) Str::uuid();
    DB::table('learning_admin_actions')->insert(['id' => $id, 'admin_id' => $user->id, 'loop' => 'wp13', 'action' => 'promote', 'candidate' => str_repeat('c', 32), 'reason' => 'synthetic', 'status' => 'queued', 'created_at' => now(), 'updated_at' => now()]);
    $bridge = Mockery::mock(LearningPythonBridge::class); $bridge->shouldNotReceive('run');
    (new RunLearningAdminAction($id))->handle(app(LearningActivationStatus::class), $bridge, app(LearningDataLifecycle::class), app(LearningBanditFeedback::class));
    $this->assertDatabaseHas('learning_admin_actions', ['id' => $id, 'status' => 'failed_closed']);
});

it('excludes superseded and expired events from the next ledger', function () {
    [$user, $row] = activationSource(); $feedback = app(LearningBanditFeedback::class); $feedback->capture($row);
    expect($feedback->ledger())->toHaveCount(1);
    $form = $row->form_data; $form['materiality_confirmation']['revision'] = 2; $row->forceFill(['form_data' => $form])->save();
    expect($feedback->ledger())->toBe([]);
    $row->forceFill(['form_data' => app(LearningFeedbackDataset::class)->seal($row, $row->form_data, 'materiality_confirmation')])->save();
    $feedback->capture($row); expect($feedback->ledger())->toHaveCount(1);
    DB::table('learning_bandit_events')->update(['created_at' => now()->subDays(31)]);
    expect($feedback->ledger())->toBe([]);
});


it('invalidates the lease revision when P6 changes without a new submission generation', function () {
    [$user, $row] = activationSource(); $feedback = app(LearningBanditFeedback::class); $feedback->capture($row);
    $before = $feedback->revision($row);
    $row->forceFill(['esrs_topic_ids' => []])->save();
    expect($feedback->revision($row))->not->toBe($before)->and($feedback->ledger())->toBe([]);
});


it('tick dispatches only with both flags and enforces each loop interval', function () {
    Queue::fake();
    config(['queue.default' => 'database', 'learning_engine.enabled' => true, 'learning_engine.scheduled' => true]);
    // Final lifecycle uses the real service; no data is required to refresh an absent root.
    $this->artisan('learning:tick')->assertSuccessful();
    Queue::assertPushed(RunLearningAdminAction::class, 2);
    $this->artisan('learning:tick')->assertSuccessful();
    Queue::assertPushed(RunLearningAdminAction::class, 2);
    $this->travel(5)->minutes();
    $this->artisan('learning:tick')->assertSuccessful();
    Queue::assertPushed(RunLearningAdminAction::class, 3);
    expect(DB::table('learning_admin_actions')->where('loop', 'wp13')->count())->toBe(1)
        ->and(DB::table('learning_admin_actions')->where('loop', 'wp23')->count())->toBe(2);
    $this->travel(1)->days();
    $this->artisan('learning:tick')->assertSuccessful();
    expect(DB::table('learning_admin_actions')->where('loop', 'wp13')->count())->toBe(2)
        ->and(DB::table('learning_admin_actions')->where('loop', 'wp23')->count())->toBe(3);
    $this->travelBack();
});

it('tick maintains privacy and heartbeats with either training switch off', function (bool $enabled, bool $scheduled) {
    Queue::fake();
    config(['queue.default' => 'database', 'learning_engine.enabled' => $enabled, 'learning_engine.scheduled' => $scheduled]);
    $id = (string) Str::uuid();
    DB::table('learning_admin_actions')->insert(['id' => $id, 'loop' => 'wp23', 'action' => 'process',
        'reason' => 'synthetic expired audit', 'status' => 'completed', 'created_at' => now()->subDays(31), 'updated_at' => now()->subDays(31)]);
    mkdir(config('learning_engine.root'), 0700, true);
    file_put_contents(config('learning_engine.root').'/bandit-catalogue.json', '{}');
    $bridge = Mockery::mock(LearningPythonBridge::class);
    $bridge->shouldReceive('run')->once()->with('bandit-maintain')->andReturn([]);
    $this->instance(LearningPythonBridge::class, $bridge);
    $this->artisan('learning:tick')->assertSuccessful();
    Queue::assertNotPushed(RunLearningAdminAction::class);
    Queue::assertPushed(\App\Jobs\LearningQueueHeartbeat::class);
    expect(DB::table('learning_admin_actions')->where('id', $id)->exists())->toBeFalse()
        ->and(DB::table('learning_heartbeats')->where('component', 'scheduler')->exists())->toBeTrue()
        ->and(is_file(config('learning_engine.root').'/authority.json'))->toBeTrue();
})->with([[false, true], [true, false], [false, false]]);

it('tick suppresses the bandit loop when its mode is off', function () {
    Queue::fake();
    config(['queue.default' => 'database', 'learning_engine.scheduled' => true, 'learning_activation.bandit_mode' => 'off']);
    $this->artisan('learning:tick')->assertSuccessful();
    Queue::assertPushed(RunLearningAdminAction::class, 1);
    expect(DB::table('learning_admin_actions')->sole()->loop)->toBe('wp13');
});

it('contending workers release then execute each durable action at most once', function () {
    $user = User::factory()->create(); config(['learning_activation.admin_ids' => [(string) $user->id]]);
    $ids = [(string) Str::uuid(), (string) Str::uuid()];
    foreach ($ids as $id) {
        DB::table('learning_admin_actions')->insert(['id' => $id, 'admin_id' => $user->id, 'loop' => 'wp23',
            'action' => 'rollback', 'reason' => 'synthetic contention', 'status' => 'queued', 'created_at' => now(), 'updated_at' => now()]);
    }
    $status = Mockery::mock(LearningActivationStatus::class)->makePartial();
    $status->shouldReceive('snapshot')->times(4)->andReturn(activationReady());
    $second = new RunLearningAdminAction($ids[1]);
    $reservation = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
    $reservation->shouldReceive('release')->once()->with(30);
    $second->setJob($reservation);
    expect($second->tries)->toBeGreaterThan(1);
    $bridge = Mockery::mock(LearningPythonBridge::class);
    $bridge->shouldReceive('run')->once()->with('bandit-rollback', Mockery::type('array'))->andReturnUsing(function () use ($second, $status, $bridge, $ids) {
        $second->handle($status, $bridge, app(LearningDataLifecycle::class), app(LearningBanditFeedback::class));
        expect(DB::table('learning_admin_actions')->where('id', $ids[1])->value('status'))->toBe('queued');
        return [];
    });
    $bridge->shouldReceive('run')->once()->with('bandit-rollback', Mockery::type('array'))->andReturn([]);
    (new RunLearningAdminAction($ids[0]))->handle($status, $bridge, app(LearningDataLifecycle::class), app(LearningBanditFeedback::class));
    $second->handle($status, $bridge, app(LearningDataLifecycle::class), app(LearningBanditFeedback::class));
    $second->handle($status, $bridge, app(LearningDataLifecycle::class), app(LearningBanditFeedback::class));
    expect(DB::table('learning_admin_actions')->whereIn('id', $ids)->where('status', 'completed')->count())->toBe(2);
});

it('OFF worker resets to base through the real Python bridge despite a previous pointer', function () {
    if (! is_executable(config('learning_engine.python'))) {
        $this->markTestSkipped('Native learning Python is required for the worker-to-CLI test.');
    }
    $user = User::factory()->create();
    config(['learning_activation.admin_ids' => [(string) $user->id], 'learning_engine.enabled' => false, 'learning_engine.authority' => 'deny']);
    mkdir(config('learning_engine.root'), 0700, true);
    // Seed pointers only: OFF reset must not load or authorise either candidate.
    app(LearningPythonBridge::class)->run('rollback', ['candidate' => 'base', 'actor' => 'admin:'.$user->id, 'reason' => 'synthetic setup']);
    $registry = new PDO('sqlite:'.config('learning_engine.root').'/registry.sqlite3');
    $insert = $registry->prepare('INSERT OR REPLACE INTO pointers(name,candidate_id) VALUES(?,?)');
    $insert->execute(['active', str_repeat('c', 32)]);
    $insert->execute(['previous', str_repeat('d', 32)]);
    $id = (string) Str::uuid();
    DB::table('learning_admin_actions')->insert(['id' => $id, 'admin_id' => $user->id, 'loop' => 'wp13', 'action' => 'rollback',
        'reason' => 'synthetic OFF reset', 'status' => 'queued', 'created_at' => now(), 'updated_at' => now()]);
    $state = activationReady(); $state['engine_enabled'] = false; $state['wp13']['previous'] = str_repeat('d', 32);
    $status = Mockery::mock(LearningActivationStatus::class)->makePartial();
    $status->shouldReceive('snapshot')->twice()->andReturn($state);
    (new RunLearningAdminAction($id))->handle($status, app(LearningPythonBridge::class), app(LearningDataLifecycle::class), app(LearningBanditFeedback::class));
    $this->assertDatabaseHas('learning_admin_actions', ['id' => $id, 'status' => 'completed']);
    $registry = new PDO('sqlite:'.config('learning_engine.root').'/registry.sqlite3');
    expect($registry->query("SELECT candidate_id FROM pointers WHERE name='active'")->fetchColumn())->toBeNull();
});

it('gate metrics validates a single candidate query and enforces the read throttle', function () {
    $user = User::factory()->create(); config(['learning_activation.admin_ids' => [(string) $user->id]]);
    $this->actingAs($user)->getJson('/api/admin/learning?candidate=../private')->assertUnprocessable();
    $this->getJson('/api/admin/learning?candidate[]=x')->assertUnprocessable();
    for ($i = 0; $i < 18; $i++) { $this->getJson('/api/admin/learning')->assertOk(); }
    $this->getJson('/api/admin/learning')->assertStatus(429);
});

it('gate metrics cannot enqueue legacy failed or stale-policy candidates', function (string $kind) {
    Queue::fake(); $user = User::factory()->create();
    config(['learning_activation.admin_ids' => [(string) $user->id], 'learning_engine.gate_metrics_enabled' => true]);
    $state = activationReady();
    $state['wp13']['candidates'] = [['id' => str_repeat('d', 32), 'eligible' => false,
        'gate' => ['passed' => $kind !== 'failed', 'schema' => $kind === 'legacy' ? 'legacy' : 'learning-gate-metrics-v1']]];
    $status = Mockery::mock(LearningActivationStatus::class)->makePartial();
    $status->shouldReceive('snapshot')->once()->with(str_repeat('d', 32))->andReturn($state); $this->instance(LearningActivationStatus::class, $status);
    $this->actingAs($user)->postJson('/api/admin/learning/actions', ['loop' => 'wp13', 'action' => 'promote',
        'candidate' => str_repeat('d', 32), 'reason' => 'synthetic review'])->assertStatus(409);
    Queue::assertNothingPushed();
})->with(['legacy', 'failed', 'stale-policy']);

it('gate metrics maps unknown and erased registry candidates to 404', function () {
    $user = User::factory()->create(); config(['learning_activation.admin_ids' => [(string) $user->id],
        'learning_engine.python' => PHP_BINARY]);
    mkdir(config('learning_engine.root'), 0700, true);
    $bridge = Mockery::mock(LearningPythonBridge::class);
    $bridge->shouldReceive('run')->once()->with('status', ['candidate' => str_repeat('a', 32)])
        ->andReturn(['candidate_found' => false]);
    $this->instance(LearningPythonBridge::class, $bridge);
    $this->actingAs($user)->getJson('/api/admin/learning?candidate='.str_repeat('a', 32))->assertNotFound();
});

it('gate metrics can enqueue an eligible candidate outside the thirty recent candidates', function () {
    Queue::fake(); $user = User::factory()->create();
    config(['learning_activation.admin_ids' => [(string) $user->id], 'learning_engine.gate_metrics_enabled' => true]);
    $id = str_repeat('a', 32);
    $recent = activationReady();
    $recent['wp13']['candidates'] = array_map(fn ($n) => ['id' => sprintf('%032x', $n), 'eligible' => false], range(1, 30));
    $detail = activationReady();
    $detail['wp13']['candidates'] = [['id' => $id, 'eligible' => true, 'gate' => ['passed' => true]]];
    $status = Mockery::mock(LearningActivationStatus::class)->makePartial();
    $status->shouldReceive('snapshot')->once()->with(null)->andReturn($recent);
    $status->shouldReceive('snapshot')->twice()->with($id)->andReturn($detail);
    $this->instance(LearningActivationStatus::class, $status);
    $this->actingAs($user)->getJson('/api/admin/learning')->assertOk()->assertJsonCount(30, 'data.wp13.candidates');
    $this->getJson('/api/admin/learning?candidate='.$id)->assertOk()->assertJsonPath('data.wp13.candidates.0.id', $id);
    $this->postJson('/api/admin/learning/actions', ['loop' => 'wp13', 'action' => 'promote',
        'candidate' => $id, 'reason' => 'synthetic older candidate review'])->assertAccepted();
    $this->assertDatabaseHas('learning_admin_actions', ['candidate' => $id, 'action' => 'promote', 'status' => 'queued']);
    Queue::assertPushedOn('learning', RunLearningAdminAction::class);
});

it('gate metrics cannot enqueue an unknown or erased promotion candidate', function () {
    Queue::fake(); $user = User::factory()->create();
    config(['learning_activation.admin_ids' => [(string) $user->id], 'learning_engine.python' => PHP_BINARY]);
    mkdir(config('learning_engine.root'), 0700, true);
    $bridge = Mockery::mock(LearningPythonBridge::class);
    $bridge->shouldReceive('run')->once()->with('status', ['candidate' => str_repeat('a', 32)])
        ->andReturn(['candidate_found' => false]);
    $this->instance(LearningPythonBridge::class, $bridge);
    $this->actingAs($user)->postJson('/api/admin/learning/actions', ['loop' => 'wp13', 'action' => 'promote',
        'candidate' => str_repeat('a', 32), 'reason' => 'synthetic missing candidate'])->assertNotFound();
    Queue::assertNothingPushed();
    $this->assertDatabaseCount('learning_admin_actions', 0);
});

it('gate metrics passes only the validated opaque candidate to the status projection', function () {
    $user = User::factory()->create(); config(['learning_activation.admin_ids' => [(string) $user->id]]);
    $status = Mockery::mock(LearningActivationStatus::class)->makePartial();
    $status->shouldReceive('snapshot')->once()->with(str_repeat('a', 32))->andReturn(activationReady());
    $this->instance(LearningActivationStatus::class, $status);
    $response = $this->actingAs($user)->getJson('/api/admin/learning?candidate='.str_repeat('a', 32))->assertOk();
    expect($response->getContent())->not->toContain('subjects')->not->toContain('truth')->not->toContain('filesystem');
});

it('gate metrics bridge fails closed on invalid or oversized subprocess responses', function (string $kind) {
    $source = config('learning_engine.root').'/synthetic-bridge'; mkdir($source, 0700, true);
    config(['learning_engine.source' => $source, 'learning_engine.python' => PHP_BINARY,
        'learning_engine.gate_metrics_enabled' => true]);
    $body = $kind === 'oversized' ? "echo str_repeat('x', 1048577);" : 'echo "{}";';
    file_put_contents($source.'/learning_activation_cli.py', '<?php '.$body);
    expect(fn () => app(LearningPythonBridge::class)->run('status'))->toThrow(RuntimeException::class);
})->with(['invalid', 'oversized']);
