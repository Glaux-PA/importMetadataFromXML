<?php

function processAbstractSecNode($secNode) {
    $content = '';
    foreach ($secNode->childNodes as $node) {
        if ($node->nodeName === 'title') {
            $content .= '<strong>' . processAbstractChildNodes($node) . '</strong> ';
        } elseif ($node->nodeName === 'p') {
            $content .= processAbstractChildNodes($node) . "\n";
        }
    }
    $content .= '<br><br>';
    return $content;
}


function processAbstractChildNodes($node) {
    $result = '';

    foreach ($node->childNodes as $child) {
        if ($child->nodeType === XML_TEXT_NODE) {
            $result .= removeLinks($child->nodeValue);
        } elseif ($child->nodeName === 'bold') {
            $result .= '<strong>' . $child->nodeValue . '</strong>';
        } elseif ($child->nodeName === 'italic') {
            $result .= '<em>' . $child->nodeValue . '</em>';
        } else {
            $result .= processAbstractChildNodes($child);
        }
    }

    return $result;
}


function removeLinks($text) {
    $pattern = '/\bhttps?:\/\/[^\s]+/i';
    return preg_replace($pattern, '', $text);
}