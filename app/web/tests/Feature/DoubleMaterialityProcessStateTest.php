<?php

use App\Models\Characterization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('returns default double materiality process state when no process has been stored', function () {
    Characterization::factory()->create([
        'user_id' => $this->user->id,
        'form_data' => [],
    ]);

    $this->actingAs($this->user)
        ->getJson('/api/double-materiality-guide/state')
        ->assertOk()
        ->assertJsonPath('data.checklist.identified_stakeholders', false)
        ->assertJsonPath('data.checklist.assessed_impacts', false)
        ->assertJsonPath('data.checklist.assessed_financial_effects', false)
        ->assertJsonPath('data.checklist.reached_conclusions', false)
        ->assertJsonPath('data.acta.completed_on', null)
        ->assertJsonPath('data.acta.method', null)
        ->assertJsonPath('data.acta.participants', null)
        ->assertJsonPath('data.acta_registered', false)
        ->assertJsonPath('data.guide_status', 'missing')
        ->assertJsonPath('data.updated_at', null);
});

it('persists checklist and acta groups separately with guide status transitions', function () {
    $characterization = Characterization::factory()->create([
        'user_id' => $this->user->id,
        'form_data' => [],
    ]);

    $this->actingAs($this->user)
        ->putJson('/api/double-materiality-guide/state', [
            'checklist' => [
                'identified_stakeholders' => true,
                'assessed_impacts' => true,
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.checklist.identified_stakeholders', true)
        ->assertJsonPath('data.checklist.assessed_impacts', true)
        ->assertJsonPath('data.checklist.assessed_financial_effects', false)
        ->assertJsonPath('data.checklist.reached_conclusions', false)
        ->assertJsonPath('data.acta_registered', false)
        ->assertJsonPath('data.guide_status', 'in_progress');

    $characterization->refresh();

    expect(data_get($characterization->form_data, 'double_materiality_process.checklist'))
        ->toBe([
            'identified_stakeholders' => true,
            'assessed_impacts' => true,
            'assessed_financial_effects' => false,
            'reached_conclusions' => false,
        ]);

    $this->actingAs($this->user)
        ->putJson('/api/double-materiality-guide/state', [
            'acta' => [
                'completed_on' => '2026-06-01',
                'method' => 'Taller interno con dirección',
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.checklist.identified_stakeholders', true)
        ->assertJsonPath('data.acta.completed_on', '2026-06-01')
        ->assertJsonPath('data.acta.method', 'Taller interno con dirección')
        ->assertJsonPath('data.acta.participants', null)
        ->assertJsonPath('data.acta_registered', false)
        ->assertJsonPath('data.guide_status', 'in_progress');

    $this->actingAs($this->user)
        ->putJson('/api/double-materiality-guide/state', [
            'acta' => [
                'completed_on' => '2026-06-01',
                'method' => 'Taller interno con dirección',
                'participants' => 'Gerencia, RRHH, producción',
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.acta_registered', true)
        ->assertJsonPath('data.guide_status', 'ready');

    $characterization->refresh();

    expect(data_get($characterization->form_data, 'double_materiality_process.acta'))
        ->toBe([
            'completed_on' => '2026-06-01',
            'method' => 'Taller interno con dirección',
            'participants' => 'Gerencia, RRHH, producción',
        ]);
    expect(data_get($characterization->form_data, 'double_materiality_process.updated_at'))->not->toBeNull();
});

it('marks the guide ready when the checklist is complete without acta', function () {
    Characterization::factory()->create([
        'user_id' => $this->user->id,
        'form_data' => [],
    ]);

    $this->actingAs($this->user)
        ->putJson('/api/double-materiality-guide/state', [
            'checklist' => [
                'identified_stakeholders' => true,
                'assessed_impacts' => true,
                'assessed_financial_effects' => true,
                'reached_conclusions' => true,
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.acta_registered', false)
        ->assertJsonPath('data.guide_status', 'ready');
});

it('rejects future acta completion dates', function () {
    Characterization::factory()->create([
        'user_id' => $this->user->id,
        'form_data' => [],
    ]);

    $this->actingAs($this->user)
        ->putJson('/api/double-materiality-guide/state', [
            'acta' => [
                'completed_on' => now()->addDay()->toDateString(),
                'method' => 'Taller interno con dirección',
                'participants' => 'Gerencia, RRHH, producción',
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['acta.completed_on']);
});
