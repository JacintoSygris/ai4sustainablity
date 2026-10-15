<?php

namespace App\Services\Report;

/** Plain text only; each output's existing renderer owns escaping. */
final class IroContextPresentation
{
    public static function lines(array $context, string $locale): array
    {
        if (($context['cards'] ?? []) === [] && ($context['dma_nlp']['shortlist'] ?? []) === []) { return []; }
        $en = $locale === 'en';
        $lines = [$en ? 'Reviewed IRO context' : 'Contexto IRO revisado',
            $en ? 'All ESRS obligations are preserved' : 'Se mantienen todas las obligaciones NEIS'];
        foreach ($context['cards'] ?? [] as $card) {
            $review = $card['review'];
            $lines[] = $review['description'];
            $lines[] = 'IRO '.$review['iro_id'].' / AR16 '.$review['topic_id'];
            foreach ($card['links'] as $link) {
                $lines[] = $link['esrs'].' / '.$link['dr'].' / '.$link['datapoint_id'];
            }
            $lines[] = ($en ? 'Source SHA-256: ' : 'Fuente SHA-256: ').$review['source_sha256']
                .' / '.($en ? 'row ' : 'fila ').$review['source_row']
                .' / '.($en ? 'P8 revision ' : 'revisión P8 ').$review['p8_revision'];
        }
        foreach ($context['dma_nlp']['shortlist'] ?? [] as $entry) {
            $lines[] = ($en ? 'Suggested ESRS indicator: ' : 'Indicador NEIS propuesto: ').$entry['candidate']['id'];
            $lines[] = 'IRO '.$entry['candidate']['iro_id'].' / '.($en ? 'import ' : 'importación ').$entry['import_id'];
            foreach ($entry['candidate']['spans'] as $span) { $lines[] = $span['quote']; }
            $lines[] = ($en ? 'Source SHA-256: ' : 'Fuente SHA-256: ').$entry['source_sha256'];
        }
        return $lines;
    }
}
