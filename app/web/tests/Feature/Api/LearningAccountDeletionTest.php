<?php

use App\Models\Characterization;
use App\Models\CharacterizationDocument;
use App\Models\LearningAuthorizationRecord;
use App\Models\LearningAuthorizationState;
use App\Models\LearningCompanyGroup;
use App\Models\LearningCompanyMembership;
use App\Models\User;
use App\Services\CharacterizationStateTransaction;
use App\Services\DenyLearningAuthorizationAuthority;
use App\Services\LearningAuthorization;
use App\Services\LearningAuthorizationAuthority;
use App\Services\LearningAuthorizationLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

// Positive sources live only here; the application binding stays deny-all.
function t05cFixture(?User $user = null, string $key = 'one', string $purpose = 'synthetic:learning'): array
{
    $user ??= User::factory()->create(['name' => 'SYNTHETIC_ACTOR']);
    $group = LearningCompanyGroup::query()->forceCreate(['company_group_key' => hash('sha256', 't05c:'.$key)]);
    $member = LearningCompanyMembership::query()->where('user_id', $user->id)->first() ?? new LearningCompanyMembership;
    $member->forceFill([
        'learning_company_group_id' => $group->id, 'user_id' => $user->id,
        'subject_type' => 'account', 'subject_identifier' => (string) $user->id,
        'evidence_digest' => hash('sha256', 't05c:membership'), 'evidence_type' => 'synthetic-only',
        'verification_status' => 'verified', 'verified_at' => now(),
    ])->save();
    $source = new class implements LearningAuthorizationAuthority {
        public bool $unavailable = false;
        public int $calls = 0;
        public function references(string $purpose): ?array
        {
            $this->calls++;
            if ($this->unavailable) { throw new RuntimeException('synthetic-issuer-unavailable'); }
            return [
                'issuer_ref' => 'synthetic:issuer', 'issuer_version' => 'v1', 'issuer_digest' => hash('sha256', 'issuer'),
                'capability_ref' => 'synthetic:capability', 'capability_version' => 'v1', 'capability_digest' => hash('sha256', 'capability'),
                'policy_version' => 'v1', 'policy_digest' => hash('sha256', 'policy'),
            ];
        }
        public function resolve(User $actor, LearningCompanyMembership $membership, string $purpose): ?array
        {
            return array_merge($this->references($purpose), [
                'actor_id' => $actor->id, 'group_id' => $membership->learning_company_group_id,
                'membership_id' => $membership->id, 'purpose' => $purpose, 'state' => 'available',
                'provenance' => 'synthetic-only', 'promotion_allowed' => false,
            ]);
        }
    };
    $ledger = new LearningAuthorizationLedger(new LearningAuthorization($source), app(CharacterizationStateTransaction::class));
    $ledger->grant($user->id, $group->id, $purpose, 0, hash('sha256', 't05c:grant:'.$user->id.':'.$key.':'.$purpose));
    return [$user, $group, $member, $ledger, $source];
}

function t05cBytes(?int $owner = null): array
{
    $states = DB::table('learning_authorization_states')->orderBy('id');
    if ($owner !== null) { $states->where('user_id', $owner); }
    $ids = (clone $states)->pluck('id');
    return [$states->get()->toJson(), DB::table('learning_authorization_records')
        ->whereIn('learning_authorization_state_id', $ids)->orderBy('id')->get()->toJson()];
}

function t05cDocument(User $user): array
{
    Storage::fake(CharacterizationDocument::STORAGE_DISK);
    $characterization = Characterization::factory()->create(['user_id' => $user->id]);
    $path = 'characterization-documents/'.$characterization->id.'/synthetic.pdf';
    Storage::disk(CharacterizationDocument::STORAGE_DISK)->put($path, 'synthetic-only');
    $document = CharacterizationDocument::query()->create([
        'characterization_id' => $characterization->id, 'original_filename' => 'synthetic.pdf',
        'stored_path' => $path, 'sha256' => hash('sha256', 'synthetic-only'), 'size_bytes' => 14,
        'mime' => 'application/pdf', 'status' => CharacterizationDocument::STATUS_UPLOADED,
    ]);
    return [$characterization, $document, $path];
}

it('T05c supported deletion closes all owned contexts and retains exact immutable history', function () {
    [$user, $group, , $ledger] = t05cFixture();
    $ledger->grant($user->id, $group->id, 'synthetic:other', 0, hash('sha256', 't05c:other'));
    [, $revokedGroup, , $second] = t05cFixture($user, 'two');
    $second->revoke($user->id, $revokedGroup->id, 'synthetic:learning', 1, hash('sha256', 't05c:revoke'));
    [$unrelated] = t05cFixture(null, 'unrelated');
    $otherBytes = t05cBytes($unrelated->id);
    $events = DB::table('learning_authorization_records')->orderBy('id')->get();
    $headers = LearningAuthorizationState::where('user_id', $user->id)->get();
    expect(app(LearningAuthorizationAuthority::class))->toBeInstanceOf(DenyLearningAuthorizationAuthority::class);
    $this->actingAs($user)->delete('/profile', ['password' => 'password'])->assertRedirect('/')->assertSessionHasNoErrors();
    $this->assertGuest();
    expect($user->fresh())->toBeNull()->and(t05cBytes($unrelated->id))->toBe($otherBytes)
        ->and(LearningAuthorizationState::count())->toBe(4)->and(LearningAuthorizationRecord::count())->toBe(8);
    foreach ($events as $event) {
        expect((array) DB::table('learning_authorization_records')->where('id', $event->id)->first())->toBe((array) $event);
    }
    foreach ($headers as $before) {
        $after = $before->fresh();
        $terminal = LearningAuthorizationRecord::where('learning_authorization_state_id', $before->id)->orderByDesc('generation')->first();
        $payload = json_decode($terminal->payload_text, true, 32, JSON_THROW_ON_ERROR);
        expect($after->status)->toBe('deleted')->and($after->user_id)->toBeNull()
            ->and($after->generation)->toBe($before->generation + 1)->and($after->subject_digest)->toBe($before->subject_digest)
            ->and($after->holder_ref)->toBe($before->holder_ref)->and($after->promotion_allowed)->toBeFalse()
            ->and($terminal->command_id)->toBe(hash('sha256', json_encode([
                'learning-ledger:account-deletion:v1', $user->id, $before->subject_digest, $before->generation,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)))
            ->and($terminal->generation)->toBe($before->generation + 1)->and($terminal->previous_generation)->toBe($before->generation)
            ->and($terminal->previous_digest)->toBe($before->event_digest)->and($terminal->event_digest)->toBe(hash('sha256', $terminal->payload_text))
            ->and($payload['actor_id'])->toBe($user->id)->and($payload['group_id'])->toBe($before->learning_company_group_id)
            ->and($payload['purpose'])->toBe($before->purpose)->and($payload['witness'])->toBeNull()
            ->and($payload['provenance'])->toBe('synthetic-only')->and($payload['promotion_allowed'])->toBeFalse();
    }
    $bytes = t05cBytes();
    expect($ledger->grant($user->id, $group->id, 'synthetic:learning', 0, hash('sha256', 't05c:late'))['status'])->toBe('denied');
    $replacement = User::factory()->create(['email' => $user->email]);
    expect($replacement->id)->not->toBe($user->id)
        ->and(app(LearningAuthorizationLedger::class)->current($replacement->id, $group->id, 'synthetic:learning')['status'])->toBe('denied')
        ->and(app(LearningAuthorizationLedger::class)->grant($replacement->id, $group->id, 'synthetic:learning', 0, hash('sha256', 't05c:new'))['status'])->toBe('denied')
        ->and(t05cBytes())->toBe($bytes);
});

it('T05c empty ledger preserves the supported lifecycle without creating rows', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->delete('/profile', ['password' => 'password'])->assertRedirect('/');
    $this->assertGuest();
    expect(LearningAuthorizationRecord::count())->toBe(0)->and(LearningAuthorizationState::count())->toBe(0);
});

it('T05c internal withdrawal and preexisting tombstones are idempotent before removal', function (bool $terminal) {
    [$user, $group, , $ledger] = t05cFixture();
    if ($terminal) { $ledger->tombstone($user->id, $group->id, 'synthetic:learning', 1, hash('sha256', 't05c:terminal')); }
    $before = t05cBytes();
    app(LearningAuthorizationLedger::class)->tombstoneForAccount($user->id);
    if ($terminal) { expect(t05cBytes())->toBe($before); }
    $before = t05cBytes();
    app(LearningAuthorizationLedger::class)->tombstoneForAccount($user->id);
    expect(t05cBytes())->toBe($before)->and(LearningAuthorizationRecord::count())->toBe(2);
    $this->actingAs($user)->delete('/profile', ['password' => 'password'])->assertRedirect('/');
    expect(LearningAuthorizationRecord::count())->toBe(2)->and(LearningAuthorizationState::first()->generation)->toBe(2);
})->with([false, true]);

it('T05c withdrawal ignores lost membership unverified account and unavailable source', function (bool $lost) {
    [$user, , $member, $ledger, $source] = t05cFixture();
    if ($lost) {
        $member->forceFill(['verification_status' => 'revoked', 'revoked_at' => now()])->save();
        $user->forceFill(['email_verified_at' => null])->save();
    }
    $source->unavailable = true;
    $calls = $source->calls;
    $this->app->instance(LearningAuthorizationLedger::class, $ledger);
    $this->actingAs($user)->delete('/profile', ['password' => 'password'])->assertRedirect('/');
    expect($source->calls)->toBe($calls)->and(LearningAuthorizationState::first()->status)->toBe('deleted')
        ->and(LearningAuthorizationRecord::count())->toBe(2);
})->with([false, true]);

it('T05c lifecycle blockers leave ledger account and documents unchanged', function (string $blocker) {
    [$user] = t05cFixture();
    [, $document, $path] = t05cDocument($user);
    $this->actingAs($user);
    $password = 'password';
    if ($blocker === 'password') { $password = 'incorrect'; }
    if ($blocker === 'auth_version') { DB::table('users')->where('id', $user->id)->update(['auth_version' => 1]); }
    if ($blocker === 'extracting') { $document->update(['status' => CharacterizationDocument::STATUS_EXTRACTING]); }
    $before = t05cBytes();
    $this->from('/profile')->delete('/profile', ['password' => $password])->assertRedirect('/profile')->assertSessionHasErrors();
    expect(t05cBytes())->toBe($before)->and($user->fresh())->not->toBeNull()->and($document->fresh())->not->toBeNull();
    Storage::disk(CharacterizationDocument::STORAGE_DISK)->assertExists($path);
})->with(['password', 'auth_version', 'extracting']);

it('T05c second tombstone and user deletion failures roll back every write and preserve session bytes', function (string $failure) {
    [$user] = t05cFixture();
    t05cFixture($user, 'two');
    [$characterization, $document, $path] = t05cDocument($user);
    $before = t05cBytes();
    $seen = 0;
    if ($failure === 'second') {
        Event::listen('eloquent.creating: '.LearningAuthorizationRecord::class, function ($record) use (&$seen): void {
            if (json_decode($record->payload_text, true)['operation'] === 'tombstone' && ++$seen === 2) {
                expect(DB::table('learning_authorization_states')->where('status', 'deleted')->count())->toBe(1);
                throw new RuntimeException('synthetic-second-tombstone-failure');
            }
        });
    } else {
        Event::listen('eloquent.deleting: '.User::class, function (): never {
            expect(DB::table('learning_authorization_states')->where('status', 'deleted')->count())->toBe(2);
            throw new RuntimeException('synthetic-user-delete-failure');
        });
    }
    $this->withoutExceptionHandling()->actingAs($user)->withSession(['t05c_marker' => 'preserved']);
    expect(fn () => $this->delete('/profile', ['password' => 'password']))->toThrow(RuntimeException::class, 'synthetic-');
    expect(t05cBytes())->toBe($before)->and($user->fresh())->not->toBeNull()
        ->and($characterization->fresh())->not->toBeNull()->and($document->fresh())->not->toBeNull()
        ->and(session('t05c_marker'))->toBe('preserved')->and(DB::table('characterization_document_purges')->count())->toBe(0);
    $this->assertAuthenticatedAs($user);
    Storage::disk(CharacterizationDocument::STORAGE_DISK)->assertExists($path);
    if ($failure === 'second') { expect($seen)->toBe(2); }
})->with(['second', 'user']);

it('T05c tampered projection or history aborts the whole supported deletion', function (string $tamper) {
    [$user] = t05cFixture();
    t05cFixture($user, 'two');
    [, $document, $path] = t05cDocument($user);
    $header = LearningAuthorizationState::orderByDesc('id')->first();
    if ($tamper === 'header') { DB::table('learning_authorization_states')->where('id', $header->id)->update(['event_digest' => str_repeat('f', 64)]); }
    else { DB::table('learning_authorization_records')->where('learning_authorization_state_id', $header->id)->update(['payload_text' => '{}']); }
    $before = t05cBytes();
    $this->withoutExceptionHandling()->actingAs($user);
    expect(fn () => $this->delete('/profile', ['password' => 'password']))->toThrow(DomainException::class, 'learning_ledger.incoherent');
    expect(t05cBytes())->toBe($before)->and($user->fresh())->not->toBeNull()->and($document->fresh())->not->toBeNull();
    $this->assertAuthenticatedAs($user);
    Storage::disk(CharacterizationDocument::STORAGE_DISK)->assertExists($path);
})->with(['header', 'history']);
