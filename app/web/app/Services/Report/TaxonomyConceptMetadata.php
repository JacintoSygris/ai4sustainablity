<?php

namespace App\Services\Report;

use DOMDocument;
use DOMXPath;
use ZipArchive;

/** Reads types/periods from the operator's checksum-verified external package, never the network. */
class TaxonomyConceptMetadata
{
    public function load(array $profile, array $manifest): array
    {
        $path = $manifest['taxonomy_package_path'] ?? '';
        $checksum = $manifest['taxonomy_package_checksum'] ?? '';
        if (! is_string($path) || ! is_file($path) || ! is_string($checksum)
            || ! hash_equals($checksum, hash_file('sha256', $path))
            || ($manifest['taxonomy_entrypoint'] ?? null) !== $profile['entrypoint']) {
            throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_taxonomy_metadata_invalid');
        }

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_taxonomy_metadata_invalid');
        }
        try {
            $xml = null;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->statIndex($i);
                if (! str_ends_with($entry['name'], '/'.$profile['core_schema'])) continue;
                if ($xml !== null || $entry['size'] > 16777216) {
                    throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_taxonomy_metadata_invalid');
                }
                $xml = $zip->getFromIndex($i);
            }
        } finally {
            $zip->close();
        }
        if (! is_string($xml) || str_contains(strtoupper($xml), '<!DOCTYPE')) {
            throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_taxonomy_metadata_invalid');
        }
        $doc = new DOMDocument;
        if (! @$doc->loadXML($xml, LIBXML_NONET) || $doc->documentElement->getAttribute('targetNamespace') !== $profile['namespace']) {
            throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_taxonomy_metadata_invalid');
        }
        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('xs', 'http://www.w3.org/2001/XMLSchema');
        $concepts = [];
        foreach ($xpath->query('/xs:schema/xs:element') as $element) {
            $type = explode(':', $element->getAttribute('type'), 2);
            if (count($type) !== 2) continue;
            $concepts['esrs:'.$element->getAttribute('name')] = [
                'type' => '{'.$element->lookupNamespaceURI($type[0]).'}'.$type[1],
                'period_type' => $element->getAttributeNS('http://www.xbrl.org/2003/instance', 'periodType'),
                'abstract' => in_array($element->getAttribute('abstract'), ['true', '1'], true),
            ];
        }

        return $concepts;
    }
}
