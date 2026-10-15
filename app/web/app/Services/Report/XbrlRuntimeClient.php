<?php

namespace App\Services\Report;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class XbrlRuntimeClient
{
    public function identify(array $input, array $context): array
    {
        $base = rtrim((string) config('services.characterization.api.base_url'), '/');
        $url = parse_url($base);
        $token = (string) config('services.characterization.api.token');
        if (! is_array($url) || ($url['scheme'] ?? '') !== 'http'
            || ! in_array($url['host'] ?? '', ['127.0.0.1', '[::1]'], true)
            || isset($url['user'], $url['pass']) || isset($url['user']) || isset($url['query']) || isset($url['fragment'])
            || ! in_array($url['path'] ?? '', ['', '/'], true) || $token === '') {
            throw new RuntimeException('xbrl_runtime_unconfigured');
        }
        try {
            $sink = \GuzzleHttp\Psr7\Utils::streamFor('');
            $boundedSink = \GuzzleHttp\Psr7\FnStream::decorate($sink, ['write' => function (string $bytes) use ($sink) {
                if ($sink->getSize() + strlen($bytes) > 32768) throw new RuntimeException('xbrl_runtime_response_size');
                return $sink->write($bytes);
            }]);
            // Stream to a bounded memory sink: stop the transfer before accumulating a large body.
            $response = Http::withToken($token)->acceptJson()->connectTimeout(2)->timeout(10)
                ->withoutRedirecting()->withOptions([
                    'proxy' => '', 'decode_content' => false, 'sink' => $boundedSink,
                    'on_headers' => function ($response) {
                        $length = $response->getHeaderLine('Content-Length');
                        if ($length !== '' && (! ctype_digit($length) || (int) $length > 32768)) {
                            throw new RuntimeException('xbrl_runtime_response_size');
                        }
                    },
                    'progress' => function ($total, $downloaded) {
                        if ($downloaded > 32768) throw new RuntimeException('xbrl_runtime_response_size');
                    },
                ])->post($base.'/xbrl/concept-candidates', $input);
            if (! $response->successful() || strlen($response->body()) > 32768) {
                throw new RuntimeException('xbrl_runtime_response_invalid');
            }
            $data = json_decode($response->body(), true, 32, JSON_THROW_ON_ERROR);
            return $this->validateResponse($data, $input, $context);
        } catch (Throwable) {
            // Never propagate transport errors, URLs, bearer tokens or response bodies.
            throw new RuntimeException('xbrl_runtime_unavailable');
        }
    }

    public function validateResponse(mixed $data, array $input, array $context): array
    {
        $keys = ['schema_version', 'request_id', 'model_version', 'artifact_sha256', 'catalog_sha256',
            'concept_map_sha256', 'taxonomy_profile', 'package_sha256', 'candidates', 'abstained', 'reason_codes'];
        if (! is_array($data) || array_diff(array_keys($data), $keys) !== [] || count($data) !== count($keys)) {
            throw new RuntimeException('xbrl_runtime_response_invalid');
        }
        foreach (['schema_version', 'request_id', 'taxonomy_profile', 'catalog_sha256'] as $key) {
            if (($data[$key] ?? null) !== $input[$key]) throw new RuntimeException('xbrl_runtime_identity_mismatch');
        }
        foreach (['model_version', 'artifact_sha256', 'catalog_sha256', 'concept_map_sha256', 'package_sha256'] as $key) {
            if (($data[$key] ?? null) !== $context['identity'][$key]) throw new RuntimeException('xbrl_runtime_identity_mismatch');
        }
        $reasons = ['unsupported_context', 'unsupported_language', 'zero_textual_overlap', 'no_compatible_candidates', 'below_threshold', 'insufficient_margin'];
        if (! is_bool($data['abstained']) || ! is_array($data['reason_codes']) || ! array_is_list($data['reason_codes'])
            || count($data['reason_codes']) > 1 || array_diff($data['reason_codes'], $reasons) !== []
            || $data['abstained'] !== ($data['reason_codes'] !== [])
            || ! is_array($data['candidates']) || ! array_is_list($data['candidates']) || count($data['candidates']) > 5
            || (! $data['abstained'] && $data['candidates'] === [])) {
            throw new RuntimeException('xbrl_runtime_response_invalid');
        }
        $serviceResponse = $data;
        $seen = [];
        $previous = 1.0;
        $previousQName = null;
        foreach ($data['candidates'] as $i => &$candidate) {
            if (! is_array($candidate) || count($candidate) !== 5
                || array_diff(array_keys($candidate), ['qname', 'rank', 'score', 'validation_status', 'validation_reasons']) !== []) {
                throw new RuntimeException('xbrl_runtime_candidate_invalid');
            }
            $qname = $candidate['qname'] ?? null;
            $score = $candidate['score'] ?? null;
            if (! is_string($qname) || ! isset($context['inventory'][$qname]) || isset($seen[$qname])
                || ($candidate['rank'] ?? null) !== $i + 1 || (! is_int($score) && ! is_float($score))
                || ! is_finite((float) $score) || $score < 0 || $score > $previous
                || ($previousQName !== null && $score == $previous && strcmp($qname, $previousQName) < 0)
                || ! in_array($candidate['validation_status'], ['map_only', 'unverified', 'verified_taxonomy'], true)
                || ! is_array($candidate['validation_reasons']) || ! array_is_list($candidate['validation_reasons'])
                || count($candidate['validation_reasons']) > 5) {
                throw new RuntimeException('xbrl_runtime_candidate_invalid');
            }
            foreach ($candidate['validation_reasons'] as $reason) {
                if (! is_string($reason) || ! preg_match('/\A[a-z_]{1,64}\z/', $reason)) throw new RuntimeException('xbrl_runtime_candidate_invalid');
            }
            $seen[$qname] = true;
            $previous = $score;
            $previousQName = $qname;
            // Independent local validation overrides the service's assessment.
            [$status, $localReasons] = XbrlRuntimeContext::compatibility($context['metadata'][$qname] ?? null, $input['indicator']);
            if ($context['verified_taxonomy'] && ! isset($context['metadata'][$qname])) {
                $status = 'incompatible';
                $localReasons = ['concept_missing'];
            }
            $candidate['validation_status'] = $status;
            $candidate['validation_reasons'] = $localReasons;
        }
        unset($candidate);
        if (! $data['abstained']) {
            $candidates = $data['candidates'];
            if (! in_array($input['indicator']['language'], $context['languages'], true)
                || $candidates[0]['score'] < $context['thresholds']['min_score']
                || (count($candidates) > 1 && $candidates[0]['score'] - $candidates[1]['score'] < $context['thresholds']['min_margin'])) {
                throw new RuntimeException('xbrl_runtime_abstention_mismatch');
            }
            if (! XbrlRuntimeContext::supportedContext($input['indicator'])) {
                throw new RuntimeException('xbrl_runtime_abstention_mismatch');
            }
            // Local metadata can remove the service's leading candidates. Reapply
            // the artifact thresholds to the surviving ranking, without rewriting
            // the original candidates, ranks or private service response.
            $compatible = array_values(array_filter($candidates,
                fn ($candidate) => $candidate['validation_status'] !== 'incompatible'));
            $localReason = null;
            if ($compatible === []) {
                $localReason = 'no_compatible_candidates';
            } elseif ($compatible[0]['score'] < $context['thresholds']['min_score']) {
                $localReason = 'below_threshold';
            } elseif (count($compatible) > 1
                && $compatible[0]['score'] - $compatible[1]['score'] < $context['thresholds']['min_margin']) {
                $localReason = 'insufficient_margin';
            }
            if ($localReason !== null) {
                $data['abstained'] = true;
                $data['reason_codes'] = [$localReason];
            }
        }
        $data['service_response'] = $serviceResponse;
        $data['validation_context'] = ['mode' => $context['verified_taxonomy'] ? 'verified_taxonomy' : 'map_only',
            'package_sha256' => $context['validation_package_sha256'], 'taxonomy_profile' => $context['identity']['taxonomy_profile']];
        return $data;
    }
}
