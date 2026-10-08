<?php

namespace App\Services\Report;

use App\Support\ApplicationLocale;
use DomainException;

/** Presentation only: never rewrites the approved IR, claims, evidence or version hash. */
final class ReportDisplayProjection
{
    public readonly string $locale;
    private static ?array $englishCopy = null;
    private static ?array $topicLabels = null;

    public function __construct(string $locale = 'es')
    {
        $this->locale = ApplicationLocale::normalize($locale);
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
        return (new \App\Support\EsrsDisplayCatalogue)->claimLabel((string) $id, $this->locale)
            ?? ($this->locale === 'es' ? 'Etiqueta no disponible' : 'Label unavailable');
    }

    public function claimValue(array $claim): ?string
    {
        return ReportVisiblePresentation::claimValue($claim, $this->locale);
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
