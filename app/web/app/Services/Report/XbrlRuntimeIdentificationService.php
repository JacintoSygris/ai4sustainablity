<?php

namespace App\Services\Report;

use App\Models\Characterization;
use App\Models\ReportAuditEvent;
use App\Models\ReportingFact;
use App\Models\XbrlIdentificationRun;
use App\Models\XbrlIdentificationReview;
use App\Services\EsrsDatapointCorpusBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class XbrlRuntimeIdentificationService
{
    public function __construct(private XbrlRuntimeClient $client, private XbrlRuntimeContext $contexts) {}

    public static function hash(mixed $value): string
    {
        $canonical = function (mixed $value) use (&$canonical): mixed {
            if (! is_array($value)) return $value;
            if (! array_is_list($value)) ksort($value);
            return array_map($canonical, $value);
        };
        return hash('sha256', json_encode($canonical($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }

    public function history(Characterization $characterization, int $page, int $perPage): array
    {
        $query = XbrlIdentificationRun::where('characterization_id', $characterization->id)->with('reviews')->orderByDesc('id');
        $result = $query->paginate(min(50, $perPage), ['*'], 'page', $page);
        return ['runs' => $result->items(), 'page' => $result->currentPage(), 'last_page' => $result->lastPage()];
    }

    private function linkedFact(Characterization $characterization, ?int $id, bool $lock): ?ReportingFact
    {
        if ($id === null) return null;
        $query = ReportingFact::where('characterization_id', $characterization->id)->whereKey($id);
        return ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
    }

    private function factIndicator(array $indicator, ?ReportingFact $fact, int $invalidStatus = 422): array
    {
        if ($fact === null) return $indicator;
        foreach (['value_type', 'unit'] as $key) {
            $authoritative = $fact->{$key};
            if (isset($indicator[$key]) && $indicator[$key] !== $authoritative) abort($invalidStatus, __('xbrl_runtime.invalid_input'));
            $indicator[$key] = $authoritative;
        }
        return $indicator;
    }

    private function contextHash(Characterization $characterization, array $context, ?string $datapoint, int $invalidStatus = 409): string
    {
        $state = app(ReportSnapshotBuilder::class)->buildCanonicalState($characterization, null, false);
        $corpus = app(EsrsDatapointCorpusBuilder::class)->build($characterization);
        $ids = [];
        foreach ($corpus['blocks'] ?? [] as $section) {
            foreach ($section['datapoints'] ?? [] as $point) $ids[] = $point['id'];
        }
        if ($datapoint !== null && ! in_array($datapoint, $ids, true)) abort($invalidStatus, __('xbrl_runtime.invalid_datapoint'));
        return self::hash([$state['characterization_hash'], $state['facts_hash'], $state['profile_hash'],
            $corpus, $context['identity'], $context['validation_package_sha256']]);
    }

    public function identify(Characterization $characterization, int $userId, array $data): XbrlIdentificationRun
    {
        $fact = $this->linkedFact($characterization, $data['reporting_fact_id'] ?? null, false);
        $indicator = $this->factIndicator($data['indicator'], $fact);
        abort_unless(XbrlRuntimeContext::supportedContext($indicator), 422, __('xbrl_runtime.invalid_input'));
        $datapoint = $data['datapoint_id'] ?? $fact?->datapoint_id;
        if ($fact && $datapoint !== $fact->datapoint_id) abort(422, __('xbrl_runtime.invalid_datapoint'));
        $subject = $fact ? 'fact:'.$fact->id : ($datapoint !== null ? 'datapoint:'.$datapoint : 'custom:'.Str::uuid());
        $context = $this->contexts->capture();
        $hash = $this->contextHash($characterization, $context, $datapoint, 422);
        $input = ['schema_version' => 'xbrl-runtime-identification-v1', 'request_id' => (string) Str::uuid(),
            'taxonomy_profile' => $context['identity']['taxonomy_profile'], 'catalog_sha256' => $context['identity']['catalog_sha256'],
            'indicator' => $indicator];
        $response = $this->client->identify($input, $context); // No database locks during inference.
        return DB::transaction(function () use ($characterization, $userId, $fact, $datapoint, $subject, $input, $response, $hash) {
            $locked = Characterization::forUser($userId)->whereKey($characterization->id)->lockForUpdate()->firstOrFail();
            $lockedFact = $this->linkedFact($locked, $fact?->id, true);
            if ($lockedFact && self::hash($this->factIndicator($input['indicator'], $lockedFact, 409)) !== self::hash($input['indicator'])) abort(409, __('xbrl_runtime.conflict'));
            if (! hash_equals($hash, $this->contextHash($locked, $this->contexts->capture(), $datapoint))) abort(409, __('xbrl_runtime.conflict'));
            $run = XbrlIdentificationRun::create([
                'characterization_id' => $locked->id, 'creator_id' => $userId, 'subject_key' => $subject,
                'reporting_fact_id' => $fact?->id, 'datapoint_id' => $datapoint, 'input_json' => $input,
                'input_sha256' => self::hash($input), 'context_sha256' => $hash, 'response_json' => $response,
                'model_version' => $response['model_version'], 'artifact_sha256' => $response['artifact_sha256'],
                'catalog_sha256' => $response['catalog_sha256'], 'created_at' => now('UTC'),
            ]);
            $this->audit($locked, $userId, 'xbrl_identification_created', ['run_id' => $run->id, 'input_sha256' => $run->input_sha256]);
            return $run->load('reviews');
        });
    }

    public function review(Characterization $characterization, int $userId, int $runId, array $data): XbrlIdentificationRun
    {
        // Scope before configuration reads too: foreign run IDs always return 404.
        $owned = XbrlIdentificationRun::where('characterization_id', $characterization->id)->findOrFail($runId);
        return DB::transaction(function () use ($characterization, $userId, $owned, $data) {
            $locked = Characterization::forUser($userId)->whereKey($characterization->id)->lockForUpdate()->firstOrFail();
            $fact = $this->linkedFact($locked, $owned->reporting_fact_id, true);
            $run = XbrlIdentificationRun::where('characterization_id', $locked->id)->whereKey($owned->id)->lockForUpdate()->firstOrFail();
            $sequence = (int) $run->reviews()->max('sequence');
            if ($sequence !== $data['expected_sequence']) abort(409, __('xbrl_runtime.conflict'));
            $candidate = collect($run->response_json['candidates'])->firstWhere('qname', $data['candidate_qname']);
            if (! $candidate) abort(422, __('xbrl_runtime.invalid_candidate'));
            if ($data['decision'] === 'accepted') {
                // Recheck linked metadata for runs persisted before authoritative derivation too.
                $indicator = $this->factIndicator($run->input_json['indicator'], $fact);
                if ($fact && self::hash($indicator) !== self::hash($run->input_json['indicator'])) abort(409, __('xbrl_runtime.conflict'));
                $context = $this->contexts->capture();
                if (! hash_equals($run->context_sha256, $this->contextHash($locked, $context, $run->datapoint_id))) abort(409, __('xbrl_runtime.conflict'));
                $validated = $this->client->validateResponse($run->response_json['service_response'] ?? $run->response_json, $run->input_json, $context);
                $candidate = collect($validated['candidates'])->firstWhere('qname', $data['candidate_qname']);
                if ($validated['abstained'] || ! in_array($candidate['validation_status'], ['map_only', 'verified_taxonomy'], true)) abort(422, __('xbrl_runtime.ineligible'));
                $runs = XbrlIdentificationRun::where('characterization_id', $locked->id)->where('subject_key', $run->subject_key)->with('reviews')->get();
                foreach ($runs as $other) {
                    $latest = $other->reviews->groupBy('candidate_qname')->map(fn ($items) => $items->last());
                    if ($latest->contains(fn ($review) => $review->decision === 'accepted')) abort(409, __('xbrl_runtime.reject_previous'));
                }
            }
            // Rejections can revoke obsolete acceptances even after artifact/catalog rollback.
            XbrlIdentificationReview::create([
                'run_id' => $run->id, 'candidate_qname' => $data['candidate_qname'], 'decision' => $data['decision'],
                'reviewer_id' => $userId, 'created_at' => now('UTC'), 'reason' => $data['reason'], 'sequence' => $sequence + 1,
            ]);
            $this->audit($locked, $userId, 'xbrl_identification_reviewed', ['run_id' => $run->id, 'sequence' => $sequence + 1, 'decision' => $data['decision']]);
            return $run->load('reviews');
        });
    }

    public function freezeEvidence(Characterization $characterization): array
    {
        $runs = XbrlIdentificationRun::where('characterization_id', $characterization->id)->whereHas('reviews')->with('reviews')->orderBy('id')->limit(1001)->get();
        if ($runs->count() > 1000) abort(409, __('xbrl_runtime.evidence_limit'));
        $records = $runs->map(fn ($run) => $run->toArray())->all();
        $evidence = ['schema_version' => 'xbrl-identifications-evidence-v1', 'records' => $records, 'sha256' => self::hash($records)];
        XbrlRuntimeEvidence::validate($evidence, $characterization->id);
        return $evidence;
    }

    private function audit(Characterization $characterization, int $userId, string $event, array $payload): void
    {
        ReportAuditEvent::create(['user_id' => $userId, 'characterization_id' => $characterization->id, 'event_type' => $event, 'payload' => $payload]);
    }
}
