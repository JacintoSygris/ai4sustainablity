<?php

namespace App\Services;

use App\Models\Characterization;
use App\Models\CharacterizationDocument;
use App\Support\CharacterizationStateVersion;
use Illuminate\Support\Arr;

/**
 * Builds the ADD-only `document_evidence` block of the P6 materiality proposal
 * payload (P6 document extraction contract v0). The base proposal topic list is
 * never touched: this presenter only reads uploaded-document extraction results
 * and deletion tombstones, and returns a self-contained block.
 */
class DocumentEvidencePresenter
{
    public const TOMBSTONES_FORM_DATA_KEY = 'document_evidence_tombstones';

    private const STANDARDS = ['E1', 'E2', 'E3', 'E4', 'E5', 'S1', 'S2', 'S3', 'S4', 'G1'];

    private const KINDS = ['positive', 'negative'];

    public function __construct(private readonly CharacterizationPredictionMapper $mapper) {}

    /**
     * @return array<string, mixed>
     */
    public function present(Characterization $characterization): array
    {
        $currentVersion = CharacterizationStateVersion::hash($characterization);
        $documents = $characterization->documents()->orderBy('id')->get();

        $documentsPayload = [];
        $topics = [];
        $tombstoneOnlyGroups = [];
        $seenEvidence = [];

        foreach ($documents as $document) {
            $documentsPayload[] = [
                'id' => $document->id,
                'original_filename' => $document->original_filename,
                'status' => $document->status,
                'stale' => $document->merged_state_version !== null
                    && $document->merged_state_version !== $currentVersion,
                'deleted' => false,
            ];

            foreach ($this->evidenceRows($document->extraction_json) as $row) {
                $groupKey = $this->groupKey($row);

                if (! isset($topics[$groupKey])) {
                    $topics[$groupKey] = $this->topicEntry($row);
                }

                $evidenceKey = $document->id.'|'.($row['page'] ?? '').'|'.($row['snippet'] ?? '');
                if (isset($seenEvidence[$groupKey][$evidenceKey])) {
                    continue;
                }
                $seenEvidence[$groupKey][$evidenceKey] = true;

                $topics[$groupKey]['evidence'][] = [
                    'document_id' => $document->id,
                    'page' => $row['page'],
                    'confidence' => $row['confidence'],
                    'snippet' => $row['snippet'],
                ];
            }
        }

        foreach ($this->tombstones($characterization) as $tombstone) {
            $documentsPayload[] = [
                'id' => $tombstone['document_id'],
                'original_filename' => $tombstone['original_filename'],
                'status' => 'deleted',
                'stale' => false,
                'deleted' => true,
            ];

            foreach ($tombstone['topics'] as $row) {
                $groupKey = $this->groupKey($row);

                if (isset($topics[$groupKey]) && ! isset($tombstoneOnlyGroups[$groupKey])) {
                    // The topic is still evidenced by a live document; it is not
                    // a "documento eliminado" topic.
                    continue;
                }

                if (! isset($topics[$groupKey])) {
                    $tombstoneOnlyGroups[$groupKey] = true;
                    $topics[$groupKey] = $this->topicEntry($row);
                }

                // Content was hard-purged with the document: only provenance
                // (the deleted document id) remains, never page/snippet.
                $topics[$groupKey]['evidence'][] = [
                    'document_id' => $tombstone['document_id'],
                ];
            }
        }

        return [
            'documents' => $documentsPayload,
            'topics' => $this->sortedTopics($topics),
        ];
    }

    /**
     * Deduped topic identity rows (standard, topic_key, kind) of a document's
     * extraction, with page/snippet/confidence content stripped. Used to build
     * the deletion tombstone stored on the characterization.
     *
     * @return array<int, array{standard: string, topic_key: ?string, kind: string}>
     */
    public function topicIdentityRows(?array $extraction): array
    {
        $identities = [];

        foreach ($this->evidenceRows($extraction) as $row) {
            $identities[$this->groupKey($row)] = [
                'standard' => $row['standard'],
                'topic_key' => $row['topic_key'],
                'kind' => $row['kind'],
            ];
        }

        return array_values($identities);
    }

    /**
     * @param  array{standard: string, topic_key: ?string, kind: string}  $row
     */
    private function groupKey(array $row): string
    {
        return $row['standard'].'|'.($row['topic_key'] ?? '').'|'.$row['kind'];
    }

    /**
     * @param  array{standard: string, topic_key: ?string, kind: string}  $row
     * @return array<string, mixed>
     */
    private function topicEntry(array $row): array
    {
        return [
            'esrs_code' => $this->mapper->esrsCodeForPredictionKey($row['topic_key']),
            'standard' => $row['standard'],
            'source' => 'document',
            'kind' => $row['kind'],
            'evidence' => [],
        ];
    }

    /**
     * @return array<int, array{standard: string, topic_key: ?string, kind: string, page: ?int, confidence: ?float, snippet: ?string}>
     */
    private function evidenceRows(?array $extraction): array
    {
        $rows = is_array($extraction['evidence'] ?? null) ? $extraction['evidence'] : [];
        $normalized = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $standard = $row['standard'] ?? null;
            $kind = $row['kind'] ?? null;

            if (! in_array($standard, self::STANDARDS, true) || ! in_array($kind, self::KINDS, true)) {
                continue;
            }

            $topicKey = $row['topic_key'] ?? null;

            $normalized[] = [
                'standard' => $standard,
                'topic_key' => is_string($topicKey) && filled($topicKey) ? $topicKey : null,
                'kind' => $kind,
                'page' => is_int($row['page'] ?? null) ? $row['page'] : null,
                'confidence' => is_numeric($row['confidence'] ?? null) ? (float) $row['confidence'] : null,
                'snippet' => is_string($row['snippet'] ?? null) ? $row['snippet'] : null,
            ];
        }

        return $normalized;
    }

    /**
     * @return array<int, array{document_id: int, original_filename: string, topics: array<int, array{standard: string, topic_key: ?string, kind: string}>}>
     */
    private function tombstones(Characterization $characterization): array
    {
        $stored = Arr::get($characterization->form_data ?? [], self::TOMBSTONES_FORM_DATA_KEY, []);

        if (! is_array($stored)) {
            return [];
        }

        $tombstones = [];

        foreach ($stored as $tombstone) {
            if (! is_array($tombstone) || ! is_int($tombstone['document_id'] ?? null)) {
                continue;
            }

            $topics = [];
            foreach (is_array($tombstone['topics'] ?? null) ? $tombstone['topics'] : [] as $row) {
                if (! is_array($row)
                    || ! in_array($row['standard'] ?? null, self::STANDARDS, true)
                    || ! in_array($row['kind'] ?? null, self::KINDS, true)) {
                    continue;
                }

                $topicKey = $row['topic_key'] ?? null;

                $topics[] = [
                    'standard' => $row['standard'],
                    'topic_key' => is_string($topicKey) && filled($topicKey) ? $topicKey : null,
                    'kind' => $row['kind'],
                ];
            }

            $tombstones[] = [
                'document_id' => $tombstone['document_id'],
                'original_filename' => is_string($tombstone['original_filename'] ?? null)
                    ? $tombstone['original_filename']
                    : '',
                'topics' => $topics,
            ];
        }

        return $tombstones;
    }

    /**
     * @param  array<string, array<string, mixed>>  $topics
     * @return array<int, array<string, mixed>>
     */
    private function sortedTopics(array $topics): array
    {
        $entries = array_values($topics);

        usort($entries, function (array $left, array $right) {
            $standardOrder = array_search($left['standard'], self::STANDARDS, true)
                <=> array_search($right['standard'], self::STANDARDS, true);

            return $standardOrder
                ?: strcmp((string) $left['esrs_code'], (string) $right['esrs_code'])
                ?: strcmp($left['kind'], $right['kind']);
        });

        return $entries;
    }
}
