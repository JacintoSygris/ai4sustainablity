<?php

namespace App\Services;

use App\Models\Characterization;
use App\Models\EsrsTopic;
use App\Models\User;
use App\Support\Wp13TrainingSourceFingerprint;
use Illuminate\Support\Facades\DB;

final class LearningFeedbackDataset
{
    public function __construct(private readonly ConsentLearningAuthorizationAuthority $authority) {}

    /** Called inside the existing account/characterization write transaction. */
    public function seal(Characterization $row, array $form, string $stage): array
    {
        $user = User::find($row->user_id);
        if ($user === null || ! $this->authority->permits($user)) {
            return $form;
        }
        $seal = [
            'schema_version' => Wp13TrainingSourceFingerprint::SCHEMA_VERSION,
            'submission_generation' => $row->submission_generation,
            'p5_input_sha256' => Wp13TrainingSourceFingerprint::hash($row),
            'subject' => $user->learning_subject,
            'consent_version' => $user->learning_consent_version,
            'p8_revision' => $form['materiality_confirmation']['revision'] ?? null,
        ];
        if ($stage === 'esrs_datapoint_responses') {
            $seal['feedback_revision'] = $form[$stage]['revision'] ?? null;
        }
        $form[$stage]['training_source'] = $seal;
        return $form;
    }

    public int $reservedExclusions = 0;

    public function export(?array $reservedGroups = null): array
    {
        if (! ConsentLearningAuthorizationAuthority::enabled()) {
            throw new \DomainException('learning.disabled');
        }
        $this->reservedExclusions = 0;
        $reservedGroups = config('learning_engine.gate_metrics_enabled')
            ? ($reservedGroups ?? app(LearningReservedGroups::class)->snapshot()['groups']) : [];
        $rows = [];
        $rejected = 0;
        $ids = EsrsTopic::orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        User::whereNotNull('learning_consent_at')->orderBy('id')->chunkById(100, function ($users) use (&$rows, &$rejected, $ids, $reservedGroups) {
            foreach ($users as $candidate) {
                if (count($rows) >= config('learning_engine.max_rows')) {
                    throw new \DomainException('learning.dataset_limit');
                }
                $record = DB::transaction(function () use ($candidate, $ids, $reservedGroups) {
                    $user = User::whereKey($candidate->id)->lockForUpdate()->first();
                    if ($user === null || ! $this->authority->permits($user)) { return null; }
                    $membership = $this->authority->membership($user);
                    if ($membership === null) { return null; }
                    if (in_array($membership->group->company_group_key, $reservedGroups, true)) {
                        return 'reserved';
                    }
                    $row = Characterization::where('user_id', $user->id)->lockForUpdate()->first();
                    if ($row === null) { return null; }
                    $form = $row->form_data;
                    $seal = $form['materiality_confirmation']['training_source'] ?? [];
                    if (($seal['subject'] ?? null) !== $user->learning_subject
                        || ($seal['consent_version'] ?? null) !== $user->learning_consent_version
                        || ($seal['p8_revision'] ?? null) !== ($form['materiality_confirmation']['revision'] ?? null)) { return null; }
                    try {
                        $export = app(Wp13P8FeedbackExport::class)->export(collect([$row]), $ids, true)[0];
                    } catch (\InvalidArgumentException) { return null; }
                    $p9 = [];
                    $p9State = $form['esrs_datapoint_responses'] ?? [];
                    $p9Seal = $p9State['training_source'] ?? [];
                    $p9Revision = $p9Seal['feedback_revision'] ?? null;
                    unset($p9Seal['feedback_revision']);
                    if ($p9Seal === $seal && is_int($p9Revision) && $p9Revision === ($p9State['revision'] ?? null)
                        && isset($p9State['learning_feedback'])) {
                        try {
                            $feedback = app(EsrsDatapointResponseState::class)->validateLearningFeedback(
                                $p9State['learning_feedback'], app(EsrsDatapointCorpusBuilder::class)->build($row));
                            // Deliberately exclude notes and reason codes (user text).
                            foreach ($feedback['decisions'] as $decision) {
                                $p9[] = array_intersect_key($decision, array_flip(['datapoint_id', 'relevant', 'selected_to_answer']));
                            }
                        } catch (\Illuminate\Validation\ValidationException) { /* Stale P9 is not a label. */ }
                    }
                    return [
                        'subject' => $user->learning_subject,
                        'group' => $membership->group->company_group_key,
                        'authority_binding' => $this->authority->binding($user, $membership),
                        'synthetic' => $user->learning_synthetic,
                        'consent_version' => $user->learning_consent_version,
                        'p5_sha256' => $export['p5_input_sha256'],
                        'generation' => $export['submission_generation'],
                        'p8_revision' => $export['p8_revision'],
                        'p9_revision' => $p9 === [] ? null : ($p9State['revision'] ?? null),
                        'input' => app(ApiCharacterizationGateway::class)->learningPayload($row),
                        'topics' => (object) $export['topics'], 'p9' => $p9,
                    ];
                }, 3);
                if ($record === 'reserved') { $this->reservedExclusions++; continue; }
                if ($record === null) { $rejected++; } else { $rows[] = $record; }
            }
        });
        return ['schema' => 'learning-feedback-production-v1', 'rows' => $rows, 'rejected_rows' => $rejected];
    }
}
