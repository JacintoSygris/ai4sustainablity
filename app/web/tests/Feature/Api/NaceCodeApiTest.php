<?php

use App\Models\NaceCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\NaceCodeSeeder::class);
});

it('returns a paginated list of NACE codes', function () {
    $user = User::factory()->create();

    $this->getJson('/api/nace-codes?per_page=50')
        ->assertUnauthorized();

    $response = $this->actingAs($user)
        ->getJson('/api/nace-codes?per_page=50');

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                [
                    'code',
                    'level',
                    'parent_code',
                    'title' => ['en', 'es'],
                ],
            ],
            'links' => [],
            'meta' => ['current_page', 'per_page', 'total'],
        ]);

    expect($response->json('meta.total'))->toBe(NaceCode::count());
    expect($response->json('data'))->toHaveCount(50);
});

it('filters NACE codes by level', function () {
    $response = $this->actingAs(User::factory()->create())
        ->getJson('/api/nace-codes?level=0');

    $response->assertOk();
    $data = $response->json('data');

    foreach ($data as $item) {
        expect($item['level'])->toBe(0);
    }
});

it('searches the current Spanish CNAE 2025 labels', function () {
    $this->actingAs(User::factory()->create())
        ->getJson('/api/nace-codes?search=agricultura&per_page=5')
        ->assertOk()
        ->assertJsonPath('data.0.code', 'A')
        ->assertJsonPath('data.0.title.es', 'AGRICULTURA, GANADERÍA, SILVICULTURA Y PESCA');
});

it('searches Spanish CNAE labels without requiring accents', function () {
    $user = User::factory()->create();

    $plain = $this->actingAs($user)
        ->getJson('/api/nace-codes?search=informatica&per_page=20')
        ->assertOk()
        ->json('data');

    $accented = $this->actingAs($user)
        ->getJson('/api/nace-codes?search=inform%C3%A1tica&per_page=20')
        ->assertOk()
        ->json('data');

    $plainCodes = collect($plain)->pluck('code')->all();
    $accentedCodes = collect($accented)->pluck('code')->all();

    expect($plainCodes)->toContain('K62');
    expect($plainCodes)->toBe($accentedCodes);
});
