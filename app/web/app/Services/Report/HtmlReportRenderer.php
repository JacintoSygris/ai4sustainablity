<?php

namespace App\Services\Report;

use DomainException;
use RuntimeException;

class HtmlReportRenderer
{
    public function render(array $ir): string
    {
        if (($ir['schema_version'] ?? null) !== 'report_ir_v1') {
            throw new DomainException('HtmlReportRenderer requires report_ir_v1.');
        }

        $claimsById = $this->claimsById($ir);
        $title = $this->text(($ir['company']['name'] ?? 'Informe ESRS').' - Ejercicio '.($ir['company']['reporting_year'] ?? '-'));

        $html = [];
        $html[] = '<!doctype html>';
        $html[] = '<html lang="es">';
        $html[] = '<head>';
        $html[] = '<meta charset="UTF-8">';
        $html[] = '<meta name="viewport" content="width=device-width, initial-scale=1">';
        $html[] = '<title>'.$title.'</title>';
        $html[] = '<style>body{font-family:Arial,sans-serif;line-height:1.5;color:#111;margin:2rem;max-width:980px}h1,h2,h3,h4{line-height:1.2}.notice{border:1px solid #bbb;padding:1rem;margin:1rem 0}.muted{color:#555}.fact{margin:.5rem 0;padding:.5rem;border-left:3px solid #777}.guidance{font-size:.95rem;color:#333}.citation{font-size:.9rem;color:#444}</style>';
        $html[] = '</head>';
        $html[] = '<body>';
        $html[] = '<header>';
        $html[] = '<p><strong>Borrador factual basado en snapshot aprobado</strong></p>';
        $html[] = '<h1>'.$title.'</h1>';
        $html[] = '<div class="notice">';
        $html[] = '<p>No es una presentación oficial, aseguramiento ni filing.</p>';
        $html[] = '<p>No constituye un documento iXBRL ni una Taxonomía UE presentada.</p>';

        foreach ($ir['disclaimers'] ?? [] as $disclaimer) {
            if ($this->hasText($disclaimer)) {
                $html[] = '<p>'.$this->text($disclaimer).'</p>';
            }
        }

        $html[] = '</div>';
        $html[] = '</header>';
        $html[] = $this->renderOmissionSection($ir['omission_section'] ?? null);

        foreach ($ir['chapters'] ?? [] as $chapter) {
            if (! is_array($chapter)) {
                continue;
            }

            $html[] = '<section>';
            $html[] = '<h2>'.$this->text($chapter['title'] ?? 'Capitulo').'</h2>';

            if ($this->hasText($chapter['floor_prose'] ?? null)) {
                $html[] = '<p>'.$this->text($chapter['floor_prose']).'</p>';
            }

            foreach ($chapter['sections'] ?? [] as $section) {
                if (! is_array($section)) {
                    continue;
                }

                $html[] = '<section>';
                $html[] = '<h3>'.$this->text($section['dr_key'] ?? 'Disclosure Requirement').'</h3>';

                foreach ($section['cross_ref_sentences'] ?? [] as $sentence) {
                    if ($this->hasText($sentence)) {
                        $html[] = '<p class="muted"><em>'.$this->text($sentence).'</em></p>';
                    }
                }

                foreach ($section['blocks'] ?? [] as $block) {
                    if (is_array($block)) {
                        $html[] = $this->renderBlock($block, $claimsById);
                    }
                }

                $html[] = '</section>';
            }

            $html[] = '</section>';
        }

        $html[] = '</body>';
        $html[] = '</html>';

        return implode("\n", $html)."\n";
    }

    /**
     * @param  array<string, mixed>|null  $omission
     */
    private function renderOmissionSection(?array $omission): string
    {
        if ($omission === null) {
            return '';
        }

        $statements = is_array($omission['statements'] ?? null) ? $omission['statements'] : [];
        $hasContent = $this->hasText($omission['declaration'] ?? null)
            || $this->hasText($omission['limitation'] ?? null)
            || $statements !== [];

        if (! $hasContent) {
            return '';
        }

        $html = [];
        $html[] = '<section>';
        $html[] = '<h2>'.$this->text($omission['title'] ?? 'Omisiones').'</h2>';

        if ($this->hasText($omission['declaration'] ?? null)) {
            $html[] = '<p><em>'.$this->text($omission['declaration']).'</em></p>';
        }

        foreach ($statements as $statement) {
            if ($this->hasText($statement)) {
                $html[] = '<p>'.$this->text($statement).'</p>';
            }
        }

        if ($this->hasText($omission['limitation'] ?? null)) {
            $html[] = '<p><em>'.$this->text($omission['limitation']).'</em></p>';
        }

        $html[] = '</section>';

        return implode("\n", $html);
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  array<string, array<string, mixed>>  $claimsById
     */
    private function renderBlock(array $block, array $claimsById): string
    {
        $html = [];
        $html[] = '<section>';
        $html[] = '<h4>'.$this->text($block['name'] ?? $block['datapoint_id'] ?? 'Datapoint').'</h4>';

        if ($this->hasText($block['datapoint_id'] ?? null)) {
            $html[] = '<p class="muted">Datapoint: '.$this->text($block['datapoint_id']).'</p>';
        }

        $assertions = is_array($block['assertions'] ?? null) ? $block['assertions'] : [];
        foreach ($assertions as $assertion) {
            if ($this->hasText($assertion)) {
                $html[] = '<p>'.$this->text($assertion).'</p>';
            }
        }

        $claimIds = is_array($block['claims'] ?? null) ? $block['claims'] : [];
        foreach ($claimIds as $claimId) {
            if (! is_string($claimId) || $claimId === '') {
                continue;
            }

            $claim = $claimsById[$claimId] ?? null;
            if (! is_array($claim)) {
                throw new RuntimeException("Factual block references missing claim.");
            }

            $value = $this->claimValue($claim);
            if ($value !== null) {
                $html[] = '<p class="fact">'.$this->text($value).'</p>';
            }
        }

        $guidance = is_array($block['guidance'] ?? null) ? $block['guidance'] : [];
        if ($this->hasText($guidance['text'] ?? null)) {
            $prefix = ($guidance['authoritative'] ?? false) ? '' : '[orientacion general] ';
            $html[] = '<p class="guidance">'.$this->text($prefix.$guidance['text']).'</p>';
        }

        $citations = is_array($guidance['citations'] ?? null) ? $guidance['citations'] : [];
        if ($citations !== []) {
            $visibleCitations = [];
            foreach ($citations as $citation) {
                if ($this->hasText($citation)) {
                    $visibleCitations[] = $this->text($citation);
                }
            }

            if ($visibleCitations !== []) {
                $html[] = '<p class="citation">Fuentes: '.implode('; ', $visibleCitations).'</p>';
            }
        }

        $html[] = '</section>';

        return implode("\n", $html);
    }

    /**
     * @param  array<string, mixed>  $ir
     * @return array<string, array<string, mixed>>
     */
    private function claimsById(array $ir): array
    {
        $claims = [];

        foreach ($ir['claims'] ?? [] as $claim) {
            if (! is_array($claim) || ! is_string($claim['claim_id'] ?? null) || $claim['claim_id'] === '') {
                continue;
            }

            $claims[$claim['claim_id']] = $claim;
        }

        return $claims;
    }

    /**
     * @param  array<string, mixed>  $claim
     */
    private function claimValue(array $claim): ?string
    {
        if (($claim['nil'] ?? false) === true) {
            return null;
        }

        $value = $claim['value'] ?? null;
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            if (array_key_exists('text', $value) && is_scalar($value['text'])) {
                return (string) $value['text'];
            }

            throw new RuntimeException('Unsupported factual value shape; expected value.text.');
        }

        if (is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
            return is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        throw new RuntimeException('Unsupported factual value type.');
    }

    private function hasText(mixed $value): bool
    {
        return is_scalar($value) && trim((string) $value) !== '';
    }

    private function text(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
