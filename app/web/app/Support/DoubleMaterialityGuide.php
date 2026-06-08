<?php

namespace App\Support;

class DoubleMaterialityGuide
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(): array
    {
        return [
            'type' => 'double_materiality_guide',
            'phase' => 'P7',
            'content_format' => 'structured_json',
            'warning' => [
                'en' => 'The guide accelerates the external double materiality assessment; it does not decide materiality.',
                'es' => 'La guia acelera la ADM externa; no decide la materialidad.',
            ],
            'sections' => [
                self::section(
                    'prepare_scope',
                    'Prepare scope',
                    'Preparar alcance',
                    [
                        self::step(
                            'review_p5_p6',
                            'Review P5 characterization and P6 proposal',
                            'Revisar la caracterizacion P5 y la propuesta P6',
                            [
                                'Confirm company perimeter, reporting year, sector, size range, and AI-proposed AR16 topics.',
                                'Mark unclear P6 topics as items to resolve during the ADM workshop.',
                            ],
                        ),
                        self::step(
                            'define_boundaries',
                            'Define assessment boundaries',
                            'Definir limites de la evaluacion',
                            [
                                'Separate own operations, upstream value chain, and downstream value chain.',
                                'Record assumptions that may affect impact or financial materiality.',
                            ],
                        ),
                    ],
                ),
                self::section(
                    'identify_iros',
                    'Identify IROs',
                    'Identificar IROs',
                    [
                        self::step(
                            'iro_inventory',
                            'Build the impact, risk, and opportunity inventory',
                            'Construir el inventario de impactos, riesgos y oportunidades',
                            [
                                'Use AR16 matters as a completeness checklist, not as the materiality decision itself.',
                                'Write one IRO per row with a clear value-chain location and affected stakeholder or financial channel.',
                            ],
                        ),
                        self::step(
                            'stakeholder_input',
                            'Capture stakeholder input',
                            'Registrar input de stakeholders',
                            [
                                'Note source, date, stakeholder group, and the matter or IRO affected.',
                                'Use this evidence outside the app; P8 only records final topic changes.',
                            ],
                        ),
                    ],
                ),
                self::section(
                    'assess_materiality',
                    'Assess materiality',
                    'Evaluar materialidad',
                    [
                        self::step(
                            'impact_materiality',
                            'Assess impact materiality',
                            'Evaluar materialidad de impacto',
                            [
                                'Score scale, scope, irremediability, and likelihood using the organization threshold method.',
                                'Keep evidence references for assurance review outside the application.',
                            ],
                        ),
                        self::step(
                            'financial_materiality',
                            'Assess financial materiality',
                            'Evaluar materialidad financiera',
                            [
                                'Estimate potential financial effects, time horizon, likelihood, and magnitude.',
                                'Record whether each IRO meets the defined threshold.',
                            ],
                        ),
                    ],
                ),
                self::section(
                    'document_decision',
                    'Document the decision',
                    'Documentar la decision',
                    [
                        self::step(
                            'decision_log',
                            'Finalize the material topics',
                            'Cerrar los temas materiales',
                            [
                                'Summarize which AR16 matters are material and why.',
                                'Keep a decision log for topics added, removed, or left unchanged against P6.',
                            ],
                        ),
                    ],
                ),
                self::section(
                    'return_to_p8',
                    'Return to P8',
                    'Volver a P8',
                    [
                        self::step(
                            'sync_to_laravel',
                            'Sync final materiality back to the app',
                            'Sincronizar la materialidad final en la app',
                            [
                                'Use P8 to confirm final material topics and optional reason chips.',
                                'If P6 proposed E1 and the ADM concludes E1 is not material, enter the required short explanation.',
                            ],
                        ),
                    ],
                ),
            ],
            'templates' => [
                [
                    'key' => 'iro_register',
                    'title' => [
                        'en' => 'IRO register',
                        'es' => 'Registro de IROs',
                    ],
                    'columns' => [
                        'ar16_topic_id',
                        'ar16_topic_label',
                        'iro_description',
                        'iro_type',
                        'value_chain_location',
                        'stakeholder_or_financial_channel',
                        'impact_materiality_score',
                        'financial_materiality_score',
                        'threshold_result',
                        'evidence_reference',
                    ],
                ],
                [
                    'key' => 'stakeholder_consultation_log',
                    'title' => [
                        'en' => 'Stakeholder consultation log',
                        'es' => 'Registro de consulta a stakeholders',
                    ],
                    'columns' => [
                        'date',
                        'stakeholder_group',
                        'source',
                        'matter_or_iro',
                        'input_summary',
                        'decision_effect',
                        'evidence_reference',
                    ],
                ],
            ],
            'handoff' => [
                'next_phase' => 'P8',
                'next_api' => '/api/materiality-confirmation',
                'note' => [
                    'en' => 'After the external ADM, return to P8 to confirm final material topics. P9 then derives datapoints from that final selection.',
                    'es' => 'Despues de la ADM externa, vuelve a P8 para confirmar los temas materiales finales. P9 deriva los datapoints desde esa seleccion final.',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function template(string $key): ?array
    {
        foreach (self::toArray()['templates'] as $template) {
            if ($template['key'] === $key) {
                return $template;
            }
        }

        return null;
    }

    /**
     * @return array{filename: string, content: string}|null
     */
    public static function templateCsv(string $key): ?array
    {
        $template = self::template($key);

        if (! $template) {
            return null;
        }

        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return null;
        }

        fputcsv($handle, $template['columns']);
        rewind($handle);

        $content = stream_get_contents($handle);
        fclose($handle);

        return [
            'filename' => str_replace('_', '-', $key).'-template.csv',
            'content' => $content === false ? '' : $content,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $steps
     * @return array<string, mixed>
     */
    private static function section(string $key, string $titleEn, string $titleEs, array $steps): array
    {
        return [
            'key' => $key,
            'title' => [
                'en' => $titleEn,
                'es' => $titleEs,
            ],
            'steps' => $steps,
        ];
    }

    /**
     * @param  array<int, string>  $checks
     * @return array<string, mixed>
     */
    private static function step(string $key, string $titleEn, string $titleEs, array $checks): array
    {
        return [
            'key' => $key,
            'title' => [
                'en' => $titleEn,
                'es' => $titleEs,
            ],
            'checks' => $checks,
        ];
    }
}
