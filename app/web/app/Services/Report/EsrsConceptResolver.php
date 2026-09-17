<?php

namespace App\Services\Report;

use DOMDocument;
use DOMXPath;
use RuntimeException;

class EsrsConceptResolver
{
    public const TAXONOMY_VERSION = 'esrs-set1-2024';
    public const PREFIX = 'esrs';

    private const XML_SCHEMA_NAMESPACE = 'http://www.w3.org/2001/XMLSchema';
    private const LOCAL_NAME_PATTERN = '/^[A-Za-z_][A-Za-z0-9_.-]*$/';

    /** @var array<string, array{type: string|null, period_type: string|null, abstract: bool}>|null */
    private ?array $globalElements = null;
    private ?string $namespaceUri = null;

    public function __construct(private readonly EsrsTaxonomyPackageRepository $packages) {}

    public function namespaceUri(): string
    {
        $this->load();

        return $this->namespaceUri;
    }

    /**
     * @return array{prefix: string, local_name: string, namespace_uri: string, qname: string, type: string|null, period_type: string|null, abstract: bool}|null
     */
    public function resolveConcept(?string $conceptId): ?array
    {
        $localName = $this->localName($conceptId);

        if ($localName === null || ! $this->hasGlobalElement($localName)) {
            return null;
        }

        $metadata = $this->globalElements[$localName];

        return [
            'prefix' => self::PREFIX,
            'local_name' => $localName,
            'namespace_uri' => $this->namespaceUri(),
            'qname' => self::PREFIX.':'.$localName,
            'type' => $metadata['type'],
            'period_type' => $metadata['period_type'],
            'abstract' => $metadata['abstract'],
        ];
    }

    public function hasGlobalElement(string $localName): bool
    {
        $this->load();

        return isset($this->globalElements[$localName]);
    }

    private function localName(?string $conceptId): ?string
    {
        if (! is_string($conceptId) || ! str_starts_with($conceptId, self::PREFIX.':')) {
            return null;
        }

        $localName = substr($conceptId, strlen(self::PREFIX) + 1);

        return preg_match(self::LOCAL_NAME_PATTERN, $localName) === 1 ? $localName : null;
    }

    private function load(): void
    {
        if ($this->globalElements !== null && $this->namespaceUri !== null) {
            return;
        }

        $package = $this->packages->verified(self::TAXONOMY_VERSION);
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->load($package->coreEntrypointPath(), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw new RuntimeException('taxonomy_core_schema_unreadable');
        }

        $namespaceUri = $document->documentElement?->getAttribute('targetNamespace') ?: '';
        if ($namespaceUri === '') {
            throw new RuntimeException('taxonomy_core_schema_invalid');
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('xs', self::XML_SCHEMA_NAMESPACE);
        $xpath->registerNamespace('xsd', self::XML_SCHEMA_NAMESPACE);

        $elements = [];
        foreach ($xpath->query('/xsd:schema/xsd:element[@name]') ?: [] as $element) {
            $name = $element->getAttribute('name');
            if (preg_match(self::LOCAL_NAME_PATTERN, $name) === 1) {
                $elements[$name] = [
                    'type' => $element->getAttribute('type') ?: null,
                    'period_type' => $element->getAttributeNS('http://www.xbrl.org/2003/instance', 'periodType') ?: null,
                    'abstract' => $element->getAttribute('abstract') === 'true',
                ];
            }
        }

        if ($elements === []) {
            throw new RuntimeException('taxonomy_core_schema_empty');
        }

        $this->namespaceUri = $namespaceUri;
        $this->globalElements = $elements;
    }
}
