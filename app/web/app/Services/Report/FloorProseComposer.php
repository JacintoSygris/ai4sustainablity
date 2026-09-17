<?php

namespace App\Services\Report;

/**
 * Composes the deterministic floor prose. No model, no network.
 *
 * The evidence grade decides what may be claimed. `direct` evidence is a recorded
 * human verdict and may say the topic was assessed. `inferred` evidence proves only
 * that the topic was not confirmed — the user may never have opened it — so it may
 * never claim an assessment. One sentence for both grades would fabricate provenance.
 */
class FloorProseComposer
{
    /** Closed enum from MaterialityConfirmationController::REASON_KEYS, mapped to audit phrases. */
    private const REASON_PHRASES = [
        'new_data' => 'nueva información disponible',
        'stakeholders' => 'consulta a grupos de interés',
        'scope_change' => 'cambio en el alcance de las operaciones',
        'threshold' => 'por debajo del umbral de materialidad',
        'sector_requirement' => 'requisito sectorial',
        'other' => 'otros motivos indicados por la entidad',
    ];

    /** @param array<string, mixed> $topic one `NotMaterialTopicResolver` omitted_topics entry */
    public function omissionStatement(array $topic): string
    {
        // `label`, not `theme_es`: the theme is the standard-level name and repeats
        // across a standard's topics (all 5 E3 topics share "Agua y recursos marinos").
        $label = (string) $topic['label'];

        $sentence = $topic['evidence_grade'] === NotMaterialTopicResolver::GRADE_DIRECT
            ? "{$label} se evaluó y no se consideró material."
            : "{$label} no fue confirmado como material tras la evaluación de doble materialidad.";

        $phrases = [];
        foreach ($topic['change_reasons'] ?? [] as $key) {
            if (isset(self::REASON_PHRASES[$key])) {
                $phrases[] = self::REASON_PHRASES[$key];
            }
        }

        // No stored reason -> no clause and no placeholder. A `direct` topic outside the
        // snapshot provably cannot carry one: MaterialityConfirmationController builds
        // $validReasonTopicIds from p6 ∪ confirmed only. The sentence stands alone.
        // change_reason_notes is free user text and NEVER enters this document.
        if ($phrases !== []) {
            $sentence .= ' Motivo: '.$this->joinEs($phrases).'.';
        }

        return $sentence;
    }

    /**
     * @param  array<int, string>  $material  theme labels
     * @param  array<int, array<string, mixed>>  $notMaterial  omitted_topics entries
     */
    public function chapterIntro(array $material, array $notMaterial): string
    {
        $sentence = 'Este capítulo cubre '.$this->joinEs($material).'.';

        foreach ($notMaterial as $topic) {
            $sentence .= ' '.$this->omissionStatement($topic);
        }

        return $sentence;
    }

    public function crossRefSentence(array $resolvedEdge): string
    {
        if ($resolvedEdge['resolution'] === 'in_scope') {
            return "Véase la sección {$resolvedEdge['target_dr']} ({$resolvedEdge['citation']}); no se repite aquí.";
        }

        return "El tema relacionado ({$resolvedEdge['target_dr']}) se evaluó como no material; divulgación relacionada omitida.";
    }

    public function notDeterminableDeclaration(bool $hasDirectOmissions): string
    {
        $what = $hasDirectOmissions ? 'qué otros temas' : 'qué temas';

        return "No consta registro de la propuesta inicial de temas, por lo que no puede determinarse {$what} se evaluaron y descartaron.";
    }

    public function unresolvedLimitation(int $count): string
    {
        return "{$count} tema(s) evaluado(s) no pudieron identificarse en el catálogo ESRS vigente; véase el paquete de evidencias.";
    }

    public function staleDisclaimer(?string $confirmedAt): string
    {
        $when = $confirmedAt !== null && $confirmedAt !== '' ? " de {$confirmedAt}" : ' registrada';

        return "La propuesta automática de temas cambió después de la confirmación; esta salida refleja la confirmación{$when}.";
    }

    private function joinEs(array $items): string
    {
        if (count($items) <= 1) {
            return implode('', $items);
        }

        $last = array_pop($items);

        return implode(', ', $items).' y '.$last;
    }
}
