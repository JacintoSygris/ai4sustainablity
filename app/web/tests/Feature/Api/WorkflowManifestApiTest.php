<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.private_dev.auto_login' => false]);
});

it('requires authentication for the frontend workflow manifest', function () {
    $this->getJson('/api/workflow')
        ->assertUnauthorized();
});

it('returns frontend workflow phases and backend capabilities', function () {
    $this->actingAs(User::factory()->create())
        ->getJson('/api/workflow')
        ->assertOk()
        ->assertJsonPath('data.type', 'frontend_workflow_manifest')
        ->assertJsonPath('data.version', 'v0')
        ->assertJsonPath('data.frontend_source.presence', 'present')
        ->assertJsonPath('data.frontend_source.integration_status', 'integrated_laravel_api_runtime')
        ->assertJsonPath('data.frontend_source.workspace', 'app/frontend')
        ->assertJsonPath('data.frontend_source.source_zip_filename', 'airis-main.zip')
        ->assertJsonPath('data.frontend_source.target_backend_owner', 'Laravel')
        ->assertJsonPath('data.frontend_source.current_runtime.auth', 'Laravel web session')
        ->assertJsonPath('data.frontend_source.current_runtime.database', 'Laravel persistence')
        ->assertJsonPath('data.frontend_source.current_runtime.api_routes', 'Laravel API via Next rewrites')
        ->assertJsonPath('data.frontend_source.secrets.example_file', 'app/frontend/.env.example')
        ->assertJsonPath('data.frontend_source.secrets.real_env_files_committed', false)
        ->assertJsonCount(0, 'data.frontend_source.secrets.required_private_values')
        ->assertJsonPath('data.frontend_source.handoff_warnings.1', 'Step 2 and Step 3 use client-supplied reportId without userId ownership checks in the received source.')
        ->assertJsonPath('data.frontend_source.handoff_warnings.2', 'Imported Better Auth and Turso/libSQL modules remain historical source-snapshot code; the current integrated runtime is Laravel-owned.')
        ->assertJsonPath('data.frontend_source.quality_gates.build.status', 'pass_smoke')
        ->assertJsonPath('data.frontend_source.quality_gates.lint.status', 'failing')
        ->assertJsonPath('data.workflow.0.phase', 'P5')
        ->assertJsonPath('data.workflow.0.primary_endpoint', '/api/characterization')
        ->assertJsonPath('data.workflow.1.phase', 'P6')
        ->assertJsonPath('data.workflow.1.primary_endpoint', '/api/materiality-proposal')
        ->assertJsonPath('data.workflow.2.phase', 'P7')
        ->assertJsonPath('data.workflow.2.primary_endpoint', '/api/double-materiality-guide')
        ->assertJsonPath('data.workflow.2.supporting_endpoints.0', '/api/double-materiality-guide/templates/{template}.csv')
        ->assertJsonPath('data.workflow.3.phase', 'P8')
        ->assertJsonPath('data.workflow.3.primary_endpoint', '/api/materiality-confirmation')
        ->assertJsonPath('data.workflow.4.phase', 'P9')
        ->assertJsonPath('data.workflow.4.primary_endpoint', '/api/esrs-datapoints')
        ->assertJsonPath('data.workflow.4.supporting_endpoints.1', '/api/esrs-datapoints/responses')
        ->assertJsonPath('data.workflow.4.supporting_endpoints.2', '/api/esrs-datapoints/responses/export.csv')
        ->assertJsonPath('data.workflow.4.supporting_endpoints.3', '/api/esrs-datapoints/export.csv')
        ->assertJsonPath('data.workflow.5.phase', 'P10')
        ->assertJsonPath('data.workflow.5.title', 'Report package')
        ->assertJsonPath('data.workflow.5.primary_endpoint', '/api/report')
        ->assertJsonPath('data.workflow.5.supporting_endpoints.0', '/api/report/draft')
        ->assertJsonPath('data.workflow.5.supporting_endpoints.1', '/api/materiality-confirmation/decision-sheet')
        ->assertJsonPath('data.workflow.5.supporting_endpoints.2', '/api/esrs-datapoints/responses/export.csv')
        ->assertJsonPath('data.capabilities.private_dev_auto_login', false)
        ->assertJsonPath('data.capabilities.characterization_gateway.default', 'mock')
        ->assertJsonPath('data.capabilities.p9.granularity', 'disclosure_requirement_mapping_required')
        ->assertJsonPath('data.capabilities.report.draft_endpoint', '/api/report/draft')
        ->assertJsonPath('data.capabilities.report.generation_status', 'not_implemented')
        ->assertJsonPath('data.limitations.0.key', 'historical_imported_frontend_source_state')
        ->assertJsonPath('data.limitations.0.message', 'The buildable original Airis/Vercel frontend source remains under app/frontend, but the current integrated P5-P10 runtime uses Laravel auth, persistence, and API ownership. Standalone Next/Turso/Better Auth routes are historical snapshot behavior only.')
        ->assertJsonPath('data.limitations.1.key', 'exact_ar16_matter_to_dr_mapping_pending')
        ->assertJsonPath('data.limitations.2.key', 'final_report_generation_pending')
        ->assertJsonPath('data.limitations.3.key', 'auth_boundary');
});

it('can auto authenticate the private dev technical user for the workflow manifest', function () {
    config([
        'services.private_dev.auto_login' => true,
        'services.private_dev.user_email' => 'i4sdev-workflow@example.test',
        'services.private_dev.user_name' => 'I4S Workflow Dev',
    ]);

    $this->getJson('/api/workflow')
        ->assertOk()
        ->assertJsonPath('data.type', 'frontend_workflow_manifest');

    expect(User::where('email', 'i4sdev-workflow@example.test')->exists())->toBeTrue();
});
