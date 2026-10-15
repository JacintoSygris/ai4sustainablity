<?php

use App\Models\ReportAuditEvent;
use App\Services\EsrsDatapointResponseState;
use Tests\Support\IroTraceabilityFixture;

// Synthetic answers only; P8 is reconfirmed through its real API, with no IRO change.
function pendingP9AuthorityReview(Tests\TestCase $test): array
{
    $fixture = IroTraceabilityFixture::make($test);
    $corpus = IroTraceabilityFixture::confirm($test, $fixture);
    $ids = array_slice(app(EsrsDatapointResponseState::class)->corpusDatapointIds($corpus), 0, 4);
    $responses = [];
    foreach (['completed', 'not_applicable', 'draft', 'completed'] as $index => $status) {
        $responses[] = ['datapoint_id' => $ids[$index], 'status' => $status,
            'value' => 'Synthetic answer '.$index, 'evidence_reference' => 'Synthetic evidence',
            'note' => 'Synthetic rationale', 'triage' => 'have_it'];
    }
    $body = ['expected_revision' => 0, 'expected_authority_digest' => $corpus['p9_authority_digest'], 'responses' => $responses];
    $test->putJson('/api/esrs-datapoints/responses', $body)->assertOk();
    // A stored response outside the current corpus must remain an orphan, not be restored by this review.
    $form = $fixture['characterization']->fresh()->form_data;
    $form['esrs_datapoint_responses']['responses']['SYNTHETIC_RETIRED'] = [
        'datapoint_id' => 'SYNTHETIC_RETIRED', 'status' => 'completed', 'value' => 'Synthetic retired answer',
    ];
    $fixture['characterization']->update(['form_data' => $form]);
    $stored = $form['esrs_datapoint_responses'];
    $test->putJson('/api/materiality-confirmation', [...$fixture['payload'], 'expected_revision' => 1])->assertOk();
    $state = $test->getJson('/api/esrs-datapoints/responses')->assertOk()->json('data');

    return compact('fixture', 'corpus', 'ids', 'body', 'stored', 'state');
}

it('projects old statuses as drafts without overwriting stored answers or orphaned responses', function () {
    $f = pendingP9AuthorityReview($this);
    expect($f['state']['authority_review_required'])->toBeTrue()
        ->and($f['state']['p9_authority_digest'])->not->toBe($f['corpus']['p9_authority_digest'])
        ->and($f['state']['revision'])->toBe(1)
        ->and($f['state']['summary']['completed_count'])->toBe(0)
        ->and($f['state']['summary']['not_applicable_count'])->toBe(0);
    foreach ($f['body']['responses'] as $original) {
        $row = $f['state']['responses'][$original['datapoint_id']];
        expect($row['status'])->toBe('draft')->and($row['previous_status'])->toBe($original['status'])
            ->and($row['value'])->toBe($original['value'])->and($row['note'])->toBe($original['note']);
    }
    expect($f['state']['responses'])->not->toHaveKey('SYNTHETIC_RETIRED')
        ->and($f['state']['orphaned']['responses']['SYNTHETIC_RETIRED']['status'])->toBe('completed')
        ->and($f['fixture']['characterization']->fresh()->form_data['esrs_datapoint_responses'])->toBe($f['stored']);
});

it('confirms retained answers and audits restored statuses using locked server state', function () {
    $f = pendingP9AuthorityReview($this);
    $responses = array_values($f['state']['responses']);
    foreach ($responses as &$row) { $row['status'] = $row['previous_status']; unset($row['previous_status']); }
    unset($row);
    // A deliberate new draft takes precedence over its old completed state.
    $responses[3]['status'] = 'draft';
    $saved = $this->putJson('/api/esrs-datapoints/responses', [
        'expected_revision' => 1, 'expected_authority_digest' => $f['state']['p9_authority_digest'],
        'authority_review_confirmed' => true, 'responses' => $responses,
    ])->assertOk()->assertJsonPath('data.authority_review_required', false)
        ->assertJsonPath('data.revision', 2)->assertJsonPath('data.summary.completed_count', 1)
        ->assertJsonPath('data.summary.not_applicable_count', 1)->json('data');
    foreach ($responses as $row) {
        expect($saved['responses'][$row['datapoint_id']]['status'])->toBe($row['status'])
            ->and($saved['responses'][$row['datapoint_id']]['value'])->toBe($row['value'])
            ->and($saved['responses'][$row['datapoint_id']])->not->toHaveKey('previous_status');
    }
    expect($saved['orphaned']['responses'])->toBe($f['state']['orphaned']['responses']);
    $event = ReportAuditEvent::where('characterization_id', $f['fixture']['characterization']->id)
        ->where('event_type', 'datapoint_responses_replaced')->latest('id')->firstOrFail();
    expect($event->user_id)->toBe($f['fixture']['user']->id)
        ->and($event->payload['previous_revision'])->toBe(1)->and($event->payload['revision'])->toBe(2)
        ->and($event->payload['authority_review'])->toBe([
            'confirmed' => true, 'previous_digest' => $f['corpus']['p9_authority_digest'],
            'current_digest' => $f['state']['p9_authority_digest'],
            'restored_statuses' => [$f['ids'][0] => 'completed', $f['ids'][1] => 'not_applicable'],
        ]);
    $summary = app(EsrsDatapointResponseState::class)->reportSummary($f['fixture']['characterization']->fresh(),
        $this->getJson('/api/esrs-datapoints')->assertOk()->json('data'));
    expect($summary['completed_count'])->toBe(1)->and($summary['not_applicable_count'])->toBe(1);
});

it('rejects implicit review stale digests and revision conflicts without changing answers or audit', function () {
    $f = pendingP9AuthorityReview($this);
    $body = [...$f['body'], 'expected_revision' => 1, 'expected_authority_digest' => $f['state']['p9_authority_digest']];
    $auditCount = ReportAuditEvent::where('event_type', 'datapoint_responses_replaced')->count();
    $this->putJson('/api/esrs-datapoints/responses', $body)->assertConflict()->assertJsonPath('code', 'iro_review_required');
    $this->putJson('/api/esrs-datapoints/responses', [...$body, 'authority_review_confirmed' => true,
        'expected_authority_digest' => $f['corpus']['p9_authority_digest']])->assertConflict()->assertJsonPath('code', 'p8_confirmation_stale');
    $this->putJson('/api/esrs-datapoints/responses', [...$body, 'authority_review_confirmed' => true, 'expected_revision' => 0])
        ->assertConflict()->assertJsonPath('code', 'datapoint_responses_conflict')
        ->assertJsonPath('data.authority_review_required', true)->assertJsonPath('data.revision', 1);
    expect($f['fixture']['characterization']->fresh()->form_data['esrs_datapoint_responses'])->toBe($f['stored'])
        ->and(ReportAuditEvent::where('event_type', 'datapoint_responses_replaced')->count())->toBe($auditCount);
    $this->putJson('/api/esrs-datapoints/responses', [...$body, 'authority_review_confirmed' => true])->assertOk();
    $this->putJson('/api/esrs-datapoints/responses', [...$body, 'authority_review_confirmed' => true])->assertConflict()
        ->assertJsonPath('data.authority_review_required', false)->assertJsonPath('data.revision', 2);
});

it('rejects out of corpus restoration and ignores forged previous statuses', function () {
    $f = pendingP9AuthorityReview($this);
    $body = ['expected_revision' => 1, 'expected_authority_digest' => $f['state']['p9_authority_digest'], 'authority_review_confirmed' => true];
    $this->putJson('/api/esrs-datapoints/responses', [...$body, 'responses' => [
        ['datapoint_id' => 'SYNTHETIC_RETIRED', 'status' => 'completed'],
    ]])->assertUnprocessable();
    expect($f['fixture']['characterization']->fresh()->form_data['esrs_datapoint_responses'])->toBe($f['stored']);
    $rows = $f['body']['responses'];
    $rows[2]['previous_status'] = 'completed';
    $saved = $this->putJson('/api/esrs-datapoints/responses', [...$body, 'responses' => $rows])->assertOk()->json('data');
    expect($saved['responses'][$f['ids'][2]]['status'])->toBe('draft');
});
