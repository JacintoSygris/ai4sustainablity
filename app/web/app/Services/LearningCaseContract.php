<?php

namespace App\Services;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;
use stdClass;

final class LearningCaseContract
{
    private const MAX_SAFE_INTEGER = 9007199254740991;

    /**
     * Validate the passive learning-case-v1 contract and its current eligibility inputs.
     *
     * The caller supplies the approved catalog/mapping authority. This class performs no
     * file, database, queue, HTTP, training, or activation work.
     */
    public function assertEligibleLearningCase(string $caseJson, string $authorityJson): void
    {
        $caseObject = $this->decodeJsonObject($caseJson, 'learning_case.json_invalid', 'learning_case.structure_invalid');
        $authorityObject = $this->decodeJsonObject(
            $authorityJson,
            'learning_authority.json_invalid',
            'learning_authority.structure_invalid',
        );
        $this->assertLearningCaseJsonStructure($caseObject);
        $this->assertLearningAuthorityJsonStructure($authorityObject);

        $this->assertEligibleLearningCaseSemantics(
            self::normalizeJsonObject($caseObject),
            self::normalizeJsonObject($authorityObject),
        );
    }

    /**
     * @param  array<string, mixed>  $case
     * @param  array<string, mixed>  $authority
     */
    private function assertEligibleLearningCaseSemantics(array $case, array $authority): void
    {
        $this->assertLearningCaseStructure($case);

        if (($case['schema_version'] ?? null) !== 'learning-case-v1') {
            $this->fail('learning_case.schema_version_invalid');
        }

        $this->assertNonEmptyString($case['case_id'] ?? null, 'learning_case.case_id_invalid');
        $this->assertDigest($case['case_hash'] ?? null, 'learning_case.case_hash_invalid');
        $this->assertNonEmptyString($case['company_group_key'] ?? null, 'learning_case.company_group_key_invalid');

        $periodScope = $this->requiredArray($case, 'period_scope', 'learning_case.period_scope_invalid');
        $this->assertNonEmptyString($periodScope['period_key'] ?? null, 'learning_case.period_scope_invalid');
        $this->assertNonEmptyString($periodScope['perimeter_key'] ?? null, 'learning_case.period_scope_invalid');

        $declaredAuthority = $this->requiredArray($case, 'authority', 'learning_case.authority_invalid');
        foreach (['framework_version', 'catalog_version', 'mapping_version'] as $field) {
            $this->assertNonEmptyString($declaredAuthority[$field] ?? null, 'learning_case.authority_invalid');
            if (($declaredAuthority[$field] ?? null) !== ($authority[$field] ?? null)) {
                $this->fail('learning_case.authority_mismatch');
            }
        }
        foreach (['catalog_digest', 'mapping_digest'] as $field) {
            $this->assertDigest($declaredAuthority[$field] ?? null, 'learning_case.authority_invalid');
            if (($declaredAuthority[$field] ?? null) !== ($authority[$field] ?? null)) {
                $this->fail('learning_case.authority_mismatch');
            }
        }

        $provenance = $this->requiredArray($case, 'provenance', 'learning_case.provenance_invalid');
        $sourceKind = $provenance['source_kind'] ?? null;
        if (! in_array($sourceKind, ['human_product', 'report', 'synthetic'], true)) {
            $this->fail('learning_case.provenance_invalid');
        }
        if ($sourceKind === 'synthetic') {
            $this->fail('learning_case.synthetic_not_eligible');
        }
        $this->assertDigest($provenance['source_record_digest'] ?? null, 'learning_case.provenance_invalid');
        $this->assertNonEmptyString($provenance['source_revision'] ?? null, 'learning_case.provenance_invalid');

        $this->assertRevisionTuple($case['source_revisions'] ?? null, 'learning_case.source_revisions_invalid');

        $p5 = $this->requiredArray($case, 'p5_snapshot', 'learning_case.p5_snapshot_invalid');
        $this->assertNonEmptyString($p5['schema_version'] ?? null, 'learning_case.p5_snapshot_invalid');
        $this->assertDigest($p5['digest'] ?? null, 'learning_case.p5_snapshot_invalid');

        $p6 = $this->requiredArray($case, 'p6_snapshot', 'learning_case.p6_snapshot_invalid');
        $this->assertNonEmptyString($p6['model_profile'] ?? null, 'learning_case.p6_snapshot_invalid');
        $this->assertDigest($p6['model_digest'] ?? null, 'learning_case.p6_snapshot_invalid');
        $this->assertDigest($p6['policy_digest'] ?? null, 'learning_case.p6_snapshot_invalid');

        $topicUniverse = $this->requiredArray($case, 'topic_universe', 'learning_case.topic_universe_invalid');
        if (! array_key_exists('reviewed_topic_ids', $topicUniverse)) {
            $this->fail('learning_case.reviewed_topic_ids_required');
        }
        $reviewedTopicIds = $this->stringList(
            $topicUniverse['reviewed_topic_ids'],
            'learning_case.reviewed_topic_ids_invalid',
        );
        $outsideTopicIds = $this->stringList(
            $topicUniverse['outside_scope_topic_ids'] ?? null,
            'learning_case.outside_scope_topic_ids_invalid',
        );
        if (array_intersect($reviewedTopicIds, $outsideTopicIds) !== []) {
            $this->fail('learning_case.topic_scope_overlap');
        }
        $this->assertKnownIds(
            array_merge($reviewedTopicIds, $outsideTopicIds),
            $authority['topic_ids'] ?? null,
            $authority['ambiguous_topic_ids'] ?? null,
            'learning_case.unknown_topic_id',
            'learning_case.ambiguous_topic_id',
        );
        $this->assertTopicLabels($case['topic_labels'] ?? null, $reviewedTopicIds, $sourceKind);

        $datapointUniverse = $this->requiredArray($case, 'datapoint_universe', 'learning_case.datapoint_universe_invalid');
        $reviewedDatapointIds = $this->stringList(
            $datapointUniverse['reviewed_datapoint_ids'] ?? null,
            'learning_case.reviewed_datapoint_ids_invalid',
        );
        $outsideDatapointIds = $this->stringList(
            $datapointUniverse['outside_scope_datapoint_ids'] ?? null,
            'learning_case.outside_scope_datapoint_ids_invalid',
        );
        if (array_intersect($reviewedDatapointIds, $outsideDatapointIds) !== []) {
            $this->fail('learning_case.datapoint_scope_overlap');
        }
        $this->assertKnownIds(
            array_merge($reviewedDatapointIds, $outsideDatapointIds),
            $authority['datapoint_ids'] ?? null,
            $authority['ambiguous_datapoint_ids'] ?? null,
            'learning_case.unknown_datapoint_id',
            'learning_case.ambiguous_datapoint_id',
        );
        $this->assertDatapointDecisions($case['datapoint_decisions'] ?? null, $reviewedDatapointIds);

        $rights = $this->requiredArray($case, 'rights', 'learning_case.rights_invalid');
        $this->assertNonEmptyString($rights['policy_version'] ?? null, 'learning_case.rights_invalid');
        $this->assertDigest($rights['policy_digest'] ?? null, 'learning_case.rights_invalid');
        $this->assertSafeInteger($rights['authorization_generation'] ?? null, 'learning_case.rights_invalid');
        if (($rights['policy_status'] ?? null) !== 'approved' || ($rights['state'] ?? null) !== 'granted') {
            $this->fail('learning_case.rights_not_eligible');
        }

        $closure = $this->requiredArray($case, 'closure_evidence', 'learning_case.closure_invalid');
        $this->assertNonEmptyString($closure['declaration_version'] ?? null, 'learning_case.closure_invalid');
        $this->assertNonEmptyString($closure['server_actor_id'] ?? null, 'learning_case.closure_invalid');
        $this->parseTimestamp($closure['recorded_at'] ?? null, 'learning_case.closure_invalid');
        if (($closure['declaration_status'] ?? null) !== 'accepted'
            || ($closure['reviewed_universe'] ?? null) !== true
            || ($closure['final_for_period_scope'] ?? null) !== true) {
            $this->fail('learning_case.closure_not_accepted');
        }
    }

    /**
     * Validate a content-addressed learning-eligibility-v1 manifest.
     */
    public function assertEligibilityManifest(
        string $manifestJson,
        mixed $previousGeneration,
        DateTimeImmutable $now,
    ): void {
        $this->assertSafeInteger($previousGeneration, 'learning_manifest.previous_generation_invalid');
        $manifestObject = $this->decodeJsonObject(
            $manifestJson,
            'learning_manifest.json_invalid',
            'learning_manifest.structure_invalid',
        );
        $this->assertEligibilityManifestJsonStructure($manifestObject);
        $manifestDigest = self::eligibilityManifestDigestObject($manifestObject);

        $this->assertEligibilityManifestSemantics(
            self::normalizeJsonObject($manifestObject),
            $previousGeneration,
            $now,
            $manifestDigest,
        );
    }

    /** @param array<string, mixed> $manifest */
    private function assertEligibilityManifestSemantics(
        array $manifest,
        int $previousGeneration,
        DateTimeImmutable $now,
        string $manifestDigest,
    ): void {
        $this->assertEligibilityManifestStructure($manifest);

        if (($manifest['schema_version'] ?? null) !== 'learning-eligibility-v1') {
            $this->fail('learning_manifest.schema_version_invalid');
        }

        $generation = $manifest['generation'] ?? null;
        $this->assertSafeInteger($generation, 'learning_manifest.generation_invalid');
        if ($generation <= $previousGeneration) {
            $this->fail('learning_manifest.generation_not_monotonic');
        }

        $issuedAt = $this->parseTimestamp($manifest['issued_at'] ?? null, 'learning_manifest.issued_at_invalid');
        $validUntil = $this->parseTimestamp($manifest['valid_until'] ?? null, 'learning_manifest.valid_until_invalid');
        if ($validUntil <= $issuedAt) {
            $this->fail('learning_manifest.validity_invalid');
        }
        if ($now < $issuedAt) {
            $this->fail('learning_manifest.not_yet_valid');
        }
        if ($now >= $validUntil) {
            $this->fail('learning_manifest.expired');
        }

        $cases = $manifest['cases'] ?? null;
        if (! is_array($cases) || ! array_is_list($cases)) {
            $this->fail('learning_manifest.cases_invalid');
        }
        $caseIds = [];
        foreach ($cases as $case) {
            if (! is_array($case)) {
                $this->fail('learning_manifest.cases_invalid');
            }
            $caseId = $case['case_id'] ?? null;
            $this->assertNonEmptyString($caseId, 'learning_manifest.cases_invalid');
            if (in_array($caseId, $caseIds, true)) {
                $this->fail('learning_manifest.duplicate_case_id');
            }
            $caseIds[] = $caseId;
            $this->assertDigest($case['case_hash'] ?? null, 'learning_manifest.cases_invalid');
            $this->assertRevisionTuple($case['source_revisions'] ?? null, 'learning_manifest.cases_invalid');
            $this->assertDigest($case['rights_digest'] ?? null, 'learning_manifest.cases_invalid');
            $this->assertDigest($case['policy_digest'] ?? null, 'learning_manifest.cases_invalid');
        }

        $eligibleCaseIds = $this->stringList(
            $manifest['eligible_case_ids'] ?? null,
            'learning_manifest.eligible_case_ids_invalid',
        );
        foreach ($eligibleCaseIds as $caseId) {
            if (! in_array($caseId, $caseIds, true)) {
                $this->fail('learning_manifest.eligible_case_unknown');
            }
        }

        $tombstones = $this->requiredArray($manifest, 'tombstones', 'learning_manifest.tombstones_invalid');
        $tombstonedIds = [];
        foreach (['revoked', 'deleted'] as $kind) {
            $rows = $tombstones[$kind] ?? null;
            if (! is_array($rows) || ! array_is_list($rows)) {
                $this->fail('learning_manifest.tombstones_invalid');
            }
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    $this->fail('learning_manifest.tombstones_invalid');
                }
                $caseId = $row['case_id'] ?? null;
                $this->assertNonEmptyString($caseId, 'learning_manifest.tombstones_invalid');
                $this->assertDigest($row['case_hash'] ?? null, 'learning_manifest.tombstones_invalid');
                $this->parseTimestamp($row['at'] ?? null, 'learning_manifest.tombstones_invalid');
                if (in_array($caseId, $tombstonedIds, true)) {
                    $this->fail('learning_manifest.duplicate_tombstone');
                }
                $tombstonedIds[] = $caseId;
            }
        }
        if (array_intersect($eligibleCaseIds, $tombstonedIds) !== []) {
            $this->fail('learning_manifest.eligible_case_tombstoned');
        }
        if (array_intersect($caseIds, $tombstonedIds) !== []) {
            $this->fail('learning_manifest.current_case_tombstoned');
        }

        $this->assertDigest($manifest['rights_snapshot_digest'] ?? null, 'learning_manifest.rights_digest_invalid');
        $this->assertDigest($manifest['eligibility_policy_digest'] ?? null, 'learning_manifest.policy_digest_invalid');
        $this->assertDigest($manifest['canonical_digest'] ?? null, 'learning_manifest.digest_invalid');

        if (! hash_equals($manifest['canonical_digest'], $manifestDigest)) {
            $this->fail('learning_manifest.digest_mismatch');
        }
    }

    public static function eligibilityManifestDigest(string $manifestJson): string
    {
        try {
            $manifest = json_decode(
                $manifestJson,
                false,
                512,
                JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING,
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('learning_manifest.json_invalid', previous: $exception);
        }
        self::assertNoDuplicateJsonObjectKeys($manifestJson, 'learning_manifest.structure_invalid');
        if (! $manifest instanceof stdClass) {
            throw new InvalidArgumentException('learning_manifest.structure_invalid');
        }

        return self::eligibilityManifestDigestObject($manifest);
    }

    /**
     * Return the content address of a learning case's canonical payload, excluding
     * only the case_hash member that carries that address.
     */
    public static function learningCaseDigest(string $caseJson): string
    {
        $case = self::decodeContentAddressedCase($caseJson);
        unset($case->case_hash);

        return hash('sha256', self::canonicalJson($case));
    }

    /**
     * Return the exact canonical UTF-8 JSON bytes stored by T02.
     */
    public static function canonicalLearningCasePayload(string $caseJson): string
    {
        return self::canonicalJson(self::decodeContentAddressedCase($caseJson));
    }

    /**
     * Recompute and verify the case's own content address.
     */
    public function assertLearningCaseHash(string $caseJson): void
    {
        $case = $this->decodeJsonObject(
            $caseJson,
            'learning_case.json_invalid',
            'learning_case.structure_invalid',
        );
        $this->assertLearningCaseJsonStructure($case);
        $this->assertDigest($case->case_hash ?? null, 'learning_case.case_hash_invalid');

        if (! hash_equals($case->case_hash, self::learningCaseDigest($caseJson))) {
            $this->fail('learning_case.case_hash_mismatch');
        }
    }

    private static function decodeContentAddressedCase(string $caseJson): stdClass
    {
        try {
            $case = json_decode(
                $caseJson,
                false,
                512,
                JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING,
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('learning_case.json_invalid', previous: $exception);
        }
        self::assertNoDuplicateJsonObjectKeys($caseJson, 'learning_case.structure_invalid');
        if (! $case instanceof stdClass) {
            throw new InvalidArgumentException('learning_case.structure_invalid');
        }

        return $case;
    }

    private static function eligibilityManifestDigestObject(stdClass $manifest): string
    {
        $manifest = clone $manifest;
        unset($manifest->canonical_digest);

        return hash('sha256', self::canonicalJson($manifest));
    }

    private function assertLearningCaseJsonStructure(stdClass $case): void
    {
        $code = 'learning_case.structure_invalid';
        $this->assertExactJsonObjectProperties($case, [
            'schema_version',
            'case_id',
            'case_hash',
            'company_group_key',
            'period_scope',
            'authority',
            'provenance',
            'source_revisions',
            'p5_snapshot',
            'p6_snapshot',
            'topic_universe',
            'topic_labels',
            'datapoint_universe',
            'datapoint_decisions',
            'rights',
            'closure_evidence',
        ], $code);
        $this->assertExactJsonObjectProperties($case->period_scope, ['period_key', 'perimeter_key'], $code);
        $this->assertExactJsonObjectProperties($case->authority, [
            'framework_version',
            'catalog_version',
            'catalog_digest',
            'mapping_version',
            'mapping_digest',
        ], $code);
        $this->assertExactJsonObjectProperties($case->provenance, [
            'source_kind',
            'source_record_digest',
            'source_revision',
        ], $code);
        $this->assertJsonRevisionTuple($case->source_revisions, $code);
        $this->assertExactJsonObjectProperties($case->p5_snapshot, ['schema_version', 'digest'], $code);
        $this->assertExactJsonObjectProperties($case->p6_snapshot, [
            'model_profile',
            'model_digest',
            'policy_digest',
        ], $code);
        $this->assertExactJsonObjectProperties($case->topic_universe, [
            'reviewed_topic_ids',
            'outside_scope_topic_ids',
        ], $code);
        $this->assertJsonList($case->topic_universe->reviewed_topic_ids, $code);
        $this->assertJsonList($case->topic_universe->outside_scope_topic_ids, $code);
        $this->assertJsonObjectList($case->topic_labels, ['topic_id', 'value', 'observed_mask'], $code);
        $this->assertExactJsonObjectProperties($case->datapoint_universe, [
            'reviewed_datapoint_ids',
            'outside_scope_datapoint_ids',
        ], $code);
        $this->assertJsonList($case->datapoint_universe->reviewed_datapoint_ids, $code);
        $this->assertJsonList($case->datapoint_universe->outside_scope_datapoint_ids, $code);
        $this->assertJsonObjectList($case->datapoint_decisions, [
            'datapoint_id',
            'relevant',
            'selected_to_answer',
            'reason_codes',
            'note',
        ], $code);
        foreach ($case->datapoint_decisions as $decision) {
            $this->assertJsonList($decision->reason_codes, $code);
        }
        $this->assertExactJsonObjectProperties($case->rights, [
            'policy_version',
            'policy_digest',
            'policy_status',
            'state',
            'authorization_generation',
        ], $code);
        $this->assertExactJsonObjectProperties($case->closure_evidence, [
            'declaration_version',
            'declaration_status',
            'reviewed_universe',
            'final_for_period_scope',
            'server_actor_id',
            'recorded_at',
        ], $code);
    }

    private function assertLearningAuthorityJsonStructure(stdClass $authority): void
    {
        $code = 'learning_authority.structure_invalid';
        $this->assertExactJsonObjectProperties($authority, [
            'framework_version',
            'catalog_version',
            'catalog_digest',
            'mapping_version',
            'mapping_digest',
            'topic_ids',
            'datapoint_ids',
            'ambiguous_topic_ids',
            'ambiguous_datapoint_ids',
        ], $code);
        foreach (['topic_ids', 'datapoint_ids', 'ambiguous_topic_ids', 'ambiguous_datapoint_ids'] as $field) {
            $this->assertJsonList($authority->{$field}, $code);
        }
    }

    private function assertEligibilityManifestJsonStructure(stdClass $manifest): void
    {
        $code = 'learning_manifest.structure_invalid';
        $this->assertExactJsonObjectProperties($manifest, [
            'schema_version',
            'generation',
            'issued_at',
            'valid_until',
            'cases',
            'eligible_case_ids',
            'tombstones',
            'rights_snapshot_digest',
            'eligibility_policy_digest',
            'canonical_digest',
        ], $code);
        $this->assertJsonObjectList($manifest->cases, [
            'case_id',
            'case_hash',
            'source_revisions',
            'rights_digest',
            'policy_digest',
        ], $code);
        foreach ($manifest->cases as $case) {
            $this->assertJsonRevisionTuple($case->source_revisions, $code);
        }
        $this->assertJsonList($manifest->eligible_case_ids, $code);
        $this->assertExactJsonObjectProperties($manifest->tombstones, ['revoked', 'deleted'], $code);
        foreach (['revoked', 'deleted'] as $kind) {
            $this->assertJsonObjectList($manifest->tombstones->{$kind}, ['case_id', 'case_hash', 'at'], $code);
        }
    }

    private function assertJsonRevisionTuple(mixed $tuple, string $code): void
    {
        $this->assertExactJsonObjectProperties($tuple, ['p5', 'p6', 'p8', 'p9'], $code);
        foreach (['p5', 'p6', 'p8', 'p9'] as $stage) {
            $this->assertExactJsonObjectProperties($tuple->{$stage}, ['generation', 'revision', 'digest'], $code);
        }
    }

    /** @param list<string> $expectedProperties */
    private function assertExactJsonObjectProperties(mixed $value, array $expectedProperties, string $code): void
    {
        if (! $value instanceof stdClass) {
            $this->fail($code);
        }

        $actualProperties = array_keys(get_object_vars($value));
        sort($actualProperties, SORT_STRING);
        sort($expectedProperties, SORT_STRING);
        if ($actualProperties !== $expectedProperties) {
            $this->fail($code);
        }
    }

    private function assertJsonList(mixed $value, string $code): void
    {
        if (! is_array($value) || ! array_is_list($value)) {
            $this->fail($code);
        }
    }

    /** @param list<string> $expectedProperties */
    private function assertJsonObjectList(mixed $value, array $expectedProperties, string $code): void
    {
        $this->assertJsonList($value, $code);
        foreach ($value as $item) {
            $this->assertExactJsonObjectProperties($item, $expectedProperties, $code);
        }
    }

    private function decodeJsonObject(string $json, string $jsonCode, string $structureCode): stdClass
    {
        try {
            $value = json_decode($json, false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException($jsonCode, previous: $exception);
        }
        self::assertNoDuplicateJsonObjectKeys($json, $structureCode);
        if (! $value instanceof stdClass) {
            $this->fail($structureCode);
        }

        return $value;
    }

    private static function assertNoDuplicateJsonObjectKeys(string $json, string $code): void
    {
        $offset = 0;
        self::scanJsonValue($json, $offset, $code);
    }

    private static function scanJsonValue(string $json, int &$offset, string $code): void
    {
        self::skipJsonWhitespace($json, $offset);
        $token = $json[$offset] ?? '';
        if ($token === '{') {
            self::scanJsonObject($json, $offset, $code);

            return;
        }
        if ($token === '[') {
            self::scanJsonArray($json, $offset, $code);

            return;
        }
        if ($token === '"') {
            self::scanJsonString($json, $offset, $code);

            return;
        }

        $length = strlen($json);
        while ($offset < $length && ! str_contains(",]} \t\r\n", $json[$offset])) {
            $offset++;
        }
    }

    private static function scanJsonObject(string $json, int &$offset, string $code): void
    {
        $offset++;
        self::skipJsonWhitespace($json, $offset);
        if (($json[$offset] ?? '') === '}') {
            $offset++;

            return;
        }

        $seen = [];
        while (true) {
            $key = self::scanJsonString($json, $offset, $code);
            $identity = "\0".$key;
            if (array_key_exists($identity, $seen)) {
                throw new InvalidArgumentException($code);
            }
            $seen[$identity] = true;

            self::skipJsonWhitespace($json, $offset);
            if (($json[$offset] ?? '') !== ':') {
                throw new InvalidArgumentException($code);
            }
            $offset++;
            self::scanJsonValue($json, $offset, $code);
            self::skipJsonWhitespace($json, $offset);
            $separator = $json[$offset] ?? '';
            if ($separator === '}') {
                $offset++;

                return;
            }
            if ($separator !== ',') {
                throw new InvalidArgumentException($code);
            }
            $offset++;
            self::skipJsonWhitespace($json, $offset);
        }
    }

    private static function scanJsonArray(string $json, int &$offset, string $code): void
    {
        $offset++;
        self::skipJsonWhitespace($json, $offset);
        if (($json[$offset] ?? '') === ']') {
            $offset++;

            return;
        }

        while (true) {
            self::scanJsonValue($json, $offset, $code);
            self::skipJsonWhitespace($json, $offset);
            $separator = $json[$offset] ?? '';
            if ($separator === ']') {
                $offset++;

                return;
            }
            if ($separator !== ',') {
                throw new InvalidArgumentException($code);
            }
            $offset++;
        }
    }

    private static function scanJsonString(string $json, int &$offset, string $code): string
    {
        self::skipJsonWhitespace($json, $offset);
        if (($json[$offset] ?? '') !== '"') {
            throw new InvalidArgumentException($code);
        }

        $start = $offset++;
        $length = strlen($json);
        while ($offset < $length) {
            if ($json[$offset] === '\\') {
                $offset += 2;

                continue;
            }
            if ($json[$offset] === '"') {
                $offset++;
                try {
                    return json_decode(substr($json, $start, $offset - $start), true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException $exception) {
                    throw new InvalidArgumentException($code, previous: $exception);
                }
            }
            $offset++;
        }

        throw new InvalidArgumentException($code);
    }

    private static function skipJsonWhitespace(string $json, int &$offset): void
    {
        $length = strlen($json);
        while ($offset < $length && str_contains(" \t\r\n", $json[$offset])) {
            $offset++;
        }
    }

    /** @return array<string, mixed> */
    private static function normalizeJsonObject(stdClass $value): array
    {
        return self::normalizeJsonValue($value);
    }

    private static function normalizeJsonValue(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $normalized = [];
            foreach (get_object_vars($value) as $key => $member) {
                $normalized[$key] = self::normalizeJsonValue($member);
            }

            return $normalized;
        }
        if (is_array($value)) {
            return array_map(self::normalizeJsonValue(...), $value);
        }

        return $value;
    }

    /** @param array<string, mixed> $case */
    private function assertLearningCaseStructure(array $case): void
    {
        $code = 'learning_case.structure_invalid';
        $this->assertExactObjectKeys($case, [
            'schema_version',
            'case_id',
            'case_hash',
            'company_group_key',
            'period_scope',
            'authority',
            'provenance',
            'source_revisions',
            'p5_snapshot',
            'p6_snapshot',
            'topic_universe',
            'topic_labels',
            'datapoint_universe',
            'datapoint_decisions',
            'rights',
            'closure_evidence',
        ], $code);
        $this->assertExactObjectKeys($case['period_scope'], ['period_key', 'perimeter_key'], $code);
        $this->assertExactObjectKeys($case['authority'], [
            'framework_version',
            'catalog_version',
            'catalog_digest',
            'mapping_version',
            'mapping_digest',
        ], $code);
        $this->assertExactObjectKeys($case['provenance'], [
            'source_kind',
            'source_record_digest',
            'source_revision',
        ], $code);
        $this->assertExactObjectKeys($case['source_revisions'], ['p5', 'p6', 'p8', 'p9'], $code);
        foreach ($case['source_revisions'] as $revision) {
            $this->assertExactObjectKeys($revision, ['generation', 'revision', 'digest'], $code);
        }
        $this->assertExactObjectKeys($case['p5_snapshot'], ['schema_version', 'digest'], $code);
        $this->assertExactObjectKeys($case['p6_snapshot'], ['model_profile', 'model_digest', 'policy_digest'], $code);
        $this->assertExactObjectKeys($case['topic_universe'], [
            'reviewed_topic_ids',
            'outside_scope_topic_ids',
        ], $code);
        $this->assertObjectList($case['topic_labels'], ['topic_id', 'value', 'observed_mask'], $code);
        $this->assertExactObjectKeys($case['datapoint_universe'], [
            'reviewed_datapoint_ids',
            'outside_scope_datapoint_ids',
        ], $code);
        $this->assertObjectList($case['datapoint_decisions'], [
            'datapoint_id',
            'relevant',
            'selected_to_answer',
            'reason_codes',
            'note',
        ], $code);
        $this->assertExactObjectKeys($case['rights'], [
            'policy_version',
            'policy_digest',
            'policy_status',
            'state',
            'authorization_generation',
        ], $code);
        $this->assertExactObjectKeys($case['closure_evidence'], [
            'declaration_version',
            'declaration_status',
            'reviewed_universe',
            'final_for_period_scope',
            'server_actor_id',
            'recorded_at',
        ], $code);
    }

    /** @param array<string, mixed> $manifest */
    private function assertEligibilityManifestStructure(array $manifest): void
    {
        $code = 'learning_manifest.structure_invalid';
        $this->assertExactObjectKeys($manifest, [
            'schema_version',
            'generation',
            'issued_at',
            'valid_until',
            'cases',
            'eligible_case_ids',
            'tombstones',
            'rights_snapshot_digest',
            'eligibility_policy_digest',
            'canonical_digest',
        ], $code);
        $this->assertObjectList($manifest['cases'], [
            'case_id',
            'case_hash',
            'source_revisions',
            'rights_digest',
            'policy_digest',
        ], $code);
        foreach ($manifest['cases'] as $case) {
            $this->assertExactObjectKeys($case['source_revisions'] ?? null, ['p5', 'p6', 'p8', 'p9'], $code);
            foreach ($case['source_revisions'] as $revision) {
                $this->assertExactObjectKeys($revision, ['generation', 'revision', 'digest'], $code);
            }
        }
        $this->assertExactObjectKeys($manifest['tombstones'], ['revoked', 'deleted'], $code);
        foreach (['revoked', 'deleted'] as $kind) {
            $this->assertObjectList($manifest['tombstones'][$kind] ?? null, ['case_id', 'case_hash', 'at'], $code);
        }
    }

    /** @param list<string> $expectedKeys */
    private function assertExactObjectKeys(mixed $value, array $expectedKeys, string $code): void
    {
        if (! is_array($value) || array_is_list($value)) {
            $this->fail($code);
        }

        $actualKeys = array_keys($value);
        sort($actualKeys, SORT_STRING);
        sort($expectedKeys, SORT_STRING);
        if ($actualKeys !== $expectedKeys) {
            $this->fail($code);
        }
    }

    /** @param list<string> $expectedKeys */
    private function assertObjectList(mixed $value, array $expectedKeys, string $code): void
    {
        if (! is_array($value) || ! array_is_list($value)) {
            $this->fail($code);
        }
        foreach ($value as $object) {
            $this->assertExactObjectKeys($object, $expectedKeys, $code);
        }
    }

    /**
     * @param  list<string>  $reviewedTopicIds
     */
    private function assertTopicLabels(mixed $labels, array $reviewedTopicIds, string $sourceKind): void
    {
        if (! is_array($labels) || ! array_is_list($labels)) {
            $this->fail('learning_case.topic_labels_invalid');
        }

        $labelIds = [];
        foreach ($labels as $label) {
            if (! is_array($label)) {
                $this->fail('learning_case.topic_labels_invalid');
            }
            $topicId = $label['topic_id'] ?? null;
            $this->assertNonEmptyString($topicId, 'learning_case.topic_labels_invalid');
            if (in_array($topicId, $labelIds, true)) {
                $this->fail('learning_case.duplicate_topic_label');
            }
            $labelIds[] = $topicId;

            $mask = $label['observed_mask'] ?? null;
            if (! is_int($mask) || ! in_array($mask, [0, 1], true)) {
                $this->fail('learning_case.topic_label_mask_invalid');
            }
            $value = $label['value'] ?? null;

            if ($sourceKind === 'human_product') {
                if ($mask !== 1 || ! is_int($value) || ! in_array($value, [0, 1], true)) {
                    $this->fail('learning_case.topic_label_value_invalid');
                }
            } elseif ($mask === 0) {
                if ($value !== null) {
                    $this->fail('learning_case.report_unobserved_label_requires_null');
                }
            } elseif (! is_int($value) || ! in_array($value, [0, 1], true)) {
                $this->fail('learning_case.topic_label_value_invalid');
            }
        }

        $actual = $labelIds;
        sort($actual, SORT_STRING);
        $expected = $reviewedTopicIds;
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            $this->fail('learning_case.topic_label_set_mismatch');
        }
    }

    /**
     * @param  list<string>  $reviewedDatapointIds
     */
    private function assertDatapointDecisions(mixed $decisions, array $reviewedDatapointIds): void
    {
        if (! is_array($decisions) || ! array_is_list($decisions)) {
            $this->fail('learning_case.datapoint_decisions_invalid');
        }

        $decisionIds = [];
        foreach ($decisions as $decision) {
            if (! is_array($decision)) {
                $this->fail('learning_case.datapoint_decisions_invalid');
            }
            $datapointId = $decision['datapoint_id'] ?? null;
            $this->assertNonEmptyString($datapointId, 'learning_case.datapoint_decisions_invalid');
            if (in_array($datapointId, $decisionIds, true)) {
                $this->fail('learning_case.duplicate_datapoint_decision');
            }
            $decisionIds[] = $datapointId;
            if (! is_bool($decision['relevant'] ?? null) || ! is_bool($decision['selected_to_answer'] ?? null)) {
                $this->fail('learning_case.datapoint_decisions_invalid');
            }
            $this->stringList($decision['reason_codes'] ?? null, 'learning_case.datapoint_decisions_invalid');
            if (! array_key_exists('note', $decision)
                || (! is_string($decision['note']) && $decision['note'] !== null)) {
                $this->fail('learning_case.datapoint_decisions_invalid');
            }
        }

        $actual = $decisionIds;
        sort($actual, SORT_STRING);
        $expected = $reviewedDatapointIds;
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            $this->fail('learning_case.datapoint_decision_set_mismatch');
        }
    }

    /**
     * @param  list<string>  $ids
     */
    private function assertKnownIds(
        array $ids,
        mixed $knownIds,
        mixed $ambiguousIds,
        string $unknownCode,
        string $ambiguousCode,
    ): void {
        $known = $this->stringList($knownIds, $unknownCode);
        $ambiguous = $this->stringList($ambiguousIds, $ambiguousCode);
        foreach ($ids as $id) {
            if (in_array($id, $ambiguous, true)) {
                $this->fail($ambiguousCode);
            }
            if (! in_array($id, $known, true)) {
                $this->fail($unknownCode);
            }
        }
    }

    private function assertRevisionTuple(mixed $tuple, string $code): void
    {
        if (! is_array($tuple)) {
            $this->fail($code);
        }
        $keys = array_keys($tuple);
        sort($keys, SORT_STRING);
        if ($keys !== ['p5', 'p6', 'p8', 'p9']) {
            $this->fail($code);
        }
        foreach ($tuple as $revision) {
            if (! is_array($revision)) {
                $this->fail($code);
            }
            $this->assertSafeInteger($revision['generation'] ?? null, $code);
            $this->assertSafeInteger($revision['revision'] ?? null, $code);
            $this->assertDigest($revision['digest'] ?? null, $code);
        }
    }

    /** @return array<string, mixed> */
    private function requiredArray(array $value, string $key, string $code): array
    {
        if (! array_key_exists($key, $value) || ! is_array($value[$key])) {
            $this->fail($code);
        }

        return $value[$key];
    }

    /** @return list<string> */
    private function stringList(mixed $value, string $code): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            $this->fail($code);
        }
        $result = [];
        foreach ($value as $item) {
            $this->assertNonEmptyString($item, $code);
            if (in_array($item, $result, true)) {
                $this->fail($code);
            }
            $result[] = $item;
        }

        return $result;
    }

    private function assertNonEmptyString(mixed $value, string $code): void
    {
        if (! is_string($value) || trim($value) === '') {
            $this->fail($code);
        }
    }

    private function assertDigest(mixed $value, string $code): void
    {
        if (! is_string($value) || preg_match('/\A[a-f0-9]{64}\z/', $value) !== 1) {
            $this->fail($code);
        }
    }

    private function assertSafeInteger(mixed $value, string $code): void
    {
        if (! is_int($value) || $value < 0 || $value > self::MAX_SAFE_INTEGER) {
            $this->fail($code);
        }
    }

    private function parseTimestamp(mixed $value, string $code): DateTimeImmutable
    {
        if (! is_string($value)
            || preg_match(
                '/\A\d{4}-\d{2}-\d{2}T(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d{1,6})?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)\z/',
                $value,
            ) !== 1) {
            $this->fail($code);
        }

        try {
            $timestamp = new DateTimeImmutable($value);
        } catch (\Exception) {
            $this->fail($code);
        }

        $errors = DateTimeImmutable::getLastErrors();
        if (is_array($errors) && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0)) {
            $this->fail($code);
        }

        return $timestamp;
    }

    private static function canonicalJson(mixed $value): string
    {
        if ($value instanceof stdClass) {
            $members = get_object_vars($value);
            ksort($members, SORT_STRING);
            $encodedMembers = [];
            foreach ($members as $key => $member) {
                $encodedMembers[] = self::encodeJson((string) $key).':'.self::canonicalJson($member);
            }

            return '{'.implode(',', $encodedMembers).'}';
        }

        if (is_array($value)) {
            return '['.implode(',', array_map(self::canonicalJson(...), $value)).']';
        }

        return self::encodeJson($value);
    }

    private static function encodeJson(mixed $value): string
    {
        try {
            return json_encode(
                $value,
                JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_LINE_TERMINATORS
                | JSON_PRESERVE_ZERO_FRACTION,
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('learning_contract.canonical_json_invalid', previous: $exception);
        }
    }

    private function fail(string $code): never
    {
        throw new InvalidArgumentException($code);
    }
}
