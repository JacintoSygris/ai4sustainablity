<?php

namespace App\Services\Dma;

use App\Models\EsrsTopic;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Only server-owned text leaves Laravel, over the private local service boundary. */
class DmaNlpGateway
{
    public const VERSION = 'dma-processing-v1';
    public const MAX_RESPONSE = 262144;

    public function manifest(): array
    {
        $path = base_path('data/dma_nlp_model_v1.json');
        $raw = file_get_contents($path);
        $this->ensure(hash_equals(DmaNlpModel::MANIFEST_SHA256, hash('sha256', $raw)));
        $manifest = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        foreach ($manifest['sources'] as $name => $hash) {
            $this->ensure(in_array($name, ['esrs_topics.json', 'esrs_datapoints_ig3.json', 'esrs_datapoint_labels_es_v1.json'], true));
            $this->ensure(hash_equals($hash, hash_file('sha256', base_path('data/'.$name))));
        }
        // Seed order is the canonical AR16 identity, never guessed from a label.
        $topics = json_decode(file_get_contents(base_path('data/esrs_topics.json')), true, 64, JSON_THROW_ON_ERROR);
        $stored = EsrsTopic::all()->keyBy('id');
        $this->ensure($stored->count() === count($topics));
        foreach ($topics as $index => $topic) {
            $record = $stored->get($index + 1);
            $this->ensure($record !== null && $record->esrs_code === $topic['esrs']);
            foreach (['theme', 'subtheme', 'subtopic'] as $field) {
                foreach (['es', 'en'] as $locale) {
                    $this->ensure($record->{$field.'_'.$locale} === ($topic[$field][$locale] ?? null));
                }
            }
        }
        return $manifest;
    }

    public function analyse(array $input): array
    {
        $this->ensure(DmaNlpRecommendations::enabled());
        $manifest = $this->manifest();
        $url = config('services.characterization.api.base_url');
        $token = config('services.characterization.api.token');
        // Deployment binds FastAPI to loopback. No redirects or caller-selected hosts.
        $parts = is_string($url) ? parse_url($url) : false;
        $this->ensure(is_array($parts) && ($parts['scheme'] ?? '') === 'http' && ($parts['host'] ?? '') === '127.0.0.1'
            && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['query']) && ! isset($parts['fragment'])
            && is_string($token) && $token !== '');
        $buffer = Utils::streamFor(''); $received = 0;
        $sink = FnStream::decorate($buffer, ['write' => static function (string $chunk) use ($buffer, &$received): int {
            if (strlen($chunk) > self::MAX_RESPONSE - $received) { return 0; }
            $written = $buffer->write($chunk); $received += $written; return $written;
        }]);
        try {
            $response = Http::acceptJson()->withToken($token)->connectTimeout(2)->timeout(12)
                ->setHandler(function ($request, array $options) use ($sink) {
                    $this->ensure(extension_loaded('curl')); $options['sink'] = $sink;
                    return (new CurlHandler)($request, $options);
                })->withOptions(['allow_redirects' => false, 'stream' => false])->post(rtrim($url, '/').'/dma/process', $input);
            $this->ensure($response->status() === 200);
            $stream = $response->toPsrResponse()->getBody(); $raw = '';
            while (! $stream->eof() && strlen($raw) <= self::MAX_RESPONSE) {
                $chunk = $stream->read(min(8192, self::MAX_RESPONSE + 1 - strlen($raw)));
                $this->ensure($chunk !== '' || $stream->eof()); $raw .= $chunk;
            }
            $stream->close(); $this->ensure(strlen($raw) <= self::MAX_RESPONSE);
            $result = $this->decode($raw);
            $this->validate($result, $input, $manifest);
            return $result;
        } finally { $sink->close(); }
    }

    public function validate(mixed $result, array $input, array $manifest): void
    {
        $this->keys($result, ['version', 'model_sha256', 'catalogue_version', 'catalogue_sha256', 'iros']);
        foreach (['version', 'model_sha256', 'catalogue_version', 'catalogue_sha256'] as $key) { $this->ensure($result[$key] === $manifest[$key]); }
        $this->ensure(is_array($result['iros']) && array_is_list($result['iros']) && count($result['iros']) === count($input['iros']));
        $labels = json_decode(file_get_contents(base_path('data/esrs_datapoint_labels_es_v1.json')), true, 64, JSON_THROW_ON_ERROR);
        $points = array_column(json_decode(file_get_contents(base_path('data/esrs_datapoints_ig3.json')), true, 64, JSON_THROW_ON_ERROR)['datapoints'], null, 'id');
        $topics = json_decode(file_get_contents(base_path('data/esrs_topics.json')), true, 64, JSON_THROW_ON_ERROR);
        foreach ($result['iros'] as $index => $iro) {
            $source = $input['iros'][$index]; $text = $source['description'];
            $this->keys($iro, ['iro_id', 'description_sha256', 'model_version', 'model_sha256', 'catalogue_version', 'catalogue_sha256', 'levels']);
            $this->ensure($iro['model_version'] === self::VERSION);
            foreach (['model_sha256', 'catalogue_version', 'catalogue_sha256'] as $field) { $this->ensure($iro[$field] === $manifest[$field]); }
            $this->ensure($iro['iro_id'] === $source['iro_id'] && $iro['description_sha256'] === hash('sha256', $text));
            $this->keys($iro['levels'], ['topic', 'dr', 'datapoint']);
            foreach ($iro['levels'] as $level => $values) {
                $this->keys($values, ['candidates', 'abstention']);
                $this->ensure(is_array($values['candidates']) && array_is_list($values['candidates']) && count($values['candidates']) <= 3);
                $this->ensure($values['candidates'] === [] ? in_array($values['abstention'], ['insufficient_evidence', 'ambiguous', 'unsupported_language'], true) : $values['abstention'] === null);
                $seen = []; $previous = null;
                foreach ($values['candidates'] as $candidate) {
                    $this->keys($candidate, ['id', 'label', 'score', 'spans']);
                    $id = $candidate['id']; $this->ensure(is_string($id) && ! isset($seen[$id])); $seen[$id] = true;
                    $score = $candidate['score'];
                    $this->ensure((is_int($score) || is_float($score)) && is_finite((float) $score) && $score >= 0 && $score <= 1);
                    $this->ensure($previous === null || $previous['score'] > $score || ($previous['score'] === $score && strcmp($previous['id'], $id) < 0)); $previous = $candidate;
                    $locale = $input['locale'];
                    if ($level === 'topic') {
                        $this->ensure(ctype_digit($id) && isset($topics[(int) $id - 1]) && (string) (int) $id === $id);
                        $topic = $topics[(int) $id - 1];
                        $label = implode(' — ', array_map(fn ($key) => $topic[$key][$locale], array_values(array_filter(['theme', 'subtheme', 'subtopic'], fn ($key) => ! empty($topic[$key])))));
                        $reference = 'esrs_topics.json#/'.((int) $id - 1);
                    } elseif ($level === 'dr') {
                        $this->ensure(isset($labels['disclosure_requirements'][$id]));
                        $label = $labels['disclosure_requirements'][$id][$locale];
                        $reference = 'esrs_datapoint_labels_es_v1.json#/disclosure_requirements/'.$id;
                    } else {
                        $this->ensure(isset($points[$id]));
                        $label = $locale === 'es' ? $labels['labels'][$id] : $points[$id]['name'];
                        $reference = 'esrs_datapoints_ig3.json#/datapoints/'.$id;
                    }
                    $this->ensure($candidate['label'] === $label && is_array($candidate['spans']) && array_is_list($candidate['spans']) && count($candidate['spans']) === 1);
                    foreach ($candidate['spans'] as $span) {
                        $this->keys($span, ['start', 'end', 'quote', 'catalogue_ref']);
                        $this->ensure(is_int($span['start']) && is_int($span['end']) && $span['start'] >= 0 && $span['end'] > $span['start'] && $span['end'] <= mb_strlen($text, 'UTF-8'));
                        $this->ensure($span['catalogue_ref'] === $reference && $span['quote'] === mb_substr($text, $span['start'], $span['end'] - $span['start'], 'UTF-8'));
                    }
                }
            }
        }
    }

    private function decode(string $raw): array
    {
        $decoded = json_decode($raw, false, 32, JSON_THROW_ON_ERROR);
        $this->ensure($decoded instanceof \stdClass);
        // Tokenize valid JSON first, retaining escaped-key equivalence before associative casting.
        preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|[{}\[\]:,]|[^\s{}\[\]:,]+/s', $raw, $tokens);
        $stack = [];
        foreach ($tokens[0] as $index => $token) {
            if ($token === '{' || $token === '[') { $stack[] = []; }
            elseif ($token === '}' || $token === ']') { array_pop($stack); }
            elseif (($tokens[0][$index + 1] ?? null) === ':' && str_starts_with($token, '"')) {
                $key = json_decode($token, true, 32, JSON_THROW_ON_ERROR); $last = count($stack) - 1;
                $this->ensure(! isset($stack[$last][$key])); $stack[$last][$key] = true;
            }
        }
        return json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    }

    private function keys(mixed $data, array $keys): void
    {
        $this->ensure(is_array($data)); $actual = array_keys($data); sort($actual); sort($keys); $this->ensure($actual === $keys);
    }

    private function ensure(bool $condition): void
    {
        if (! $condition) { throw new RuntimeException('dma_nlp_unavailable'); }
    }
}
