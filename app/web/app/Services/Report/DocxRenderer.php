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
 * Guidance is rendered only when it contains text. Empty guidance metadata must
 * never create a visible technical marker in the factual report.
 */
class DocxRenderer
{
    private const CUSTOM_XML_RELATIONSHIP_TYPE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/customXml';

    public function render(array $ir): string
    {
        $doc = new PhpWord();
        $this->configureStyles($doc);
        $section = $doc->addSection([
            'marginTop' => 1134,
            'marginBottom' => 1134,
            'marginLeft' => 1276,
            'marginRight' => 1276,
        ]);
        $section->addTitle(($ir['company']['name'] ?? 'Informe').' — Ejercicio '.($ir['company']['reporting_year'] ?? '-'), 1);
        $claimsById = $this->claimsById($ir);
        $isFactual = ($ir['schema_version'] ?? null) === 'report_ir_v1';

        if ($isFactual) {
            $section->addText(
                'Borrador factual basado en una versión aprobada',
                ['bold' => true, 'size' => 12, 'color' => '1F4E78'],
                ['spaceBefore' => 360, 'spaceAfter' => 240],
            );
        }

        $disclaimers = $ir['disclaimers'] ?? [];
        if ($isFactual && $disclaimers === []) {
            $disclaimers = ['No constituye una presentación oficial ni un trabajo de aseguramiento; tampoco acredita el cumplimiento de la Taxonomía de la UE ni genera el formato electrónico regulatorio.'];
        }

        foreach (array_values(array_unique($disclaimers)) as $disclaimer) {
            $visibleDisclaimer = $isFactual
                ? ReportVisiblePresentation::controlledNarrative($disclaimer)
                : $disclaimer;
            $section->addText($visibleDisclaimer, ['italic' => true]);
        }

        if ($isFactual) {
            $section->addPageBreak();
        } else {
            $this->renderOmissionSection($section, $ir['omission_section'] ?? null, false);
        }

        $slotMap = [];
        foreach (array_values($ir['chapters'] ?? []) as $chapter) {
            $chapterTitle = $isFactual
                ? ReportVisiblePresentation::chapterTitle($chapter['block_key'] ?? null)
                : $chapter['title'];
            $section->addTitle($chapterTitle, 2);

            if (! $isFactual && filled($chapter['floor_prose'] ?? null)) {
                $section->addText($chapter['floor_prose']);
            }

            foreach ($chapter['sections'] ?? [] as $sec) {
                $sectionTitle = $isFactual
                    ? ReportVisiblePresentation::sectionTitle($sec['dr_key'] ?? null)
                    : $sec['dr_key'];
                $section->addTitle($sectionTitle, 3);

                if (! $isFactual) {
                    foreach ($sec['cross_ref_sentences'] ?? [] as $sentence) {
                        $section->addText($sentence, ['italic' => true]);
                    }
                }

                foreach ($sec['blocks'] ?? [] as $block) {
                    if ($isFactual) {
                        $section->addText(
                            ReportVisiblePresentation::claimLabel(
                            $block['datapoint_id'] ?? null,
                            $this->firstClaimForBlock($block, $claimsById),
                            ),
                            ['bold' => true],
                            ['keepNext' => true],
                        );
                    } else {
                        $section->addText($block['name'].' — '.($block['assertions'][0] ?? ''));
                    }

                    $guidance = $block['guidance'] ?? [];
                    if (! $isFactual) {
                        $isAuthoritative = $guidance['authoritative'] ?? false;
                        $prefix = $isAuthoritative ? '' : '[orientación general] ';
                        $section->addText($prefix.($guidance['text'] ?? ''), ['size' => 9]);
                    }

                    $citations = $guidance['citations'] ?? [];
                    if (! $isFactual && $citations !== []) {
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
                        $textrun->addText($isFactual ? 'Valor reportado: ' : (($slot['label'] ?? 'Información reportada').': '));
                        $textrun->addText($this->slotToken($nodeId));
                        $factValue = $this->factValueForSlot($slot, $claimsById, $isFactual);

                        $slotMap[$nodeId] = [
                            'datapoint_id' => $block['datapoint_id'],
                            'xbrl_concept' => $slot['xbrl_concept'],
                            'claim_id' => $slot['claim_id'] ?? null,
                            'fact_id' => $slot['fact_id'] ?? null,
                            'display_value' => $factValue ?? '____________',
                        ];
                    }
                }
            }
        }

        if ($isFactual && $this->hasOmissionContent($ir['omission_section'] ?? null)) {
            $section->addPageBreak();
            $this->renderOmissionSection($section, $ir['omission_section'], true);
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

    private function configureStyles(PhpWord $doc): void
    {
        $doc->setDefaultFontName('Aptos');
        $doc->setDefaultFontSize(10.5);
        $doc->addTitleStyle(1, ['name' => 'Aptos Display', 'size' => 24, 'bold' => true, 'color' => '1F4E78'], ['spaceAfter' => 240]);
        $doc->addTitleStyle(2, ['name' => 'Aptos Display', 'size' => 18, 'bold' => true, 'color' => '1F4E78'], ['spaceBefore' => 180, 'spaceAfter' => 120, 'keepNext' => true]);
        $doc->addTitleStyle(3, ['name' => 'Aptos', 'size' => 14, 'bold' => true, 'color' => '2F5597'], ['spaceBefore' => 160, 'spaceAfter' => 80, 'keepNext' => true]);
    }

    /**
     * The consolidated omission section sits above legacy chapters and in a final
     * appendix for factual reports. It is skipped entirely when there is nothing to
     * say — never printed empty, which would read as "nothing was ever rejected".
     *
     * @param  array<string, mixed>|null  $omission
     */
    private function renderOmissionSection(\PhpOffice\PhpWord\Element\Section $section, ?array $omission, bool $isFactual): void
    {
        if (! $this->hasOmissionContent($omission)) {
            return;
        }

        $title = $isFactual
            ? ReportVisiblePresentation::controlledNarrative($omission['title'])
            : $omission['title'];

        $section->addTitle(
            $isFactual ? 'Anexo: '.$title : $title,
            2,
        );

        if (filled($omission['declaration'] ?? null)) {
            $declaration = $isFactual
                ? ReportVisiblePresentation::controlledNarrative($omission['declaration'])
                : $omission['declaration'];
            $section->addText($declaration, ['italic' => true]);
        }

        // Same fallback as the hasContent check above: an IR without the key must not crash rendering.
        foreach ($omission['statements'] ?? [] as $statement) {
            $section->addText($isFactual ? ReportVisiblePresentation::controlledNarrative($statement) : $statement);
        }

        if (filled($omission['limitation'] ?? null)) {
            $limitation = $isFactual
                ? ReportVisiblePresentation::controlledNarrative($omission['limitation'])
                : $omission['limitation'];
            $section->addText($limitation, ['italic' => true]);
        }
    }

    /** @param array<string, mixed>|null $omission */
    private function hasOmissionContent(?array $omission): bool
    {
        return $omission !== null
            && (($omission['statements'] ?? []) !== []
                || filled($omission['declaration'] ?? null)
                || filled($omission['limitation'] ?? null));
    }

    /**
     * @param array<string, mixed> $block
     * @param array<string, array<string, mixed>> $claimsById
     * @return array<string, mixed>|null
     */
    private function firstClaimForBlock(array $block, array $claimsById): ?array
    {
        foreach ($block['claims'] ?? [] as $claimId) {
            if (is_string($claimId) && isset($claimsById[$claimId])) {
                return $claimsById[$claimId];
            }
        }

        return null;
    }

    /** The placeholder token PhpWord writes for a slot before post-processing wraps it in a w:sdt. */
    private function slotToken(string $nodeId): string
    {
        return '«'.$nodeId.'»';
    }

    /**
     * @param  array<string, mixed>  $ir
     * @return array<string, array<string, mixed>>
     */
    private function claimsById(array $ir): array
    {
        $claims = [];

        foreach ($ir['claims'] ?? [] as $claim) {
            if (! is_array($claim) || ! isset($claim['claim_id'])) {
                continue;
            }

            $claims[(string) $claim['claim_id']] = $claim;
        }

        return $claims;
    }

    /**
     * @param  array<string, mixed>  $slot
     * @param  array<string, array<string, mixed>>  $claimsById
     */
    private function factValueForSlot(array $slot, array $claimsById, bool $isFactual): ?string
    {
        $claimId = $slot['claim_id'] ?? null;
        if (! is_string($claimId) || $claimId === '') {
            return null;
        }

        $claim = $claimsById[$claimId] ?? null;
        if (! is_array($claim)) {
            throw new RuntimeException("Factual slot references missing claim_id={$claimId}.");
        }

        if ($isFactual) {
            return ReportVisiblePresentation::claimValue($claim);
        }

        if (($claim['nil'] ?? false) === true) {
            return null;
        }

        $value = $claim['value'] ?? null;
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            if (array_key_exists('text', $value) && is_string($value['text'])) {
                return $value['text'];
            }

            throw new RuntimeException("Unsupported factual value shape for claim_id={$claimId}; expected value.text.");
        }

        if (is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
            return is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        throw new RuntimeException("Unsupported factual value type for claim_id={$claimId}.");
    }

    /**
     * Wrap each slot's isolated placeholder run in a run-level w:sdt (content control)
     * tagged with its node_id, and add a Custom XML part mapping every node_id to its
     * {datapoint_id, xbrl_concept}.
     *
     * @param  array<string,array{datapoint_id:mixed,xbrl_concept:mixed,claim_id:mixed,fact_id:mixed,display_value:mixed}>  $slotMap
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
                $document = $this->wrapSlotRunInSdt($document, $nodeId, (string) $meta['display_value']);
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
    private function wrapSlotRunInSdt(string $document, string $nodeId, string $displayValue): string
    {
        $token = preg_quote($this->slotToken($nodeId), '/');
        $pattern = '/<w:r>(?:(?!<\/w:r>).)*?<w:t[^>]*>'.$token.'<\/w:t><\/w:r>/s';

        $sdt = '<w:sdt>'
            .'<w:sdtPr><w:tag w:val="'.htmlspecialchars($nodeId, ENT_QUOTES | ENT_XML1).'"/>'
            .'<w:id w:val="'.abs(crc32($nodeId)).'"/></w:sdtPr>'
            .'<w:sdtContent><w:r><w:t xml:space="preserve">'.htmlspecialchars($displayValue, ENT_QUOTES | ENT_XML1).'</w:t></w:r></w:sdtContent>'
            .'</w:sdt>';

        $replaced = preg_replace_callback($pattern, static fn (): string => $sdt, $document, 1, $count);

        if ($replaced === null || $count !== 1) {
            throw new RuntimeException("Unable to locate the placeholder run for slot node_id={$nodeId} in document.xml.");
        }

        return $replaced;
    }

    /**
     * @param  array<string,array{datapoint_id:mixed,xbrl_concept:mixed,claim_id:mixed,fact_id:mixed}>  $slotMap
     */
    private function addCustomXmlPart(ZipArchive $zip, array $slotMap): void
    {
        $custom = "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\"?>\n<slots xmlns=\"urn:ia4sustainability:report-slots\">";
        foreach ($slotMap as $nodeId => $meta) {
            $custom .= '<slot node_id="'.htmlspecialchars((string) $nodeId, ENT_QUOTES | ENT_XML1).'"'
                .' datapoint_id="'.htmlspecialchars((string) $meta['datapoint_id'], ENT_QUOTES | ENT_XML1).'"'
                .' xbrl_concept="'.htmlspecialchars((string) $meta['xbrl_concept'], ENT_QUOTES | ENT_XML1).'"';

            if (($meta['claim_id'] ?? null) !== null) {
                $custom .= ' claim_id="'.htmlspecialchars((string) $meta['claim_id'], ENT_QUOTES | ENT_XML1).'"';
            }

            if (($meta['fact_id'] ?? null) !== null) {
                $custom .= ' fact_id="'.htmlspecialchars((string) $meta['fact_id'], ENT_QUOTES | ENT_XML1).'"';
            }

            $custom .= '/>';
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
