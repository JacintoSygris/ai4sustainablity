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

    public function __construct(public Characterization $characterization)
    {
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
