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

/**
 * Maps an xml:lang value (es, es-ES, pt-BR...) to one of the journal's locales.
 */
function xmlLangToLocale($lang) {
    $locales = Application::get()->getRequest()->getContext()->getSupportedSubmissionMetadataLocales();
    $locale = str_replace('-', '_', $lang);
    if ($lang === '' || in_array($locale, $locales)) {
        return $locale;
    }
    foreach ($locales as $supportedLocale) {
        if (strtolower(substr($supportedLocale, 0, 2)) === strtolower(substr($lang, 0, 2))) {
            return $supportedLocale;
        }
    }
    return $lang;
}
