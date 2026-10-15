<?php

namespace App\Repositories;

use App\Models\EsrsTopic;
use App\Support\EsrsDisplayCatalogue;
use Throwable;

/** Optional observational hints. Never used to derive scope, decisions or authority digests. */
final class ConceptRelationsRepository
{
    private static ?array $cached = null;

    public function __construct(private readonly ?string $path = null) {}

    public function load(): ?array
    {
        $path = $this->path ?? base_path('data/esrs_concept_relations_v1.json');
        if (! is_file($path) || ! is_readable($path) || ! is_file($path.'.sha256') || ! is_readable($path.'.sha256')) {
            return null;
        }

        try {
            $bytes = file_get_contents($path);
            $expected = trim(file_get_contents($path.'.sha256'));
            if (! preg_match('/^[a-f0-9]{64}$/D', $expected) || ! hash_equals($expected, hash('sha256', $bytes))) {
                return null;
            }
            // Bind suggestions to the installed canonical inputs, even on a warm cache.
            if (! is_readable(base_path('data/esrs_datapoints_ig3.json'))
                || ! is_readable(base_path('data/ar16_to_python_esrs_mapping_new_format_732_v1.json'))) {
                return null;
            }
            $ig3 = hash_file('sha256', base_path('data/esrs_datapoints_ig3.json'));
            $mapping = hash_file('sha256', base_path('data/ar16_to_python_esrs_mapping_new_format_732_v1.json'));
            $cacheKey = $path.':'.$expected.':'.$ig3.':'.$mapping;
            if ((self::$cached['key'] ?? null) === $cacheKey) {
                return self::$cached['payload'];
            }
            $payload = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
            if (($payload['version'] ?? null) !== 'esrs-concept-relations-v1'
                || ($payload['source_sha256']['ig3_datapoints'] ?? null) !== $ig3
                || ($payload['source_sha256']['ar16_mapping'] ?? null) !== $mapping
                || ! is_array($payload['topic_relations'] ?? null)
                || ! array_is_list($payload['topic_relations'])
                || ! is_array($payload['datapoints'] ?? null)
                || ! is_array($payload['clusters'] ?? null)) {
                return null;
            }
            $seen = [];
            foreach ($payload['clusters'] as $cluster => $ids) {
                if (! is_array($ids) || ! array_is_list($ids) || $ids === []) return null;
                foreach ($ids as $id) {
                    if (! is_string($id) || isset($seen[$id])
                        || ($payload['datapoints'][$id]['cluster'] ?? null) !== (string) $cluster) return null;
                    $seen[$id] = true;
                }
            }
            if (count($seen) !== count($payload['datapoints'])) return null;
            $canonical = array_column(json_decode(file_get_contents(base_path('data/esrs_datapoints_ig3.json')), true, 512, JSON_THROW_ON_ERROR)['datapoints'], null, 'id');
            if (count($seen) !== count($canonical) || array_diff_key($seen, $canonical) !== []) return null;
            foreach ($payload['datapoints'] as $id => $row) {
                if (! is_array($row) || ! is_array($row['siblings'] ?? null) || ! array_is_list($row['siblings'])) return null;
                $siblings = [];
                foreach ($row['siblings'] as $sibling) {
                    if (! is_string($sibling) || $sibling === $id || isset($siblings[$sibling])
                        || ! isset($payload['datapoints'][$sibling])
                        || $payload['datapoints'][$sibling]['cluster'] !== $row['cluster']) return null;
                    $siblings[$sibling] = true;
                }
            }
            foreach ($payload['topic_relations'] as $edge) {
                if (! is_array($edge) || ! is_int($edge['source'] ?? null) || ! is_int($edge['target'] ?? null)
                    || $edge['source'] < 1 || $edge['target'] < 1 || $edge['source'] === $edge['target']) return null;
                foreach (['phi', 'jaccard', 'lift', 'confidence', 'support'] as $metric) {
                    if (! is_numeric($edge[$metric] ?? null) || ! is_finite((float) $edge[$metric])) return null;
                }
                if ($edge['lift'] <= 1 || $edge['phi'] <= 0 || $edge['phi'] > 1
                    || $edge['confidence'] < 0 || $edge['confidence'] > 1
                    || $edge['jaccard'] < 0 || $edge['jaccard'] > 1
                    || $edge['support'] <= 0 || $edge['support'] > 1) return null;
            }
            self::$cached = ['key' => $cacheKey, 'payload' => $payload];

            return $payload;
        } catch (Throwable) {
            return null;
        }
    }

    public function forTopic(int $id, int $limit = 5): array
    {
        return $this->topicEdges($this->load(), $id, $limit);
    }

    private function topicEdges(?array $payload, int $id, int $limit): array
    {
        $edges = array_values(array_filter($payload['topic_relations'] ?? [], fn (array $edge) => $edge['source'] === $id));
        usort($edges, fn (array $a, array $b) => ($b['lift'] <=> $a['lift']) ?: ($b['confidence'] <=> $a['confidence']) ?: ($a['target'] <=> $b['target']));

        return array_slice($edges, 0, max(0, min(5, $limit)));
    }

    public function forDatapoint(string $id, array $allowedIds, int $limit = 5): array
    {
        return $this->siblingIds($this->load(), $id, array_fill_keys($allowedIds, true), $limit);
    }

    private function siblingIds(?array $payload, string $id, array $allowed, int $limit): array
    {
        $siblings = $payload['datapoints'][$id]['siblings'] ?? [];

        return array_slice(array_values(array_filter($siblings, fn (string $sibling) => isset($allowed[$sibling]))), 0, max(0, min(5, $limit)));
    }

    public function decorateTopics(array $topics): array
    {
        if (! config('services.concept_relations.enabled', false)) return $topics;
        $payload = $this->load();
        $bySource = [];
        $targetIds = [];
        foreach ($topics as $topic) {
            $bySource[$topic['id']] = $this->topicEdges($payload, $topic['id'], 5);
            foreach ($bySource[$topic['id']] as $edge) $targetIds[] = $edge['target'];
        }
        $targets = EsrsTopic::whereIn('id', array_unique($targetIds))->get()->keyBy('id');
        foreach ($topics as &$topic) {
            $topic['often_reported_with'] = [];
            foreach ($bySource[$topic['id']] as $edge) {
                $target = $targets->get($edge['target']);
                if (! $target) continue;
                $locale = app()->getLocale() === 'en' ? 'en' : 'es';
                $topic['often_reported_with'][] = [
                    'id' => $target->id,
                    'esrs_code' => $target->esrs_code,
                    'label' => $target->{'subtopic_'.$locale} ?: $target->{'subtheme_'.$locale} ?: $target->{'theme_'.$locale},
                    'lift' => $edge['lift'], 'confidence' => $edge['confidence'],
                ];
            }
        }
        unset($topic);

        return $topics;
    }

    public function decorateCorpus(array $corpus): array
    {
        if (! config('services.concept_relations.enabled', false)) return $corpus;
        $allowed = [];
        foreach ($corpus['blocks'] ?? [] as $block) {
            foreach ($block['datapoints'] ?? [] as $dp) $allowed[] = $dp['id'];
        }
        $payload = $this->load();
        $allowed = array_fill_keys($allowed, true);
        $catalogue = app(EsrsDisplayCatalogue::class);
        foreach ($corpus['blocks'] ?? [] as $key => $block) {
            foreach ($block['datapoints'] ?? [] as $index => $dp) {
                $corpus['blocks'][$key]['datapoints'][$index]['related_datapoints'] = array_map(
                    fn (string $id) => ['id' => $id, 'label' => $catalogue->claimLabel($id, app()->getLocale()) ?? $id],
                    $this->siblingIds($payload, $dp['id'], $allowed, 5),
                );
            }
        }

        return $corpus;
    }
}
