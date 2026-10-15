<?php

namespace App\Services\Report;

use App\Support\ApplicationLocale;
use DomainException;

/** Presentation only: never rewrites the approved IR, claims, evidence or version hash. */
final class ReportDisplayProjection
{
    public readonly string $locale;
    private bool $coherentPresentation = false;
    private ?array $capturedEnglishLabels = null;
    private static ?array $englishCopy = null;
    private static ?array $topicLabels = null;

    public function __construct(string $locale = 'es', ?array $ir = null)
    {
        $this->locale = ApplicationLocale::normalize($locale);
        if ($ir !== null && self::coherent($ir)) {
            $this->coherentPresentation = true;
            $this->capturedEnglishLabels = $ir['display_labels']['en'] ?? null;
        }
    }

    public function text(string $source): string
    {
        if ($this->locale === 'es') return $source;
        self::$englishCopy ??= json_decode(file_get_contents(dirname(__DIR__, 3).'/lang/en.json'), true, 512, JSON_THROW_ON_ERROR);

        return self::$englishCopy[$source] ?? throw new DomainException('Missing approved report display copy.');
    }

    public function chapterTitle(mixed $key): string
    {
        return $this->text(ReportVisiblePresentation::chapterTitle($key));
    }

    public function sectionTitle(mixed $key): string
    {
        return (new \App\Support\EsrsDisplayCatalogue)->sectionTitle((string) $key, $this->locale)
            ?? ($this->locale === 'es' ? 'Título no disponible' : 'Title unavailable');
    }

    public function claimLabel(?string $id, ?array $claim = null): string
    {
        if ($this->locale === 'en' && $this->coherentPresentation) {
            // Withdrawn datapoints can survive as reviewed appendix decisions.
            // Missing captured metadata must never trigger a live corpus lookup.
            return $this->capturedEnglishLabels[$id] ?? 'Label unavailable';
        }
        return (new \App\Support\EsrsDisplayCatalogue)->claimLabel((string) $id, $this->locale)
            ?? ($this->locale === 'es' ? 'Etiqueta no disponible' : 'Label unavailable');
    }

    public function claimValue(array $claim): ?string
    {
        return ReportVisiblePresentation::claimValue($claim, $this->locale, $this->coherentPresentation);
    }

    /** The only projected IR fields are closed system prose, never facts or notes. */
    public function narrative(array $ir): array
    {
        if (($ir['schema_version'] ?? null) === 'report_ir_v1') {
            foreach ($ir['disclaimers'] ?? [] as $disclaimer) {
                ReportVisiblePresentation::controlledNarrative($disclaimer);
            }
            $omission = $ir['omission_section'] ?? [];
            foreach (['title', 'declaration', 'limitation'] as $field) {
                if (isset($omission[$field])) ReportVisiblePresentation::controlledNarrative($omission[$field]);
            }
            foreach ($omission['statements'] ?? [] as $statement) {
                ReportVisiblePresentation::controlledNarrative($statement);
            }
        }
        if ($this->locale === 'es') {
            return ['disclaimers' => $ir['disclaimers'] ?? [], 'omission_section' => $ir['omission_section'] ?? null];
        }
        $trace = $ir['materiality_trace'] ?? [];
        if (!empty($ir['omission_section']['statements']) && !isset($trace['omitted_topics'])) {
            throw new DomainException('Omission display requires its immutable materiality trace.');
        }
        $composer = new FloorProseComposer;
        $disclaimers = [$this->text('No constituye una presentación oficial ni un trabajo de aseguramiento; tampoco acredita el cumplimiento de la Taxonomía de la UE ni genera el formato electrónico regulatorio.')];
        if ($trace['is_stale'] ?? false) {
            $disclaimers[] = $composer->staleDisclaimer($trace['confirmed_at'] ?? null, $this->locale);
        }
        $omission = null;
        if (isset($ir['omission_section'])) {
            $topics = $trace['omitted_topics'] ?? [];
            $statements = [];
            $hasDirect = false;
            foreach ($topics as $topic) {
                $hasDirect = $hasDirect || ($topic['evidence_grade'] ?? '') === NotMaterialTopicResolver::GRADE_DIRECT;
                // The source label is produced by the resolver, never entered by the user.
                $topic['label'] = $this->topicLabel((string) $topic['label']);
                $statements[] = $composer->omissionStatement($topic, $this->locale);
            }
            $unresolved = count($trace['unresolved_omitted_topic_ids'] ?? []);
            $omission = [
                'title' => $this->text('Temas no confirmados como materiales'),
                'declaration' => ($trace['inferred_omissions_status'] ?? '') === NotMaterialTopicResolver::STATUS_NOT_DETERMINABLE
                    ? $composer->notDeterminableDeclaration($hasDirect, $this->locale) : null,
                'limitation' => $unresolved > 0 ? $composer->unresolvedLimitation($unresolved, $this->locale) : null,
                'statements' => $statements,
            ];
        }

        return ['disclaimers' => $disclaimers, 'omission_section' => $omission];
    }

    public static function coherent(array $ir): bool
    {
        // The approved IR carries the mode, including after feature rollback.
        // Legacy and standalone renderers must not need a Laravel container.
        return ($ir['schema_version'] ?? null) === 'report_ir_v1'
            && isset($ir['source']['scope']);
    }

    /** Ordered events retain chapter/section/block nesting in every output. */
    public function ordered(array $ir): array
    {
        if (($ir['schema_version'] ?? null) !== 'report_ir_v1') throw new DomainException('approved_snapshot_invalid');
        $this->coherentPresentation = true;
        $this->capturedEnglishLabels = $ir['display_labels']['en'] ?? null;
        $events = [];
        $claims = [];
        foreach ($ir['claims'] ?? [] as $claim) {
            if (! is_array($claim) || ! is_string($claim['claim_id'] ?? null) || isset($claims[$claim['claim_id']])) throw new DomainException('approved_snapshot_invalid');
            foreach (['claim_id', 'fact_id', 'datapoint_id'] as $key) self::assertXmlText($claim[$key] ?? null);
            $claims[$claim['claim_id']] = $claim;
        }
        $events[] = ['kind' => 'title', 'text' => ($ir['company']['name'] ?? $this->text('Informe NEIS')).' — '.$this->text('Ejercicio').' '.($ir['company']['reporting_year'] ?? '-')];
        $events[] = ['kind' => 'text', 'text' => $this->text('Borrador factual basado en una versión aprobada')];
        $narrative = $this->narrative($ir);
        foreach ($narrative['disclaimers'] as $text) $events[] = ['kind' => 'text', 'text' => $text];
        $iroLines = IroContextPresentation::lines($ir['iro_context_v1'] ?? [], $this->locale);
        if ($iroLines !== []) {
            $events[] = ['kind' => 'open', 'level' => 2, 'text' => array_shift($iroLines)];
            foreach ($iroLines as $text) $events[] = ['kind' => 'text', 'text' => $text];
            $events[] = ['kind' => 'close'];
        }
        $seen = [];
        foreach ($ir['chapters'] ?? [] as $chapter) {
            $events[] = ['kind' => 'open', 'level' => 2, 'text' => $this->chapterTitle($chapter['block_key'] ?? null)];
            foreach ($chapter['sections'] ?? [] as $section) {
                $e1 = ($chapter['block_key'] ?? null) === 'e1_not_material_explanation';
                $events[] = ['kind' => 'open', 'level' => 3, 'text' => $e1 ? null : $this->sectionTitle($section['dr_key'] ?? null)];
                foreach ($section['blocks'] ?? [] as $block) {
                    $events[] = ['kind' => 'open', 'level' => 4, 'text' => $e1 ? null : $this->claimLabel($block['datapoint_id'] ?? null)];
                    if ($e1) {
                        foreach ($block['assertions'] ?? [] as $text) $events[] = ['kind' => 'text', 'text' => $text];
                    }
                    foreach ($block['claims'] ?? [] as $id) {
                        if (! is_string($id) || ! isset($claims[$id])) throw new DomainException('approved_snapshot_invalid');
                        $event = $this->valueEvent($claims[$id]);
                        foreach ($block['slots'] ?? [] as $slot) {
                            if (($slot['claim_id'] ?? null) === $id) {
                                foreach (['node_id', 'xbrl_concept'] as $key) {
                                    if (isset($slot[$key])) self::assertXmlText($slot[$key]);
                                }
                                $event['slot'] = $slot;
                                break;
                            }
                        }
                        $events[] = $event;
                        $events = [...$events, ...$this->evidenceEvents($claims[$id])];
                        $seen[$id] = true;
                    }
                    $events[] = ['kind' => 'close'];
                }
                $events[] = ['kind' => 'close'];
            }
            $events[] = ['kind' => 'close'];
        }
        // Missing layout must never silently drop an approved claim.
        if (count($seen) !== count($claims)) throw new DomainException('approved_snapshot_invalid');
        $decisions = array_filter($ir['fact_decisions'] ?? [], fn (array $fact): bool =>
            ($fact['output_section'] ?? null) !== 'body' && in_array($fact['approval_status'] ?? null, ['reviewed', 'approved'], true));
        if ($decisions !== []) {
            $events[] = ['kind' => 'open', 'level' => 2, 'text' => $this->text('Anexo: decisiones revisadas fuera del contenido afirmativo')];
            foreach ($decisions as $fact) {
                $events[] = ['kind' => 'open', 'level' => 4, 'text' => $this->claimLabel($fact['datapoint_id'] ?? null)];
                $applicability = match ($fact['applicability'] ?? null) {
                    'applicable' => 'Aplicable', 'not_applicable' => 'No aplicable', 'unavailable' => 'No disponible',
                    'pending' => 'Pendiente', 'blocked' => 'Bloqueado', default => throw new DomainException('approved_snapshot_invalid'),
                };
                $events[] = ['kind' => 'text', 'text' => $this->text('Aplicabilidad').': '.$this->text($applicability)];
                // Retain any recorded value as a decision, never as an affirmative body claim.
                $copy = $fact;
                $copy['nil'] = false;
                $value = $this->exactValue($copy);
                $events[] = ['kind' => 'text', 'text' => $this->text('Valor registrado').': '.($value ?? ReportVisiblePresentation::withVisibleDimensions($this->text('Sin valor'), $fact['dimensions'] ?? [], $this->locale, true))];
                if (($fact['nil_reason'] ?? null) !== null) $events[] = ['kind' => 'text', 'text' => $this->text('Motivo sin valor').': '.$fact['nil_reason']];
                $events = [...$events, ...$this->evidenceEvents($fact)];
                $events[] = ['kind' => 'close'];
            }
            $events[] = ['kind' => 'close'];
        }
        $omission = $narrative['omission_section'];
        if ($omission && (! empty($omission['statements']) || ! empty($omission['declaration']) || ! empty($omission['limitation']))) {
            $events[] = ['kind' => 'open', 'level' => 2, 'text' => $this->text('Anexo: ').$omission['title']];
            foreach ([$omission['declaration'] ?? null, ...($omission['statements'] ?? []), $omission['limitation'] ?? null] as $text) {
                if ($text !== null && $text !== '') $events[] = ['kind' => 'text', 'text' => $text];
            }
            $events[] = ['kind' => 'close'];
        }
        foreach ($events as $event) {
            if (isset($event['text'])) self::assertXmlText($event['text']);
        }
        return $events;
    }

    public static function assertXmlText(mixed $text): void
    {
        if (! is_string($text) || preg_match('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', $text) !== 0) {
            throw new DomainException('approved_snapshot_invalid');
        }
    }

    private function exactValue(array $claim): ?string
    {
        if (isset($claim['unit'])) self::assertXmlText($claim['unit']);
        if (isset($claim['nil_reason'])) self::assertXmlText($claim['nil_reason']);
        // Display precision must not round away digits subsequently consumed by an inline tag.
        if (in_array($claim['value_type'] ?? null, ['monetary', 'decimal'], true)) $claim['value_type'] = 'number';
        return $this->claimValue($claim);
    }

    private function valueEvent(array $claim): array
    {
        $text = $this->exactValue($claim);
        if ($text === null) throw new DomainException('approved_snapshot_invalid');
        $numeric = null;
        if (in_array($claim['value_type'] ?? null, ['number', 'monetary', 'integer', 'decimal'], true)) {
            $calendarUnit = in_array($claim['unit'] ?? null, ['year', 'año'], true) ? $claim['unit'] : null;
            $number = array_replace($claim, ['unit' => $calendarUnit, 'dimensions' => []]);
            $numeric = $this->exactValue($number);
        }
        return ['kind' => 'value', 'text' => $text, 'numeric_text' => $numeric, 'claim' => $claim];
    }

    private function evidenceEvents(array $fact): array
    {
        $events = [];
        $refs = $fact['evidence_refs'] ?? [];
        if (! is_array($refs) || ! array_is_list($refs)) throw new DomainException('approved_snapshot_invalid');
        foreach ($refs as $ref) {
            if (! is_array($ref) || ! is_string($ref['value'] ?? null)) throw new DomainException('approved_snapshot_invalid');
            $events[] = ['kind' => 'text', 'text' => $this->text('Referencia de evidencia').': '.$ref['value']];
        }
        return $events;
    }

    private function topicLabel(string $source): string
    {
        if (self::$topicLabels === null) {
            self::$topicLabels = [];
            $topics = json_decode(file_get_contents(dirname(__DIR__, 3).'/data/esrs_topics.json'), true, 512, JSON_THROW_ON_ERROR);
            foreach ($topics as $topic) {
                $labels = [];
                foreach (['es', 'en'] as $locale) {
                    $qualifier = $topic['subtopic'][$locale] ?? $topic['subtheme'][$locale] ?? '';
                    $labels[$locale] = $topic['theme'][$locale].($qualifier !== '' ? ': '.$qualifier : '');
                }
                // Plain interface names; controlledNarrative's existing guard remains intact.
                self::$topicLabels[$labels['es']] = str_replace(
                    ['Own workforce', 'Resource use and circular economy'],
                    ['Company workforce', 'Resources and circularity'],
                    $labels['en'],
                );
            }
        }

        return self::$topicLabels[$source] ?? throw new DomainException('Missing approved topic display label.');
    }
}
