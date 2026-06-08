<?php

namespace App\Support;

class FrontendWorkflowManifest
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(): array
    {
        return [
            'type' => 'frontend_workflow_manifest',
            'version' => 'v0',
            'frontend_source' => [
                'presence' => 'present',
                'integration_status' => 'integrated_laravel_api_runtime',
                'workspace' => 'app/frontend',
                'target_backend_owner' => 'Laravel',
                'current_runtime' => [
                    'framework' => 'Next 16.1.4',
                    'auth' => 'Laravel web session',
                    'database' => 'Laravel persistence',
                    'api_routes' => 'Laravel API via Next rewrites',
                ],
                'secrets' => [
                    'example_file' => 'app/frontend/.env.example',
                    'real_env_files_committed' => false,
                    'required_private_values' => [],
                    'optional_private_values' => [],
                ],
                'integration_notes' => [
                    'The active P5-P10 workflow is Laravel-owned.',
                    'Local Next backend routes are not authoritative IA4S workflow APIs.',
                ],
                'quality_gates' => [
                    'build' => [
                        'command' => 'corepack pnpm build',
                        'status' => 'pass_smoke',
                        'notes' => 'Next build succeeds as a runability smoke, but next.config.mjs skips TypeScript validation.',
                    ],
                    'lint' => [
                        'command' => 'corepack pnpm lint',
                        'status' => 'failing',
                        'notes' => 'package.json defines lint: eslint ., but eslint is not installed in the frontend package.',
                    ],
                ],
            ],
            'workflow' => [
                self::phase(
                    'P5',
                    'Characterization',
                    '/api/characterization',
                    [
                        '/api/characterization/options',
                        '/api/nace-codes',
                    ],
                    'implemented',
                    'Company profile, operations profile, NACE sector, and progressive disclosure metadata.',
                ),
                self::phase(
                    'P6',
                    'AI materiality proposal',
                    '/api/materiality-proposal',
                    [
                        '/api/characterization/submit',
                    ],
                    'implemented',
                    'AI-proposed ESRS topics plus user review traceability before external ADM.',
                ),
                self::phase(
                    'P7',
                    'Double materiality guide',
                    '/api/double-materiality-guide',
                    [
                        '/api/double-materiality-guide/templates/{template}.csv',
                    ],
                    'implemented',
                    'Structured guide for the external double materiality assessment; it does not decide materiality.',
                ),
                self::phase(
                    'P8',
                    'Final materiality confirmation',
                    '/api/materiality-confirmation',
                    [
                        '/api/materiality-confirmation/decision-sheet',
                    ],
                    'implemented',
                    'Final user-confirmed materiality with delta against P6 and decision-sheet JSON.',
                ),
                self::phase(
                    'P9',
                    'ESRS datapoints',
                    '/api/esrs-datapoints',
                    [
                        '/api/esrs-topics',
                        '/api/esrs-datapoints/responses',
                        '/api/esrs-datapoints/responses/export.csv',
                        '/api/esrs-datapoints/export.csv',
                    ],
                    'implemented_standard_level',
                    'Deterministic IG3 datapoint corpus with DR grouping, completion plan, phase-in metadata, and pending exact matter mapping.',
                ),
                self::phase(
                    'P10',
                    'Report package',
                    '/api/report',
                    [
                        '/api/report/draft',
                        '/api/materiality-confirmation/decision-sheet',
                        '/api/esrs-datapoints/responses/export.csv',
                        '/api/esrs-datapoints/export.csv',
                        '/characterization/summary?format=pdf',
                    ],
                    'readiness_api_implemented_generation_pending',
                    'Report-package readiness, available downloads, and remaining blockers for the separate frontend report step.',
                ),
            ],
            'capabilities' => [
                'characterization_gateway' => [
                    'default' => 'mock',
                    'python_smoke_command' => 'php artisan characterization:smoke-api-gateway --base-url=http://127.0.0.1:8001',
                ],
                'p6' => [
                    'submit_without_preselected_topics' => true,
                    'review_traceability' => true,
                ],
                'p7' => [
                    'content_format' => 'structured_json',
                ],
                'p8' => [
                    'decision_sheet_json' => true,
                    'e1_non_material_explanation_required_when_removed' => true,
                ],
                'p9' => [
                    'granularity' => 'standard_level_partial',
                    'disclosure_requirement_grouping' => true,
                    'completion_plan' => true,
                    'phase_in_assessment' => true,
                    'matter_mapping_status' => 'pending',
                ],
                'report' => [
                    'readiness_endpoint' => '/api/report',
                    'draft_endpoint' => '/api/report/draft',
                    'generation_status' => 'not_implemented',
                    'available_downloads' => [
                        '/api/report/draft',
                        '/api/materiality-confirmation/decision-sheet',
                        '/api/esrs-datapoints/responses/export.csv',
                        '/api/esrs-datapoints/export.csv',
                        '/characterization/summary?format=pdf',
                    ],
                ],
            ],
            'limitations' => [
                [
                    'key' => 'historical_imported_frontend_source_state',
                    'message' => 'The buildable original Airis/Vercel frontend source remains under app/frontend, but the current integrated P5-P10 runtime uses Laravel auth, persistence, and API ownership. Standalone Next/Turso/Better Auth routes are historical snapshot behavior only.',
                ],
                [
                    'key' => 'exact_ar16_matter_to_dr_mapping_pending',
                    'message' => 'P9 currently filters topical datapoints at ESRS standard level; exact AR16 matter to Disclosure Requirement mapping is still pending.',
                ],
                [
                    'key' => 'final_report_generation_pending',
                    'message' => 'The backend exposes report-package readiness and supporting downloads, but final AI report/XBRL generation is not implemented in this release.',
                ],
            ],
        ];
    }

    /**
     * @param list<string> $supportingEndpoints
     *
     * @return array<string, mixed>
     */
    private static function phase(
        string $phase,
        string $title,
        string $primaryEndpoint,
        array $supportingEndpoints,
        string $status,
        string $summary,
    ): array {
        return [
            'phase' => $phase,
            'title' => $title,
            'status' => $status,
            'primary_endpoint' => $primaryEndpoint,
            'supporting_endpoints' => $supportingEndpoints,
            'summary' => $summary,
        ];
    }
}
