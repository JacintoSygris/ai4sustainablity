<?php

it('validates the canonical AR16 matter to DR mapping file', function () {
    $this->artisan('esrs:validate-matter-dr-mapping')
        ->expectsOutputToContain('source.status: approved')
        ->expectsOutputToContain('needs_review_topics: 0')
        ->expectsOutputToContain('P9 unlock: yes')
        ->assertExitCode(0);
});

it('fails when a mapping references a DR that does not exist for its standard', function () {
    $path = tamperedCanonicalMap(function (array $payload): array {
        $payload['mappings'][0]['disclosure_requirements'][] = 'E1-99';

        return $payload;
    });

    $this->artisan('esrs:validate-matter-dr-mapping', ['path' => $path])
        ->expectsOutputToContain('unknown_disclosure_requirement')
        ->assertExitCode(1);

    @unlink($path);
});

it('fails when a topic id is mapped more than once', function () {
    $path = tamperedCanonicalMap(function (array $payload): array {
        $payload['mappings'][] = $payload['mappings'][0];

        return $payload;
    });

    $this->artisan('esrs:validate-matter-dr-mapping', ['path' => $path])
        ->expectsOutputToContain('duplicate_ar16_topic_id')
        ->assertExitCode(1);

    @unlink($path);
});

it('fails when coverage of the selectable AR16 topics is incomplete', function () {
    $path = tamperedCanonicalMap(function (array $payload): array {
        array_pop($payload['mappings']);

        return $payload;
    });

    $this->artisan('esrs:validate-matter-dr-mapping', ['path' => $path])
        ->expectsOutputToContain('missing_ar16_topic_coverage')
        ->assertExitCode(1);

    @unlink($path);
});

it('fails when the esrs_code does not match the AR16 topic standard', function () {
    $path = tamperedCanonicalMap(function (array $payload): array {
        $payload['mappings'][0]['esrs_code'] = 'G1';

        return $payload;
    });

    $this->artisan('esrs:validate-matter-dr-mapping', ['path' => $path])
        ->expectsOutputToContain('esrs_code_mismatch')
        ->assertExitCode(1);

    @unlink($path);
});

it('fails when source.status is not draft or approved', function () {
    $path = tamperedCanonicalMap(function (array $payload): array {
        $payload['source']['status'] = 'experimental';

        return $payload;
    });

    $this->artisan('esrs:validate-matter-dr-mapping', ['path' => $path])
        ->expectsOutputToContain('invalid_source_status')
        ->assertExitCode(1);

    @unlink($path);
});

function tamperedCanonicalMap(callable $mutate): string
{
    $payload = json_decode(
        file_get_contents(base_path('data/ar16_to_esrs_dr_mapping_esrs2023_v1.json')),
        true,
        flags: JSON_THROW_ON_ERROR
    );

    $path = tempnam(sys_get_temp_dir(), 'i4s-dr-map-validate-');

    file_put_contents($path, json_encode($mutate($payload), JSON_THROW_ON_ERROR));

    return $path;
}
