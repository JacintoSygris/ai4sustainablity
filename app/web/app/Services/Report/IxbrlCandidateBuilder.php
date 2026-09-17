<?php

namespace App\Services\Report;

use DOMDocument;
use DOMElement;
use Illuminate\Support\Arr;
use RuntimeException;

class IxbrlCandidateBuilder
{
    private const XHTML_NS = 'http://www.w3.org/1999/xhtml';
    private const IX_NS = 'http://www.xbrl.org/2013/inlineXBRL';
    private const XBRLI_NS = 'http://www.xbrl.org/2003/instance';
    private const XBRLDI_NS = 'http://xbrl.org/2006/xbrldi';
    private const LINK_NS = 'http://www.xbrl.org/2003/linkbase';
    private const XLINK_NS = 'http://www.w3.org/1999/xlink';
    private const XSI_NS = 'http://www.w3.org/2001/XMLSchema-instance';
    private const ISO4217_NS = 'http://www.xbrl.org/2003/iso4217';
    private const SCHEMA_REF = 'taxonomies/esrs-set1-2024/xbrl.efrag.org/taxonomy/esrs/2023-12-22/esrs_all.xsd';
    private const NUMERIC_KINDS = ['monetary', 'decimal', 'percent', 'integer'];
    private const SUPPORTED_TYPE_KINDS = [
        'dtr-types:textBlockItemType' => ['narrative', 'string'],
        'xbrli:stringItemType' => ['narrative', 'string'],
        'xbrli:booleanItemType' => ['boolean'],
        'xbrli:dateItemType' => ['date'],
        'xbrli:integerItemType' => ['integer'],
        'xbrli:monetaryItemType' => ['monetary'],
        'xbrli:decimalItemType' => ['decimal'],
        'dtr-types:percentItemType' => ['percent'],
    ];

    public function __construct(private readonly EsrsConceptResolver $concepts) {}

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public function preflight(array $state): array
    {
        $facts = $this->exportableFacts($state);
        $issues = [];

        if (($state['stored_schema_version'] ?? null) !== 'v1') {
            $issues[] = $this->issue('p9_schema_version_unsupported');
        }

        $entity = Arr::get($state, 'reporting_entity', []);
        if (! is_array($entity)
            || ! filled($entity['identifier_scheme'] ?? null)
            || ! filled($entity['identifier'] ?? null)
        ) {
            $issues[] = $this->issue('reporting_entity_missing');
        }
        foreach ($this->flattenStrings($entity) as $value) {
            if ($this->hasXmlControlCharacters($value)) {
                $issues[] = $this->issue('xml_control_character');
                break;
            }
        }

        if ($facts === []) {
            $issues[] = $this->issue('no_exportable_facts');
        }

        foreach (Arr::get($state, 'responses', []) as $datapointId => $response) {
            if (! is_array($response)) {
                continue;
            }

            $id = (string) ($response['datapoint_id'] ?? $datapointId);
            $responseFacts = is_array($response['facts'] ?? null) ? $response['facts'] : [];
            $status = $response['status'] ?? null;

            if ($status === 'completed' && Arr::get($response, 'fact_readiness.state') !== 'valid_completed') {
                $issues[] = $this->issue('invalid_completed_response', $id);
            }

            if ($status !== 'completed' && $responseFacts !== []) {
                $issues[] = $this->issue('fact_in_non_completed_response', $id);
            }
        }

        foreach ($facts as $fact) {
            $issues = array_merge($issues, $this->factIssues($fact));
        }

        return $this->preflightPayload($issues, count($facts));
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public function build(array $state): IxbrlCandidateDocument
    {
        $preflight = $this->preflight($state);
        if ($preflight['status'] !== 'available') {
            throw new RuntimeException('ixbrl_candidate_blocked');
        }

        $facts = $this->exportableFacts($state);
        usort($facts, fn (array $a, array $b): int => [
            (string) ($a['datapoint_id'] ?? ''),
            (string) ($a['fact_id'] ?? ''),
        ] <=> [
            (string) ($b['datapoint_id'] ?? ''),
            (string) ($b['fact_id'] ?? ''),
        ]);

        $contexts = $this->contexts($facts, Arr::get($state, 'reporting_entity'));
        $units = $this->units($facts);

        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;
        $document->preserveWhiteSpace = false;

        $html = $document->createElementNS(self::XHTML_NS, 'html');
        $html->setAttribute('xmlns', self::XHTML_NS);
        $html->setAttribute('xmlns:xhtml', self::XHTML_NS);
        $html->setAttribute('xmlns:ix', self::IX_NS);
        $html->setAttribute('xmlns:xbrli', self::XBRLI_NS);
        $html->setAttribute('xmlns:xbrldi', self::XBRLDI_NS);
        $html->setAttribute('xmlns:link', self::LINK_NS);
        $html->setAttribute('xmlns:xlink', self::XLINK_NS);
        $html->setAttribute('xmlns:xsi', self::XSI_NS);
        $html->setAttribute('xmlns:esrs', $this->concepts->namespaceUri());
        $html->setAttribute('xmlns:iso4217', self::ISO4217_NS);
        $html->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:lang', 'es');
        $document->appendChild($html);

        $this->appendHead($document, $html);
        $body = $this->append($document, $html, 'body');
        $this->appendIxHeader($document, $body, $contexts, $units);
        $this->appendVisibleContent($document, $body, $state, $facts, $contexts, $units);

        $bytes = $document->saveXML();
        if (! is_string($bytes)) {
            throw new RuntimeException('ixbrl_candidate_serialization_failed');
        }

        return new IxbrlCandidateDocument(
            $bytes,
            'ixbrl-candidate-characterization-'.((int) ($state['characterization_id'] ?? 0)).'.xhtml',
        );
    }

    private function appendHead(DOMDocument $document, DOMElement $html): DOMElement
    {
        $head = $this->append($document, $html, 'head');
        $this->append($document, $head, 'title', 'Candidato tecnico iXBRL; no presentacion oficial');

        return $head;
    }

    /**
     * @param  array<string, array<string, mixed>>  $contexts
     * @param  array<string, array<string, mixed>>  $units
     */
    private function appendIxHeader(DOMDocument $document, DOMElement $body, array $contexts, array $units): void
    {
        $hidden = $this->append($document, $body, 'div');
        $hidden->setAttribute('style', 'display:none');

        $header = $document->createElementNS(self::IX_NS, 'ix:header');
        $hidden->appendChild($header);

        $references = $document->createElementNS(self::IX_NS, 'ix:references');
        $schemaRef = $document->createElementNS(self::LINK_NS, 'link:schemaRef');
        $schemaRef->setAttributeNS(self::XLINK_NS, 'xlink:type', 'simple');
        $schemaRef->setAttributeNS(self::XLINK_NS, 'xlink:href', self::SCHEMA_REF);
        $references->appendChild($schemaRef);
        $header->appendChild($references);

        $resources = $document->createElementNS(self::IX_NS, 'ix:resources');
        foreach ($contexts as $context) {
            $resources->appendChild($this->contextElement($document, $context));
        }
        foreach ($units as $unit) {
            $resources->appendChild($this->unitElement($document, $unit));
        }
        $header->appendChild($resources);
    }

    /**
     * @param  list<array<string, mixed>>  $facts
     * @param  array<string, array<string, mixed>>  $contexts
     * @param  array<string, array<string, mixed>>  $units
     */
    private function appendVisibleContent(DOMDocument $document, DOMElement $body, array $state, array $facts, array $contexts, array $units): void
    {
        $this->append($document, $body, 'h1', 'Candidato tecnico iXBRL; no presentacion oficial');
        $this->append($document, $body, 'p', 'Documento tecnico candidato generado desde datos estructurados. No implica presentacion oficial, aseguramiento, opinion legal, validacion regulatoria ni aceptacion por una autoridad.');
        $this->append($document, $body, 'p', 'Entidad: '.(string) Arr::get($state, 'reporting_entity.name', 'Entidad sin nombre'));

        $list = $this->append($document, $body, 'ul');
        $this->append($document, $list, 'li', 'Caracterizacion: '.(string) ($state['characterization_id'] ?? ''));
        $this->append($document, $list, 'li', 'Taxonomia: '.EsrsConceptResolver::TAXONOMY_VERSION);
        $this->append($document, $list, 'li', 'Hechos incluidos: '.count($facts));
        $this->append($document, $list, 'li', 'Contextos: '.count($contexts));
        $this->append($document, $list, 'li', 'Unidades: '.count($units));

        foreach ($facts as $fact) {
            $paragraph = $this->append($document, $body, 'p');
            $paragraph->setAttribute('id', $this->xmlId('dp_'.$fact['datapoint_id'].'_'.$fact['fact_id']));
            $paragraph->appendChild($this->factElement($document, $fact));
        }
    }

    /** @param array<string, mixed> $context */
    private function contextElement(DOMDocument $document, array $context): DOMElement
    {
        $element = $document->createElementNS(self::XBRLI_NS, 'xbrli:context');
        $element->setAttribute('id', $context['id']);

        $entity = $document->createElementNS(self::XBRLI_NS, 'xbrli:entity');
        $identifier = $document->createElementNS(self::XBRLI_NS, 'xbrli:identifier');
        $identifier->setAttribute('scheme', $context['entity']['identifier_scheme']);
        $identifier->appendChild($document->createTextNode($context['entity']['identifier']));
        $entity->appendChild($identifier);

        if ($context['dimensions'] !== []) {
            $segment = $document->createElementNS(self::XBRLI_NS, 'xbrli:segment');
            foreach ($context['dimensions'] as $dimension) {
                $member = $document->createElementNS(self::XBRLDI_NS, 'xbrldi:explicitMember');
                $member->setAttribute('dimension', $dimension['axis']);
                $member->appendChild($document->createTextNode($dimension['member']));
                $segment->appendChild($member);
            }
            $entity->appendChild($segment);
        }

        $period = $document->createElementNS(self::XBRLI_NS, 'xbrli:period');
        if ($context['period_type'] === 'instant') {
            $period->appendChild($document->createElementNS(self::XBRLI_NS, 'xbrli:instant', $context['instant_date']));
        } else {
            $period->appendChild($document->createElementNS(self::XBRLI_NS, 'xbrli:startDate', $context['start_date']));
            $period->appendChild($document->createElementNS(self::XBRLI_NS, 'xbrli:endDate', $context['end_date']));
        }

        $element->appendChild($entity);
        $element->appendChild($period);

        return $element;
    }

    /** @param array<string, mixed> $unit */
    private function unitElement(DOMDocument $document, array $unit): DOMElement
    {
        $element = $document->createElementNS(self::XBRLI_NS, 'xbrli:unit');
        $element->setAttribute('id', $unit['id']);
        $element->appendChild($document->createElementNS(self::XBRLI_NS, 'xbrli:measure', $unit['measure']));

        return $element;
    }

    /** @param array<string, mixed> $fact */
    private function factElement(DOMDocument $document, array $fact): DOMElement
    {
        $kind = $fact['value_kind'];
        $element = in_array($kind, self::NUMERIC_KINDS, true)
            ? $document->createElementNS(self::IX_NS, 'ix:nonFraction')
            : $document->createElementNS(self::IX_NS, 'ix:nonNumeric');

        $element->setAttribute('id', $this->xmlId('f_'.$fact['fact_id']));
        $element->setAttribute('name', $fact['concept']['concept_id']);
        $element->setAttribute('contextRef', $fact['context_id']);

        if (in_array($kind, self::NUMERIC_KINDS, true)) {
            $element->setAttribute('unitRef', $fact['unit_id']);
            $element->setAttribute('decimals', (string) $fact['decimals']);
        }

        $value = is_bool($fact['value']) ? ($fact['value'] ? 'true' : 'false') : (string) $fact['value'];
        $element->appendChild($document->createTextNode($value));

        return $element;
    }

    /**
     * @param  list<array<string, mixed>>  $facts
     * @return array<string, array<string, mixed>>
     */
    private function contexts(array &$facts, mixed $entity): array
    {
        $contexts = [];
        $entity = is_array($entity) ? $entity : [];

        foreach ($facts as &$fact) {
            $context = [
                'entity' => [
                    'identifier_scheme' => (string) ($entity['identifier_scheme'] ?? ''),
                    'identifier' => (string) ($entity['identifier'] ?? ''),
                ],
                ...(is_array($fact['context'] ?? null) ? $fact['context'] : []),
            ];
            $hash = substr(hash('sha256', $this->canonicalJson($context)), 0, 16);
            $id = 'c_'.$hash;
            $context['id'] = $id;
            $contexts[$hash] = $context;
            $fact['context_id'] = $id;
        }
        unset($fact);

        ksort($contexts);

        return $contexts;
    }

    /**
     * @param  list<array<string, mixed>>  $facts
     * @return array<string, array<string, mixed>>
     */
    private function units(array &$facts): array
    {
        $units = [];
        foreach ($facts as &$fact) {
            if (! in_array($fact['value_kind'], self::NUMERIC_KINDS, true)) {
                continue;
            }

            $measure = $this->unitMeasure((string) Arr::get($fact, 'unit.measure'));
            $hash = substr(hash('sha256', $measure), 0, 16);
            $id = 'u_'.$hash;
            $units[$hash] = ['id' => $id, 'measure' => $measure];
            $fact['unit_id'] = $id;
        }
        unset($fact);

        ksort($units);

        return $units;
    }

    private function unitMeasure(string $measure): string
    {
        if ($measure === '' || $measure === 'pure') {
            return 'xbrli:pure';
        }

        return $measure;
    }

    private function supportedUnitMeasure(string $measure): bool
    {
        return in_array($measure, ['pure', 'xbrli:pure'], true)
            || preg_match('/^iso4217:[A-Z]{3}$/', $measure) === 1;
    }

    /**
     * @param  array<string, mixed>  $state
     * @return list<array<string, mixed>>
     */
    private function exportableFacts(array $state): array
    {
        $facts = [];

        foreach (Arr::get($state, 'responses', []) as $datapointId => $response) {
            if (! is_array($response) || ($response['status'] ?? null) !== 'completed') {
                continue;
            }

            foreach (is_array($response['facts'] ?? null) ? $response['facts'] : [] as $fact) {
                if (! is_array($fact)) {
                    continue;
                }

                $fact['datapoint_id'] = (string) ($response['datapoint_id'] ?? $datapointId);
                $facts[] = $fact;
            }
        }

        return $facts;
    }

    /**
     * @param  array<string, mixed>  $fact
     * @return list<array{reason_code: string, datapoint_id?: string}>
     */
    private function factIssues(array $fact): array
    {
        $issues = [];
        $datapointId = (string) ($fact['datapoint_id'] ?? '');
        $concept = Arr::get($fact, 'concept', []);
        $conceptId = is_array($concept) ? $concept['concept_id'] ?? null : null;

        if (! filled($fact['fact_id'] ?? null)) {
            $issues[] = $this->issue('fact_id_missing', $datapointId);
        }

        $resolvedConcept = null;
        if (! is_array($concept) || ($concept['taggable_state'] ?? null) !== 'mapped') {
            $issues[] = $this->issue('fact_concept_not_mapped', $datapointId);
        } else {
            $resolvedConcept = $this->concepts->resolveConcept(is_string($conceptId) ? $conceptId : null);
        }

        if (is_array($concept) && ($concept['taggable_state'] ?? null) === 'mapped' && $resolvedConcept === null) {
            $issues[] = $this->issue('taxonomy_concept_unknown', $datapointId);
        }

        foreach ($this->flattenStrings($fact) as $value) {
            if ($this->hasXmlControlCharacters($value)) {
                $issues[] = $this->issue('xml_control_character', $datapointId);
                break;
            }
        }

        $context = $fact['context'] ?? null;
        if (! is_array($context)) {
            $issues[] = $this->issue('context_missing', $datapointId);
        } elseif (($context['period_type'] ?? null) === 'duration') {
            if (! is_string($context['start_date'] ?? null) || ! is_string($context['end_date'] ?? null)) {
                $issues[] = $this->issue('context_period_incomplete', $datapointId);
            }
        } elseif (($context['period_type'] ?? null) === 'instant') {
            if (! is_string($context['instant_date'] ?? null)) {
                $issues[] = $this->issue('context_period_incomplete', $datapointId);
            }
        } else {
            $issues[] = $this->issue('context_period_unsupported', $datapointId);
        }

        foreach (Arr::get($fact, 'context.dimensions', []) as $dimension) {
            if (! is_array($dimension)) {
                continue;
            }

            $axis = $dimension['axis'] ?? null;
            $member = $dimension['member'] ?? null;
            if (! is_string($axis) || ! is_string($member)
                || ! str_starts_with($axis, 'esrs:')
                || ! str_starts_with($member, 'esrs:')
            ) {
                $issues[] = $this->issue('unsupported_dimension_namespace', $datapointId);
                continue;
            }

            if ($this->concepts->resolveConcept($axis) === null) {
                $issues[] = $this->issue('dimension_axis_unknown', $datapointId);
            }
            if ($this->concepts->resolveConcept($member) === null) {
                $issues[] = $this->issue('dimension_member_unknown', $datapointId);
            }
        }

        $kind = $fact['value_kind'] ?? null;
        if (! in_array($kind, ['narrative', 'string', 'boolean', 'date', 'integer', 'decimal', 'monetary', 'percent'], true)) {
            $issues[] = $this->issue('unsupported_value_kind', $datapointId);
        }

        if ($resolvedConcept !== null && is_string($kind)) {
            $type = $resolvedConcept['type'] ?? null;
            $supportedKinds = is_string($type) ? (self::SUPPORTED_TYPE_KINDS[$type] ?? null) : null;
            if (($resolvedConcept['abstract'] ?? false) === true || ! is_array($supportedKinds)) {
                $issues[] = $this->issue('concept_type_unsupported', $datapointId);
            } elseif (! in_array($kind, $supportedKinds, true)) {
                $issues[] = $this->issue('fact_value_kind_incompatible', $datapointId);
            }

            $conceptPeriodType = $resolvedConcept['period_type'] ?? null;
            $factPeriodType = Arr::get($fact, 'context.period_type');
            if (is_string($conceptPeriodType) && is_string($factPeriodType) && $conceptPeriodType !== $factPeriodType) {
                $issues[] = $this->issue('fact_period_type_incompatible', $datapointId);
            } elseif (! is_string($conceptPeriodType) || $conceptPeriodType === '') {
                $issues[] = $this->issue('fact_period_type_incompatible', $datapointId);
            }
        }

        if (in_array($kind, self::NUMERIC_KINDS, true)) {
            if (! is_int($fact['decimals'] ?? null)) {
                $issues[] = $this->issue('numeric_decimals_missing', $datapointId);
            }
            $measure = Arr::get($fact, 'unit.measure');
            if ($kind !== 'integer' && (! is_array($fact['unit'] ?? null) || ! is_string($measure))) {
                $issues[] = $this->issue('numeric_unit_missing', $datapointId);
            } elseif (is_string($measure) && ! $this->supportedUnitMeasure($measure)) {
                $issues[] = $this->issue('unsupported_unit_measure', $datapointId);
            }
        }

        return $issues;
    }

    /**
     * @param  list<array{reason_code: string, datapoint_id?: string}>  $issues
     * @return array<string, mixed>
     */
    private function preflightPayload(array $issues, int $factCount): array
    {
        $reasonCodes = collect($issues)
            ->pluck('reason_code')
            ->unique()
            ->sort()
            ->values()
            ->all();
        $datapointIds = collect($issues)
            ->pluck('datapoint_id')
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();

        return [
            'status' => $issues === [] ? 'available' : 'blocked',
            'reason_codes' => $reasonCodes,
            'blocking_datapoint_ids' => $datapointIds,
            'fact_count' => $factCount,
            'limitations' => [
                'candidate_technical_package',
                'not_official_filing',
                'requires_arelle_runtime_structural_validation',
                'xhtml_ixbrl_generation_capability_remains_disabled',
            ],
        ];
    }

    /** @return array{reason_code: string, datapoint_id?: string} */
    private function issue(string $reasonCode, ?string $datapointId = null): array
    {
        return array_filter([
            'reason_code' => $reasonCode,
            'datapoint_id' => $datapointId,
        ], fn ($value): bool => $value !== null && $value !== '');
    }

    /** @return list<string> */
    private function flattenStrings(mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->flatMap(fn ($item): array => $this->flattenStrings($item))
            ->values()
            ->all();
    }

    private function hasXmlControlCharacters(string $value): bool
    {
        return preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value) === 1;
    }

    private function canonicalJson(array $value): string
    {
        $normalized = $this->sortRecursively($value);

        return json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function sortRecursively(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $isList = array_keys($value) === range(0, count($value) - 1);
        $normalized = array_map(fn ($item) => $this->sortRecursively($item), $value);

        if (! $isList) {
            ksort($normalized);
        }

        return $normalized;
    }

    private function xmlId(string $value): string
    {
        $id = preg_replace('/[^A-Za-z0-9_.-]+/', '_', $value) ?: 'id';
        if (preg_match('/^[A-Za-z_]/', $id) !== 1) {
            $id = 'id_'.$id;
        }

        return $id;
    }

    private function append(DOMDocument $document, DOMElement $parent, string $name, ?string $text = null): DOMElement
    {
        $element = $document->createElementNS(self::XHTML_NS, $name);
        if ($text !== null) {
            $element->appendChild($document->createTextNode($text));
        }
        $parent->appendChild($element);

        return $element;
    }
}
