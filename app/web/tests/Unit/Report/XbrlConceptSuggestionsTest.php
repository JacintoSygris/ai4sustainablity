<?php

use App\Services\Report\XbrlConceptMap;
use App\Services\Report\XbrlConceptSuggestionRepository;

uses(Tests\TestCase::class);

beforeEach(function () {
    $this->reviewDirectory = sys_get_temp_dir().'/xbrl-review-'.bin2hex(random_bytes(8));
    mkdir($this->reviewDirectory, 0700);
    $this->suggestionsPath = $this->reviewDirectory.'/suggestions.json';
    $this->overlayPath = $this->reviewDirectory.'/decisions.json';
    copy(base_path('data/xbrl_concept_suggestions_v1.json'), $this->suggestionsPath);
    config([
        'xbrl_suggestions.overlay_enabled' => false,
        'xbrl_suggestions.suggestions_path' => $this->suggestionsPath,
        'xbrl_suggestions.overlay_path' => $this->overlayPath,
    ]);
    $this->repository = new XbrlConceptSuggestionRepository();
    $this->candidate = array_values($this->repository->suggestions())[0];
});

afterEach(function () {
    foreach (glob($this->reviewDirectory.'/*') as $file) {
        unlink($file);
    }
    rmdir($this->reviewDirectory);
});

it('leaves defaults and pending suggestions out of the map', function () {
    $map = new XbrlConceptMap();
    $before = $map->sha256();
    expect($map->conceptFor($this->candidate['datapoint_id'])['concept_id'])->toBeNull();
    $this->repository->record($this->candidate['id'], 'accepted', 'test-reviewer');
    expect($map->conceptFor($this->candidate['datapoint_id'])['concept_id'])->toBeNull();
    expect($map->sha256())->toBe($before);
    config(['xbrl_suggestions.overlay_enabled' => true]);
    $map = $map->capture();
    expect($map->conceptFor($this->candidate['datapoint_id'])['concept_id'])->toBe($this->candidate['concept_id']);
    expect($map->sha256())->not->toBe($before);
    expect($map->version())->toStartWith('atomizer_xbrl_v1+reviewed-');
    expect($map->conceptFor('E1-6_07')['concept_id'])->toBe('esrs:GrossScope1GreenhouseGasEmissions');
    expect($map->conceptFor('unknown')['reason_code'])->toBe('unknown_datapoint');
});

it('supports reversal by rejection and preserves the reviewer audit history', function () {
    $this->repository->record($this->candidate['id'], 'accepted', 'test-reviewer');
    $this->repository->record($this->candidate['id'], 'rejected', 'second-reviewer');
    config(['xbrl_suggestions.overlay_enabled' => true]);
    expect((new XbrlConceptMap())->conceptFor($this->candidate['datapoint_id'])['concept_id'])->toBeNull();
    $entries = $this->repository->decisions();
    expect($entries)->toHaveCount(2);
    expect($entries[0]['reviewer'])->toBe('test-reviewer');
    expect($entries[1]['reviewer'])->toBe('second-reviewer');
    expect($entries[0]['reviewed_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');
    expect($entries[0]['suggestions_sha256'])->toBe(hash_file('sha256', $this->suggestionsPath));
});

it('rejects conflicting acceptances until the prior suggestion is rejected', function () {
    $this->repository->record($this->candidate['id'], 'accepted', 'test-reviewer');
    $alternative = $this->candidate['datapoint_id'].'::2';
    expect(fn () => $this->repository->record($alternative, 'accepted', 'test-reviewer'))
        ->toThrow(RuntimeException::class, 'reject_previous_acceptance_first');
    $this->repository->record($this->candidate['id'], 'rejected', 'test-reviewer');
    $this->repository->record($alternative, 'accepted', 'test-reviewer');
    expect($this->repository->accepted())->toHaveCount(1);
});

it('invalidates approvals when candidate bytes change', function () {
    $this->repository->record($this->candidate['id'], 'accepted', 'test-reviewer');
    file_put_contents($this->suggestionsPath, file_get_contents($this->suggestionsPath)."\n");
    expect($this->repository->accepted())->toBe([]);
});

it('rejects unknown IDs unsafe aliases and forged source or concepts', function () {
    expect(fn () => $this->repository->record('unknown', 'accepted', 'reviewer'))->toThrow(RuntimeException::class);
    expect(fn () => $this->repository->record($this->candidate['id'], 'accepted', '<script>'))->toThrow(RuntimeException::class);
    expect(fn () => $this->repository->record($this->candidate['id'], 'pending_review', 'reviewer'))->toThrow(RuntimeException::class);
    $data = json_decode(file_get_contents($this->suggestionsPath), true);
    $data['suggestions'][0]['concept_id'] = 'esrs:FabricatedConcept';
    file_put_contents($this->suggestionsPath, json_encode($data));
    expect(fn () => $this->repository->suggestions())->toThrow(RuntimeException::class);
    $data = json_decode(file_get_contents(base_path('data/xbrl_concept_suggestions_v1.json')), true);
    $data['source']['ig3_sha256'] = 'invalid';
    file_put_contents($this->suggestionsPath, json_encode($data));
    expect(fn () => $this->repository->suggestions())->toThrow(RuntimeException::class);
    $data = json_decode(file_get_contents(base_path('data/xbrl_concept_suggestions_v1.json')), true);
    $data['source']['concept_map_sha256'] = 'invalid';
    file_put_contents($this->suggestionsPath, json_encode($data));
    expect(fn () => $this->repository->suggestions())->toThrow(RuntimeException::class, 'invalid_suggestions');
    expect(is_file($this->overlayPath))->toBeFalse();
});

it('fails closed on corrupt decisions while disabled mode remains unchanged', function () {
    file_put_contents($this->overlayPath, '{"schema_version":1,"decisions":[{}]}');
    expect((new XbrlConceptMap())->conceptFor($this->candidate['datapoint_id'])['concept_id'])->toBeNull();
    config(['xbrl_suggestions.overlay_enabled' => true]);
    expect(fn () => (new XbrlConceptMap())->conceptFor($this->candidate['datapoint_id']))->toThrow(RuntimeException::class);
});

it('requires an explicit decision and reviewer in the operator command', function () {
    $this->artisan('xbrl:concept-suggestions')->assertExitCode(1);
    $this->artisan('xbrl:concept-suggestions', ['--accept' => $this->candidate['id']])->assertExitCode(1);
    $this->artisan('xbrl:concept-suggestions', ['--accept' => $this->candidate['id'], '--reject' => $this->candidate['id'], '--reviewer' => 'reviewer'])->assertExitCode(1);
    $this->artisan('xbrl:concept-suggestions', ['--list' => true])->assertExitCode(0);
    expect(is_file($this->overlayPath))->toBeFalse();
    $this->artisan('xbrl:concept-suggestions', ['--accept' => $this->candidate['id'], '--reviewer' => 'reviewer'])->assertExitCode(0);
    expect($this->repository->decisions())->toHaveCount(1);
    $this->artisan('xbrl:concept-suggestions', ['--reject' => $this->candidate['id'], '--reviewer' => 'reviewer'])->assertExitCode(0);
    expect($this->repository->accepted())->toBe([]);
});

it('fails closed on conflicting acceptances introduced outside the command', function () {
    $this->repository->record($this->candidate['id'], 'accepted', 'test-reviewer');
    $data = json_decode(file_get_contents($this->overlayPath), true);
    $alternative = $this->repository->suggestions()[$this->candidate['datapoint_id'].'::2'];
    $entry = $data['decisions'][0];
    $entry['suggestion_id'] = $alternative['id'];
    $entry['concept_id'] = $alternative['concept_id'];
    $data['decisions'][] = $entry;
    file_put_contents($this->overlayPath, json_encode($data));
    expect(fn () => $this->repository->accepted())->toThrow(RuntimeException::class, 'conflicting_acceptances');
});


it('pins one map revision across lookups hashes and versions while decisions change', function () {
    config(['xbrl_suggestions.overlay_enabled' => true]);
    $this->repository->record($this->candidate['id'], 'accepted', 'reviewer');
    $map = (new XbrlConceptMap())->capture();
    $frozen = $map->frozen();
    $first = $map->conceptFor($this->candidate['datapoint_id']);
    $this->repository->record($this->candidate['id'], 'rejected', 'reviewer');
    expect($map->conceptFor($this->candidate['datapoint_id']))->toBe($first);
    expect($map->sha256())->toBe($frozen['sha256']);
    expect($map->version())->toBe($frozen['version']);
    expect(XbrlConceptMap::fromFrozen($frozen)->conceptFor($this->candidate['datapoint_id']))->toBe($first);
    expect($map->capture()->conceptFor($this->candidate['datapoint_id'])['concept_id'])->toBeNull();
});
