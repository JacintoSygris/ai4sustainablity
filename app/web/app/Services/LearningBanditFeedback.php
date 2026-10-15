<?php

namespace App\Services;

use App\Http\Controllers\Api\MaterialityConfirmationController;
use App\Models\Characterization;
use App\Models\EsrsTopic;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class LearningBanditFeedback
{
    public static function enabled(): bool
    {
        return ConsentLearningAuthorizationAuthority::enabled()
            && in_array(config('learning_activation.bandit_mode'), ['active', 'shadow'], true);
    }

    public function stream(Characterization $row): string
    {
        return hash_hmac('sha256', 'bandit:'.$row->id, (string) config('app.key'));
    }

    public function revision(Characterization $row): string
    {
        $topics = $row->esrs_topic_ids ?? [];
        sort($topics, SORT_NUMERIC);
        $basisHash = hash('sha256', json_encode([
            \App\Support\Wp13TrainingSourceFingerprint::hash($row), $topics,
            $row->result_data['mapping_metadata']['python']['feedback_bandit']['basis'] ?? null,
            $row->result_data['raw_prediction'] ?? null,
        ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        return $row->submission_generation.':'.($row->form_data['materiality_confirmation']['revision'] ?? 0).':'.$basisHash;
    }

    /** Pure contract projection; only closed singleton mappings can carry labels. */
    public function project(array $state, array $basis, array $mapping): array
    {
        if (($state['is_confirmed'] ?? false) !== true || ($state['is_stale'] ?? true) !== false
            || ($state['confirmation_status'] ?? null) !== 'confirmed'
            || ($basis['encoder'] ?? null) !== 'p5-hash-tanh-v1'
            || ! is_array($basis['context'] ?? null) || count($basis['context']) !== 16
            || ! array_is_list($basis['context'])) { return []; }
        foreach (['model_sha256', 'feature_schema_sha256'] as $field) {
            if (! is_string($basis[$field] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $basis[$field])) { return []; }
        }
        foreach ($basis['context'] as $value) {
            if ((! is_float($value) && ! is_int($value)) || ! is_finite((float) $value) || abs($value) > 1) { return []; }
        }
        $byTopic = [];
        foreach ($mapping['keys'] ?? [] as $entry) {
            foreach ($entry['ar16_topic_ids'] ?? [] as $id) { $byTopic[$id][] = $entry; }
        }
        $events = [];
        foreach ($state['learning_topic_labels'] ?? [] as $id => $label) {
            $entries = $byTopic[$id] ?? [];
            if (count($entries) !== 1 || count($entries[0]['ar16_topic_ids'] ?? []) !== 1
                || ($entries[0]['status'] ?? null) !== 'approved' || ! in_array($label, [0, 1], true)) { continue; }
            $guided = $state['confirmation']['guided_answers'][$id] ?? null;
            if ($guided !== null && (($guided['revisar'] ?? true) !== false
                || ($guided['final_result'] ?? null) !== ($label === 1 ? 'material' : 'no_material'))) { continue; }
            $key = $entries[0]['python_esrs_key'];
            $score = $basis['base_scores'][$key] ?? null;
            $proposed = in_array((int) $id, $state['p6_snapshot']['topic_ids'] ?? [], true);
            $accepted = in_array((int) $id, $state['confirmed_topic_ids'] ?? [], true);
            if ($accepted !== ($label === 1) || (! $proposed && ! $accepted)
                || (! is_int($score) && ! is_float($score)) || ! is_finite((float) $score) || $score < 0 || $score > 1) { continue; }
            $events[$key] = ['version' => 'p8-bandit-event-v1', 'profile' => 'new_format_732_v1_gpt41',
                'model_sha256' => $basis['model_sha256'], 'feature_schema_sha256' => $basis['feature_schema_sha256'],
                'encoder' => $basis['encoder'], 'topic' => $key, 'context' => $basis['context'],
                'base_score' => $score, 'proposed' => $proposed, 'accepted' => $accepted];
        }
        ksort($events);
        return $events;
    }

    /** Inside P8 transaction. Savepoint failure must not break a user's confirmation. */
    public function capture(Characterization $row): void
    {
        if (! self::enabled() || ! Schema::hasTable('learning_bandit_events')) { return; }
        try {
            DB::transaction(function () use ($row) {
                $user = User::whereKey($row->user_id)->lockForUpdate()->first();
                if ($user === null || ! app(ConsentLearningAuthorizationAuthority::class)->permits($user)) { return; }
                $seal = $row->form_data['materiality_confirmation']['training_source'] ?? [];
                if (($seal['subject'] ?? null) !== $user->learning_subject) { return; }
                // This validates P5 fingerprint, generation, frozen P6 and guided consistency.
                app(Wp13P8FeedbackExport::class)->export(collect([$row]), EsrsTopic::orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all(), true);
                $state = app(MaterialityConfirmationController::class)->confirmationState($row, app(EsrsDatapointCorpusBuilder::class));
                $basis = $row->result_data['mapping_metadata']['python']['feedback_bandit']['basis'] ?? [];
                $mapping = json_decode(file_get_contents(config('services.characterization.prediction_mapping_path')), true, 64, JSON_THROW_ON_ERROR);
                $stream = $this->stream($row);
                $revision = $this->revision($row);
                DB::table('learning_bandit_events')->where('stream', $stream)->where('revision', '!=', $revision)->delete();
                foreach ($this->project($state, $basis, $mapping) as $topic => $event) {
                    $raw = $row->result_data['raw_prediction'][$topic] ?? null;
                    if (! in_array($raw, [0, 1], true) || ($raw === 1) !== $event['proposed']) { continue; }
                    DB::table('learning_bandit_events')->insertOrIgnore([
                        'source_key' => hash('sha256', $user->learning_subject.':'.$stream.':'.$revision.':'.$topic),
                        'subject' => $user->learning_subject, 'stream' => $stream, 'revision' => $revision,
                        'event' => json_encode($event, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), 'created_at' => now(),
                    ]);
                }
            });
        } catch (\Throwable) {
            \Illuminate\Support\Facades\Log::warning('learning.bandit_capture_failed_closed');
        }
    }

    /** Current revisions are a short serving lease, not historical consent. */
    public function revisions(): array
    {
        if (! Schema::hasTable('learning_bandit_events')) { return []; }
        $out = [];
        Characterization::whereIn('user_id', User::whereNotNull('learning_consent_at')->select('id'))
            ->orderBy('id')->chunkById(100, function ($rows) use (&$out) {
                foreach ($rows as $row) {
                    if ($row->status === Characterization::STATUS_COMPLETED) { $out[$this->stream($row)] = $this->revision($row); }
                }
            });
        return $out;
    }

    public function ledger(): array
    {
        $allowed = User::whereNotNull('learning_consent_at')->get()->filter(fn ($u) => app(ConsentLearningAuthorizationAuthority::class)->permits($u))->pluck('learning_subject')->all();
        $revisions = $this->revisions();
        DB::table('learning_bandit_events')->where('created_at', '<', now()->subDays(config('learning_activation.retention_days')))->delete();
        DB::table('learning_bandit_events')->whereNotIn('subject', $allowed)->delete();
        $rows = DB::table('learning_bandit_events')->orderBy('id')->limit(config('learning_activation.max_events') + 1)->get();
        if ($rows->count() > config('learning_activation.max_events')) { throw new \RuntimeException('learning.event_limit'); }
        $out = [];
        foreach ($rows as $row) {
            if (($revisions[$row->stream] ?? null) !== $row->revision) {
                DB::table('learning_bandit_events')->where('id', $row->id)->delete();
                continue;
            }
            $out[] = ['id' => $row->source_key, 'subject' => $row->subject, 'stream' => $row->stream,
                'revision' => $row->revision, 'expires_at' => \Illuminate\Support\Carbon::parse($row->created_at)->addDays(config('learning_activation.retention_days'))->timestamp, 'event' => json_decode($row->event, true, 32, JSON_THROW_ON_ERROR)];
        }
        return $out;
    }
}
