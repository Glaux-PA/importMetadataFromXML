<?php

/**
 * Detects all languages in the XML that use the xml:lang attribute.
 *
 * @param DOMDocument $document The DOM document containing the XML.
 * @return array List of detected languages.
 */
function detectLocales(DOMDocument $document): array {
    $detectedLocales = [];
    $xpath = new DOMXPath($document);

    $nodesWithLang = $xpath->query('//*[@xml:lang]');

    foreach ($nodesWithLang as $node) {
        $locale = $node->getAttribute('xml:lang');
        if (!empty($locale) && !in_array($locale, $detectedLocales)) {
            $detectedLocales[] = $locale;
        }
    }

    return $detectedLocales;
}