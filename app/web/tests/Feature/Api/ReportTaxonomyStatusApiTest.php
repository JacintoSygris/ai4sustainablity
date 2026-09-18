<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('exposes only safe ESRS taxonomy status metadata to authenticated P10 users', function () {
    config(['services.report.external_taxonomy_manifest_path' => null]);

    $payload = $this->actingAs(User::factory()->create())
        ->getJson('/api/report/taxonomy')
        ->assertOk()
        ->assertJsonPath('data.taxonomy.name', 'EFRAG ESRS XBRL Taxonomy Set 1')
        ->assertJsonPath('data.taxonomy.version', '2023-12-22')
        ->assertJsonPath('data.reporting_profile', 'esrs-2023-preparatory-v1')
        ->assertJsonPath('data.availability.state', 'blocked')
        ->assertJsonPath('data.availability.reason_code', 'external_taxonomy_manifest_missing')
        ->json('data');

    $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

    expect($encoded)
        ->not->toContain('taxonomy_package_path')
        ->not->toContain('manifest_path')
        ->not->toContain('external_taxonomy_manifest_path')
        ->not->toMatch('/[a-f0-9]{64}/i');
});
