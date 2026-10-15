<?php

use App\Repositories\ConceptRelationsRepository;

uses(Tests\TestCase::class);

it('loads the checked artifact and sorts directional topic hints by lift', function () {
    $repo = new ConceptRelationsRepository();
    $payload = $repo->load();
    expect($payload)->not->toBeNull();
    $source = $payload['topic_relations'][0]['source'];
    $edges = $repo->forTopic($source);
    expect(count($edges))->toBeLessThanOrEqual(5)->toBeGreaterThan(0);
    foreach ($edges as $index => $edge) {
        expect($edge['source'])->toBe($source);
        if ($index > 0) expect($edge['lift'])->toBeLessThanOrEqual($edges[$index - 1]['lift']);
    }
    expect($repo->forTopic(99999))->toBe([]);
    expect($repo->forTopic($source, 0))->toBe([]);
});

it('filters siblings to the current P9 scope before taking the limit', function () {
    $repo = new ConceptRelationsRepository();
    foreach ($repo->load()['datapoints'] as $id => $row) {
        if (count($row['siblings']) > 5) {
            $last = end($row['siblings']);
            expect($repo->forDatapoint($id, [$last]))->toBe([$last]);
            expect($repo->forDatapoint($id, [$id]))->toBe([]);
            expect($repo->forDatapoint($id, $row['siblings'], 100))->toHaveCount(5);
            return;
        }
    }
    $this->fail('Expected a real cluster with more than five siblings');
});

it('fails closed on missing bytes sidecar tampering and invalid schema including a warm cache', function () {
    $path = tempnam(sys_get_temp_dir(), 'concept-relations-');
    $original = file_get_contents(base_path('data/esrs_concept_relations_v1.json'));
    try {
        $repo = new ConceptRelationsRepository($path);
        expect($repo->load())->toBeNull();
        file_put_contents($path, $original);
        file_put_contents($path.'.sha256', hash('sha256', $original));
        expect($repo->load())->not->toBeNull();
        file_put_contents($path, $original.' ');
        expect($repo->load())->toBeNull();
        expect($repo->forTopic(1))->toBe([]);
        file_put_contents($path, $original);
        file_put_contents($path.'.sha256', str_repeat('0', 64));
        expect($repo->load())->toBeNull();
        $bad = json_decode($original, true, flags: JSON_THROW_ON_ERROR);
        $bad['version'] = 'unknown';
        $raw = json_encode($bad, JSON_THROW_ON_ERROR);
        file_put_contents($path, $raw);
        file_put_contents($path.'.sha256', hash('sha256', $raw));
        expect($repo->load())->toBeNull();
        file_put_contents($path, '{');
        file_put_contents($path.'.sha256', hash('sha256', '{'));
        expect($repo->load())->toBeNull();
    } finally {
        unlink($path);
        if (is_file($path.'.sha256')) unlink($path.'.sha256');
    }
});

it('rejects source drift and a sibling outside its cluster with a valid checksum', function () {
    $path = tempnam(sys_get_temp_dir(), 'concept-relations-');
    $original = json_decode(file_get_contents(base_path('data/esrs_concept_relations_v1.json')), true, flags: JSON_THROW_ON_ERROR);
    try {
        $bad = $original;
        $bad['source_sha256']['ig3_datapoints'] = str_repeat('0', 64);
        $raw = json_encode($bad, JSON_THROW_ON_ERROR);
        file_put_contents($path, $raw);
        file_put_contents($path.'.sha256', hash('sha256', $raw));
        expect((new ConceptRelationsRepository($path))->load())->toBeNull();
        $bad = $original;
        $id = array_key_first($bad['datapoints']);
        $bad['datapoints'][$id]['siblings'][] = $id;
        $raw = json_encode($bad, JSON_THROW_ON_ERROR);
        file_put_contents($path, $raw);
        file_put_contents($path.'.sha256', hash('sha256', $raw));
        expect((new ConceptRelationsRepository($path))->load())->toBeNull();
    } finally {
        unlink($path);
        unlink($path.'.sha256');
    }
});
