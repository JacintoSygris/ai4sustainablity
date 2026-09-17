<?php

use App\Jobs\SubmitCharacterizationJob;
use App\Models\Characterization;
use App\Models\User;
use App\Services\Contracts\CharacterizationGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\NaceCodeSeeder::class);
    $this->seed(\Database\Seeders\EsrsTopicSeeder::class);

    $this->user = User::factory()->create();
});

it('redirects the retired Blade characterization wizard to the Next wizard', function () {
    $this->actingAs($this->user)
        ->get(route('characterization.create'))
        ->assertRedirect('/wizard/step-1');

    expect(Route::has('characterization.store'))->toBeFalse();
});

it('does not accept legacy Blade characterization form posts', function () {
    $this->actingAs($this->user)
        ->post('/characterization', [])
        ->assertStatus(405);
});

it('handles the status lifecycle within the submission job', function () {
    config(['services.characterization.mock_outcome' => 'success']);

    $topicId = \App\Models\EsrsTopic::first()->id;

    $characterization = Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_SUBMITTED,
        'nace_code' => 'A',
        'esrs_topic_ids' => [$topicId],
        'form_data' => [
            'company_profile' => [
                'nace_code' => 'A',
                'company_name' => 'Entidad Demo',
            ],
            'operations' => [
                'employee_count_range' => '50_249',
                'revenue_range' => '2m_to_10m',
            ],
        ],
        'submitted_at' => now(),
    ]);

    (new SubmitCharacterizationJob($characterization))->handle(app(CharacterizationGateway::class));

    $characterization->refresh();

    expect($characterization->status)->toBe(Characterization::STATUS_COMPLETED);
    expect($characterization->result_data)->toBeArray();
    expect($characterization->completed_at)->not->toBeNull();
    expect($characterization->last_error)->toBeNull();
});

it('skips submission jobs already claimed by another worker', function () {
    $characterization = Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_PROCESSING,
        'nace_code' => 'A',
        'submitted_at' => now()->subMinute(),
        'last_job_attempted_at' => now()->subMinute(),
    ]);

    $gateway = new class implements CharacterizationGateway
    {
        public bool $called = false;

        public function submit(Characterization $characterization): array
        {
            $this->called = true;

            return [
                'status' => 'completed',
                'summary' => 'Should not be called.',
                'candidate_topics' => [],
            ];
        }
    };

    (new SubmitCharacterizationJob($characterization))->handle($gateway);

    $characterization->refresh();

    expect($gateway->called)->toBeFalse();
    expect($characterization->status)->toBe(Characterization::STATUS_PROCESSING);
    expect($characterization->result_data)->toBeNull();
});

it('records retry metadata and releases the job inside the retry window', function () {
    $characterization = Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_SUBMITTED,
        'nace_code' => 'A',
        'submitted_at' => now()->subHour(),
        'retry_count' => 0,
    ]);

    $gateway = new class implements CharacterizationGateway
    {
        public function submit(Characterization $characterization): array
        {
            throw new RuntimeException('Python service unavailable');
        }
    };

    $job = (new SubmitCharacterizationJob($characterization))->withFakeQueueInteractions();

    $job->handle($gateway);

    $job->assertReleased(300);

    $characterization->refresh();

    expect($characterization->status)->toBe(Characterization::STATUS_WAITING);
    expect($characterization->retry_count)->toBe(1);
    expect($characterization->last_error)->toBe('Python service unavailable');
    expect($characterization->last_job_attempted_at)->not->toBeNull();
    expect($characterization->next_retry_at)->not->toBeNull();
});

it('marks submissions timed out once the retry window is exhausted', function () {
    $characterization = Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_SUBMITTED,
        'nace_code' => 'A',
        'submitted_at' => now()->subHours(73),
        'retry_count' => 4,
    ]);

    $gateway = new class implements CharacterizationGateway
    {
        public function submit(Characterization $characterization): array
        {
            throw new RuntimeException('Python service unavailable');
        }
    };

    $job = (new SubmitCharacterizationJob($characterization))->withFakeQueueInteractions();

    expect(fn () => $job->handle($gateway))->toThrow(RuntimeException::class, 'Python service unavailable');

    $job->assertNotReleased();

    $characterization->refresh();

    expect($characterization->status)->toBe(Characterization::STATUS_TIMED_OUT);
    expect($characterization->retry_count)->toBe(5);
    expect($characterization->last_error)->toBe('Python service unavailable');
    expect($characterization->last_job_attempted_at)->not->toBeNull();
    expect($characterization->next_retry_at)->toBeNull();
});

it('stores ai candidate topics as the P6 materiality proposal when the job completes', function () {
    $e1Topic = \App\Models\EsrsTopic::where('esrs_code', 'E1')->firstOrFail();
    $e2Topic = \App\Models\EsrsTopic::where('esrs_code', 'E2')->firstOrFail();

    $characterization = Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_SUBMITTED,
        'nace_code' => 'A',
        'esrs_topic_ids' => [],
        'form_data' => [
            'company_profile' => [
                'nace_code' => 'A',
                'company_name' => 'Entidad Demo',
            ],
            'operations' => [
                'employee_count_range' => '50_249',
                'revenue_range' => '2m_to_10m',
            ],
        ],
        'submitted_at' => now(),
    ]);

    $gateway = new class($e1Topic->id, $e2Topic->id) implements CharacterizationGateway
    {
        public function __construct(private readonly int $e1TopicId, private readonly int $e2TopicId) {}

        public function submit(Characterization $characterization): array
        {
            return [
                'status' => 'completed',
                'summary' => 'AI proposed 2 candidate ESRS topics.',
                'candidate_topics' => [
                    ['ar16_topic_id' => $this->e2TopicId, 'suggested' => true],
                    ['ar16_topic_id' => $this->e1TopicId, 'suggested' => true],
                ],
                'review_required_prediction_keys' => [],
                'raw_prediction' => [],
            ];
        }
    };

    (new SubmitCharacterizationJob($characterization))->handle($gateway);

    $characterization->refresh();

    expect($characterization->status)->toBe(Characterization::STATUS_COMPLETED);
    expect($characterization->esrs_topic_ids)->toBe([$e2Topic->id, $e1Topic->id]);
    expect(data_get($characterization->form_data, 'esg_focus.topic_ids'))->toBe([$e2Topic->id, $e1Topic->id]);
    expect($characterization->result_data['candidate_topics'])->toHaveCount(2);
});

it('allows manual retry of failed submissions', function () {
    Bus::fake();

    $previousSubmittedAt = now()->subDays(2);
    $previousCompletedAt = now()->subDay();
    $previousAttemptedAt = now()->subHours(12);

    $characterization = Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_FAILED,
        'retry_count' => 3,
        'submitted_at' => $previousSubmittedAt,
        'completed_at' => $previousCompletedAt,
        'last_job_attempted_at' => $previousAttemptedAt,
        'next_retry_at' => now()->subHour(),
        'last_error' => 'Previous failure',
        'result_data' => ['stale' => true],
        'nace_code' => 'A',
        'esrs_topic_ids' => [\App\Models\EsrsTopic::first()->id],
        'form_data' => [
            'company_profile' => ['nace_code' => 'A'],
            'esg_focus' => ['topic_ids' => [\App\Models\EsrsTopic::first()->id]],
        ],
    ]);

    $this->actingAs($this->user)
        ->post(route('characterization.retry'))
        ->assertRedirect(route('characterization.create'))
        ->assertSessionHas('status');

    $characterization->refresh();

    expect($characterization->status)->toBe(Characterization::STATUS_SUBMITTED);
    expect($characterization->retry_count)->toBe(0);
    expect($characterization->submitted_at->greaterThan($previousSubmittedAt))->toBeTrue();
    expect($characterization->next_retry_at)->toBeNull();
    expect($characterization->last_error)->toBeNull();
    expect($characterization->last_job_attempted_at)->toBeNull();
    expect($characterization->completed_at)->toBeNull();
    expect($characterization->result_data)->toBeNull();

    Bus::assertDispatched(SubmitCharacterizationJob::class);
});

it('renders characterization summary and downloads pdf', function () {
    $topicId = \App\Models\EsrsTopic::first()->id;

    Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'nace_code' => 'A',
        'esrs_topic_ids' => [$topicId],
        'form_data' => [
            'company_profile' => [
                'nace_code' => 'A',
                'company_name' => 'Entidad Demo',
                'headquarters_country' => 'Spain',
                'reporting_year' => 2025,
                'reporting_scope' => 'consolidated_group',
                'num_subsidiaries_countries' => 3,
                'stock_listed' => false,
                'reporting_currency' => 'EUR',
                'product_service_type' => 'software_digital_services',
            ],
            'esg_focus' => ['topic_ids' => [$topicId]],
            'operations' => [
                'regions' => ['eu'],
                'value_chain' => ['direct_operations'],
                'employee_count_range' => '50_249',
                'revenue_range' => '2m_to_10m',
            ],
            'notes' => 'Summary check',
        ],
    ]);

    $this->actingAs($this->user)
        ->get(route('characterization.summary'))
        ->assertOk()
        ->assertSee('Characterization Summary')
        ->assertSee('Entidad Demo')
        ->assertSee('España')
        ->assertSee('2025')
        ->assertSee('Grupo consolidado')
        ->assertSee('3')
        ->assertSee('No')
        ->assertSee('EUR')
        ->assertSee('Software/SaaS/servicios digitales')
        ->assertSee('50-249')
        ->assertSee('EUR 2M-10M');

    $this->actingAs($this->user)
        ->get(route('characterization.summary', ['format' => 'pdf']))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});
