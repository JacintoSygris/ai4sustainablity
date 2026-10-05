<?php

namespace App\Services;

use App\Models\LearningCaseAnnotation;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use stdClass;

/** Private synthetic import. Commands select annotations; they never grant rights. */
final class LearningCaseCuration
{
    private array $context = [];
    private ?LearningCaseExport $exporter = null;
    private ?LearningAuthorizationLedger $ledger = null;

    public static function forSyntheticTests(array $context, LearningCaseExport $exporter, LearningAuthorizationLedger $ledger): self
    {
        $self = new self;
        $self->context = $context;
        $self->exporter = $exporter;
        $self->ledger = $ledger;
        return $self;
    }

    private function connection(): \Illuminate\Database\Connection
    {
        if (! app()->environment('testing') || $this->exporter === null || $this->ledger === null
            || ($this->context['namespace'] ?? null) !== 'test-namespace:t07-export'
            || ($this->context['synthetic_only'] ?? null) !== true
            || ($this->context['promotion_allowed'] ?? null) !== false
            || ($this->context['purpose'] ?? null) !== 'synthetic:curation') {
            throw new DomainException('learning_curation.disabled');
        }
        $c = DB::connection();
        if ((new LearningCaseAnnotation)->getConnection() !== $c) {
            throw new DomainException('learning_curation.isolation_required');
        }
        if (! CharacterizationStateTransaction::admitsIsolatedConnections([$c])) { throw new DomainException('learning_curation.isolation_required'); }
        return $c;
    }

    public function importForAccount(mixed $author, mixed $curator, mixed $jsonl, mixed $commandJson): array
    {
        $c = $this->connection(); // Default denial precedes PDO and untrusted parsing.
        if (! is_int($author) || $author < 1 || ! is_int($curator) || $curator < 1) {
            throw new InvalidArgumentException('learning_curation.actor_invalid');
        }
        $command = $this->command($commandJson);
        if (! is_string($jsonl) || strlen($jsonl) > 1048576 || substr_count($jsonl, "\n") !== 1 || ! str_ends_with($jsonl, "\n")) {
            throw new InvalidArgumentException('learning_curation.jsonl_invalid');
        }
        $this->object(substr($jsonl, 0, -1));
        $pdo = $c->getPdo();
        return $c->transaction(function () use ($c, $pdo, $author, $curator, $jsonl, $command): array {
            $witness = null;
            $stored = null;
            $out = app(CharacterizationStateTransaction::class)->runForUser($author, function () use ($author, $curator, $jsonl, $command, &$witness, &$stored): array {
                [$case, $rights] = $this->current($author, $curator, $jsonl, $command);
                $witness = [$case, $rights];
                $rows = LearningCaseAnnotation::query()->where('learning_case_id', $case->id)->orderBy('annotation_revision')->lockForUpdate()->get();
                $revision = 0;
                $replay = null;
                foreach ($rows as $row) {
                    $raw = $row->getRawOriginal();
                    foreach (['annotation_revision'=>1, 'expected_revision'=>0, 'curator_authorization_generation'=>1] as $key=>$minimum) {
                        if (! is_int($raw[$key]) || $raw[$key] < $minimum || $raw[$key] > 9007199254740991) {
                            throw new DomainException('learning_curation.annotation_corrupt');
                        }
                    }
                    if ($raw['annotation_revision'] !== ++$revision || $raw['expected_revision'] !== $revision - 1
                        || hash('sha256', $row->command_text) !== $row->command_digest
                        || hash('sha256', $row->annotation_text) !== $row->annotation_digest) {
                        throw new DomainException('learning_curation.annotation_corrupt');
                    }
                    try { $persisted = $this->command($row->command_text); }
                    catch (InvalidArgumentException|\JsonException) { throw new DomainException('learning_curation.annotation_corrupt'); }
                    $expected = ['schema_version'=>'learning-case-annotation-v1', 'case_id'=>$persisted['reference']['case_id'],
                        'case_hash'=>$persisted['reference']['case_hash'], 'export_digest'=>$persisted['export_digest'],
                        'annotation_revision'=>$revision, 'command_id'=>$persisted['command_id'],
                        'topic_labels'=>$persisted['topic_labels'], 'notes'=>$persisted['notes'],
                        'synthetic_only'=>true, 'promotion_allowed'=>false];
                    if ($persisted['expected_annotation_revision'] !== $raw['expected_revision']
                        || $persisted['command_id'] !== $raw['command_id']
                        || $this->canonical($persisted) !== $row->command_text
                        || $this->canonical($expected) !== $row->annotation_text
                        || ! is_string($raw['curator_authorization_digest'])
                        || preg_match('/\A[a-f0-9]{64}\z/D', $raw['curator_authorization_digest']) !== 1) {
                        throw new DomainException('learning_curation.annotation_corrupt');
                    }
                    if ($row->command_id === $command['command_id']) { $replay = $row; }
                }
                $text = $this->canonical($command);
                if ($replay !== null) {
                    if ($replay->curator_id !== $curator || $replay->command_text !== $text) {
                        throw new DomainException('learning_curation.command_conflict');
                    }
                    if ($replay->getRawOriginal('curator_authorization_generation') !== $rights['generation']
                        || $replay->getRawOriginal('curator_authorization_digest') !== $rights['current_digest']) {
                        throw new DomainException('learning_curation.replay_authorization_changed');
                    }
                    $stored = $replay->getRawOriginal();
                    return json_decode($replay->annotation_text, true, 32, JSON_THROW_ON_ERROR);
                }
                if ($revision !== $command['expected_annotation_revision']) {
                    throw new DomainException('learning_curation.stale_annotation');
                }
                $annotation = ['schema_version'=>'learning-case-annotation-v1', 'case_id'=>$command['reference']['case_id'],
                    'case_hash'=>$command['reference']['case_hash'], 'export_digest'=>$command['export_digest'],
                    'annotation_revision'=>$revision + 1, 'command_id'=>$command['command_id'],
                    'topic_labels'=>$command['topic_labels'], 'notes'=>$command['notes'],
                    'synthetic_only'=>true, 'promotion_allowed'=>false];
                $annotationText = $this->canonical($annotation);
                $row = LearningCaseAnnotation::query()->forceCreate(['learning_case_id'=>$case->id, 'curator_id'=>$curator,
                    'annotation_revision'=>$revision + 1, 'expected_revision'=>$revision,
                    'command_id'=>$command['command_id'], 'command_text'=>$text, 'command_digest'=>hash('sha256', $text),
                    'annotation_text'=>$annotationText, 'annotation_digest'=>hash('sha256', $annotationText),
                    'curator_authorization_generation'=>$rights['generation'], 'curator_authorization_digest'=>$rights['current_digest']]);
                $stored = $row->fresh()->getRawOriginal();
                return json_decode($annotationText, true, 32, JSON_THROW_ON_ERROR);
            });
            // Bounded readback after Common, before the outer commit on the same PDO.
            if ($this->connection() !== $c || $c->getPdo() !== $pdo) { throw new DomainException('learning_curation.changed'); }
            $fresh = $this->current($author, $curator, $jsonl, $command);
            $row = LearningCaseAnnotation::query()->whereKey($stored['id'])->first();
            if ($fresh != $witness || $row === null || $row->getRawOriginal() !== $stored
                || $this->connection() !== $c || $c->getPdo() !== $pdo) {
                throw new DomainException('learning_curation.changed');
            }
            return $out;
        });
    }

    /** Raw ownership/membership first, then trusted curator ledger, then author exporter. */
    private function current(int $author, int $curator, string $jsonl, array $command): array
    {
        $members = [];
        foreach (array_unique([$author, $curator]) as $actor) {
            $user = DB::table('users')->where('id', $actor)->lockForUpdate()->first();
            $m = DB::table('learning_company_memberships')->where('user_id', $actor)
                ->where('subject_type', 'account')->where('subject_identifier', (string) $actor)
                ->where('verification_status', 'verified')->whereNotNull('verified_at')->whereNull('revoked_at')->lockForUpdate()->get();
            if ($user === null || $user->id !== $actor || $user->email_verified_at === null || count($m) !== 1
                || $m[0]->user_id !== $actor || $m[0]->subject_identifier !== (string) $actor
                || ! is_int($m[0]->learning_company_group_id)) { throw new DomainException('learning_curation.forbidden'); }
            $members[$actor] = $m[0];
        }
        $group = $members[$author]->learning_company_group_id;
        if ($members[$curator]->learning_company_group_id !== $group) { throw new DomainException('learning_curation.forbidden'); }
        $rights = $this->ledger->current($curator, $group, $this->context['purpose']);
        if ($rights['status'] !== 'granted' || $rights['provenance'] !== 'synthetic-only' || $rights['promotion_allowed'] !== false) {
            throw new DomainException('learning_curation.rights_denied');
        }
        $reference = $this->exporter->referenceForAccount($author);
        if ($this->canonical($reference) !== $this->canonical($command['reference'])) {
            throw new DomainException('learning_curation.stale_export');
        }
        $packet = $this->exporter->exportForAccount($author, $reference);
        if ($packet['jsonl'] !== $jsonl || $packet['digest'] !== $command['export_digest']) {
            throw new DomainException('learning_curation.stale_export');
        }
        if ($this->ledger->current($curator, $group, $this->context['purpose']) !== $rights
            || $this->exporter->exportForAccount($author, $reference) !== $packet) {
            throw new DomainException('learning_curation.changed');
        }
        foreach ($members as $actor=>$member) {
            $fresh = DB::table('learning_company_memberships')->where('id', $member->id)->first();
            if ($fresh != $member) { throw new DomainException('learning_curation.changed'); }
        }
        $export = json_decode($jsonl, true, 512, JSON_THROW_ON_ERROR);
        foreach ($command['topic_labels'] as $label) {
            if (! in_array($label['topic_id'], $export['topic_universe']['reviewed_topic_ids'], true)) {
                throw new DomainException('learning_curation.topic_invalid');
            }
        }
        $case = DB::table('learning_cases')->where('case_id', $reference['case_id'])->where('learning_company_group_id', $group)->lockForUpdate()->first();
        if ($case === null || $case->case_hash !== $reference['case_hash']) { throw new DomainException('learning_curation.forbidden'); }
        return [$case, $rights];
    }

    private function command(mixed $raw): array
    {
        $v = $this->object($raw);
        $this->keys($v, ['schema_version','export_digest','reference','expected_annotation_revision','command_id','topic_labels','notes']);
        if ($v->schema_version !== 'learning-case-annotation-command-v1' || ! is_int($v->expected_annotation_revision)
            || $v->expected_annotation_revision < 0 || $v->expected_annotation_revision >= 9007199254740991) { $this->invalid(); }
        foreach ([$v->export_digest, $v->command_id] as $digest) { $this->hex($digest); }
        $this->keys($v->reference, ['case_id','case_hash','expected_revisions','source_token','expected_authorization_generation']);
        if (! is_string($v->reference->case_id) || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9:._-]{0,127}\z/D', $v->reference->case_id) !== 1) { $this->invalid(); }
        foreach ([$v->reference->case_hash, $v->reference->source_token] as $digest) { $this->hex($digest); }
        if (! is_int($v->reference->expected_authorization_generation) || $v->reference->expected_authorization_generation < 1) { $this->invalid(); }
        $this->keys($v->reference->expected_revisions, ['p5','p6_base','p8','p9']);
        foreach (get_object_vars($v->reference->expected_revisions) as $header) {
            $this->keys($header, ['generation','revision','epoch','digest']);
            foreach ([$header->epoch, $header->digest] as $digest) { $this->hex($digest); }
            foreach ([$header->generation, $header->revision] as $n) { if (! is_int($n) || $n < 0 || $n > 9007199254740991) { $this->invalid(); } }
        }
        $this->keys($v->notes, ['status','reason']);
        if ($v->notes->status !== 'withheld' || $v->notes->reason !== 'free_text_transport_disabled'
            || ! is_array($v->topic_labels) || count($v->topic_labels) < 1 || count($v->topic_labels) > 1024) { $this->invalid(); }
        $seen = [];
        foreach ($v->topic_labels as $label) {
            $this->keys($label, ['topic_id','value','observed_mask']);
            if (! is_string($label->topic_id) || preg_match('/\A[1-9][0-9]{0,15}\z/D', $label->topic_id) !== 1 || isset($seen[$label->topic_id])
                || ! is_int($label->observed_mask) || ! in_array($label->observed_mask, [0,1], true)
                || ($label->observed_mask === 0 ? $label->value !== null : ! is_int($label->value) || ! in_array($label->value, [0,1], true))) { $this->invalid(); }
            $seen[$label->topic_id] = true;
        }
        return json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    }

    private function invalid(): never { throw new InvalidArgumentException('learning_curation.command_invalid'); }
    private function canonical(mixed $v): string
    {
        if (is_array($v)) {
            if (array_is_list($v)) { return '['.implode(',', array_map($this->canonical(...), $v)).']'; }
            ksort($v, SORT_STRING); $parts = [];
            foreach ($v as $key=>$value) { $parts[] = $this->canonical((string) $key).':'.$this->canonical($value); }
            return '{'.implode(',', $parts).'}';
        }
        return json_encode($v, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_PRESERVE_ZERO_FRACTION);
    }
    private function hex(mixed $v): void { if (! is_string($v) || preg_match('/\A[a-f0-9]{64}\z/D', $v) !== 1) { $this->invalid(); } }
    private function keys(mixed $v, array $keys): void
    {
        if (! $v instanceof stdClass) { $this->invalid(); }
        $actual = array_keys(get_object_vars($v)); sort($actual); sort($keys);
        if ($actual !== $keys) { $this->invalid(); }
    }

    /** Validate raw root and duplicate keys before associative casts lose identity. */
    private function object(mixed $raw): stdClass
    {
        if (! is_string($raw) || strlen($raw) > 1048576) { $this->invalid(); }
        try { $v = json_decode($raw, false, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING); }
        catch (\JsonException) { $this->invalid(); }
        if (! $v instanceof stdClass) { $this->invalid(); }
        preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|[{}\[\]:,]|[^\s{}\[\]:,]+/s', $raw, $tokens);
        $stack = [];
        foreach ($tokens[0] as $i => $token) {
            if ($token === '{' || $token === '[') { $stack[] = ['object'=>$token === '{','seen'=>[]]; }
            elseif ($token === '}' || $token === ']') { array_pop($stack); }
            elseif (($tokens[0][$i+1] ?? null) === ':' && str_starts_with($token, '"')) {
                $key = json_decode($token, true, 64, JSON_THROW_ON_ERROR); $j = count($stack) - 1;
                if (isset($stack[$j]['seen'][$key])) { $this->invalid(); }
                $stack[$j]['seen'][$key] = true;
            }
        }
        return $v;
    }
}
