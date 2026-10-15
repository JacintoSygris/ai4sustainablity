<?php

namespace App\Services\Report;

use DomainException;

/** Frozen evidence validator: intentionally no model, config, database or taxonomy reads. */
class XbrlRuntimeEvidence
{
    public static function validate(mixed $evidence, int $characterizationId): void
    {
        $valid = is_array($evidence) && count($evidence) === 3
            && ($evidence['schema_version'] ?? null) === 'xbrl-identifications-evidence-v1'
            && is_array($evidence['records'] ?? null) && array_is_list($evidence['records'])
            && count($evidence['records']) <= 1000
            && ($evidence['sha256'] ?? null) === XbrlRuntimeIdentificationService::hash($evidence['records']);
        if (! $valid) throw new DomainException('report_snapshot_xbrl_identifications_invalid');
        $seen = [];
        foreach ($evidence['records'] as $run) {
            if (! is_array($run) || ! is_int($run['id'] ?? null) || isset($seen[$run['id']])
                || ($run['characterization_id'] ?? null) !== $characterizationId
                || ! is_array($run['input_json'] ?? null) || ! is_array($run['response_json'] ?? null)
                || ($run['input_sha256'] ?? null) !== XbrlRuntimeIdentificationService::hash($run['input_json'])
                || ! is_string($run['subject_key'] ?? null) || ! preg_match('/\A(fact:[1-9][0-9]*|datapoint:.{1,255}|custom:[a-f0-9-]{36})\z/', $run['subject_key'])
                || ! is_array($run['reviews'] ?? null) || ! array_is_list($run['reviews']) || $run['reviews'] === []) {
                throw new DomainException('report_snapshot_xbrl_identifications_invalid');
            }
            $allowedRunKeys = ['id', 'characterization_id', 'creator_id', 'subject_key', 'reporting_fact_id', 'datapoint_id',
                'input_json', 'input_sha256', 'context_sha256', 'response_json', 'model_version', 'artifact_sha256', 'catalog_sha256', 'created_at', 'reviews'];
            $input = $run['input_json'];
            $indicator = $input['indicator'] ?? null;
            if (array_diff(array_keys($run), $allowedRunKeys) !== [] || ! is_int($run['creator_id'] ?? null)
                || array_diff(array_keys($input), ['schema_version', 'request_id', 'taxonomy_profile', 'catalog_sha256', 'indicator']) !== []
                || ! is_string($input['request_id'] ?? null) || ! \Illuminate\Support\Str::isUuid($input['request_id'])
                || ! is_array($indicator) || array_diff(array_keys($indicator), ['label', 'description', 'language', 'standard', 'dr', 'value_type', 'unit', 'period_type']) !== []
                || ! is_string($indicator['label'] ?? null) || mb_strlen($indicator['label']) > 300
                || ! is_string($indicator['description'] ?? null) || mb_strlen($indicator['description']) > 4000
                || trim($indicator['label'].$indicator['description']) === ''
                || ! is_string($indicator['language'] ?? null) || ! preg_match('/\A[a-zA-Z-]{2,16}\z/', $indicator['language'])) {
                throw new DomainException('report_snapshot_xbrl_identifications_invalid');
            }
            $seen[$run['id']] = true;
            foreach (['context_sha256', 'artifact_sha256', 'catalog_sha256'] as $field) {
                if (! is_string($run[$field] ?? null) || ! preg_match('/\A[a-f0-9]{64}\z/', $run[$field])) throw new DomainException('report_snapshot_xbrl_identifications_invalid');
            }
            $response = $run['response_json'];
            foreach (['artifact_sha256', 'catalog_sha256', 'model_version'] as $field) {
                if (($response[$field] ?? null) !== ($run[$field] ?? null)) throw new DomainException('report_snapshot_xbrl_identifications_invalid');
            }
            if (($run['input_json']['schema_version'] ?? null) !== 'xbrl-runtime-identification-v1'
                || ($response['schema_version'] ?? null) !== 'xbrl-runtime-identification-v1'
                || ($response['request_id'] ?? null) !== ($run['input_json']['request_id'] ?? null)
                || ! is_bool($response['abstained'] ?? null) || ! is_array($response['candidates'] ?? null)
                || ! array_is_list($response['candidates']) || count($response['candidates']) > 5) throw new DomainException('report_snapshot_xbrl_identifications_invalid');
            if (isset($response['validation_context'])) {
                $context = $response['validation_context'];
                if (! is_array($context) || count($context) !== 3 || ! in_array($context['mode'] ?? null, ['map_only', 'verified_taxonomy'], true)
                    || ($context['taxonomy_profile'] ?? null) !== ($response['taxonomy_profile'] ?? null)
                    || ($context['mode'] === 'verified_taxonomy' && (! is_string($context['package_sha256'] ?? null) || ! preg_match('/\A[a-f0-9]{64}\z/', $context['package_sha256'])))
                    || ($context['mode'] === 'map_only' && ($context['package_sha256'] ?? null) !== null)) throw new DomainException('report_snapshot_xbrl_identifications_invalid');
            }
            $candidates = [];
            foreach ($response['candidates'] as $index => $candidate) {
                $qname = $candidate['qname'] ?? null;
                $score = $candidate['score'] ?? null;
                if (! is_string($qname) || ! preg_match('/\Aesrs:[A-Za-z_][A-Za-z0-9_]*\z/', $qname) || isset($candidates[$qname])
                    || ($candidate['rank'] ?? null) !== $index + 1 || (! is_int($score) && ! is_float($score))
                    || ! is_finite((float) $score) || $score < 0 || $score > 1
                    || ! in_array($candidate['validation_status'] ?? null, ['map_only', 'verified_taxonomy', 'unverified', 'incompatible'], true)) throw new DomainException('report_snapshot_xbrl_identifications_invalid');
                $candidates[$qname] = $candidate;
            }
            foreach ($run['reviews'] as $index => $review) {
                if (($review['run_id'] ?? null) !== $run['id'] || ($review['sequence'] ?? null) !== $index + 1
                    || ! isset($candidates[$review['candidate_qname'] ?? ''])
                    || ! in_array($review['decision'] ?? null, ['accepted', 'rejected'], true)
                    || ! is_int($review['reviewer_id'] ?? null) || ! is_string($review['created_at'] ?? null)
                    || ! preg_match('/\A\d{4}-\d{2}-\d{2}T.*Z\z/', $review['created_at'])
                    || ! is_string($review['reason'] ?? null) || trim($review['reason']) === '' || mb_strlen($review['reason']) > 1000
                    || ($review['decision'] === 'accepted' && ($response['abstained'] || ! in_array($candidates[$review['candidate_qname']]['validation_status'], ['map_only', 'verified_taxonomy'], true)))) {
                    throw new DomainException('report_snapshot_xbrl_identifications_invalid');
                }
            }
        }
    }
}
