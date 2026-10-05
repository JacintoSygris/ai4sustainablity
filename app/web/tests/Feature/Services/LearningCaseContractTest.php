<?php

it('accepts a complete human product learning case with an authoritative closed topic universe', function () {
    $contract = new LearningCaseContract;

    $contract->assertEligibleLearningCase(learningCaseFixture(), learningAuthorityFixture());

    $reorderedRevisionTuple = learningCaseFixture();
    $revisions = $reorderedRevisionTuple['source_revisions'];
    $reorderedRevisionTuple['source_revisions'] = [
        'p9' => $revisions['p9'],
        'p5' => $revisions['p5'],
        'p8' => $revisions['p8'],
        'p6' => $revisions['p6'],
    ];
    $contract->assertEligibleLearningCase($reorderedRevisionTuple, learningAuthorityFixture());

    expect(true)->toBeTrue();
});

it('rejects unknown keys at every learning case object boundary before semantic validation', function () {
    $contract = new LearningCaseContract;
    $mutations = [
        'root case' => function (array &$case): void {
            $case['unexpected'] = 'sensitive';
        },
        'period scope' => function (array &$case): void {
            $case['period_scope']['unexpected'] = 'sensitive';
        },
        'authority' => function (array &$case): void {
            $case['authority']['unexpected'] = 'sensitive';
        },
        'provenance' => function (array &$case): void {
            $case['provenance']['unexpected'] = 'sensitive';
        },
        'source revision tuple' => function (array &$case): void {
            $case['source_revisions']['unexpected'] = [
                'generation' => 0,
                'revision' => 0,
                'digest' => str_repeat('a', 64),
            ];
        },
        'p5 revision' => function (array &$case): void {
            $case['source_revisions']['p5']['unexpected'] = 'sensitive';
        },
        'p6 revision' => function (array &$case): void {
            $case['source_revisions']['p6']['unexpected'] = 'sensitive';
        },
        'p8 revision' => function (array &$case): void {
            $case['source_revisions']['p8']['unexpected'] = 'sensitive';
        },
        'p9 revision' => function (array &$case): void {
            $case['source_revisions']['p9']['unexpected'] = 'sensitive';
        },
        'p5 snapshot' => function (array &$case): void {
            $case['p5_snapshot']['unexpected'] = 'sensitive';
        },
        'p6 snapshot' => function (array &$case): void {
            $case['p6_snapshot']['unexpected'] = 'sensitive';
        },
        'topic universe' => function (array &$case): void {
            $case['topic_universe']['unexpected'] = 'sensitive';
        },
        'datapoint universe' => function (array &$case): void {
            $case['datapoint_universe']['unexpected'] = 'sensitive';
        },
        'topic label' => function (array &$case): void {
            $case['topic_labels'][0]['unexpected'] = 'sensitive';
        },
        'datapoint decision' => function (array &$case): void {
            $case['datapoint_decisions'][0]['unexpected'] = 'sensitive';
        },
        'rights' => function (array &$case): void {
            $case['rights']['unexpected'] = 'sensitive';
        },
        'closure evidence' => function (array &$case): void {
            $case['closure_evidence']['unexpected'] = 'sensitive';
        },
    ];
    $accepted = [];
    $wrongCodes = [];

    foreach ($mutations as $location => $mutate) {
        $case = learningCaseFixture();
        $mutate($case);

        try {
            $contract->assertEligibleLearningCase($case, learningAuthorityFixture());
            $accepted[] = $location;
        } catch (\InvalidArgumentException $exception) {
            if ($exception->getMessage() !== 'learning_case.structure_invalid') {
                $wrongCodes[$location] = $exception->getMessage();
            }
        }
    }

    expect($accepted)->toBe([])
        ->and($wrongCodes)->toBe([]);
});

it('requires exact framework authority and keeps synthetic cases ineligible', function () {
    $contract = new LearningCaseContract;

    $missingFramework = learningAuthorityFixture();
    unset($missingFramework['framework_version']);
    expect(fn () => $contract->assertEligibleLearningCase(learningCaseFixture(), $missingFramework))
        ->toThrow(\InvalidArgumentException::class, 'learning_authority.structure_invalid');

    $mismatchedFramework = learningAuthorityFixture();
    $mismatchedFramework['framework_version'] = 'other-framework';
    expect(fn () => $contract->assertEligibleLearningCase(learningCaseFixture(), $mismatchedFramework))
        ->toThrow(\InvalidArgumentException::class, 'learning_case.authority_mismatch');

    $synthetic = learningCaseFixture();
    $synthetic['provenance']['source_kind'] = 'synthetic';
    expect(fn () => $contract->assertEligibleLearningCase($synthetic, learningAuthorityFixture()))
        ->toThrow(\InvalidArgumentException::class, 'learning_case.synthetic_not_eligible');
});

it('bounds every learning case revision and authorization generation to a cross-language safe integer', function () {
    $contract = new LearningCaseContract;
    $invalidValues = [-1, 9007199254740992];

    foreach (['p5', 'p6', 'p8', 'p9'] as $stage) {
        foreach (['generation', 'revision'] as $field) {
            foreach ($invalidValues as $invalidValue) {
                $case = learningCaseFixture();
                $case['source_revisions'][$stage][$field] = $invalidValue;
                expect(fn () => $contract->assertEligibleLearningCase($case, learningAuthorityFixture()))
                    ->toThrow(\InvalidArgumentException::class, 'learning_case.source_revisions_invalid');
            }
        }
    }

    foreach ($invalidValues as $invalidValue) {
        $case = learningCaseFixture();
        $case['rights']['authorization_generation'] = $invalidValue;
        expect(fn () => $contract->assertEligibleLearningCase($case, learningAuthorityFixture()))
            ->toThrow(\InvalidArgumentException::class, 'learning_case.rights_invalid');
    }
});

it('rejects missing, non-binary, missing, extra, unknown, and ambiguous human topic labels', function () {
    $contract = new LearningCaseContract;

    $missingUniverse = learningCaseFixture();
    unset($missingUniverse['topic_universe']['reviewed_topic_ids']);
    expect(fn () => $contract->assertEligibleLearningCase($missingUniverse, learningAuthorityFixture()))
        ->toThrow(\InvalidArgumentException::class, 'learning_case.structure_invalid');

    foreach ([true, '1', null, 2] as $invalidValue) {
        $nonBinary = learningCaseFixture();
        $nonBinary['topic_labels'][0]['value'] = $invalidValue;
        expect(fn () => $contract->assertEligibleLearningCase($nonBinary, learningAuthorityFixture()))
            ->toThrow(\InvalidArgumentException::class, 'learning_case.topic_label_value_invalid');
    }

    $missingLabel = learningCaseFixture();
    array_pop($missingLabel['topic_labels']);
    expect(fn () => $contract->assertEligibleLearningCase($missingLabel, learningAuthorityFixture()))
        ->toThrow(\InvalidArgumentException::class, 'learning_case.topic_label_set_mismatch');

    $extraLabel = learningCaseFixture();
    $extraLabel['topic_labels'][] = ['topic_id' => '103', 'value' => 0, 'observed_mask' => 1];
    expect(fn () => $contract->assertEligibleLearningCase($extraLabel, learningAuthorityFixture()))
        ->toThrow(\InvalidArgumentException::class, 'learning_case.topic_label_set_mismatch');

    $unknown = learningCaseFixture();
    $unknown['topic_universe']['reviewed_topic_ids'][] = '999';
    $unknown['topic_labels'][] = ['topic_id' => '999', 'value' => 0, 'observed_mask' => 1];
    expect(fn () => $contract->assertEligibleLearningCase($unknown, learningAuthorityFixture()))
        ->toThrow(\InvalidArgumentException::class, 'learning_case.unknown_topic_id');

    $ambiguousAuthority = learningAuthorityFixture();
    $ambiguousAuthority['ambiguous_topic_ids'] = ['102'];
    expect(fn () => $contract->assertEligibleLearningCase(learningCaseFixture(), $ambiguousAuthority))
        ->toThrow(\InvalidArgumentException::class, 'learning_case.ambiguous_topic_id');
});

it('keeps outside-scope topics as metadata and rejects unaccepted closure or rights policy', function () {
    $contract = new LearningCaseContract;
    $case = learningCaseFixture();

    expect($case['topic_universe']['outside_scope_topic_ids'])->toBe(['103'])
        ->and(array_column($case['topic_labels'], 'topic_id'))->not->toContain('103');

    $unacceptedClosure = learningCaseFixture();
    $unacceptedClosure['closure_evidence']['declaration_status'] = 'unaccepted';
    expect(fn () => $contract->assertEligibleLearningCase($unacceptedClosure, learningAuthorityFixture()))
        ->toThrow(\InvalidArgumentException::class, 'learning_case.closure_not_accepted');

    $unapprovedPolicy = learningCaseFixture();
    $unapprovedPolicy['rights']['policy_status'] = 'unapproved';
    expect(fn () => $contract->assertEligibleLearningCase($unapprovedPolicy, learningAuthorityFixture()))
        ->toThrow(\InvalidArgumentException::class, 'learning_case.rights_not_eligible');

    $revokedRights = learningCaseFixture();
    $revokedRights['rights']['state'] = 'revoked';
    expect(fn () => $contract->assertEligibleLearningCase($revokedRights, learningAuthorityFixture()))
        ->toThrow(\InvalidArgumentException::class, 'learning_case.rights_not_eligible');
});

it('requires report-derived unobserved labels to use an explicit zero mask and no negative value', function () {
    $contract = new LearningCaseContract;
    $reportCase = learningCaseFixture();
    $reportCase['provenance']['source_kind'] = 'report';
    $reportCase['topic_labels'][1] = ['topic_id' => '102', 'value' => null, 'observed_mask' => 0];

    $contract->assertEligibleLearningCase($reportCase, learningAuthorityFixture());

    $inventedNegative = $reportCase;
    $inventedNegative['topic_labels'][1]['value'] = 0;
    expect(fn () => $contract->assertEligibleLearningCase($inventedNegative, learningAuthorityFixture()))
        ->toThrow(\InvalidArgumentException::class, 'learning_case.report_unobserved_label_requires_null');

    $missingMask = $reportCase;
    unset($missingMask['topic_labels'][1]['observed_mask']);
    expect(fn () => $contract->assertEligibleLearningCase($missingMask, learningAuthorityFixture()))
        ->toThrow(\InvalidArgumentException::class, 'learning_case.structure_invalid');
});

it('accepts a fresh monotonic eligibility manifest with an exact canonical digest', function () {
    $contract = new LearningCaseContract;

    $contract->assertEligibilityManifest(
        learningEligibilityManifestFixture(),
        previousGeneration: 7,
        now: new DateTimeImmutable('2026-10-02T09:30:00Z'),
    );

    expect(true)->toBeTrue();
});

it('rejects unknown keys at every eligibility manifest object boundary before digest validation', function () {
    $contract = new LearningCaseContract;
    $mutations = [
        'root manifest' => function (array &$manifest): void {
            $manifest['unexpected'] = 'sensitive';
        },
        'case entry' => function (array &$manifest): void {
            $manifest['cases'][0]['unexpected'] = 'sensitive';
        },
        'tombstones container' => function (array &$manifest): void {
            $manifest['tombstones']['unexpected'] = [];
        },
        'revoked tombstone' => function (array &$manifest): void {
            $manifest['tombstones']['revoked'][0]['unexpected'] = 'sensitive';
        },
        'deleted tombstone' => function (array &$manifest): void {
            $manifest['tombstones']['deleted'][0]['unexpected'] = 'sensitive';
        },
    ];
    $accepted = [];
    $wrongCodes = [];

    foreach ($mutations as $location => $mutate) {
        $manifest = learningEligibilityManifestFixture();
        $mutate($manifest);
        $manifest = learningManifestWithDigest($manifest);

        try {
            $contract->assertEligibilityManifest(
                $manifest,
                previousGeneration: 7,
                now: new DateTimeImmutable('2026-10-02T09:30:00Z'),
            );
            $accepted[] = $location;
        } catch (\InvalidArgumentException $exception) {
            if ($exception->getMessage() !== 'learning_manifest.structure_invalid') {
                $wrongCodes[$location] = $exception->getMessage();
            }
        }
    }

    expect($accepted)->toBe([])
        ->and($wrongCodes)->toBe([]);
});

it('requires current manifest cases to be absent from every revoked or deleted tombstone', function () {
    $contract = new LearningCaseContract;

    foreach ([str_repeat('1', 64), str_repeat('a', 64)] as $tombstoneHash) {
        $manifest = learningEligibilityManifestFixture();
        $manifest['eligible_case_ids'] = [];
        $manifest['tombstones']['revoked'][0]['case_id'] = 'case-001';
        $manifest['tombstones']['revoked'][0]['case_hash'] = $tombstoneHash;
        $manifest = learningManifestWithDigest($manifest);
        expect(fn () => $contract->assertEligibilityManifest(
            $manifest,
            previousGeneration: 7,
            now: new DateTimeImmutable('2026-10-02T09:30:00Z'),
        ))->toThrow(\InvalidArgumentException::class, 'learning_manifest.current_case_tombstoned');
    }

    $duplicateAcrossKinds = learningEligibilityManifestFixture();
    $duplicateAcrossKinds['tombstones']['deleted'][0]['case_id'] = 'case-revoked';
    $duplicateAcrossKinds = learningManifestWithDigest($duplicateAcrossKinds);
    expect(fn () => $contract->assertEligibilityManifest(
        $duplicateAcrossKinds,
        previousGeneration: 7,
        now: new DateTimeImmutable('2026-10-02T09:30:00Z'),
    ))->toThrow(\InvalidArgumentException::class, 'learning_manifest.duplicate_tombstone');
});

it('uses a half-open validity interval and bounds manifest and previous generations', function () {
    $contract = new LearningCaseContract;

    expect(fn () => $contract->assertEligibilityManifest(
        learningEligibilityManifestFixture(),
        previousGeneration: 7,
        now: new DateTimeImmutable('2026-10-02T10:00:00Z'),
    ))->toThrow(\InvalidArgumentException::class, 'learning_manifest.expired');

    foreach ([-1, 9007199254740992] as $invalidGeneration) {
        $manifest = learningEligibilityManifestFixture();
        $manifest['generation'] = $invalidGeneration;
        $manifest = learningManifestWithDigest($manifest);
        expect(fn () => $contract->assertEligibilityManifest(
            $manifest,
            previousGeneration: 0,
            now: new DateTimeImmutable('2026-10-02T09:30:00Z'),
        ))->toThrow(\InvalidArgumentException::class, 'learning_manifest.generation_invalid');
    }

    foreach ([-1, 9007199254740992] as $invalidPreviousGeneration) {
        expect(fn () => $contract->assertEligibilityManifest(
            learningEligibilityManifestFixture(),
            previousGeneration: $invalidPreviousGeneration,
            now: new DateTimeImmutable('2026-10-02T09:30:00Z'),
        ))->toThrow(\InvalidArgumentException::class, 'learning_manifest.previous_generation_invalid');
    }
});

it('rejects coerced and out-of-range previous generations from non-strict callers', function () {
    $contract = new LearningCaseContract;
    $accepted = [];
    $wrongCodes = [];

    foreach ([true, false, '7', 7.9, -1, 9007199254740992] as $invalidPreviousGeneration) {
        try {
            $contract->assertEligibilityManifest(
                learningEligibilityManifestFixture(),
                previousGeneration: $invalidPreviousGeneration,
                now: new DateTimeImmutable('2026-10-02T09:30:00Z'),
            );
            $accepted[] = $invalidPreviousGeneration;
        } catch (\InvalidArgumentException $exception) {
            if ($exception->getMessage() !== 'learning_manifest.previous_generation_invalid') {
                $wrongCodes[] = [$invalidPreviousGeneration, $exception->getMessage()];
            }
        }
    }

    expect($accepted)->toBe([])
        ->and($wrongCodes)->toBe([]);
});

it('preserves raw JSON object and list identity at every public contract list boundary', function () {
    $contract = new LearningCaseContract;
    $caseListPaths = [
        ['topic_universe', 'reviewed_topic_ids'],
        ['topic_universe', 'outside_scope_topic_ids'],
        ['topic_labels'],
        ['datapoint_universe', 'reviewed_datapoint_ids'],
        ['datapoint_universe', 'outside_scope_datapoint_ids'],
        ['datapoint_decisions'],
        ['datapoint_decisions', 0, 'reason_codes'],
    ];
    $authorityListPaths = [
        ['topic_ids'],
        ['datapoint_ids'],
        ['ambiguous_topic_ids'],
        ['ambiguous_datapoint_ids'],
    ];
    $manifestListPaths = [
        ['cases'],
        ['eligible_case_ids'],
        ['tombstones', 'revoked'],
        ['tombstones', 'deleted'],
    ];
    $wrong = [];

    foreach (['empty_object', 'numeric_keyed_object'] as $shape) {
        foreach ($caseListPaths as $path) {
            $case = learningCaseFixture();
            replaceLearningFixturePathWithObject($case, $path, $shape);
            try {
                $contract->assertEligibleLearningCase(
                    encodeLearningJson($case),
                    encodeLearningJson(learningAuthorityFixture()),
                );
                $wrong[] = ['case', $shape, $path, 'accepted'];
            } catch (\Throwable $exception) {
                if (! $exception instanceof \InvalidArgumentException
                    || $exception->getMessage() !== 'learning_case.structure_invalid') {
                    $wrong[] = ['case', $shape, $path, $exception::class, $exception->getMessage()];
                }
            }
        }

        foreach ($authorityListPaths as $path) {
            $authority = learningAuthorityFixture();
            replaceLearningFixturePathWithObject($authority, $path, $shape);
            try {
                $contract->assertEligibleLearningCase(
                    encodeLearningJson(learningCaseFixture()),
                    encodeLearningJson($authority),
                );
                $wrong[] = ['authority', $shape, $path, 'accepted'];
            } catch (\Throwable $exception) {
                if (! $exception instanceof \InvalidArgumentException
                    || $exception->getMessage() !== 'learning_authority.structure_invalid') {
                    $wrong[] = ['authority', $shape, $path, $exception::class, $exception->getMessage()];
                }
            }
        }

        foreach ($manifestListPaths as $path) {
            $manifest = learningEligibilityManifestFixture();
            replaceLearningFixturePathWithObject($manifest, $path, $shape);
            try {
                $contract->assertEligibilityManifest(
                    encodeLearningJson($manifest),
                    previousGeneration: 7,
                    now: new DateTimeImmutable('2026-10-02T09:30:00Z'),
                );
                $wrong[] = ['manifest', $shape, $path, 'accepted'];
            } catch (\Throwable $exception) {
                if (! $exception instanceof \InvalidArgumentException
                    || $exception->getMessage() !== 'learning_manifest.structure_invalid') {
                    $wrong[] = ['manifest', $shape, $path, $exception::class, $exception->getMessage()];
                }
            }
        }
    }

    foreach ([
        ['case root array', fn () => $contract->assertEligibleLearningCase('[]', encodeLearningJson(learningAuthorityFixture()))],
        ['authority root array', fn () => $contract->assertEligibleLearningCase(encodeLearningJson(learningCaseFixture()), '[]')],
        ['manifest root array', fn () => $contract->assertEligibilityManifest(
            '[]',
            previousGeneration: 7,
            now: new DateTimeImmutable('2026-10-02T09:30:00Z'),
        )],
    ] as [$name, $probe]) {
        try {
            $probe();
            $wrong[] = [$name, 'accepted'];
        } catch (\Throwable $exception) {
            if (! $exception instanceof \InvalidArgumentException
                || $exception->getMessage() !== match ($name) {
                    'authority root array' => 'learning_authority.structure_invalid',
                    'manifest root array' => 'learning_manifest.structure_invalid',
                    default => 'learning_case.structure_invalid',
                }) {
                $wrong[] = [$name, $exception::class, $exception->getMessage()];
            }
        }
    }

    expect($wrong)->toBe([]);
});

it('rejects malformed, over-depth, and unsafe-number raw JSON while accepting schema-valid empty lists', function () {
    $contract = new LearningCaseContract;
    $tooDeep = str_repeat('{"x":', 513).'null'.str_repeat('}', 513);

    expect(fn () => $contract->assertEligibleLearningCase('{', encodeLearningJson(learningAuthorityFixture())))
        ->toThrow(\InvalidArgumentException::class, 'learning_case.json_invalid');
    expect(fn () => $contract->assertEligibleLearningCase(encodeLearningJson(learningCaseFixture()), '{'))
        ->toThrow(\InvalidArgumentException::class, 'learning_authority.json_invalid');
    expect(fn () => $contract->assertEligibilityManifest(
        $tooDeep,
        previousGeneration: 7,
        now: new DateTimeImmutable('2026-10-02T09:30:00Z'),
    ))->toThrow(\InvalidArgumentException::class, 'learning_manifest.json_invalid');

    $emptyCase = learningCaseFixture();
    $emptyCase['topic_universe'] = ['reviewed_topic_ids' => [], 'outside_scope_topic_ids' => []];
    $emptyCase['topic_labels'] = [];
    $emptyCase['datapoint_universe'] = ['reviewed_datapoint_ids' => [], 'outside_scope_datapoint_ids' => []];
    $emptyCase['datapoint_decisions'] = [];
    $emptyAuthority = learningAuthorityFixture();
    $emptyAuthority['topic_ids'] = [];
    $emptyAuthority['datapoint_ids'] = [];
    $contract->assertEligibleLearningCase(encodeLearningJson($emptyCase), encodeLearningJson($emptyAuthority));

    $emptyManifest = learningEligibilityManifestFixture();
    $emptyManifest['cases'] = [];
    $emptyManifest['eligible_case_ids'] = [];
    $emptyManifest['tombstones'] = ['revoked' => [], 'deleted' => []];
    $emptyManifest = learningManifestWithDigest($emptyManifest);
    $contract->assertEligibilityManifest(
        encodeLearningJson($emptyManifest),
        previousGeneration: 7,
        now: new DateTimeImmutable('2026-10-02T09:30:00Z'),
    );

    foreach ([
        ['case authorization float', ['rights', 'authorization_generation'], 4.0, 'learning_case.rights_invalid'],
        ['case revision huge', ['source_revisions', 'p5', 'revision'], 9223372036854775808, 'learning_case.source_revisions_invalid'],
    ] as [$name, $path, $value, $code]) {
        $case = learningCaseFixture();
        replaceLearningFixturePath($case, $path, $value);
        expect(fn () => $contract->assertEligibleLearningCase(
            encodeLearningJson($case),
            encodeLearningJson(learningAuthorityFixture()),
        ))->toThrow(\InvalidArgumentException::class, $code);
    }
});

it('rejects duplicate JSON object members and canonicalization input errors', function () {
    $contract = new LearningCaseContract;
    $caseJson = encodeLearningJson(learningCaseFixture());
    $authorityJson = encodeLearningJson(learningAuthorityFixture());
    $manifestJson = encodeLearningJson(learningEligibilityManifestFixture());

    $duplicateCaseRoot = str_replace(
        '"schema_version":"learning-case-v1"',
        '"schema_version":"learning-case-v1","schema_version":"other"',
        $caseJson,
    );
    expect(fn () => $contract->assertEligibleLearningCase($duplicateCaseRoot, $authorityJson))
        ->toThrow(\InvalidArgumentException::class, 'learning_case.structure_invalid');

    $duplicateAuthorityList = str_replace('"topic_ids":', '"topic_ids":[],"topic_ids":', $authorityJson);
    expect(fn () => $contract->assertEligibleLearningCase($caseJson, $duplicateAuthorityList))
        ->toThrow(\InvalidArgumentException::class, 'learning_authority.structure_invalid');

    $escapedDuplicateManifestList = str_replace('"cases":', '"cases":[],"c\u0061ses":', $manifestJson);
    expect(fn () => $contract->assertEligibilityManifest(
        $escapedDuplicateManifestList,
        previousGeneration: 7,
        now: new DateTimeImmutable('2026-10-02T09:30:00Z'),
    ))->toThrow(\InvalidArgumentException::class, 'learning_manifest.structure_invalid');

    expect(fn () => LearningCaseContract::eligibilityManifestDigest('{'))
        ->toThrow(\InvalidArgumentException::class, 'learning_manifest.json_invalid');
    expect(fn () => LearningCaseContract::eligibilityManifestDigest('[]'))
        ->toThrow(\InvalidArgumentException::class, 'learning_manifest.structure_invalid');
    expect(fn () => LearningCaseContract::eligibilityManifestDigest($escapedDuplicateManifestList))
        ->toThrow(\InvalidArgumentException::class, 'learning_manifest.structure_invalid');
});

it('enforces RFC3339 clock and offset bounds before DateTime normalization', function () {
    $contract = new LearningCaseContract;
    $invalidTimestamps = [
        '2026-10-02T09:00:00+24:00',
        '2026-10-02T09:00:00-24:00',
        '2026-10-02T09:00:00+00:60',
        '2026-10-02T09:60:00Z',
        '2026-10-02T24:00:00Z',
        '2026-10-02T09:00:60Z',
        '2026-10-02T09:00:00.Z',
        '2026-10-02T09:00:00+0:00',
        '2026-10-02T09:00:00+00',
    ];
    $accepted = [];
    $wrongCodes = [];

    foreach ($invalidTimestamps as $timestamp) {
        $manifest = learningEligibilityManifestFixture();
        $manifest['tombstones']['revoked'][0]['at'] = $timestamp;
        $manifest = learningManifestWithDigest($manifest);
        try {
            $contract->assertEligibilityManifest(
                $manifest,
                previousGeneration: 7,
                now: new DateTimeImmutable('2026-10-02T09:30:00Z'),
            );
            $accepted[] = $timestamp;
        } catch (\InvalidArgumentException $exception) {
            if ($exception->getMessage() !== 'learning_manifest.tombstones_invalid') {
                $wrongCodes[$timestamp] = $exception->getMessage();
            }
        }
    }

    expect($accepted)->toBe([])
        ->and($wrongCodes)->toBe([]);

    foreach (['2026-10-02T09:00:00Z', '2026-10-02T09:00:00+00:00', '2026-10-02T09:00:00-00:00', '2026-10-02T09:00:00+23:59'] as $timestamp) {
        $manifest = learningEligibilityManifestFixture();
        $manifest['tombstones']['revoked'][0]['at'] = $timestamp;
        $manifest = learningManifestWithDigest($manifest);
        $contract->assertEligibilityManifest(
            $manifest,
            previousGeneration: 7,
            now: new DateTimeImmutable('2026-10-02T09:30:00Z'),
        );
    }

    $equivalentInstants = learningEligibilityManifestFixture();
    $equivalentInstants['issued_at'] = '2026-10-02T11:00:00+02:00';
    $equivalentInstants['valid_until'] = '2026-10-02T12:00:00+02:00';
    $equivalentInstants = learningManifestWithDigest($equivalentInstants);
    $contract->assertEligibilityManifest(
        $equivalentInstants,
        previousGeneration: 7,
        now: new DateTimeImmutable('2026-10-02T09:30:00Z'),
    );
});

it('supports only one through six fractional second digits without precision collapse', function () {
    $contract = new LearningCaseContract;

    $notYetValidBeyondMicroseconds = learningEligibilityManifestFixture();
    $notYetValidBeyondMicroseconds['issued_at'] = '2026-10-02T09:00:00.0000009Z';
    $notYetValidBeyondMicroseconds = learningManifestWithDigest($notYetValidBeyondMicroseconds);
    expect(fn () => $contract->assertEligibilityManifest(
        $notYetValidBeyondMicroseconds,
        previousGeneration: 7,
        now: new DateTimeImmutable('2026-10-02T09:00:00.000000Z'),
    ))->toThrow(\InvalidArgumentException::class, 'learning_manifest.issued_at_invalid');

    $collapsedPositiveInterval = learningEligibilityManifestFixture();
    $collapsedPositiveInterval['issued_at'] = '2026-10-02T09:00:00.0000001Z';
    $collapsedPositiveInterval['valid_until'] = '2026-10-02T09:00:00.0000002Z';
    $collapsedPositiveInterval = learningManifestWithDigest($collapsedPositiveInterval);
    expect(fn () => $contract->assertEligibilityManifest(
        $collapsedPositiveInterval,
        previousGeneration: 7,
        now: new DateTimeImmutable('2026-10-02T09:00:00.000000Z'),
    ))->toThrow(\InvalidArgumentException::class, 'learning_manifest.issued_at_invalid');

    $overPreciseClosure = learningCaseFixture();
    $overPreciseClosure['closure_evidence']['recorded_at'] = '2026-10-02T09:00:00.1234567Z';
    expect(fn () => $contract->assertEligibleLearningCase($overPreciseClosure, learningAuthorityFixture()))
        ->toThrow(\InvalidArgumentException::class, 'learning_case.closure_invalid');

    $overPreciseTombstone = learningEligibilityManifestFixture();
    $overPreciseTombstone['tombstones']['revoked'][0]['at'] = '2026-10-02T09:00:00.123456789+00:00';
    $overPreciseTombstone = learningManifestWithDigest($overPreciseTombstone);
    expect(fn () => $contract->assertEligibilityManifest(
        $overPreciseTombstone,
        previousGeneration: 7,
        now: new DateTimeImmutable('2026-10-02T09:30:00Z'),
    ))->toThrow(\InvalidArgumentException::class, 'learning_manifest.tombstones_invalid');

    foreach (range(1, 6) as $digits) {
        $fraction = str_repeat('1', $digits);
        foreach (['Z', '+02:30', '-02:30'] as $offset) {
            $case = learningCaseFixture();
            $case['closure_evidence']['recorded_at'] = "2026-10-02T09:00:00.{$fraction}{$offset}";
            $contract->assertEligibleLearningCase($case, learningAuthorityFixture());

            $manifest = learningEligibilityManifestFixture();
            $manifest['tombstones']['revoked'][0]['at'] = "2026-10-02T09:00:00.{$fraction}{$offset}";
            $manifest = learningManifestWithDigest($manifest);
            $contract->assertEligibilityManifest(
                $manifest,
                previousGeneration: 7,
                now: new DateTimeImmutable('2026-10-02T09:30:00Z'),
            );
        }
    }

    $exactIssuedAt = learningEligibilityManifestFixture();
    $exactIssuedAt['issued_at'] = '2026-10-02T11:00:00.123456+02:00';
    $exactIssuedAt['valid_until'] = '2026-10-02T12:00:00.123456+02:00';
    $exactIssuedAt = learningManifestWithDigest($exactIssuedAt);
    $contract->assertEligibilityManifest(
        $exactIssuedAt,
        previousGeneration: 7,
        now: new DateTimeImmutable('2026-10-02T09:00:00.123456Z'),
    );
    expect(fn () => $contract->assertEligibilityManifest(
        $exactIssuedAt,
        previousGeneration: 7,
        now: new DateTimeImmutable('2026-10-02T10:00:00.123456Z'),
    ))->toThrow(\InvalidArgumentException::class, 'learning_manifest.expired');
});

it('uses the documented canonical Unicode and escaping flags in an exact digest vector', function () {
    $vector = [
        'z' => ['array/first', 'array-second'],
        'a' => "ordinary-é / \" \\ \n \t \u{2028}\u{2029}",
        'canonical_digest' => str_repeat('0', 64),
    ];

    expect(LearningCaseContract::eligibilityManifestDigest($vector))
        ->toBe('dda763900bd26b1f7e83a1ac873e93d8663a0702b235611ca41e33546e1bfedd');
});

it('produces the same canonical digest for recursively reordered object keys while preserving array order', function () {
    $manifest = learningEligibilityManifestFixture();
    $secondCase = $manifest['cases'][0];
    $secondCase['case_id'] = 'case-002';
    $secondCase['case_hash'] = str_repeat('4', 64);
    $manifest['cases'][] = $secondCase;
    $manifest['eligible_case_ids'][] = 'case-002';
    $reordered = recursivelyReverseObjectKeys($manifest);

    expect($reordered)->not->toBe($manifest)
        ->and($reordered['eligible_case_ids'])->toBe($manifest['eligible_case_ids'])
        ->and(array_column($reordered['cases'], 'case_id'))->toBe(['case-001', 'case-002'])
        ->and(LearningCaseContract::eligibilityManifestDigest($reordered))
        ->toBe(LearningCaseContract::eligibilityManifestDigest($manifest));

    $reversedArray = $manifest;
    $reversedArray['eligible_case_ids'] = array_reverse($reversedArray['eligible_case_ids']);
    expect(LearningCaseContract::eligibilityManifestDigest($reversedArray))
        ->not->toBe(LearningCaseContract::eligibilityManifestDigest($manifest));
});

it('rejects malformed, non-monotonic, expired, and digest-mismatched eligibility manifests', function () {
    $contract = new LearningCaseContract;

    $malformed = learningEligibilityManifestFixture();
    unset($malformed['schema_version']);
    expect(fn () => $contract->assertEligibilityManifest(
        $malformed,
        previousGeneration: 7,
        now: new DateTimeImmutable('2026-10-02T09:30:00Z'),
    ))->toThrow(\InvalidArgumentException::class, 'learning_manifest.structure_invalid');

    expect(fn () => $contract->assertEligibilityManifest(
        learningEligibilityManifestFixture(),
        previousGeneration: 8,
        now: new DateTimeImmutable('2026-10-02T09:30:00Z'),
    ))->toThrow(\InvalidArgumentException::class, 'learning_manifest.generation_not_monotonic');

    expect(fn () => $contract->assertEligibilityManifest(
        learningEligibilityManifestFixture(),
        previousGeneration: 7,
        now: new DateTimeImmutable('2026-10-02T10:00:01Z'),
    ))->toThrow(\InvalidArgumentException::class, 'learning_manifest.expired');

    $digestMismatch = learningEligibilityManifestFixture();
    $digestMismatch['canonical_digest'] = str_repeat('a', 64);
    expect(fn () => $contract->assertEligibilityManifest(
        $digestMismatch,
        previousGeneration: 7,
        now: new DateTimeImmutable('2026-10-02T09:30:00Z'),
    ))->toThrow(\InvalidArgumentException::class, 'learning_manifest.digest_mismatch');

    $invalidCalendarDate = learningEligibilityManifestFixture();
    $invalidCalendarDate['issued_at'] = '2026-02-31T09:00:00Z';
    expect(fn () => $contract->assertEligibilityManifest(
        $invalidCalendarDate,
        previousGeneration: 7,
        now: new DateTimeImmutable('2026-10-02T09:30:00Z'),
    ))->toThrow(\InvalidArgumentException::class, 'learning_manifest.issued_at_invalid');
});

/** @return array<string, mixed> */
function learningCaseFixture(): array
{
    return [
        'schema_version' => 'learning-case-v1',
        'case_id' => 'case-001',
        'case_hash' => str_repeat('1', 64),
        'company_group_key' => 'group-pseudonymous-001',
        'period_scope' => [
            'period_key' => '2025',
            'perimeter_key' => 'entity-only',
        ],
        'authority' => [
            'framework_version' => 'esrs-2023',
            'catalog_version' => 'ar16-v1',
            'catalog_digest' => str_repeat('a', 64),
            'mapping_version' => 'ar16-python-v1',
            'mapping_digest' => str_repeat('b', 64),
        ],
        'provenance' => [
            'source_kind' => 'human_product',
            'source_record_digest' => str_repeat('c', 64),
            'source_revision' => 'source-r1',
        ],
        'source_revisions' => learningRevisionTupleFixture(),
        'p5_snapshot' => [
            'schema_version' => 'p5-learning-input-v1',
            'digest' => str_repeat('4', 64),
        ],
        'p6_snapshot' => [
            'model_profile' => 'candidate-profile',
            'model_digest' => str_repeat('5', 64),
            'policy_digest' => str_repeat('6', 64),
        ],
        'topic_universe' => [
            'reviewed_topic_ids' => ['101', '102'],
            'outside_scope_topic_ids' => ['103'],
        ],
        'topic_labels' => [
            ['topic_id' => '101', 'value' => 1, 'observed_mask' => 1],
            ['topic_id' => '102', 'value' => 0, 'observed_mask' => 1],
        ],
        'datapoint_universe' => [
            'reviewed_datapoint_ids' => ['E1.IRO-1_01'],
            'outside_scope_datapoint_ids' => ['E1.IRO-1_02'],
        ],
        'datapoint_decisions' => [
            [
                'datapoint_id' => 'E1.IRO-1_01',
                'relevant' => true,
                'selected_to_answer' => false,
                'reason_codes' => ['scope'],
                'note' => null,
            ],
        ],
        'rights' => [
            'policy_version' => 'learning-rights-v1',
            'policy_digest' => str_repeat('7', 64),
            'policy_status' => 'approved',
            'state' => 'granted',
            'authorization_generation' => 4,
        ],
        'closure_evidence' => [
            'declaration_version' => 'technical-closure-v1',
            'declaration_status' => 'accepted',
            'reviewed_universe' => true,
            'final_for_period_scope' => true,
            'server_actor_id' => 'system:laravel',
            'recorded_at' => '2026-10-02T09:00:00Z',
        ],
    ];
}

/** @return array<string, mixed> */
function learningAuthorityFixture(): array
{
    return [
        'framework_version' => 'esrs-2023',
        'catalog_version' => 'ar16-v1',
        'catalog_digest' => str_repeat('a', 64),
        'mapping_version' => 'ar16-python-v1',
        'mapping_digest' => str_repeat('b', 64),
        'topic_ids' => ['101', '102', '103'],
        'datapoint_ids' => ['E1.IRO-1_01', 'E1.IRO-1_02'],
        'ambiguous_topic_ids' => [],
        'ambiguous_datapoint_ids' => [],
    ];
}

/** @return array<string, array<string, int|string>> */
function learningRevisionTupleFixture(): array
{
    return [
        'p5' => ['generation' => 1, 'revision' => 2, 'digest' => str_repeat('d', 64)],
        'p6' => ['generation' => 2, 'revision' => 3, 'digest' => str_repeat('e', 64)],
        'p8' => ['generation' => 3, 'revision' => 4, 'digest' => str_repeat('f', 64)],
        'p9' => ['generation' => 4, 'revision' => 5, 'digest' => str_repeat('0', 64)],
    ];
}

/** @return array<string, mixed> */
function learningEligibilityManifestFixture(): array
{
    return [
        'schema_version' => 'learning-eligibility-v1',
        'generation' => 8,
        'issued_at' => '2026-10-02T09:00:00Z',
        'valid_until' => '2026-10-02T10:00:00Z',
        'cases' => [[
            'case_id' => 'case-001',
            'case_hash' => str_repeat('1', 64),
            'source_revisions' => learningRevisionTupleFixture(),
            'rights_digest' => str_repeat('6', 64),
            'policy_digest' => str_repeat('7', 64),
        ]],
        'eligible_case_ids' => ['case-001'],
        'tombstones' => [
            'revoked' => [[
                'case_id' => 'case-revoked',
                'case_hash' => str_repeat('2', 64),
                'at' => '2026-10-02T08:00:00Z',
            ]],
            'deleted' => [[
                'case_id' => 'case-deleted',
                'case_hash' => str_repeat('3', 64),
                'at' => '2026-10-02T08:30:00Z',
            ]],
        ],
        'rights_snapshot_digest' => str_repeat('8', 64),
        'eligibility_policy_digest' => str_repeat('9', 64),
        'canonical_digest' => 'cc97b31fe4c4905a9bd34dd161a6c8566c4456c21e7aecbf319836a4f8efcc7d',
    ];
}

/** @param array<string, mixed> $manifest
 *  @return array<string, mixed>
 */
function learningManifestWithDigest(array $manifest): array
{
    $manifest['canonical_digest'] = LearningCaseContract::eligibilityManifestDigest($manifest);

    return $manifest;
}

function recursivelyReverseObjectKeys(mixed $value): mixed
{
    if (! is_array($value)) {
        return $value;
    }

    if (array_is_list($value)) {
        return array_map(recursivelyReverseObjectKeys(...), $value);
    }

    $reordered = [];
    foreach (array_reverse(array_keys($value)) as $key) {
        $reordered[$key] = recursivelyReverseObjectKeys($value[$key]);
    }

    return $reordered;
}

/** @param array<string|int, mixed> $fixture
 *  @param list<string|int> $path
 */
function replaceLearningFixturePathWithObject(array &$fixture, array $path, string $shape): void
{
    $cursor =& $fixture;
    foreach (array_slice($path, 0, -1) as $key) {
        $cursor =& $cursor[$key];
    }
    $leaf = $path[array_key_last($path)];
    $original = $cursor[$leaf];
    $cursor[$leaf] = $shape === 'empty_object'
        ? (object) []
        : (object) ['0' => is_array($original) && $original !== [] ? $original[0] : 'shape-probe'];
}

/** @param array<string|int, mixed> $fixture
 *  @param list<string|int> $path
 */
function replaceLearningFixturePath(array &$fixture, array $path, mixed $value): void
{
    $cursor =& $fixture;
    foreach (array_slice($path, 0, -1) as $key) {
        $cursor =& $cursor[$key];
    }
    $cursor[$path[array_key_last($path)]] = $value;
}

function encodeLearningJson(mixed $value): string
{
    return json_encode(
        $value,
        JSON_THROW_ON_ERROR
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_LINE_TERMINATORS
        | JSON_PRESERVE_ZERO_FRACTION,
    );
}

/**
 * Test adapter for legacy array fixtures. Production exposes only raw-JSON boundaries;
 * raw strings pass through unchanged so structural identity tests exercise those APIs.
 */
final class LearningCaseContract
{
    private App\Services\LearningCaseContract $contract;

    public function __construct()
    {
        $this->contract = new App\Services\LearningCaseContract;
    }

    public function assertEligibleLearningCase(mixed $case, mixed $authority): void
    {
        $this->contract->assertEligibleLearningCase(
            is_string($case) ? $case : encodeLearningJson($case),
            is_string($authority) ? $authority : encodeLearningJson($authority),
        );
    }

    public function assertEligibilityManifest(
        mixed $manifest,
        mixed $previousGeneration,
        DateTimeImmutable $now,
    ): void {
        $this->contract->assertEligibilityManifest(
            is_string($manifest) ? $manifest : encodeLearningJson($manifest),
            $previousGeneration,
            $now,
        );
    }

    public static function eligibilityManifestDigest(mixed $manifest): string
    {
        return App\Services\LearningCaseContract::eligibilityManifestDigest(
            is_string($manifest) ? $manifest : encodeLearningJson($manifest),
        );
    }
}
