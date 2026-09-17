<?php

namespace App\Services\Report;

use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use RuntimeException;
use ZipArchive;

/**
 * Renders the report IR (from ReportIrBuilder::build) to a real .docx (OOXML) file.
 *
 * Every editable slot becomes a run-level Structured Document Tag (`w:sdt`) whose
 * `w:tag@w:val` equals the slot's `node_id`, so downstream tooling (or Word itself)
 * can locate the exact content control for a given slot. A Custom XML part
 * (`customXml/item1.xml`) carries the node_id -> {datapoint_id, xbrl_concept} map,
 * with the part properly declared in [Content_Types].xml and related from
 * word/_rels/document.xml.rels so the file is a genuinely valid, openable package
 * (not just a zip entry the test happens to find).
 *
 * Non-authoritative guidance (guidance.authoritative === false) is always rendered
 * with a visible "[orientación general] " prefix -- generic guidance must never be
 * presented as if it were authoritative.
 */
class DocxRenderer
{
    private const CUSTOM_XML_RELATIONSHIP_TYPE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/customXml';

    public function render(array $ir): string
    {
        $doc = new PhpWord();
        $section = $doc->addSection();
        $section->addTitle(($ir['company']['name'] ?? 'Informe').' — Ejercicio '.($ir['company']['reporting_year'] ?? '-'), 1);

        foreach ($ir['disclaimers'] ?? [] as $disclaimer) {
            $section->addText($disclaimer, ['italic' => true]);
        }

        $this->renderOmissionSection($section, $ir['omission_section'] ?? null);

        $slotMap = [];
        foreach ($ir['chapters'] ?? [] as $chapter) {
            $section->addTitle($chapter['title'], 2);

            if (filled($chapter['floor_prose'] ?? null)) {
                $section->addText($chapter['floor_prose']);
            }

            foreach ($chapter['sections'] ?? [] as $sec) {
                $section->addTitle($sec['dr_key'], 3);

                foreach ($sec['cross_ref_sentences'] ?? [] as $sentence) {
                    $section->addText($sentence, ['italic' => true]);
                }

                foreach ($sec['blocks'] ?? [] as $block) {
                    $section->addText($block['name'].' — '.($block['assertions'][0] ?? ''));

                    $guidance = $block['guidance'] ?? [];
                    $isAuthoritative = $guidance['authoritative'] ?? false;
                    $prefix = $isAuthoritative ? '' : '[orientación general] ';
                    $section->addText($prefix.($guidance['text'] ?? ''), ['size' => 9]);

                    $citations = $guidance['citations'] ?? [];
                    if ($citations !== []) {
                        $section->addText('Fuentes: '.implode('; ', $citations), ['size' => 9]);
                    }

                    foreach ($block['slots'] ?? [] as $slot) {
                        $nodeId = $slot['node_id'];

                        if (array_key_exists($nodeId, $slotMap)) {
                            throw new RuntimeException("Duplicate slot node_id: {$nodeId}");
                        }

                        // Each slot's placeholder token lives in its own isolated run so the
                        // post-processing step below can match and replace exactly that run
                        // (and only that run) with a w:sdt, instead of splicing markup into
                        // the middle of an existing <w:t> text node.
                        $textrun = $section->addTextRun();
                        $textrun->addText(($slot['label'] ?? $nodeId).': ');
                        $textrun->addText($this->slotToken($nodeId));
                        $textrun->addText(' ____________');

                        $slotMap[$nodeId] = [
                            'datapoint_id' => $block['datapoint_id'],
                            'xbrl_concept' => $slot['xbrl_concept'],
                        ];
                    }
                }
            }
        }

        $tmp = tempnam(sys_get_temp_dir(), 'p10docx');
        if ($tmp === false) {
            throw new RuntimeException('Unable to allocate a temporary file for DOCX rendering.');
        }

        try {
            IOFactory::createWriter($doc, 'Word2007')->save($tmp);
            $this->injectSdtAndCustomXml($tmp, $slotMap);

            $bytes = file_get_contents($tmp);
            if ($bytes === false) {
                throw new RuntimeException('Unable to read the generated DOCX file.');
            }

            return $bytes;
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * The consolidated omission section sits above the chapters: when a whole ESRS
     * standard is not material it has no chapter to be declared in. It is skipped
     * entirely when there is nothing to say — never printed empty, which would read
     * as "nothing was ever rejected".
     *
     * @param  array<string, mixed>|null  $omission
     */
    private function renderOmissionSection(\PhpOffice\PhpWord\Element\Section $section, ?array $omission): void
    {
        if ($omission === null) {
            return;
        }

        $hasContent = ($omission['statements'] ?? []) !== []
            || filled($omission['declaration'] ?? null)
            || filled($omission['limitation'] ?? null);

        if (! $hasContent) {
            return;
        }

        $section->addTitle($omission['title'], 2);

        if (filled($omission['declaration'] ?? null)) {
            $section->addText($omission['declaration'], ['italic' => true]);
        }

        // Same fallback as the hasContent check above: an IR without the key must not crash rendering.
        foreach ($omission['statements'] ?? [] as $statement) {
            $section->addText($statement);
        }

        if (filled($omission['limitation'] ?? null)) {
            $section->addText($omission['limitation'], ['italic' => true]);
        }
    }

    /** The placeholder token PhpWord writes for a slot before post-processing wraps it in a w:sdt. */
    private function slotToken(string $nodeId): string
    {
        return '«'.$nodeId.'»';
    }

    /**
     * Wrap each slot's isolated placeholder run in a run-level w:sdt (content control)
     * tagged with its node_id, and add a Custom XML part mapping every node_id to its
     * {datapoint_id, xbrl_concept}.
     *
     * @param  array<string,array{datapoint_id:mixed,xbrl_concept:mixed}>  $slotMap
     */
    private function injectSdtAndCustomXml(string $path, array $slotMap): void
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException("Unable to open generated DOCX package at {$path}.");
        }

        try {
            $document = $zip->getFromName('word/document.xml');
            if ($document === false) {
                throw new RuntimeException('Generated DOCX package is missing word/document.xml.');
            }

            foreach ($slotMap as $nodeId => $meta) {
                $document = $this->wrapSlotRunInSdt($document, $nodeId);
            }

            $zip->deleteName('word/document.xml');
            $zip->addFromString('word/document.xml', $document);

            $this->addCustomXmlPart($zip, $slotMap);
        } finally {
            $zip->close();
        }
    }

    /**
     * Find the isolated <w:r>...<w:t ...>«node_id»</w:t></w:r> run written for this slot
     * and replace the whole run with a run-level w:sdt (a sibling of other w:r elements
     * inside the paragraph, which is valid OOXML for an inline/run-level content control)
     * so node_id appears as a real structural w:tag@w:val, not text buried inside a run.
     */
    private function wrapSlotRunInSdt(string $document, string $nodeId): string
    {
        $token = preg_quote($this->slotToken($nodeId), '/');
        $pattern = '/<w:r>(?:(?!<\/w:r>).)*?<w:t[^>]*>'.$token.'<\/w:t><\/w:r>/s';

        $sdt = '<w:sdt>'
            .'<w:sdtPr><w:tag w:val="'.htmlspecialchars($nodeId, ENT_QUOTES | ENT_XML1).'"/>'
            .'<w:id w:val="'.abs(crc32($nodeId)).'"/></w:sdtPr>'
            .'<w:sdtContent><w:r><w:t xml:space="preserve">'.htmlspecialchars($nodeId, ENT_QUOTES | ENT_XML1).'</w:t></w:r></w:sdtContent>'
            .'</w:sdt>';

        $replaced = preg_replace($pattern, $sdt, $document, 1, $count);

        if ($replaced === null || $count !== 1) {
            throw new RuntimeException("Unable to locate the placeholder run for slot node_id={$nodeId} in document.xml.");
        }

        return $replaced;
    }

    /**
     * @param  array<string,array{datapoint_id:mixed,xbrl_concept:mixed}>  $slotMap
     */
    private function addCustomXmlPart(ZipArchive $zip, array $slotMap): void
    {
        $custom = "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\"?>\n<slots xmlns=\"urn:ia4sustainability:report-slots\">";
        foreach ($slotMap as $nodeId => $meta) {
            $custom .= '<slot node_id="'.htmlspecialchars((string) $nodeId, ENT_QUOTES | ENT_XML1).'"'
                .' datapoint_id="'.htmlspecialchars((string) $meta['datapoint_id'], ENT_QUOTES | ENT_XML1).'"'
                .' xbrl_concept="'.htmlspecialchars((string) $meta['xbrl_concept'], ENT_QUOTES | ENT_XML1).'"/>';
        }
        $custom .= '</slots>';

        $zip->addFromString('customXml/item1.xml', $custom);

        $this->registerCustomXmlContentType($zip);
        $this->relateCustomXmlPart($zip);
    }

    /** Declare customXml/item1.xml as an application/xml part in [Content_Types].xml. */
    private function registerCustomXmlContentType(ZipArchive $zip): void
    {
        $contentTypes = $zip->getFromName('[Content_Types].xml');
        if ($contentTypes === false) {
            throw new RuntimeException('Generated DOCX package is missing [Content_Types].xml.');
        }

        if (! str_contains($contentTypes, '/customXml/item1.xml')) {
            $override = '<Override PartName="/customXml/item1.xml" ContentType="application/xml"/>';
            $contentTypes = str_replace('</Types>', $override.'</Types>', $contentTypes);

            $zip->deleteName('[Content_Types].xml');
            $zip->addFromString('[Content_Types].xml', $contentTypes);
        }
    }

    /** Relate word/document.xml to the customXml part so the package references it (real, not orphaned). */
    private function relateCustomXmlPart(ZipArchive $zip): void
    {
        $relsPath = 'word/_rels/document.xml.rels';
        $rels = $zip->getFromName($relsPath);
        if ($rels === false) {
            throw new RuntimeException("Generated DOCX package is missing {$relsPath}.");
        }

        if (str_contains($rels, self::CUSTOM_XML_RELATIONSHIP_TYPE)) {
            return;
        }

        $existingIds = [];
        preg_match_all('/Id="(rId\d+)"/', $rels, $existingIds);
        $maxId = array_reduce($existingIds[1] ?? [], fn ($carry, $id) => max($carry, (int) substr($id, 3)), 0);
        $newId = 'rId'.($maxId + 1);

        $relationship = '<Relationship Id="'.$newId.'" Type="'.self::CUSTOM_XML_RELATIONSHIP_TYPE.'" Target="../customXml/item1.xml"/>';
        $rels = str_replace('</Relationships>', $relationship.'</Relationships>', $rels);

        $zip->deleteName($relsPath);
        $zip->addFromString($relsPath, $rels);
    }
}
