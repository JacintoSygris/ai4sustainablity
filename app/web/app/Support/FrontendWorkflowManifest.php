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
                'source_zip_filename' => 'airis-main.zip',
                'target_backend_owner' => 'Laravel',
                'current_runtime' => [
                    'framework' => 'Next 16.3.5',
                    'auth' => 'Laravel web session',
                    'database' => 'Laravel persistence',
                    'api_routes' => 'Laravel API via Next rewrites',
                ],
                'secrets' => [
                    'example_file' => 'app/frontend/.env.example',
                    'real_env_files_committed' => false,
                    'deployment_secret_delivery' => 'deployer_supplied_environment',
                    'required_private_values' => [],
                    'optional_private_values' => [],
                ],
                'handoff_warnings' => [
                    'The received Next /api/wizard/* routes are historical source behavior and are not authoritative IA4S workflow APIs.',
                    'Step 2 and Step 3 use client-supplied reportId without userId ownership checks in the received source.',
                    'Imported Better Auth and Turso/libSQL modules remain historical source-snapshot code; the current integrated runtime is Laravel-owned.',
                ],
                'quality_gates' => [
                    'build' => [
                        'command' => 'corepack pnpm build',
                        'status' => 'pass_smoke',
                        'notes' => 'Next build succeeds as a runability smoke and the public gate runs TypeScript validation explicitly with corepack pnpm exec tsc --noEmit.',
                    ],
                    'lint' => [
                        'command' => 'corepack pnpm lint',
                        'status' => 'pass_with_baseline_warnings',
                        'notes' => 'ESLint is enabled and exits successfully. Current legacy debt is tracked as warnings; new lint errors fail the gate.',
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
                        '/api/double-materiality-guide/state',
                        '/api/double-materiality-guide/templates/{template}.csv',
                    ],
                    'implemented',
                    'Prose guide plus persisted checklist, ADM acta, and localized CSV templates for the external double materiality assessment; it does not decide materiality.',
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
                    'implemented_dr_level',
                    'Deterministic IG3 datapoint corpus with approved AR16 matter to Disclosure Requirement mapping, DR grouping, completion plan, and phase-in metadata.',
                ),
                self::phase(
                    'P10',
                    'Report package',
                    '/api/report',
                    [
                        '/api/report/draft',
                        '/api/report/package',
                        '/api/report/evidence-bundle',
                        '/api/materiality-confirmation/decision-sheet',
                        '/api/esrs-datapoints/responses/export.csv',
                        '/api/esrs-datapoints/export.csv',
                        '/characterization/summary?format=pdf',
                    ],
                    'implemented_report_preparation_package',
                    'Report-package readiness, printable HTML package, evidence bundle, available downloads, and remaining blockers for the separate frontend report step.',
                ),
            ],
            'capabilities' => [
                'private_dev_auto_login' => false,
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
                    'granularity' => 'disclosure_requirement_mapping_required',
                    'disclosure_requirement_grouping' => true,
                    'completion_plan' => true,
                    'phase_in_assessment' => true,
                    'matter_mapping_status' => 'approved_loaded',
                ],
                'report' => [
                    'finish_line' => 'report_preparation_package',
                    'readiness_endpoint' => '/api/report',
                    'draft_endpoint' => '/api/report/draft',
                    'package_endpoint' => '/api/report/package',
                    'evidence_bundle_endpoint' => '/api/report/evidence-bundle',
                    'generation_status' => 'report_preparation_package_ready',
                    'xhtml_ixbrl_generation' => false,
                    'native_pdf_generation' => false,
                    'contract_document' => 'app/docs/p10-report-package-contract.md',
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
                    'message' => 'El código fuente importado se conserva por trazabilidad, pero la aplicación integrada actual usa una única autoridad de autenticación, persistencia y API. Las rutas independientes del prototipo histórico no forman parte del producto vigente.',
                ],
                [
                    'key' => 'final_report_package_scope',
                    'message' => 'La fase final prepara un informe ESRS 2023 revisable y exporta sus evidencias. No constituye una presentación oficial ni un trabajo de aseguramiento, no acredita el cumplimiento de la Taxonomía de la UE y no genera formatos electrónicos regulatorios ni PDF nativo.',
                ],
                [
                    'key' => 'auth_boundary',
                    'message' => 'La aplicación usa una sesión autenticada para el acceso de usuarios y las API protegidas. Un operador puede añadir una autenticación externa en su propia infraestructura sin cambiar el contrato de la aplicación.',
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
