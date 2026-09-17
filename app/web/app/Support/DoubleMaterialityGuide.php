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
            'content_format' => 'structured_prose_v2',
            'warning' => [
                'en' => 'The guide accelerates the external double materiality assessment; it does not decide materiality.',
                'es' => 'La guía acelera la ADM externa; no decide la materialidad.',
            ],
            'sections' => [
                self::section(
                    'prepare_scope',
                    'Prepare scope',
                    'Preparar alcance',
                    [
                        self::step(
                            'review_p5_p6',
                            'Review the organization description and proposed topics',
                            'Revisar la descripción de la organización y la propuesta de temas',
                            [
                                'en' => 'Before starting, review what you already have: your company description from step 1 and the list of proposed topics from step 2. That list is your starting point, not the final decision. Note the topics you are unsure about: they are the ones that need the most attention in the analysis.',
                                'es' => 'Antes de empezar, repasa lo que ya tienes: la descripción de tu empresa del paso 1 y la lista de temas propuestos del paso 2. Esa lista es tu punto de partida, no la decisión final. Apunta los temas que no tengas claros: son los que más atención necesitan en el análisis.',
                            ],
                            [
                                'Confirma perímetro, ejercicio, sector, tamaño y temas propuestos.',
                                'Marca los temas dudosos para resolverlos durante el análisis.',
                            ],
                        ),
                        self::step(
                            'define_boundaries',
                            'Define assessment boundaries',
                            'Definir límites de la evaluación',
                            [
                                'en' => 'Decide what is included in the analysis: your own activity, what happens before it (suppliers, raw materials), and what happens after it (distribution, product use, waste). Perfection is not needed: write down what you include and leave out, and why.',
                                'es' => 'Decide qué entra en el análisis: tu propia actividad, lo que pasa antes (proveedores, materias primas) y lo que pasa después (distribución, uso del producto, residuos). No hace falta perfección: anota qué incluyes y qué dejas fuera, y por qué.',
                            ],
                            [
                                'Separa operaciones propias, cadena de valor anterior y cadena de valor posterior.',
                                'Registra los supuestos que puedan afectar a la materialidad de impacto o financiera.',
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
                                'en' => 'For each topic in your list, write in a simple table (your own spreadsheet or paper is fine): what impact your company causes (who it affects and how much), and what economic risk or opportunity it creates for you (fines, costs, customer requirements, savings). Use one row per concrete idea and indicate where it happens (your company, suppliers, or customers).',
                                'es' => 'Para cada tema de tu lista, escribe en una tabla sencilla (vale una hoja de cálculo propia o papel): qué impacto causa tu empresa (a quién afecta y cuánto), y qué riesgo u oportunidad económica supone para ti (multas, costes, clientes que lo exigen, ahorros). Una línea por idea concreta, indicando dónde ocurre (tu empresa, proveedores o clientes).',
                            ],
                            [
                                'Usa los asuntos AR16 como comprobación de completitud, no como decisión de materialidad.',
                                'Escribe un IRO por fila con ubicación clara en la cadena de valor y grupo de interés o canal financiero afectado.',
                            ],
                        ),
                        self::step(
                            'stakeholder_input',
                            'Capture stakeholder input',
                            'Registrar aportaciones de grupos de interés',
                            [
                                'en' => 'Talk to people who know the company from inside and outside: staff, main customers, key suppliers, accountant or advisory office, and bank if relevant. Ask: which topics in this list worry you or could affect us? Note who said what and when. Decide the depth of consultation according to your organisation, context and risk.',
                                'es' => 'Habla con quien conoce la empresa por dentro y por fuera: plantilla, clientes principales, proveedores clave, gestoría, banco si aplica. Pregunta: ¿qué temas de esta lista os preocupan o nos pueden afectar? Apunta quién dijo qué y cuándo. Decide la profundidad de la consulta según tu organización, contexto y riesgo.',
                            ],
                            [
                                'Anota fuente, fecha, grupo de interés y tema o IRO afectado.',
                                'Conserva esta evidencia fuera de la aplicación; el paso 4 solo registra los cambios finales de temas.',
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
                                'en' => "For each topic, ask: how severe is the harm we cause or could cause (or the benefit)? How many people or how much of the environment does it affect? Can it be reversed? How likely is it? If the combined answer is 'important', the topic is material by impact. Be conservative: when in doubt, keep it in.",
                                'es' => "Para cada tema, pregunta: ¿cómo de grave es el daño que causamos o podemos causar (o el beneficio)? ¿A cuánta gente o entorno afecta? ¿Se puede revertir? ¿Cómo de probable es? Si la respuesta combinada es 'importante', el tema es material por impacto. Sé conservador: ante la duda, dentro.",
                            ],
                            [
                                'Valora escala, alcance, carácter irremediable y probabilidad con el método de umbral de la organización.',
                                'Conserva las referencias de evidencia para revisión externa fuera de la aplicación.',
                            ],
                        ),
                        self::step(
                            'financial_materiality',
                            'Assess financial materiality',
                            'Evaluar materialidad financiera',
                            [
                                'en' => 'Now the other side: could this topic cost us or make us earn a meaningful amount of money? Think about fines, permits, customer requirements, energy or material costs, and access to finance. If the possible effect is meaningful for the size of your company, the topic is financially material.',
                                'es' => 'Ahora el otro lado: ¿este tema puede costarnos o hacernos ganar dinero de forma apreciable? Piensa en multas, licencias, clientes que exigen requisitos, costes de energía o materiales, acceso a financiación. Si el efecto posible es apreciable para el tamaño de tu empresa, el tema es material financieramente.',
                            ],
                            [
                                'Estima efectos financieros potenciales, horizonte temporal, probabilidad y magnitud.',
                                'Registra si cada IRO alcanza el umbral definido.',
                            ],
                        ),
                    ],
                ),
                self::section(
                    'document_decision',
                    'Document the decision',
                    'Documentar la decisión',
                    [
                        self::step(
                            'decision_log',
                            'Finalize the material topics',
                            'Cerrar los temas materiales',
                            [
                                'en' => 'Close the list: for each topic, write material or not material and one sentence explaining why. If you remove a topic that was proposed, the reason is mandatory for your own traceability. That closed list is what you will confirm in step 4.',
                                'es' => 'Cierra la lista: para cada tema escribe material o no material y una frase de motivo. Si quitas un tema que estaba propuesto, el motivo es obligatorio para tu propia trazabilidad. Esa lista cerrada es lo que confirmarás en el paso 4.',
                            ],
                            [
                                'Resume qué asuntos AR16 son materiales y por qué.',
                                'Mantén un registro de decisión para los temas añadidos, retirados o mantenidos frente a la propuesta.',
                            ],
                        ),
                    ],
                ),
                self::section(
                    'return_to_p8',
                    'Return to topic confirmation',
                    'Volver a la confirmación de temas',
                    [
                        self::step(
                            'sync_to_laravel',
                            'Sync final materiality back to the app',
                            'Sincronizar la materialidad final en la app',
                            [
                                'en' => 'Return to the application with your closed list and the meeting record (date, method, participants). In step 4 you will record the changes against the proposal and the application will save your decision sheet.',
                                'es' => 'Vuelve a la aplicación con tu lista cerrada y el acta de la reunión (fecha, método, participantes). En el paso 4 registrarás los cambios frente a la propuesta y la aplicación guardará tu hoja de decisión.',
                            ],
                            [
                                'Usa el paso 4 para confirmar los temas materiales finales y los motivos opcionales.',
                                'Si la propuesta incluía E1 y la ADM concluye que E1 no es material, introduce la breve explicación requerida.',
                            ],
                        ),
                    ],
                ),
                self::section(
                    'worked_example',
                    'Practical example',
                    'Ejemplo práctico',
                    [
                        self::step(
                            'example_e2',
                            'How a 40-person company did it',
                            'Cómo lo hizo una empresa de 40 personas',
                            [
                                'en' => "A 40-person industrial company brought together management, the production lead, and the administrative colleague who works with the advisory office (90 minutes). They reviewed the proposed list topic by topic. For pollution (E2), they saw that the painting line generates volatile compounds with emission limits in its activity permit: a clear outward impact and an inward sanction risk. Conclusion: E2 material through both paths. In their meeting record they wrote: date, '90-minute internal workshop', and the three participants. That record is what you will register in this step.",
                                'es' => "Una empresa industrial de 40 personas reunió a gerencia, el responsable de producción y la administrativa que trata con la gestoría (90 minutos). Repasaron la lista propuesta tema a tema. En contaminación (E2) salió que la línea de pintura genera compuestos volátiles con límites de emisión en su licencia de actividad: impacto hacia fuera claro y riesgo de sanción hacia dentro. Conclusión: E2 material por las dos vías. En su acta apuntaron: fecha, 'taller interno de 90 minutos', y los tres participantes. Ese acta es lo que registrarás en este paso.",
                            ],
                            [],
                        ),
                    ],
                ),
            ],
            'templates' => self::templates(),
            'next_step' => [
                'next_phase' => 'P8',
                'next_api' => '/api/materiality-confirmation',
                'note' => [
                    'en' => 'When you finish the analysis, return to step 4 to confirm your final material topics. Step 5 will derive the data to report from that selection.',
                    'es' => 'Cuando termines el análisis, vuelve al paso 4 para confirmar tus temas materiales finales. El paso 5 derivará los datos a reportar de esa selección.',
                ],
            ],
        ];
    }

    public const TEMPLATE_LOCALES = ['en', 'es'];

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function templates(): array
    {
        return [
            [
                'key' => 'iro_register',
                'title' => [
                    'en' => 'Impact, risk, and opportunity register',
                    'es' => 'Registro de impactos, riesgos y oportunidades',
                ],
                'columns' => [
                    self::column('ar16_topic_id', 'AR16 topic ID', 'ID del tema AR16'),
                    self::column('ar16_topic_label', 'AR16 topic label', 'Nombre del tema AR16'),
                    self::column('iro_description', 'Impact, risk, or opportunity description', 'Descripción del impacto, riesgo u oportunidad'),
                    self::column('iro_type', 'Type (impact, risk, or opportunity)', 'Tipo (impacto, riesgo u oportunidad)'),
                    self::column('value_chain_location', 'Value chain location', 'Ubicación en la cadena de valor'),
                    self::column('stakeholder_or_financial_channel', 'Affected stakeholder or financial channel', 'Grupo de interés afectado o canal financiero'),
                    self::column('impact_materiality_score', 'Impact materiality score', 'Puntuación de materialidad de impacto'),
                    self::column('financial_materiality_score', 'Financial materiality score', 'Puntuación de materialidad financiera'),
                    self::column('threshold_result', 'Threshold result', 'Resultado frente al umbral'),
                    self::column('evidence_reference', 'Evidence reference', 'Referencia de evidencia'),
                ],
            ],
            [
                'key' => 'stakeholder_consultation_log',
                'title' => [
                    'en' => 'Stakeholder consultation log',
                    'es' => 'Registro de consultas a grupos de interés',
                ],
                'columns' => [
                    self::column('date', 'Date', 'Fecha'),
                    self::column('stakeholder_group', 'Stakeholder group', 'Grupo de interés'),
                    self::column('source', 'Source', 'Fuente'),
                    self::column('matter_or_iro', 'Matter or IRO affected', 'Tema o IRO afectado'),
                    self::column('input_summary', 'Input summary', 'Resumen de la aportación'),
                    self::column('decision_effect', 'Effect on the decision', 'Efecto en la decisión'),
                    self::column('evidence_reference', 'Evidence reference', 'Referencia de evidencia'),
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function template(string $key): ?array
    {
        foreach (self::templates() as $template) {
            if ($template['key'] === $key) {
                return $template;
            }
        }

        return null;
    }

    /**
     * @return array{filename: string, content: string}|null
     */
    public static function templateCsv(string $key, string $locale = 'es'): ?array
    {
        $template = self::template($key);

        if (! $template || ! in_array($locale, self::TEMPLATE_LOCALES, true)) {
            return null;
        }

        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return null;
        }

        fputcsv($handle, array_map(
            fn (array $column): string => $column['label'][$locale],
            $template['columns'],
        ));
        rewind($handle);

        $content = stream_get_contents($handle);
        fclose($handle);

        return [
            'filename' => str_replace('_', '-', $key).'-template-'.$locale.'.csv',
            'content' => $content === false ? '' : $content,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function column(string $key, string $labelEn, string $labelEs): array
    {
        return [
            'key' => $key,
            'label' => [
                'en' => $labelEn,
                'es' => $labelEs,
            ],
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
     * @param  array{en: string, es: string}  $body
     * @param  array<int, string>  $checks
     * @return array<string, mixed>
     */
    private static function step(string $key, string $titleEn, string $titleEs, array $body, array $checks): array
    {
        return [
            'key' => $key,
            'title' => [
                'en' => $titleEn,
                'es' => $titleEs,
            ],
            'body' => $body,
            'checks' => $checks,
        ];
    }
}
