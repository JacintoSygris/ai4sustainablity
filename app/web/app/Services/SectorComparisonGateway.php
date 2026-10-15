<?php

namespace App\Services;

use App\Models\Characterization;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/** Read-only adapter. No identifiers, products or user content leave Laravel. */
class SectorComparisonGateway
{
    private const VERSION = 'sector-comparison-v1';
    private const TAXONOMY = 'NACE Rev.2.1 / CNAE-2025';
    private const MAX_RESPONSE = 262144;

    public static function sections(Characterization $characterization): array
    {
        $code = $characterization->nace_code;
        $section = is_string($code) ? strtoupper(substr($code, 0, 1)) : '';

        return preg_match('/^[A-V]$/', $section) === 1 ? [$section] : [];
    }

    public static function fingerprint(Characterization $characterization): string
    {
        return hash('sha256', json_encode([
            'nace_code' => $characterization->nace_code,
            'sections' => self::sections($characterization),
            'taxonomy' => self::TAXONOMY,
        ], JSON_THROW_ON_ERROR));
    }

    public function compare(Characterization $characterization, array $topicIds): array
    {
        if (! config('services.sector_comparison.enabled', false)) {
            throw new RuntimeException('sector_comparison_unavailable');
        }
        $topics = array_map('strval', $topicIds);
        $sections = self::sections($characterization);
        $this->ensure(count($topics) >= 1 && count($topics) <= 89 && count(array_unique($topics)) === count($topics));
        foreach ($topics as $topic) {
            $this->ensure(preg_match('/^(?:[1-9]|[1-8][0-9])$/', $topic) === 1);
        }
        $url = config('services.characterization.api.base_url');
        $token = config('services.characterization.api.token');
        $this->ensure(is_string($url) && $url !== '' && is_string($token) && $token !== '');
        // Buffer during the cURL transfer: its total deadline covers headers and
        // every body chunk, while the sink refuses bytes beyond the response cap.
        $buffer = Utils::streamFor('');
        $received = 0;
        $oversized = false;
        $sink = FnStream::decorate($buffer, [
            'write' => static function (string $chunk) use ($buffer, &$received, &$oversized): int {
                if (strlen($chunk) > self::MAX_RESPONSE - $received) {
                    $oversized = true;
                    return 0; // Abort cURL before storing an oversized chunk.
                }
                $written = $buffer->write($chunk);
                $received += $written;

                return $written;
            },
        ]);
        // Install the sink at the transport layer, after Laravel's fake-response
        // middleware (which only supports resource/string sinks).
        $transport = function (RequestInterface $request, array $options) use ($sink) {
            $this->ensure(extension_loaded('curl'));
            $options['sink'] = $sink;

            return (new CurlHandler)($request, $options);
        };
        try {
            return $this->fetch($url, $token, $sections, $topics, $transport);
        } catch (\Throwable $exception) {
            if ($oversized) {
                throw new RuntimeException('sector_comparison_unavailable', 0, $exception);
            }
            throw $exception;
        } finally {
            $sink->close();
        }
    }

    private function fetch(string $url, string $token, array $sections, array $topics, callable $transport): array
    {
        $response = Http::acceptJson()->withToken($token)->connectTimeout(1)->timeout(3)
            ->setHandler($transport)
            ->withOptions(['allow_redirects' => false, 'stream' => false])
            ->post(rtrim($url, '/').'/sector-comparison', [
                'version' => self::VERSION, 'sections' => $sections, 'topics' => $topics,
            ]);
        $this->ensure($response->status() === 200);
        $length = $response->header('Content-Length');
        $this->ensure($length === '' || $length === null || (ctype_digit($length) && (int) $length <= self::MAX_RESPONSE));
        $stream = $response->toPsrResponse()->getBody();
        $raw = '';
        while (! $stream->eof() && strlen($raw) <= self::MAX_RESPONSE) {
            $chunk = $stream->read(min(8192, self::MAX_RESPONSE + 1 - strlen($raw)));
            $this->ensure($chunk !== '' || $stream->eof());
            $raw .= $chunk;
        }
        $stream->close();
        $this->ensure(strlen($raw) <= self::MAX_RESPONSE);
        $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        $this->validate($data, $sections, $topics);

        return $data;
    }

    private function ensure(bool $condition): void
    {
        if (! $condition) {
            throw new RuntimeException('sector_comparison_unavailable');
        }
    }

    private function keys(mixed $data, array $keys): void
    {
        $this->ensure(is_array($data));
        $actual = array_keys($data);
        sort($actual);
        sort($keys);
        $this->ensure($actual === $keys);
    }

    private function digest(mixed $digest): void
    {
        $this->ensure(is_string($digest) && preg_match('/^[0-9a-f]{64}$/', $digest) === 1);
    }

    private function countValue(mixed $count): void
    {
        $this->ensure(is_int($count) && $count >= 0 && $count <= 1000000000);
    }

    private function counts(mixed $counts, int $population): void
    {
        $this->keys($counts, ['n_scored', 'n_material']);
        $this->countValue($counts['n_scored']);
        $this->countValue($counts['n_material']);
        $this->ensure($counts['n_material'] <= $counts['n_scored'] && $counts['n_scored'] <= $population);
    }

    private function date(mixed $value): void
    {
        $this->ensure(is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $this->ensure($date !== false && $date->format('Y-m-d') === $value);
    }

    private function validate(mixed $data, array $sections, array $topics): void
    {
        $this->keys($data, ['version', 'artifact_sha256', 'built_at', 'hashes', 'provenance', 'coverage_period', 'sector_taxonomy', 'min_support', 'global_population_companies', 'comparisons']);
        $this->ensure($data['version'] === self::VERSION && $data['sector_taxonomy'] === self::TAXONOMY && $data['min_support'] === 5);
        $this->digest($data['artifact_sha256']);
        $this->ensure(is_string($data['built_at']) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|\+00:00)$/', $data['built_at']) === 1);
        $this->date(substr($data['built_at'], 0, 10));
        $this->ensure((int) substr($data['built_at'], 11, 2) < 24 && (int) substr($data['built_at'], 14, 2) < 60 && (int) substr($data['built_at'], 17, 2) < 60);
        $this->keys($data['hashes'], ['labels', 'masks', 'companies', 'encoding', 'topic_mapping']);
        foreach ($data['hashes'] as $hash) {
            $this->digest($hash);
        }
        $this->ensure(hash_equals(hash_file('sha256', base_path('data/p5-gpt41-feature-encoding-v1.json')), $data['hashes']['encoding']));
        $this->ensure(hash_equals(hash_file('sha256', base_path('data/ar16_to_python_esrs_mapping_new_format_732_v1.json')), $data['hashes']['topic_mapping']));
        $this->keys($data['provenance'], ['label_kind', 'source_reference', 'authorization_reference']);
        $this->ensure(in_array($data['provenance']['label_kind'], ['automatic', 'human', 'mixed', 'synthetic'], true));
        foreach (['source_reference', 'authorization_reference'] as $key) {
            $this->ensure(is_string($data['provenance'][$key]) && preg_match('/^[A-Za-z0-9_-]{1,128}$/', $data['provenance'][$key]) === 1);
        }
        $this->keys($data['coverage_period'], ['start', 'end']);
        $this->date($data['coverage_period']['start']);
        $this->date($data['coverage_period']['end']);
        $this->ensure($data['coverage_period']['start'] <= $data['coverage_period']['end']);
        $this->countValue($data['global_population_companies']);
        $globalPopulation = $data['global_population_companies'];
        $this->ensure(is_array($data['comparisons']) && array_is_list($data['comparisons']) && count($data['comparisons']) === max(1, count($sections)));
        $globalByTopic = [];
        foreach ($data['comparisons'] as $index => $comparison) {
            $this->keys($comparison, ['section', 'population_companies', 'topics']);
            $this->ensure($comparison['section'] === ($sections[$index] ?? null));
            $this->countValue($comparison['population_companies']);
            $population = $comparison['population_companies'];
            $this->ensure($population <= $globalPopulation && ($comparison['section'] !== null || $population === 0));
            $this->ensure(is_array($comparison['topics']) && array_is_list($comparison['topics']) && count($comparison['topics']) === count($topics));
            foreach ($comparison['topics'] as $i => $cell) {
                $this->keys($cell, ['topic_id', 'source', 'low_confidence', 'proportion', 'sector', 'global_counts']);
                $this->ensure($cell['topic_id'] === $topics[$i]);
                $this->counts($cell['sector'], $population);
                $this->counts($cell['global_counts'], $globalPopulation);
                $s = $cell['sector'];
                $g = $cell['global_counts'];
                $this->ensure($s['n_material'] <= $g['n_material'] && $s['n_scored'] - $s['n_material'] <= $g['n_scored'] - $g['n_material']);
                if (isset($globalByTopic[$topics[$i]])) {
                    $this->ensure($globalByTopic[$topics[$i]] === $g);
                }
                $globalByTopic[$topics[$i]] = $g;
                $source = $s['n_scored'] >= 5 ? 'sector' : ($g['n_scored'] >= 5 ? 'global_fallback' : 'insufficient_data');
                $this->ensure($cell['source'] === $source && $cell['low_confidence'] === ($source !== 'sector'));
                if ($source === 'insufficient_data') {
                    $this->ensure($cell['proportion'] === null);
                } else {
                    $chosen = $source === 'sector' ? $s : $g;
                    $this->ensure((is_int($cell['proportion']) || is_float($cell['proportion'])) && is_finite((float) $cell['proportion']) && abs($cell['proportion'] - $chosen['n_material'] / $chosen['n_scored']) < 0.000000001);
                }
            }
        }
    }
}
