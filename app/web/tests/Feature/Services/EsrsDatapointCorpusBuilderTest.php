<?php

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('derives the E1 exception baseline from the stored P6 snapshot, not the live proposal', function () {
    $this->seed(\Database\Seeders\EsrsTopicSeeder::class);
    $user = \App\Models\User::factory()->create();
    $e1 = \App\Models\EsrsTopic::where('esrs_code', 'E1')->firstOrFail();
    $e2 = \App\Models\EsrsTopic::where('esrs_code', 'E2')->firstOrFail();

    // The user saw E1 and E2, confirmed only E2. P6 has since re-run and dropped E1.
    $characterization = \App\Models\Characterization::factory()->create([
        'user_id' => $user->id,
        'status' => \App\Models\Characterization::STATUS_COMPLETED,
        'nace_code' => 'A',
        'esrs_topic_ids' => [$e2->id],                 // live: E1 gone
        'form_data' => [
            'materiality_confirmation' => [
                'confirmed_topic_ids' => [$e2->id],
                'p6_snapshot' => ['topic_ids' => [$e1->id, $e2->id], 'captured_at' => '2026-01-01T00:00:00Z'],
                'e1_not_material_explanation' => 'E1 no material por ubicación.',
            ],
        ],
    ]);

    $corpus = app(\App\Services\EsrsDatapointCorpusBuilder::class)->build($characterization);

    // The user DID assess E1 and rejected it, so the mandatory explanation still applies.
    expect($corpus['blocks']['e1_not_material_explanation']['applies'])->toBeTrue();
    expect($corpus['blocks']['e1_not_material_explanation']['status'])->toBe('satisfied');
    // P9 output must be untouched by this change.
    expect($corpus['material_topic_ids'])->toBe([$e2->id]);
});

it('treats a present-but-null materiality_confirmation as absent instead of throwing', function () {
    $this->seed(\Database\Seeders\EsrsTopicSeeder::class);
    $user = \App\Models\User::factory()->create();
    $e2 = \App\Models\EsrsTopic::where('esrs_code', 'E2')->firstOrFail();

    // form_data has the key, but its value is explicitly null (e.g. a prior save wiped it).
    $characterization = \App\Models\Characterization::factory()->create([
        'user_id' => $user->id,
        'status' => \App\Models\Characterization::STATUS_COMPLETED,
        'nace_code' => 'A',
        'esrs_topic_ids' => [$e2->id],
        'form_data' => [
            'materiality_confirmation' => null,
        ],
    ]);

    $corpus = app(\App\Services\EsrsDatapointCorpusBuilder::class)->build($characterization);

    expect($corpus['material_topic_ids'])->toBe([$e2->id]);
    expect($corpus['blocks']['e1_not_material_explanation']['applies'])->toBeFalse();
});
