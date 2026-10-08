<?php

use App\Models\Characterization;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

it('preserves sparse nested topic maps and JSON lists in characterization HTTP readback', function () {
    expect(app()->environment())->toBe('testing');
    expect(config('database.default'))->toBe('sqlite');
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    config(['services.private_dev.auto_login' => false]);
    Http::preventStrayRequests();
    Mail::fake();
    $user = User::factory()->create(['name' => 'SYNTHETIC', 'email' => 'synthetic@example.invalid']);
    $confirmation = [
        'guided_answers' => [3 => ['final_result' => 'material'], 87 => ['final_result' => 'no_material']],
        'change_reasons' => [3 => ['new_data'], 87 => ['new_data', 'scope_change']],
        'change_reason_notes' => [3 => 'Synthetic note', 87 => 'Synthetic scope'],
        'dimensions' => [3 => [25 => ['impact', 'financial']], 87 => [76 => ['impact']]],
    ];
    $formData = [
        'operations' => ['regions' => ['eu', 'global']],
        'materiality_confirmation' => $confirmation,
    ];
    $resultData = ['dimensions' => [3 => [87 => ['impact', 'financial']]], 'candidate_topics' => [3, 87]];
    $model = Characterization::factory()->create([
        'user_id' => $user->id,
        'esrs_topic_ids' => [3, 87],
        'form_data' => $formData,
        'result_data' => $resultData,
    ]);

    $response = $this->actingAs($user)->getJson('/api/characterization')->assertOk();
    expect($response->json('data.form_data.materiality_confirmation'))->toBe($confirmation);
    expect($response->json('data.form_data'))->toBe($formData);
    expect($response->json('data.result_data'))->toBe($resultData);
    $json = json_decode($response->getContent(), false, 512, JSON_THROW_ON_ERROR)->data;
    foreach (array_keys($confirmation) as $field) {
        expect($json->form_data->materiality_confirmation->{$field})->toBeInstanceOf(stdClass::class);
        expect(array_keys(get_object_vars($json->form_data->materiality_confirmation->{$field})))->toBe([3, 87]);
    }
    expect($json->esrs_topic_ids)->toBe([3, 87]);
    expect($json->form_data->operations->regions)->toBe(['eu', 'global']);
    expect($json->form_data->materiality_confirmation->change_reasons->{'87'})->toBe(['new_data', 'scope_change']);
    expect($json->form_data->materiality_confirmation->dimensions->{'3'}->{'25'})->toBe(['impact', 'financial']);
    expect($json->result_data->candidate_topics)->toBe([3, 87]);
    expect($model->fresh()->form_data)->toBe($formData);
    expect(Characterization::count())->toBe(1);
    expect(User::count())->toBe(1);
    Http::assertNothingSent();
    Mail::assertNothingSent();
    Mail::assertNothingQueued();
});
