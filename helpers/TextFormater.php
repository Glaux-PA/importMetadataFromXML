<?php

function getNodeText($node) {
    $text = '';
    foreach ($node->childNodes as $child) {
        if ($child->nodeType === XML_TEXT_NODE || $child->nodeType === XML_CDATA_SECTION_NODE) {
            $text .= $child->nodeValue;
        } elseif ($child->nodeType === XML_ELEMENT_NODE) {
            $text .= getNodeText($child);
        }
    }
    return $text;
}

function normalizeSpaces($text) {
    return preg_replace('/\s+/', ' ', trim($text));
}