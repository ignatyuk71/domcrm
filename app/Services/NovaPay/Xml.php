<?php

namespace App\Services\NovaPay;

use DOMDocument;
use DOMElement;
use DOMXPath;

class Xml
{
    public static function parse(string $body): DOMDocument
    {
        // Забороняємо DTD та мережеве завантаження зовнішніх сутностей.
        if (strlen($body) > 10 * 1024 * 1024 || preg_match('/<!DOCTYPE|<!ENTITY/i', $body)) {
            throw new NovaPayException('invalid_xml', 'Непідтримуваний формат відповіді NovaPay.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            if (! $document->loadXML($body, LIBXML_NONET | LIBXML_NOCDATA)) {
                throw new NovaPayException('invalid_xml', 'Не вдалося прочитати відповідь NovaPay.');
            }

            return $document;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    public static function value(DOMElement $node, string $name): string
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $name) {
                return trim($child->textContent);
            }
        }

        return '';
    }

    public static function nodes(DOMElement|DOMDocument $node, string $name): array
    {
        $xpath = new DOMXPath($node instanceof DOMDocument ? $node : $node->ownerDocument);

        return iterator_to_array($xpath->query('.//*[local-name()="'.$name.'"]', $node));
    }
}
