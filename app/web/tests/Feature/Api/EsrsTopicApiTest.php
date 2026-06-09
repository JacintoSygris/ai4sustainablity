<?php

use App\Models\EsrsTopic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\EsrsTopicSeeder::class);
});

it('returns a paginated list of ESRS topics', function () {
    $user = User::factory()->create();

    $this->getJson('/api/esrs-topics?per_page=25')
        ->assertUnauthorized();

    $response = $this->actingAs($user)
        ->getJson('/api/esrs-topics?per_page=25');

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                [
                    'id',
                    'esrs_code',
                    'theme' => ['en', 'es'],
                    'subtheme' => ['en', 'es'],
                    'subtopic' => ['en', 'es'],
                    'examples' => ['en', 'es'],
                    'tags' => [
                        'competencies' => ['competency_1', 'competency_2', 'competency_3'],
                        'regulations' => ['regulation_1', 'regulation_2', 'regulation_3'],
                        'internal' => ['strategy', 'activities', 'policies', 'regulations'],
                    ],
                    'consolidated',
                ],
            ],
            'links' => [],
            'meta' => ['current_page', 'per_page', 'total'],
        ]);

    expect($response->json('meta.total'))->toBe(EsrsTopic::count());
    expect($response->json('data'))->toHaveCount(25);
});

it('filters ESRS topics by code', function () {
    $topic = EsrsTopic::first();

    $response = $this->actingAs(User::factory()->create())
        ->getJson('/api/esrs-topics?esrs_code=' . $topic->esrs_code);

    $response->assertOk();
    $data = $response->json('data');

    foreach ($data as $item) {
        expect($item['esrs_code'])->toBe($topic->esrs_code);
    }
});

it('can auto authenticate the private dev technical user for ESRS catalog requests', function () {
    config([
        'services.private_dev.auto_login' => true,
        'services.private_dev.user_email' => 'i4sdev-esrs@example.test',
        'services.private_dev.user_name' => 'I4S ESRS Dev',
    ]);

    $this->getJson('/api/esrs-topics?per_page=5')
        ->assertOk()
        ->assertJsonCount(5, 'data');

    expect(User::where('email', 'i4sdev-esrs@example.test')->exists())->toBeTrue();
});
