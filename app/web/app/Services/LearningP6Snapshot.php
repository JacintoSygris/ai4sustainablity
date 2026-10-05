<?php

namespace App\Services;

use App\Models\Characterization;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;

/** Private transient references only; unconsumed, with no rights or eligibility approval. */
final class LearningP6Snapshot
{
    /** Private current references; never historical producer-input attestation. */
    public function projectInputsForAccount(int $actor, array $expectedSourceHeaders, ApiCharacterizationGateway $gateway): array
    {
        $connection = $this->inputsConnection();
        $parents = array_keys($expectedSourceHeaders); sort($parents, SORT_STRING);
        if ($actor < 1 || $parents !== ['p5', 'p6_base']) { throw new DomainException('learning_p6.inputs_headers'); }
        foreach ($expectedSourceHeaders as $header) {
            if (! is_array($header)) { throw new DomainException('learning_p6.inputs_headers'); }
            $keys = array_keys($header); sort($keys, SORT_STRING);
            if ($keys !== ['characterization_id', 'digest', 'epoch', 'generation', 'revision']) {
                throw new DomainException('learning_p6.inputs_headers');
            }
        }
        $pdo = $connection->getPdo();
        return $connection->transaction(function () use ($actor, $expectedSourceHeaders, $gateway, $connection, $pdo): array {
            $captured = null;
            $result = app(CharacterizationStateTransaction::class)->runForUser($actor,
                function (?Characterization $source) use ($actor, $expectedSourceHeaders, $gateway, $connection, $pdo, &$captured): array {
                    $this->inputsConnection($connection, $pdo);
                    if ($source === null) { throw new DomainException('learning_p6.current_source_missing'); }
                    $row = new Characterization;
                    $row->setRawAttributes($source->getRawOriginal(), true);
                    $identity = LearningP6JobParentFence::identity($row->getRawOriginal());
                    if ($identity['user_id'] !== $actor) { throw new DomainException('learning_p6.current_identity_mismatch'); }
                    $headers = ['p5' => (new LearningP5SourceRevisionClock)->current($identity['id']),
                        'p6_base' => (new LearningP6BaseSourceRevisionClock)->current($identity['id'])];
                    foreach ($headers as $name => $header) {
                        $expected = $expectedSourceHeaders[$name]; ksort($expected, SORT_STRING);
                        if ($header === null) { throw new DomainException('learning_p6_job.missing_parent'); }
                        $sorted = $header; ksort($sorted, SORT_STRING);
                        if ($sorted !== $expected) { throw new DomainException('learning_p6.inputs_headers'); }
                    }
                    $projection = $this->projectForAccount($actor, $headers['p6_base'])['projection'];
                    $fence = LearningP6JobParentFence::capture($identity, $row, $gateway, true);
                    $request = $fence->prepared();
                    if (($request->payload()['model_profile'] ?? null) !== $projection['p6_snapshot']['model_profile']) {
                        throw new DomainException('learning_p6.inputs_profile');
                    }
                    $fence->assertParents($row, $gateway, [Characterization::STATUS_COMPLETED]);
                    $fresh = Characterization::query()->whereKey($identity['id'])->first();
                    if ($fresh === null) { throw new DomainException('learning_p6.current_source_missing'); }
                    $copy = new Characterization; $copy->setRawAttributes($fresh->getRawOriginal(), true);
                    $fence->assertParents($copy, $gateway, [Characterization::STATUS_COMPLETED]);
                    if ($copy->getRawOriginal() !== $row->getRawOriginal()) {
                        throw new DomainException('learning_p6.inputs_source_drift');
                    }
                    $captured = [$identity, $row->getRawOriginal(), $fence];
                    return ['source_headers' => $headers, 'projection' => $projection,
                        'prepared_input_digest' => $request->digest(),
                        'interpretation_digest' => $request->interpretation()->digest(),
                        'documents_digest' => $fence->documentsDigest()];
                });
            // The composed frame is checked AFTER every Common owner/finalizer, BEFORE outer commit.
            $this->inputsConnection($connection, $pdo);
            [$identity, $raw, $fence] = $captured;
            $lookup = function () use ($identity, $raw): Characterization {
                $fresh = Characterization::query()->whereKey($identity['id'])->first();
                if ($fresh === null || $fresh->getRawOriginal() !== $raw) {
                    throw new DomainException('learning_p6.inputs_source_drift');
                }
                $copy = new Characterization; $copy->setRawAttributes($fresh->getRawOriginal(), true);
                return $copy;
            };
            $fence->assertParents($lookup(), $gateway, [Characterization::STATUS_COMPLETED]);
            $clock = new LearningP6BaseSourceRevisionClock;
            if ($clock->finalizationWitness($lookup)['header'] !== $result['source_headers']['p6_base']
                || (new LearningP5SourceRevisionClock)->current($identity['id']) !== $result['source_headers']['p5']) {
                throw new DomainException('learning_p6.inputs_headers');
            }
            $lookup();
            $this->inputsConnection($connection, $pdo);
            return $result;
        });
    }

    /** Every relevant declaration precedes PDO resolution; aliases may share the default PDO. */
    private function inputsConnection(?\Illuminate\Database\Connection $expected = null, ?\PDO $expectedPdo = null): \Illuminate\Database\Connection
    {
        if (! LearningP5SourceRevisionClock::enabled() || ! LearningP6BaseSourceRevisionClock::enabled()
            || config('services.learning_p6_prepared_request.enabled') !== true
            || config('services.learning_p6_job_parent_fence.enabled') !== true
            || config('services.learning_p6_interpretation_context.enabled') !== true
            || ! app()->environment('testing')) { throw new DomainException('learning_p6.inputs_guard'); }
        LearningP6InterpretationContext::guard();
        $default = DB::connection();
        $connections = array_map(fn ($class) => (new $class)->getConnection(), [User::class, Characterization::class,
            \App\Models\CharacterizationDocument::class, \App\Models\EsrsTopic::class, \App\Models\NaceCode::class]);
        if (! CharacterizationStateTransaction::admitsIsolatedConnections([$default, ...$connections])) { throw new DomainException('learning_p6.disposable_connection_required'); }
        $pdo = $default->getPdo();
        foreach ($connections as $connection) {
            if ($connection->getPdo() !== $pdo) { throw new DomainException('learning_p6.connection_mismatch'); }
        }
        return $this->currentConnection($expected, $expectedPdo === null ? $pdo : $expectedPdo);
    }

    public function projectForAccount(int $actor, array $expectedSourceHeader): array
    {
        $connection = $this->currentConnection();
        $pdo = $connection->getPdo();
        return $connection->transaction(function () use ($actor, $expectedSourceHeader, $connection, $pdo): array {
            $clock = new LearningP6BaseSourceRevisionClock;
            $captured = null;
            $result = app(CharacterizationStateTransaction::class)->runForUser($actor, function (?Characterization $source) use ($actor, $expectedSourceHeader, $connection, $pdo, $clock, &$captured): array {
                $this->currentConnection($connection, $pdo);
                if ($source === null) { throw new DomainException('learning_p6.current_source_missing'); }
                $copy = new Characterization;
                $copy->setRawAttributes($source->getRawOriginal(), true);
                $id = $copy->getRawOriginal('id');
                $owner = $copy->getRawOriginal('user_id');
                if ($owner !== $actor || ! is_int($id)) { throw new DomainException('learning_p6.current_identity_mismatch'); }
                $header = $clock->current($id);
                $expected = $expectedSourceHeader;
                if ($header === null) { throw new DomainException('learning_p6.current_header_missing'); }
                ksort($expected, SORT_STRING);
                $sorted = $header; ksort($sorted, SORT_STRING);
                if ($expected !== $sorted) { throw new DomainException('learning_p6.current_header_mismatch'); }
                $lookup = function () use ($actor, $id, $copy): ?Characterization {
                    $fresh = Characterization::query()->where('user_id', $actor)->first();
                    if ($fresh === null || $fresh->getRawOriginal('id') !== $id
                        || $fresh->getRawOriginal('user_id') !== $actor
                        || $fresh->getRawOriginal('status') !== $copy->getRawOriginal('status')) {
                        throw new DomainException('learning_p6.current_identity_mismatch');
                    }
                    return $fresh;
                };
                // Bind the exact RAW ORIGINAL copy to the live row using BASE's existing checksum.
                $witness = $clock->finalizationWitness(fn () => $copy);
                if ($witness['header'] !== $header || $clock->finalizationWitness($lookup) !== $witness) {
                    throw new DomainException('learning_p6.current_source_drift');
                }
                $projection = $this->project($copy);
                $this->currentConnection($connection, $pdo);
                if ($clock->finalizationWitness($lookup) !== $witness || $clock->current($id) !== $header) {
                    throw new DomainException('learning_p6.current_source_drift');
                }
                // BASE.current performs its own read; close that seam with the same witness.
                if ($clock->finalizationWitness($lookup) !== $witness) {
                    throw new DomainException('learning_p6.current_source_drift');
                }
                $this->currentConnection($connection, $pdo);
                $captured = [$id, $header, $witness, $lookup];
                return ['source_header' => $header, 'projection' => $projection];
            });
            // Common/BASE finalizers must remain inside this same-PDO transaction.
            $this->currentConnection($connection, $pdo);
            [$id, $header, $witness, $lookup] = $captured;
            if ($clock->finalizationWitness($lookup) !== $witness || $clock->current($id) !== $header) {
                throw new DomainException('learning_p6.current_source_drift');
            }
            $this->currentConnection($connection, $pdo);
            return $result;
        });
    }

    /** Resolve metadata first; unsafe declarations must never open PDO or run SQL. */
    private function currentConnection(?\Illuminate\Database\Connection $expected = null, ?\PDO $expectedPdo = null): \Illuminate\Database\Connection
    {
        if (! LearningP6BaseSourceRevisionClock::enabled()) {
            throw new DomainException('learning_p6.current_disabled');
        }
        $connection = DB::connection();
        $models = [(new User)->getConnection(), (new Characterization)->getConnection()];
        if (! CharacterizationStateTransaction::admitsIsolatedConnections([$connection, ...$models])) { throw new DomainException('learning_p6.disposable_connection_required'); }
        if ($expected !== null && $connection !== $expected) { throw new DomainException('learning_p6.connection_mismatch'); }
        $pdo = $connection->getPdo();
        foreach ($models as $modelConnection) {
            if ($modelConnection->getPdo() !== $pdo) { throw new DomainException('learning_p6.connection_mismatch'); }
        }
        if ($expectedPdo !== null && $pdo !== $expectedPdo) { throw new DomainException('learning_p6.connection_mismatch'); }
        return $connection;
    }

    /** @return array{schema_version: string, p6_snapshot: array{model_profile: string, model_digest: string, policy_digest: string}, serving_identity: array<string, mixed>} */
    public function project(Characterization $characterization): array
    {
        $attributes = $characterization->getAttributes();
        $raw = $attributes['result_data'] ?? null;
        if (! is_string($raw)) {
            throw new InvalidArgumentException('learning_p6.result_data_invalid');
        }
        try {
            $result = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('learning_p6.result_data_invalid', 0, $exception);
        }
        $result = $this->map($result);
        if (($attributes['status'] ?? null) !== Characterization::STATUS_COMPLETED || ($result['status'] ?? null) !== 'completed') {
            throw new InvalidArgumentException('learning_p6.status_invalid');
        }
        $metadata = $this->map($result['mapping_metadata'] ?? null);
        $python = $this->map($metadata['python'] ?? null);
        $identity = $this->map($python['serving_identity'] ?? null);
        $this->keys($identity, ['profile','artifact_sha256','policy_sha256','runtime_config','serving_identity_sha256']);
        if (! is_string($identity['profile']) || preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $identity['profile']) !== 1
            || $identity['profile'] !== ($result['model_profile'] ?? null)) {
            throw new InvalidArgumentException('learning_p6.profile_invalid');
        }
        $identity['artifact_sha256'] = $this->hashMap($identity['artifact_sha256'], false);
        $identity['policy_sha256'] = $this->hashMap($identity['policy_sha256'], true);
        $this->sha($identity['serving_identity_sha256']);
        $runtime = $this->map($identity['runtime_config']);
        $this->keys($runtime, ['score_threshold','policy_active']);
        $score = $runtime['score_threshold'];
        if ($score !== null && ((! is_int($score) && ! is_float($score)) || ! is_finite($score) || $score < 0 || $score > 1)) {
            throw new InvalidArgumentException('learning_p6.score_invalid');
        }
        $flags = $this->map($runtime['policy_active']);
        $this->keys($flags, ['label_thresholds','crc_recall_floor','sector_guard']);
        foreach ($flags as $name => $active) {
            if (! is_bool($active) || $active !== array_key_exists($name, $identity['policy_sha256'])) {
                throw new InvalidArgumentException('learning_p6.policy_binding_invalid');
            }
        }
        ksort($flags, SORT_STRING);
        $identity['runtime_config'] = ['policy_active' => $flags, 'score_threshold' => $score];
        ksort($identity, SORT_STRING);

        return $this->envelope($identity);
    }

    private function map(mixed $value): array
    {
        if (! is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException('learning_p6.map_invalid');
        }

        return $value;
    }

    private function keys(array $value, array $expected): void
    {
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($keys !== $expected) {
            throw new InvalidArgumentException('learning_p6.keys_invalid');
        }
    }

    private function sha(mixed $value): void
    {
        if (! is_string($value) || preg_match('/\A[a-f0-9]{64}\z/', $value) !== 1) {
            throw new InvalidArgumentException('learning_p6.sha_invalid');
        }
    }

    private function hashMap(mixed $value, bool $policy): array
    {
        // Only this contextual dictionary may accept PHP's persisted empty array.
        if ($policy && $value === []) {
            return [];
        }
        $value = $this->map($value);
        foreach ($value as $key => $sha) {
            $valid = is_string($key) && ($policy ? in_array($key,['label_thresholds','crc_recall_floor','sector_guard'],true)
                : preg_match('/\A[A-Za-z0-9_.-]{1,128}\z/', $key) === 1 && ! in_array($key,['.','..'],true));
            if (! $valid) {
                throw new InvalidArgumentException('learning_p6.dictionary_key_invalid');
            }
            $this->sha($sha);
        }
        ksort($value, SORT_STRING);

        return $value;
    }

    private function envelope(array $identity): array
    {
        // Immutable supplied component hashes, never model bytes or verified signatures.
        // Policy reference includes full producer serving SHA: intentionally model-dependent.
        $model = ['schema_version'=>'p6-model-reference-v1','profile'=>$identity['profile'],'artifact_sha256'=>$identity['artifact_sha256']];
        $policy = ['schema_version'=>'p6-policy-reference-v1','policy_sha256'=>$identity['policy_sha256'] === [] ? (object) [] : $identity['policy_sha256'], 'runtime_config'=>$identity['runtime_config'],'source_serving_identity_sha256'=>$identity['serving_identity_sha256']];
        return ['schema_version'=>'p6-learning-provenance-v1','p6_snapshot'=>['model_profile'=>$identity['profile'],'model_digest'=>hash('sha256',$this->canonical($model)),'policy_digest'=>hash('sha256',$this->canonical($policy))],'serving_identity'=>$identity];
    }
    private function canonical(mixed $value): string
    {
        if (is_array($value) && ! array_is_list($value)) {
            ksort($value, SORT_STRING);
            $members = [];
            foreach ($value as $key => $member) {
                $members[] = $this->canonical((string) $key).':'.$this->canonical($member);
            }
            return '{'.implode(',', $members).'}';
        }
        if (is_array($value)) {
            return '['.implode(',',array_map($this->canonical(...),$value)).']';
        }
        return json_encode($value, JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_LINE_TERMINATORS|JSON_PRESERVE_ZERO_FRACTION);
    }
}
