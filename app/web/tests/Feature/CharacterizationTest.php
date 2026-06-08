<?php

use App\Jobs\SubmitCharacterizationJob;
use App\Models\Characterization;
use App\Models\User;
use App\Services\Contracts\CharacterizationGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\NaceCodeSeeder::class);
    $this->seed(\Database\Seeders\EsrsTopicSeeder::class);

    $this->user = User::factory()->create();
});

function validCharacterizationPayload(int $topicId, array $overrides = []): array
{
    return array_replace_recursive([
        'step' => 'review',
        'nace_code' => 'A',
        'esrs_topic_ids' => [$topicId],
        'form_data' => [
            'company_profile' => [
                'company_name' => 'Entidad Demo',
                'headquarters_country' => 'Spain',
                'reporting_year' => 2025,
                'reporting_scope' => 'consolidated_group',
                'num_subsidiaries_countries' => 3,
                'stock_listed' => false,
                'reporting_currency' => 'EUR',
                'product_service_type' => 'software_digital_services',
            ],
            'notes' => 'Ready',
            'operations' => [
                'regions' => ['eu'],
                'value_chain' => ['direct_operations'],
                'employee_count_range' => '50_249',
                'revenue_range' => '2m_to_10m',
            ],
        ],
        'action' => 'submit',
    ], $overrides);
}

it('renders company profile inputs needed by the prediction adapter', function () {
    $this->actingAs($this->user)
        ->get(route('characterization.create', ['step' => 'company']))
        ->assertOk()
        ->assertSee('form_data[company_profile][company_name]', false)
        ->assertSee('form_data[company_profile][headquarters_country]', false)
        ->assertSee('form_data[company_profile][reporting_year]', false)
        ->assertSee('form_data[company_profile][reporting_scope]', false)
        ->assertSee('form_data[company_profile][num_subsidiaries_countries]', false)
        ->assertSee('form_data[company_profile][stock_listed]', false)
        ->assertSee('form_data[company_profile][reporting_currency]', false)
        ->assertSee('form_data[company_profile][product_service_type]', false);
});

it('renders SME-friendly operations size range selects', function () {
    $this->actingAs($this->user)
        ->get(route('characterization.create', ['step' => 'operations']))
        ->assertOk()
        ->assertSee('form_data[operations][employee_count_range]', false)
        ->assertSee('form_data[operations][revenue_range]', false)
        ->assertSee('50-249')
        ->assertSee('EUR 2M-10M');
});

it('allows saving a draft characterization without dispatching the submission job', function () {
    $topicId = \App\Models\EsrsTopic::first()->id;

    Bus::fake();

    $response = $this->actingAs($this->user)->post(route('characterization.store'), [
        'step' => 'esg',
        'nace_code' => 'A',
        'esrs_topic_ids' => [$topicId],
        'form_data' => [
            'company_profile' => [
                'company_name' => 'Entidad Demo',
                'headquarters_country' => 'Spain',
                'reporting_year' => 2025,
                'reporting_scope' => 'consolidated_group',
                'num_subsidiaries_countries' => 3,
                'stock_listed' => false,
                'reporting_currency' => 'EUR',
                'product_service_type' => 'software_digital_services',
            ],
            'notes' => 'Initial draft',
            'operations' => [
                'regions' => ['eu'],
                'value_chain' => ['direct_operations'],
                'employee_count_range' => '50_249',
                'revenue_range' => '2m_to_10m',
            ],
        ],
        'action' => 'save_draft',
    ]);

    $response->assertRedirect(route('characterization.create', ['step' => 'esg']));
    $response->assertSessionHas('status');

    $characterization = Characterization::where('user_id', $this->user->id)->first();

    expect($characterization)->not->toBeNull();
    expect($characterization->status)->toBe(Characterization::STATUS_DRAFT);
    expect($characterization->esrs_topic_ids)->toContain($topicId);
    expect($characterization->form_data['notes'])->toBe('Initial draft');
    expect($characterization->form_data['company_profile']['nace_code'])->toBe('A');
    expect($characterization->form_data['company_profile']['company_name'])->toBe('Entidad Demo');
    expect($characterization->form_data['company_profile']['headquarters_country'])->toBe('Spain');
    expect($characterization->form_data['company_profile']['reporting_year'])->toBe(2025);
    expect($characterization->form_data['company_profile']['reporting_scope'])->toBe('consolidated_group');
    expect($characterization->form_data['company_profile']['num_subsidiaries_countries'])->toBe(3);
    expect($characterization->form_data['company_profile']['stock_listed'])->toBeFalse();
    expect($characterization->form_data['company_profile']['reporting_currency'])->toBe('EUR');
    expect($characterization->form_data['company_profile']['product_service_type'])->toBe('software_digital_services');
    expect($characterization->form_data['esg_focus']['topic_ids'])->toContain($topicId);
    expect($characterization->form_data['operations']['regions'])->toBeArray()->toContain('eu');
    expect($characterization->form_data['operations']['value_chain'])->toContain('direct_operations');
    expect($characterization->form_data['operations']['employee_count_range'])->toBe('50_249');
    expect($characterization->form_data['operations']['revenue_range'])->toBe('2m_to_10m');

    Bus::assertNotDispatched(SubmitCharacterizationJob::class);
});

it('allows progressing through steps using profile and operations data saved earlier', function () {
    $topicId = \App\Models\EsrsTopic::first()->id;

    $this->actingAs($this->user)->post(route('characterization.store'), [
        'step' => 'company',
        'nace_code' => 'A',
        'form_data' => [
            'company_profile' => [
                'company_name' => 'Entidad Demo',
                'headquarters_country' => 'Spain',
                'reporting_year' => 2025,
                'reporting_scope' => 'consolidated_group',
                'num_subsidiaries_countries' => 3,
                'stock_listed' => false,
                'reporting_currency' => 'EUR',
                'product_service_type' => 'software_digital_services',
            ],
        ],
        'action' => 'next',
    ])->assertRedirect(route('characterization.create', ['step' => 'operations']));

    $this->actingAs($this->user)->post(route('characterization.store'), [
        'step' => 'operations',
        'form_data' => [
            'operations' => [
                'regions' => ['eu'],
                'value_chain' => ['direct_operations'],
                'employee_count_range' => '50_249',
                'revenue_range' => '2m_to_10m',
            ],
        ],
        'action' => 'next',
    ])->assertRedirect(route('characterization.create', ['step' => 'esg']));

    $this->actingAs($this->user)->post(route('characterization.store'), [
        'step' => 'esg',
        'esrs_topic_ids' => [$topicId],
        'action' => 'next',
    ])->assertRedirect(route('characterization.create', ['step' => 'review']));
});

it('validates required fields when submitting', function () {
    $response = $this->actingAs($this->user)->post(route('characterization.store'), [
        'step' => 'review',
        'action' => 'submit',
    ]);

    $response->assertSessionHasErrors([
        'nace_code',
        'esrs_topic_ids',
        'form_data.company_profile.company_name',
        'form_data.company_profile.headquarters_country',
        'form_data.company_profile.reporting_year',
        'form_data.company_profile.reporting_scope',
        'form_data.company_profile.num_subsidiaries_countries',
        'form_data.company_profile.stock_listed',
        'form_data.company_profile.reporting_currency',
        'form_data.company_profile.product_service_type',
        'form_data.operations.regions',
        'form_data.operations.value_chain',
        'form_data.operations.employee_count_range',
        'form_data.operations.revenue_range',
    ]);
});

it('rejects unsupported product or service types', function () {
    $response = $this->actingAs($this->user)->post(route('characterization.store'), [
        'step' => 'company',
        'nace_code' => 'A',
        'form_data' => [
            'company_profile' => [
                'company_name' => 'Entidad Demo',
                'headquarters_country' => 'Spain',
                'reporting_year' => 2025,
                'reporting_scope' => 'consolidated_group',
                'num_subsidiaries_countries' => 3,
                'stock_listed' => false,
                'reporting_currency' => 'EUR',
                'product_service_type' => 'unsupported_type',
            ],
        ],
        'action' => 'next',
    ]);

    $response->assertSessionHasErrors([
        'form_data.company_profile.product_service_type',
    ]);
});

it('rejects unsupported operations size ranges', function () {
    $response = $this->actingAs($this->user)->post(route('characterization.store'), [
        'step' => 'operations',
        'form_data' => [
            'operations' => [
                'regions' => ['eu'],
                'value_chain' => ['direct_operations'],
                'employee_count_range' => 'unsupported_headcount',
                'revenue_range' => 'unsupported_revenue',
            ],
        ],
        'action' => 'next',
    ]);

    $response->assertSessionHasErrors([
        'form_data.operations.employee_count_range',
        'form_data.operations.revenue_range',
    ]);
});

it('dispatches the submission job when status transitions to submitted', function () {
    Bus::fake();

    $topicId = \App\Models\EsrsTopic::first()->id;

    $this->actingAs($this->user)
        ->post(route('characterization.store'), validCharacterizationPayload($topicId))
        ->assertRedirect(route('characterization.create', ['step' => 'review']));

    $characterization = Characterization::where('user_id', $this->user->id)->first();

    expect($characterization->status)->toBe(Characterization::STATUS_SUBMITTED);
    expect($characterization->submitted_at)->not->toBeNull();

    Bus::assertDispatched(SubmitCharacterizationJob::class, function ($job) use ($characterization) {
        return $job->characterization->is($characterization);
    });
});

it('handles the status lifecycle within the submission job', function () {
    config(['services.characterization.mock_outcome' => 'success']);

    $topicId = \App\Models\EsrsTopic::first()->id;

    $this->actingAs($this->user)->post(route('characterization.store'), validCharacterizationPayload($topicId, [
        'form_data' => [
            'notes' => 'Lifecycle check',
            'operations' => [
                'value_chain' => ['direct_operations', 'downstream'],
                'employee_count_range' => '50_249',
                'revenue_range' => '2m_to_10m',
            ],
        ],
    ]));

    $characterization = Characterization::where('user_id', $this->user->id)->first();

    (new SubmitCharacterizationJob($characterization))->handle(app(\App\Services\Contracts\CharacterizationGateway::class));

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

    $gateway = new class($e1Topic->id, $e2Topic->id) implements \App\Services\Contracts\CharacterizationGateway
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

    $characterization = Characterization::factory()->create([
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
