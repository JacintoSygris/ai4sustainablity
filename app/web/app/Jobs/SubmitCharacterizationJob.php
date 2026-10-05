<?php

namespace App\Jobs;

use App\Exceptions\CharacterizationCapacityException;
use App\Events\CharacterizationStatusUpdated;
use App\Models\Characterization;
use App\Models\EsrsTopic;
use App\Services\CharacterizationStateTransaction;
use App\Services\Contracts\CharacterizationGateway;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use DateTimeInterface;
use Throwable;

class SubmitCharacterizationJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $submissionGeneration = 0;

    public ?int $retryDeadlineTimestamp = null;

    private bool $parentFenceRequested = false;
    private bool $interpretationRequested = false;
    private ?array $parentFenceRawIdentity = null;
    private ?\App\Services\LearningP6JobParentFence $parentFence = null;
    private ?\App\Services\Contracts\PreparedCharacterizationGateway $parentFenceGateway = null;

    public function __construct(public Characterization $characterization)
    {
        $this->interpretationRequested = \App\Services\LearningP6InterpretationContext::requested();
        $this->parentFenceRequested = \App\Services\LearningP6JobParentFence::requested();
        if ($this->parentFenceRequested) {
            $raw = $characterization->getAttributes();
            $this->parentFenceRawIdentity = array_intersect_key($raw, array_flip(['id', 'user_id', 'submission_generation']));
        }
        $this->submissionGeneration = (int) ($characterization->submission_generation ?? 0);
        $submittedAt = $characterization->submitted_at
            ?? $characterization->created_at
            ?? now();
        $this->retryDeadlineTimestamp = $submittedAt->copy()->addHours(72)->getTimestamp();
    }

    public function retryUntil(): DateTimeInterface
    {
        return CarbonImmutable::createFromTimestamp(
            $this->retryDeadlineTimestamp ?? now()->addHours(72)->getTimestamp()
        );
    }

    public function failed(?Throwable $exception): void
    {
        if ($this->interpretationRequested || \App\Services\LearningP6InterpretationContext::requested()) {
            try { \App\Services\LearningP6InterpretationContext::guard(); }
            catch (\DomainException $denied) { return; }
            if (! $this->parentFenceRequested && ! \App\Services\LearningP6JobParentFence::requested()) { return; }
        }
        if ($this->parentFenceRequested || \App\Services\LearningP6JobParentFence::requested()) {
            if ($this->parentFence !== null && $this->parentFenceGateway !== null) {
                $this->parentFenceFailure($exception ?? new \RuntimeException('Retry deadline exhausted.'), app(CharacterizationStateTransaction::class), true);
            }
            return;
        }
        $now = now();
        $updated = Characterization::query()
            ->whereKey($this->characterization->id)
            ->where('submission_generation', $this->submissionGeneration)
            ->whereIn('status', [
                Characterization::STATUS_SUBMITTED,
                Characterization::STATUS_WAITING,
                Characterization::STATUS_PROCESSING,
            ])
            ->update([
                'status' => Characterization::STATUS_TIMED_OUT,
                'last_error' => $exception?->getMessage() ?? 'The characterization retry deadline was exhausted.',
                'last_job_attempted_at' => $now,
                'next_retry_at' => null,
                'updated_at' => $now,
            ]);

        if ($updated === 1 && ($characterization = Characterization::query()->find($this->characterization->id))) {
            event(new CharacterizationStatusUpdated($characterization));
        }
    }

    public function handle(
        CharacterizationGateway $gateway,
        ?CharacterizationStateTransaction $stateTransactions = null,
    ): void
    {
        if ($this->interpretationRequested || \App\Services\LearningP6InterpretationContext::requested()) {
            $this->interpretationRequested = true;
            \App\Services\LearningP6InterpretationContext::guard();
            if (! $this->parentFenceRequested && ! \App\Services\LearningP6JobParentFence::requested()) {
                throw new \DomainException('learning_p6_interpretation.prepared_required');
            }
        }
        if ($this->parentFenceRequested || \App\Services\LearningP6JobParentFence::requested()) {
            $this->parentFenceRequested = true;
            $this->handleParentFence($gateway, $stateTransactions ?? app(CharacterizationStateTransaction::class));
            return;
        }
        $stateTransactions ??= app(CharacterizationStateTransaction::class);

        if (! $this->claimForProcessing()) {
            return;
        }

        try {
            $response = $gateway->submit($this->characterization->fresh());

            $completed = $stateTransactions->run(
                $this->characterization->id,
                function (Characterization $locked) use ($response): ?Characterization {
                    if (! $this->matchesSubmission($locked) || $locked->status !== Characterization::STATUS_PROCESSING) {
                        return null;
                    }

                    $attributes = [
                        'result_data' => $response,
                        'completed_at' => now(),
                        'next_retry_at' => null,
                    ];

                    if (array_key_exists('candidate_topics', $response)) {
                        $topicIds = $this->candidateTopicIds($response);
                        $formData = $locked->form_data ?? [];
                        Arr::set($formData, 'esg_focus.topic_ids', $topicIds);

                        $attributes['esrs_topic_ids'] = $topicIds;
                        $attributes['form_data'] = $formData;
                    }

                    $locked->updateStatus(Characterization::STATUS_COMPLETED, $attributes);

                    return $locked;
                },
            );

            if (! $completed) {
                Log::info('Characterization completion skipped because the submission generation changed', [
                    'characterization_id' => $this->characterization->id,
                    'submission_generation' => $this->submissionGeneration,
                ]);

                return;
            }

            $this->characterization = $completed;

            Log::info('Characterization completed', [
                'characterization_id' => $this->characterization->id,
            ]);
        } catch (Throwable $exception) {
            $this->handleFailure($exception, $stateTransactions);
        }
    }

    private function claimForProcessing(): bool
    {
        $now = now();

        $claimed = Characterization::query()
            ->whereKey($this->characterization->id)
            ->where('submission_generation', $this->submissionGeneration)
            ->whereIn('status', [
                Characterization::STATUS_SUBMITTED,
                Characterization::STATUS_WAITING,
            ])
            ->update([
                'status' => Characterization::STATUS_PROCESSING,
                'last_error' => null,
                'next_retry_at' => null,
                'last_job_attempted_at' => $now,
                'updated_at' => $now,
            ]);

        if ($claimed !== 1) {
            Log::info('Characterization processing skipped because it is not claimable', [
                'characterization_id' => $this->characterization->id,
            ]);

            return false;
        }

        $this->characterization->refresh();
        event(new CharacterizationStatusUpdated($this->characterization->fresh()));

        return true;
    }

    private function handleParentFence(CharacterizationGateway $gateway, CharacterizationStateTransaction $transactions): void
    {
        \App\Services\LearningP6JobParentFence::guard($gateway);
        $identity = \App\Services\LearningP6JobParentFence::identity($this->parentFenceRawIdentity ?? []);
        $this->parentFenceGateway = $gateway;
        $claimed = \Illuminate\Support\Facades\DB::transaction(function () use ($gateway, $transactions, $identity): bool {
            $claimed = false;
            $expected = null;
            $transactions->runForUser($identity['user_id'], function ($row) use ($gateway, $identity, &$claimed, &$expected): void {
                if ($row === null) { throw new \DomainException('learning_p6_job.row_missing'); }
                if (\App\Services\LearningP6JobParentFence::identity($row->getRawOriginal()) !== $identity) {
                    throw new \DomainException('learning_p6_job.identity_changed');
                }
                if (! in_array($row->status, [Characterization::STATUS_SUBMITTED, Characterization::STATUS_WAITING], true)) { return; }
                if ($this->parentFence === null) {
                    \App\Services\LearningP6JobParentFence::assertFreshClaim($row->getRawOriginal(), $this->attempts());
                    $this->parentFence = \App\Services\LearningP6JobParentFence::capture($identity, $row, $gateway);
                } else {
                    $this->parentFence->assertParents($row, $gateway, [Characterization::STATUS_SUBMITTED, Characterization::STATUS_WAITING]);
                }
                $row->update(['status' => Characterization::STATUS_PROCESSING, 'last_error' => null,
                    'next_retry_at' => null, 'last_job_attempted_at' => now()]);
                $expected = $row->getRawOriginal();
                $claimed = true;
            });
            if ($claimed) {
                $fresh = Characterization::query()->findOrFail($identity['id']);
                if ($fresh->getRawOriginal() !== $expected) { throw new \DomainException('learning_p6_job.claim_finalization'); }
                $this->parentFence->assertParents($fresh, $gateway, [Characterization::STATUS_PROCESSING]);
            }
            return $claimed;
        });
        if (! $claimed) { return; }
        $this->characterization = Characterization::query()->findOrFail($identity['id']);
        event(new CharacterizationStatusUpdated($this->characterization));
        $this->parentFence->mutate($gateway, $transactions, [Characterization::STATUS_PROCESSING], fn () => null);
        // Only the actual transport invocation participates in network failure handling.
        try {
            $response = $gateway->submitPrepared($this->parentFence->prepared());
        } catch (Throwable $exception) {
            $this->parentFenceFailure($exception, $transactions);
            return;
        }
        unset($response['request_payload']);
        $this->characterization = $this->parentFence->mutate($gateway, $transactions, [Characterization::STATUS_PROCESSING], function ($row) use ($response): void {
            $attributes = ['status' => Characterization::STATUS_COMPLETED, 'result_data' => $response,
                'completed_at' => now(), 'next_retry_at' => null];
            if (array_key_exists('candidate_topics', $response)) {
                $ids = $this->parentFence->prepared()->interpretation()?->candidateTopicIds($response)
                    ?? $this->candidateTopicIds($response);
                $form = $row->form_data ?? [];
                Arr::set($form, 'esg_focus.topic_ids', $ids);
                $attributes['esrs_topic_ids'] = $ids;
                $attributes['form_data'] = $form;
            }
            $row->update($attributes);
        }, true);
        event(new CharacterizationStatusUpdated($this->characterization));
    }

    private function parentFenceFailure(Throwable $exception, CharacterizationStateTransaction $transactions, bool $terminal = false): void
    {
        if ($this->parentFence === null || $this->parentFenceGateway === null) { return; }
        $retry = false;
        $delay = 0;
        try {
            $row = $this->parentFence->mutate($this->parentFenceGateway, $transactions,
                $terminal ? [Characterization::STATUS_PROCESSING, Characterization::STATUS_WAITING, Characterization::STATUS_SUBMITTED] : [Characterization::STATUS_PROCESSING],
                function ($row) use ($exception, $terminal, &$retry, &$delay): void {
                    $attempt = (int) $row->retry_count + 1;
                    $delay = $exception instanceof CharacterizationCapacityException ? $exception->retryAfterSeconds : $this->calculateDelaySeconds($attempt);
                    $retry = ! $terminal && now()->addSeconds($delay)->getTimestamp() <= ($this->retryDeadlineTimestamp ?? 0);
                    $row->update(['status' => $retry ? Characterization::STATUS_WAITING : Characterization::STATUS_TIMED_OUT,
                        'retry_count' => $attempt, 'last_error' => $exception->getMessage(), 'last_job_attempted_at' => now(),
                        'next_retry_at' => $retry ? now()->addSeconds($delay) : null]);
                });
        } catch (\DomainException $denied) { return; }
        $this->characterization = $row;
        event(new CharacterizationStatusUpdated($row));
        if ($retry) { $this->release($delay); }
    }

    protected function handleFailure(
        Throwable $exception,
        CharacterizationStateTransaction $stateTransactions,
    ): void {
        $failure = $stateTransactions->run(
            $this->characterization->id,
            function (Characterization $locked) use ($exception): ?array {
                if (! $this->matchesSubmission($locked) || $locked->status !== Characterization::STATUS_PROCESSING) {
                    return null;
                }

                $attempt = ((int) ($locked->retry_count ?? 0)) + 1;
                $delaySeconds = $exception instanceof CharacterizationCapacityException
                    ? $exception->retryAfterSeconds
                    : $this->calculateDelaySeconds($attempt);
                $nextRetryWithinWindow = now()->addSeconds($delaySeconds)->getTimestamp()
                    <= ($this->retryDeadlineTimestamp ?? 0);

                $locked->updateStatus(
                    $nextRetryWithinWindow ? Characterization::STATUS_WAITING : Characterization::STATUS_TIMED_OUT,
                    [
                        'retry_count' => $attempt,
                        'last_error' => $exception->getMessage(),
                        'last_job_attempted_at' => now(),
                        'next_retry_at' => $nextRetryWithinWindow ? now()->addSeconds($delaySeconds) : null,
                    ],
                );

                return [
                    'attempt' => $attempt,
                    'delay_seconds' => $delaySeconds,
                    'retry' => $nextRetryWithinWindow,
                    'characterization' => $locked,
                ];
            },
        );

        if (! $failure) {
            Log::info('Characterization failure skipped because the submission generation changed', [
                'characterization_id' => $this->characterization->id,
                'submission_generation' => $this->submissionGeneration,
            ]);

            return;
        }

        $attempt = $failure['attempt'];
        $delaySeconds = $failure['delay_seconds'];
        $nextRetryWithinWindow = $failure['retry'];
        $this->characterization = $failure['characterization'];

        Log::error('Characterization submission failed', [
            'characterization_id' => $this->characterization->id,
            'error' => $exception->getMessage(),
            'attempt' => $attempt,
            'next_retry_in' => $nextRetryWithinWindow ? $delaySeconds : null,
        ]);

        if ($nextRetryWithinWindow) {
            $this->release($delaySeconds);
        } else {
            throw $exception;
        }
    }

    private function matchesSubmission(Characterization $characterization): bool
    {
        return (int) ($characterization->submission_generation ?? 0) === $this->submissionGeneration;
    }

    protected function calculateDelaySeconds(int $attempt): int
    {
        $base = 5 * 60; // 5 minutes
        $maxDelay = 4 * 60 * 60; // 4 hours

        $delay = $base * (2 ** ($attempt - 1));

        return min($delay, $maxDelay);
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<int, int>
     */
    private function candidateTopicIds(array $response): array
    {
        $topicIds = collect($response['candidate_topics'] ?? [])
            ->filter(fn ($topic) => is_array($topic))
            ->map(fn (array $topic) => (int) Arr::get($topic, 'ar16_topic_id'))
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values();

        if ($topicIds->isEmpty()) {
            return [];
        }

        $validIds = EsrsTopic::whereIn('id', $topicIds->all())
            ->pluck('id')
            ->flip();

        return $topicIds
            ->filter(fn (int $id) => $validIds->has($id))
            ->values()
            ->all();
    }
}
