<?php

namespace App\Services\Report;

use DOMDocument;
use DOMElement;

class XhtmlIxbrlCandidateRenderer
{
    private const XHTML_NS = 'http://www.w3.org/1999/xhtml';
    private const IX_NS = 'http://www.xbrl.org/2013/inlineXBRL';
    private const XBRLI_NS = 'http://www.xbrl.org/2003/instance';
    private const LINK_NS = 'http://www.xbrl.org/2003/linkbase';
    private const XLINK_NS = 'http://www.w3.org/1999/xlink';
    private const ISO4217_NS = 'http://www.xbrl.org/2003/iso4217';
    private const ESRS_NS = 'https://xbrl.efrag.org/taxonomy/esrs/2023-12-22/esrs';

    private const NUMERIC_VALUE_TYPES = ['decimal', 'integer', 'monetary', 'number'];

    public function __construct(
        private readonly XbrlConceptMap $conceptMap,
    ) {}

    /**
     * @param array<string, mixed> $ir
     * @param array<string, mixed> $internalManifest
     */
    public function render(array $ir, ReportingProfile $profile, array $internalManifest): string
    {
        $context = $this->context($ir);
        $facts = $this->facts($ir);

        if ($facts === []) {
            throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_no_renderable_claims');
        }

        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = false;
        $doc->preserveWhiteSpace = false;

        $html = $doc->createElementNS(self::XHTML_NS, 'html');
        $html->setAttribute('xmlns:ix', self::IX_NS);
        $html->setAttribute('xmlns:xbrli', self::XBRLI_NS);
        $html->setAttribute('xmlns:link', self::LINK_NS);
        $html->setAttribute('xmlns:xlink', self::XLINK_NS);
        $html->setAttribute('xmlns:iso4217', self::ISO4217_NS);
        $html->setAttribute('xmlns:esrs', self::ESRS_NS);
        $doc->appendChild($html);

        $head = $html->appendChild($doc->createElementNS(self::XHTML_NS, 'head'));
        $head->appendChild($doc->createElementNS(self::XHTML_NS, 'title'))
            ->appendChild($doc->createTextNode('Candidato XHTML/iXBRL ESRS'));

        $body = $html->appendChild($doc->createElementNS(self::XHTML_NS, 'body'));
        $header = $body->appendChild($doc->createElementNS(self::IX_NS, 'ix:header'));
        $references = $header->appendChild($doc->createElementNS(self::IX_NS, 'ix:references'));
        $schemaRef = $references->appendChild($doc->createElementNS(self::LINK_NS, 'link:schemaRef'));
        $schemaRef->setAttributeNS(self::XLINK_NS, 'xlink:type', 'simple');
        $schemaRef->setAttributeNS(self::XLINK_NS, 'xlink:href', (string) $internalManifest['taxonomy_entrypoint']);

        $resources = $header->appendChild($doc->createElementNS(self::IX_NS, 'ix:resources'));
        $this->appendContext($doc, $resources, 'c1', $context);

        foreach ($this->units($facts) as $unitId => $measure) {
            $this->appendUnit($doc, $resources, $unitId, $measure);
        }

        $body->appendChild($doc->createElementNS(self::XHTML_NS, 'h1'))
            ->appendChild($doc->createTextNode('Candidato XHTML/iXBRL ESRS'));
        $body->appendChild($doc->createElementNS(self::XHTML_NS, 'p'))
            ->appendChild($doc->createTextNode($context['entity_name'].' - Ejercicio '.$context['year']));

        foreach ($facts as $index => $fact) {
            $p = $body->appendChild($doc->createElementNS(self::XHTML_NS, 'p'));
            $nonFraction = $p->appendChild($doc->createElementNS(self::IX_NS, 'ix:nonFraction'));
            $nonFraction->setAttribute('name', $fact['concept']);
            $nonFraction->setAttribute('contextRef', 'c1');
            $nonFraction->setAttribute('unitRef', $fact['unit_id']);
            $nonFraction->setAttribute('decimals', $fact['decimals']);
            $nonFraction->setAttribute('format', 'ixt:num-dot-decimal');
            $nonFraction->setAttribute('id', 'f'.($index + 1));
            $nonFraction->appendChild($doc->createTextNode($fact['value']));
        }

        $xml = $doc->saveXML();
        if (! is_string($xml) || $xml === '') {
            throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_render_failed');
        }

        return $xml;
    }

    /**
     * @param array<string, mixed> $ir
     * @return array{entity_name: string, entity_identifier: string, entity_identifier_scheme: string, year: int, start: string, end: string}
     */
    private function context(array $ir): array
    {
        $company = is_array($ir['company'] ?? null) ? $ir['company'] : [];
        $name = trim((string) ($company['name'] ?? ''));
        $year = $company['reporting_year'] ?? null;
        $entityIdentifier = $company['entity_identifier'] ?? null;
        $entityIdentifierScheme = $company['entity_identifier_scheme'] ?? null;

        if ($name === ''
            || ! is_int($year)
            || $year < 2000
            || $year > 9999
            || ! $this->isSafeEntityIdentifier($entityIdentifier)
            || ! $this->isSafeEntityIdentifierScheme($entityIdentifierScheme)) {
            throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_context_missing');
        }

        return [
            'entity_name' => $name,
            'entity_identifier' => $entityIdentifier,
            'entity_identifier_scheme' => $entityIdentifierScheme,
            'year' => $year,
            'start' => sprintf('%04d-01-01', $year),
            'end' => sprintf('%04d-12-31', $year),
        ];
    }

    private function isSafeEntityIdentifier(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/\A[A-Z0-9]{20}\z/', $value) === 1;
    }

    private function isSafeEntityIdentifierScheme(mixed $value): bool
    {
        return $value === 'https://standards.iso.org/iso/17442';
    }

    /**
     * @param array<string, mixed> $ir
     * @return list<array{concept: string, value: string, unit_id: string, unit_measure: string, decimals: string}>
     */
    private function facts(array $ir): array
    {
        $claims = is_array($ir['claims'] ?? null) ? $ir['claims'] : [];
        $facts = [];

        foreach ($claims as $claim) {
            if (! is_array($claim)) {
                continue;
            }

            if (! in_array($claim['value_type'] ?? null, self::NUMERIC_VALUE_TYPES, true)) {
                continue;
            }

            $dimensions = $claim['dimensions'] ?? [];
            if (is_array($dimensions) && $dimensions !== []) {
                throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_dimensions_unsupported');
            }

            $concept = $this->concept((string) ($claim['datapoint_id'] ?? ''));
            $value = $this->numericValue($claim['value'] ?? null);
            $decimals = $this->decimals($claim['decimals'] ?? null);
            [$unitId, $measure] = $this->unit((string) ($claim['unit'] ?? ''));

            $facts[] = [
                'concept' => $concept,
                'value' => $value,
                'unit_id' => $unitId,
                'unit_measure' => $measure,
                'decimals' => $decimals,
            ];
        }

        return $facts;
    }

    private function concept(string $datapointId): string
    {
        $concept = $this->conceptMap->conceptFor($datapointId);
        if (! is_array($concept)
            || ($concept['taggable_state'] ?? null) !== 'mapped'
            || ! is_string($concept['concept_id'] ?? null)
            || ! preg_match('/\Aesrs:[A-Za-z_][A-Za-z0-9._-]*\z/', $concept['concept_id'])) {
            throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_concept_unavailable');
        }

        return $concept['concept_id'];
    }

    private function numericValue(mixed $value): string
    {
        if (is_array($value)) {
            foreach (['amount', 'value', 'number'] as $key) {
                if (array_key_exists($key, $value)) {
                    return $this->numericValue($value[$key]);
                }
            }
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value) && is_finite($value)) {
            return rtrim(rtrim(sprintf('%.12F', $value), '0'), '.');
        }

        if (is_string($value) && preg_match('/\A[+-]?(?:\d+(?:\.\d*)?|\.\d+)\z/', $value)) {
            return $value;
        }

        throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_no_renderable_claims');
    }

    private function decimals(mixed $decimals): string
    {
        if ($decimals === 'INF') {
            return 'INF';
        }

        if (is_int($decimals)) {
            return (string) $decimals;
        }

        if (is_string($decimals) && preg_match('/\A-?\d+\z/', $decimals)) {
            return $decimals;
        }

        throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_decimals_invalid');
    }

    /** @return array{0: string, 1: string} */
    private function unit(string $unit): array
    {
        $unit = trim($unit);

        if ($unit === 'pure') {
            return ['u_pure', 'xbrli:pure'];
        }

        if (preg_match('/\A[A-Z]{3}\z/', $unit)) {
            return ['u_'.$unit, 'iso4217:'.$unit];
        }

        throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_unit_invalid');
    }

    /**
     * @param list<array{unit_id: string, unit_measure: string}> $facts
     * @return array<string, string>
     */
    private function units(array $facts): array
    {
        $units = [];
        foreach ($facts as $fact) {
            $units[$fact['unit_id']] = $fact['unit_measure'];
        }
        ksort($units);

        return $units;
    }

    /** @param array{entity_identifier: string, entity_identifier_scheme: string, start: string, end: string} $context */
    private function appendContext(DOMDocument $doc, DOMElement $resources, string $id, array $context): void
    {
        $node = $resources->appendChild($doc->createElementNS(self::XBRLI_NS, 'xbrli:context'));
        $node->setAttribute('id', $id);
        $entity = $node->appendChild($doc->createElementNS(self::XBRLI_NS, 'xbrli:entity'));
        $identifier = $entity->appendChild($doc->createElementNS(self::XBRLI_NS, 'xbrli:identifier'));
        $identifier->setAttribute('scheme', $context['entity_identifier_scheme']);
        $identifier->appendChild($doc->createTextNode($context['entity_identifier']));
        $period = $node->appendChild($doc->createElementNS(self::XBRLI_NS, 'xbrli:period'));
        $period->appendChild($doc->createElementNS(self::XBRLI_NS, 'xbrli:startDate', $context['start']));
        $period->appendChild($doc->createElementNS(self::XBRLI_NS, 'xbrli:endDate', $context['end']));
    }

    private function appendUnit(DOMDocument $doc, DOMElement $resources, string $id, string $measure): void
    {
        $unit = $resources->appendChild($doc->createElementNS(self::XBRLI_NS, 'xbrli:unit'));
        $unit->setAttribute('id', $id);
        $unit->appendChild($doc->createElementNS(self::XBRLI_NS, 'xbrli:measure', $measure));
    }
}
