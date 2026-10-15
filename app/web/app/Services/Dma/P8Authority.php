<?php

namespace App\Services\Dma;

use App\Models\Characterization;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Arr;

final class P8Authority
{
    public static function enabled(): bool
    {
        return (bool) config('services.dma_import.enabled', false)
            && (bool) config('services.dma_import.iro_traceability_enabled', false);
    }

    public static function reject(string $code): never
    {
        throw new HttpResponseException(response()->json([
            'code' => $code, 'message' => __($code === 'p9_mapping_unavailable'
                ? 'The approved normative mapping is unavailable. Contact the administrator.'
                : 'Confirm double materiality again'),
        ], 409));
    }

    public function assertCurrent(Characterization $characterization): array
    {
        $p8 = Arr::get($characterization->form_data ?? [], 'materiality_confirmation', []);
        if (! is_array($p8) || ! is_array($p8['confirmed_topic_ids'] ?? null)
            || ! is_int($p8['revision'] ?? null) || $p8['revision'] < 1
            || ! is_string($p8['confirmed_at'] ?? null) || strtotime($p8['confirmed_at']) === false) {
            self::reject('p8_confirmation_required');
        }
        $current = $characterization->esrs_topic_ids ?? [];
        $previous = $p8['p6_snapshot']['topic_ids'] ?? [];
        if (! is_array($current) || ! is_array($previous)) { self::reject('p8_confirmation_stale'); }
        sort($current); sort($previous);
        if ($characterization->status !== Characterization::STATUS_COMPLETED || $current !== $previous
            || ! is_string($p8['p6_snapshot']['captured_at'] ?? null)
            || strtotime($p8['p6_snapshot']['captured_at']) === false
            || ! array_key_exists('submission_generation', $p8['p6_snapshot'] ?? [])
            || $p8['p6_snapshot']['submission_generation'] !== (int) $characterization->submission_generation) {
            self::reject('p8_confirmation_stale');
        }
        foreach ($p8['dma_import_evidence'] ?? [] as $evidence) {
            $rows = array_column($evidence['iro_rows'] ?? [], 'iro_id');
            $reviews = $evidence['iro_reviews'] ?? [];
            $reviewIds = array_column($reviews, 'iro_id');
            sort($rows); sort($reviewIds);
            if (count($rows) !== count($evidence['iro_rows'] ?? []) || $rows !== $reviewIds
                || count($reviewIds) !== count(array_unique($reviewIds))) {
                self::reject('iro_review_required');
            }
            foreach ($reviews as $review) {
                if (($review['p8_revision'] ?? null) !== $p8['revision']
                    || ($review['rules_version'] ?? null) !== IroContextProjector::VERSION) {
                    self::reject('iro_review_required');
                }
            }
        }
        return $p8;
    }

    public function digest(Characterization $characterization, array $assets): string
    {
        $p8 = $this->assertCurrent($characterization);
        // Operational scope does not depend on consent or learning export seals.
        unset($p8['training_source'], $p8['universe_attestation']);
        return self::hash(['p8' => $p8, 'assets' => $assets]);
    }

    /** Canonical hashing is independent of JSON object insertion order and locale. */
    public static function hash(mixed $value): string
    {
        $canonical = function (mixed $item) use (&$canonical): mixed {
            if (! is_array($item)) { return $item; }
            if (! array_is_list($item)) { ksort($item); }
            return array_map($canonical, $item);
        };
        return hash('sha256', json_encode($canonical($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }
}
