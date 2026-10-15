<?php

namespace App\Services\Report;

use DOMDocument;

/** DOM text nodes only: evidence stays plain text, never an active URL or fragment. */
final class ReportPresentationDocument
{
    public const NS = 'http://www.w3.org/1999/xhtml';

    public function build(array $ir, string $locale): DOMDocument
    {
        $display = new ReportDisplayProjection($locale);
        $events = $display->ordered($ir);
        $doc = new DOMDocument('1.0', 'UTF-8');
        $html = $doc->appendChild($doc->createElementNS(self::NS, 'html'));
        $html->setAttribute('lang', $display->locale);
        $html->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:lang', $display->locale);
        $head = $html->appendChild($doc->createElementNS(self::NS, 'head'));
        $title = $head->appendChild($doc->createElementNS(self::NS, 'title'));
        $title->appendChild($doc->createTextNode($events[0]['text']));
        $meta = $head->appendChild($doc->createElementNS(self::NS, 'meta'));
        $meta->setAttribute('charset', 'UTF-8');
        $body = $html->appendChild($doc->createElementNS(self::NS, 'body'));
        $stack = [$body];
        foreach ($events as $event) {
            if ($event['kind'] === 'close') { array_pop($stack); continue; }
            $parent = $stack[array_key_last($stack)];
            if ($event['kind'] === 'open') {
                $parent = $parent->appendChild($doc->createElementNS(self::NS, 'section'));
                $stack[] = $parent;
                if ($event['text'] === null) continue;
            }
            $tag = match ($event['kind']) { 'title' => 'h1', 'open' => 'h'.$event['level'], default => 'p' };
            $node = $parent->appendChild($doc->createElementNS(self::NS, $tag));
            if ($event['kind'] === 'value') {
                $node->setAttribute('data-claim-id', $event['claim']['claim_id']);
                $node->setAttribute('class', 'fact');
                if ($event['numeric_text'] !== null) {
                    $span = $node->appendChild($doc->createElementNS(self::NS, 'span'));
                    $span->setAttribute('class', 'numeric-value');
                    $span->appendChild($doc->createTextNode($event['numeric_text']));
                    $node->appendChild($doc->createTextNode(substr($event['text'], strlen($event['numeric_text']))));
                    continue;
                }
            }
            $node->appendChild($doc->createTextNode($event['text']));
        }
        return $doc;
    }
}
