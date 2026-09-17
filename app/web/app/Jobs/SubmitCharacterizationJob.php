<?php

namespace App\Jobs;

use App\Events\CharacterizationStatusUpdated;
use App\Models\Characterization;
use App\Models\EsrsTopic;
use App\Services\Contracts\CharacterizationGateway;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class SubmitCharacterizationJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Characterization $characterization) {}

    public function handle(CharacterizationGateway $gateway): void
    {
        if (! $this->claimForProcessing()) {
            return;
        }

        try {
            $response = $gateway->submit($this->characterization->fresh());

            $attributes = [
                'result_data' => $response,
                'completed_at' => now(),
                'next_retry_at' => null,
            ];

            if (array_key_exists('candidate_topics', $response)) {
                $topicIds = $this->candidateTopicIds($response);
                $formData = $this->characterization->form_data ?? [];
                Arr::set($formData, 'esg_focus.topic_ids', $topicIds);

                $attributes['esrs_topic_ids'] = $topicIds;
                $attributes['form_data'] = $formData;
            }

            $this->characterization->updateStatus(Characterization::STATUS_COMPLETED, $attributes);

            Log::info('Characterization completed', [
                'characterization_id' => $this->characterization->id,
            ]);
        } catch (\Throwable $exception) {
            $this->handleFailure($exception);
        }
    }

    private function claimForProcessing(): bool
    {
        $now = now();

        $claimed = Characterization::query()
            ->whereKey($this->characterization->id)
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

    protected function handleFailure(\Throwable $exception): void
    {
        $attempt = ($this->characterization->retry_count ?? 0) + 1;
        $delaySeconds = $this->calculateDelaySeconds($attempt);
        $submittedAt = $this->characterization->submitted_at ?? now();
        $elapsed = $submittedAt->diffInSeconds(now());
        $maxWindow = 72 * 60 * 60; // 72 hours
        $nextRetryWithinWindow = ($elapsed + $delaySeconds) <= $maxWindow;

        $attributes = [
            'retry_count' => $attempt,
            'last_error' => $exception->getMessage(),
            'last_job_attempted_at' => now(),
        ];

        if ($nextRetryWithinWindow) {
            $attributes['next_retry_at'] = now()->addSeconds($delaySeconds);
            $this->characterization->updateStatus(Characterization::STATUS_WAITING, $attributes);
        } else {
            $attributes['next_retry_at'] = null;
            $this->characterization->updateStatus(Characterization::STATUS_TIMED_OUT, $attributes);
        }

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
