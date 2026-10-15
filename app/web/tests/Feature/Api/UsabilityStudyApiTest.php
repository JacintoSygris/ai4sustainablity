<?php

use App\Models\User;
use App\Services\Usability\StudyAnalysis;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['services.private_dev.auto_login' => false, 'usability.enabled' => true, 'usability.admin_user_ids' => []]);
});

function enrolStudy($test, User $user): string
{
    return $test->actingAs($user)->postJson('/api/usability-study/enrol', [
        'consent' => true, 'consent_version' => config('usability.consent_version'),
    ])->assertOk()->json('data.study_participant_id');
}

it('requires a real Laravel session on every study endpoint', function (string $method, string $path) {
    $this->json($method, '/api/usability-study'.$path)->assertUnauthorized();
})->with([['GET', ''], ['POST', '/enrol'], ['DELETE', ''], ['POST', '/events'], ['PUT', '/questionnaire'], ['GET', '/export.csv']]);

it('advertises no study and stores no data while the flag is off', function () {
    config(['usability.enabled' => false]);
    $user = User::factory()->create();
    $this->actingAs($user)->getJson('/api/auth/session')->assertOk()->assertJsonMissingPath('data.usability_study_enabled');
    $this->getJson('/api/usability-study')->assertNotFound();
    $this->postJson('/api/usability-study/enrol', ['consent' => true, 'consent_version' => config('usability.consent_version')])->assertNotFound();
    $this->postJson('/api/usability-study/events')->assertNotFound();
    $this->putJson('/api/usability-study/questionnaire')->assertNotFound();
    $this->getJson('/api/usability-study/export.csv')->assertNotFound();
    $this->assertDatabaseCount('usability_participants', 0);
    $this->artisan('usability:report')->assertExitCode(1);
});

it('requires explicit versioned consent before enrolling or collecting events and SUS', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->getJson('/api/auth/session')->assertJsonPath('data.usability_study_enabled', true);
    $this->postJson('/api/usability-study/enrol', ['consent' => false, 'consent_version' => config('usability.consent_version')])->assertUnprocessable();
    $this->postJson('/api/usability-study/enrol', ['consent' => true, 'consent_version' => 'stale'])->assertUnprocessable();
    $id = (string) Str::uuid();
    $this->postJson('/api/usability-study/events', ['study_participant_id' => $id, 'task_id' => (string) Str::uuid(), 'step' => 'P5', 'event' => 'start'])->assertForbidden();
    $this->putJson('/api/usability-study/questionnaire', ['study_participant_id' => $id, 'answers' => array_fill(0, 10, 3)])->assertForbidden();
    $this->assertDatabaseCount('usability_participants', 0);
    $this->assertDatabaseCount('usability_events', 0);
});

it('stores pseudonymous task transitions idempotently with server timestamps', function () {
    $user = User::factory()->create();
    $id = enrolStudy($this, $user);
    expect($id)->not->toContain($user->email);
    $payload = ['study_participant_id' => $id, 'task_id' => (string) Str::uuid(), 'step' => 'P5', 'event' => 'start', 'occurred_at' => '2000-01-01'];
    $this->freezeTime();
    $this->postJson('/api/usability-study/events', $payload)->assertNoContent();
    $this->postJson('/api/usability-study/events', $payload)->assertNoContent();
    $this->assertDatabaseCount('usability_events', 1);
    expect(DB::table('usability_tasks')->first()->started_at)->toBe(now()->format('Y-m-d H:i:s'));
    $this->travel(60)->seconds();
    $payload['event'] = 'complete'; $payload['error_count'] = 2;
    $this->postJson('/api/usability-study/events', $payload)->assertNoContent();
    $this->postJson('/api/usability-study/events', $payload)->assertNoContent();
    $payload['event'] = 'abandon';
    $this->postJson('/api/usability-study/events', $payload)->assertConflict();
    $this->assertDatabaseCount('usability_events', 2);
    $metrics = app(StudyAnalysis::class)->report()['participants'][0];
    expect($metrics['tasks']['P5']['completed_seconds']['mean'])->toBe(60.0)
        ->and($metrics['tasks']['P5']['error_count'])->toBe(2);
});

it('rejects invalid task transitions and SUS responses', function () {
    $id = enrolStudy($this, User::factory()->create());
    $payload = ['study_participant_id' => $id, 'task_id' => (string) Str::uuid(), 'step' => 'P5', 'event' => 'complete'];
    $this->postJson('/api/usability-study/events', $payload)->assertForbidden();
    $this->postJson('/api/usability-study/events', [...$payload, 'step' => 'P11'])->assertUnprocessable();
    $this->postJson('/api/usability-study/events', [...$payload, 'error_count' => -1])->assertUnprocessable();
    $this->putJson('/api/usability-study/questionnaire', ['study_participant_id' => $id, 'answers' => array_fill(0, 9, 3)])->assertUnprocessable();
    $this->putJson('/api/usability-study/questionnaire', ['study_participant_id' => $id, 'answers' => array_fill(0, 10, 6)])->assertUnprocessable();
    $this->putJson('/api/usability-study/questionnaire', ['study_participant_id' => $id, 'answers' => array_fill(0, 10, 3), 'comments' => str_repeat('x', 2001)])->assertUnprocessable();
});

it('prevents IDOR through participant and task identifiers', function () {
    $owner = User::factory()->create(); $other = User::factory()->create();
    $ownerId = enrolStudy($this, $owner);
    $payload = ['study_participant_id' => $ownerId, 'task_id' => (string) Str::uuid(), 'step' => 'P6', 'event' => 'start'];
    $this->postJson('/api/usability-study/events', $payload)->assertNoContent();
    $otherId = enrolStudy($this, $other);
    $this->postJson('/api/usability-study/events', $payload)->assertForbidden();
    $this->postJson('/api/usability-study/events', [...$payload, 'study_participant_id' => $otherId, 'event' => 'complete'])->assertForbidden();
    $this->putJson('/api/usability-study/questionnaire', ['study_participant_id' => $ownerId, 'answers' => array_fill(0, 10, 3)])->assertForbidden();
    $this->deleteJson('/api/usability-study', ['study_participant_id' => $ownerId])->assertNoContent();
    $this->assertDatabaseHas('usability_participants', ['study_participant_id' => $ownerId]);
    $this->assertDatabaseMissing('usability_participants', ['study_participant_id' => $otherId]);
});

it('erases study data while disabled and rejects stale writes after re-enrolment', function () {
    $user = User::factory()->create(); $id = enrolStudy($this, $user);
    $payload = ['study_participant_id' => $id, 'task_id' => (string) Str::uuid(), 'step' => 'P10', 'event' => 'start'];
    $this->postJson('/api/usability-study/events', $payload)->assertNoContent();
    $this->postJson('/api/usability-study/events', [...$payload, 'event' => 'complete', 'error_count' => 0])->assertNoContent();
    $this->getJson('/api/usability-study')->assertJsonPath('data.p10_completed', true);
    $this->putJson('/api/usability-study/questionnaire', ['study_participant_id' => $id, 'answers' => array_fill(0, 10, 3), 'comments' => '<script>synthetic-test-only</script>'])->assertNoContent();
    config(['usability.enabled' => false]);
    $this->deleteJson('/api/usability-study')->assertNoContent();
    foreach (['usability_participants', 'usability_tasks', 'usability_events', 'usability_questionnaires'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
    config(['usability.enabled' => true]);
    expect(enrolStudy($this, $user))->not->toBe($id);
    $this->postJson('/api/usability-study/events', $payload)->assertForbidden();
});

it('blocks collection after a consent version change until explicit re-consent', function () {
    $id = enrolStudy($this, User::factory()->create());
    config(['usability.consent_version' => 'next-test-only']);
    $this->getJson('/api/usability-study')->assertJsonPath('data.consented', false);
    $this->postJson('/api/usability-study/events', ['study_participant_id' => $id, 'task_id' => (string) Str::uuid(), 'step' => 'P5', 'event' => 'start'])->assertForbidden();
});

it('exports only pseudonymous metrics to server-configured administrators', function () {
    $user = User::factory()->create(); $id = enrolStudy($this, $user);
    $this->putJson('/api/usability-study/questionnaire', ['study_participant_id' => $id, 'answers' => array_fill(0, 10, 3), 'comments' => '=HYPERLINK("synthetic-test-only")'])->assertNoContent();
    $this->getJson('/api/usability-study/export.csv')->assertForbidden();
    config(['usability.admin_user_ids' => [$user->id]]);
    $response = $this->get('/api/usability-study/export.csv')->assertOk();
    $csv = $response->getContent();
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    expect($csv)->toContain($id)->toContain(',50,')
        ->not->toContain($user->email)->not->toContain($user->name)->not->toContain('HYPERLINK')->not->toContain('user_id');
    $this->artisan('usability:report')->assertExitCode(0);
    $this->artisan('usability:report', ['--csv' => true])->assertExitCode(0);
});

it('cascades study erasure on account deletion', function () {
    $user = User::factory()->create(); $id = enrolStudy($this, $user);
    $this->postJson('/api/usability-study/events', ['study_participant_id' => $id, 'task_id' => (string) Str::uuid(), 'step' => 'P5', 'event' => 'start'])->assertNoContent();
    $user->delete();
    $this->assertDatabaseCount('usability_participants', 0);
    $this->assertDatabaseCount('usability_tasks', 0);
    $this->assertDatabaseCount('usability_events', 0);
});

it('rate limits study collection', function () {
    $id = enrolStudy($this, User::factory()->create());
    for ($i = 0; $i < 60; $i++) {
        $this->postJson('/api/usability-study/events', ['study_participant_id' => $id, 'task_id' => (string) Str::uuid(), 'step' => 'P5', 'event' => 'start'])->assertNoContent();
    }
    $this->postJson('/api/usability-study/events', ['study_participant_id' => $id, 'task_id' => (string) Str::uuid(), 'step' => 'P5', 'event' => 'start'])->assertStatus(429);
});

it('erases expired participants and allows full closure with collection disabled', function () {
    $user = User::factory()->create(); $id = enrolStudy($this, $user);
    $this->putJson('/api/usability-study/questionnaire', ['study_participant_id' => $id, 'answers' => array_fill(0, 10, 3)])->assertNoContent();
    $this->artisan('usability:purge')->assertExitCode(0);
    $this->assertDatabaseCount('usability_participants', 1);
    $this->travel(91)->days();
    $this->artisan('usability:purge')->assertExitCode(0);
    $this->assertDatabaseCount('usability_participants', 0);
    $this->assertDatabaseCount('usability_questionnaires', 0);
    enrolStudy($this, $user);
    config(['usability.enabled' => false]);
    $this->artisan('usability:purge', ['--all' => true])->assertExitCode(0);
    $this->assertDatabaseCount('usability_participants', 0);
});

it('permits withdrawal even if email verification becomes required', function () {
    $user = User::factory()->create(); enrolStudy($this, $user);
    $user->forceFill(['email_verified_at' => null])->save();
    config(['services.auth_hardening.require_email_verification' => true]);
    $this->actingAs($user)->deleteJson('/api/usability-study')->assertNoContent();
    $this->assertDatabaseCount('usability_participants', 0);
});

it('expires collection without relying on the scheduler and renews with a fresh identifier', function () {
    $user = User::factory()->create(); $id = enrolStudy($this, $user);
    $this->travel(91)->days();
    $this->getJson('/api/usability-study')->assertJsonPath('data.consented', false);
    $this->postJson('/api/usability-study/events', ['study_participant_id' => $id, 'task_id' => (string) Str::uuid(), 'step' => 'P5', 'event' => 'start'])->assertForbidden();
    expect(enrolStudy($this, $user))->not->toBe($id);
});

it('records re-consent time without extending the original retention deadline', function () {
    $this->freezeTime();
    $user = User::factory()->create(); $id = enrolStudy($this, $user);
    $enrolledAt = DB::table('usability_participants')->value('enrolled_at');
    $this->travel(30)->days();
    config(['usability.consent_version' => 'next-test-only']);
    expect(enrolStudy($this, $user))->toBe($id);
    $record = DB::table('usability_participants')->first();
    expect($record->enrolled_at)->toBe($enrolledAt)
        ->and($record->consented_at)->toBe(now()->format('Y-m-d H:i:s'));
    $this->travel(61)->days();
    $this->getJson('/api/usability-study')->assertJsonPath('data.consented', false);
});

it('restores the complete own SUS questionnaire for answer-only edits and explicit comment erasure', function () {
    $user = User::factory()->create(); $id = enrolStudy($this, $user);
    $answers = array_fill(0, 10, 3);
    $comments = '<script>synthetic-test-only</script>';
    $this->putJson('/api/usability-study/questionnaire', [
        'study_participant_id' => $id, 'answers' => $answers, 'comments' => $comments,
    ])->assertNoContent();
    $saved = $this->getJson('/api/usability-study')->assertOk()
        ->assertJsonPath('data.questionnaire.answers', $answers)
        ->assertJsonPath('data.questionnaire.comments', $comments)
        ->json('data.questionnaire');
    $saved['answers'][0] = 4;
    $this->putJson('/api/usability-study/questionnaire', [
        'study_participant_id' => $id, 'answers' => $saved['answers'], 'comments' => $saved['comments'],
    ])->assertNoContent();
    $this->getJson('/api/usability-study')->assertJsonPath('data.questionnaire.answers.0', 4)
        ->assertJsonPath('data.questionnaire.comments', $comments);
    $this->assertDatabaseCount('usability_questionnaires', 1);
    $this->putJson('/api/usability-study/questionnaire', [
        'study_participant_id' => $id, 'answers' => $saved['answers'], 'comments' => '',
    ])->assertNoContent();
    $this->getJson('/api/usability-study')->assertJsonPath('data.questionnaire.comments', null);
});

it('never exposes another participant questionnaire or a questionnaire without current consent', function () {
    $user = User::factory()->create(); $id = enrolStudy($this, $user);
    $this->putJson('/api/usability-study/questionnaire', [
        'study_participant_id' => $id, 'answers' => array_fill(0, 10, 3), 'comments' => 'synthetic-test-only',
    ])->assertNoContent();
    $this->actingAs(User::factory()->create())->getJson('/api/usability-study?study_participant_id='.$id)
        ->assertJsonPath('data.questionnaire', null);
    $this->actingAs($user);
    config(['usability.consent_version' => 'next-test-only']);
    $this->getJson('/api/usability-study')->assertJsonPath('data.questionnaire', null);
    config(['usability.consent_version' => '2026-10-08.1']);
    $this->travel(91)->days();
    $this->getJson('/api/usability-study')->assertJsonPath('data.questionnaire', null);
});
