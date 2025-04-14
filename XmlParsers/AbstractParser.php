<?php
function abstractParse($abstractNode, $allLanguages, $primaryLanguage, $publication)
{
    $localeAbstracts = [];
    if ($abstractNode) {
        $abstractContent = '';
        foreach ($abstractNode->childNodes as $childNode) {
            if ($childNode->nodeName === 'title' && $childNode->parentNode->nodeName === 'abstract') {
                continue;
            }
            if ($childNode->nodeName === 'sec') {
                $abstractContent .= processAbstractSecNode($childNode);
            } else if ($childNode->nodeName === 'p') {
                $abstractContent .= processAbstractChildNodes($childNode);
            }
        }
        $locale = $abstractNode->hasAttribute('xml:lang') ? $abstractNode->getAttribute('xml:lang') : $primaryLanguage;
        if ($locale && !empty($abstractContent)) {
            $localeAbstracts[$locale] = $abstractContent;
        }
    }

    return $localeAbstracts;
}


function transAbstractParse($transAbstracts, $publication)
{
    $localeAbstracts = [];

    if ($transAbstracts) {
        foreach ($transAbstracts as $transAbstract) {
            $locale = $transAbstract->getAttribute('xml:lang');
            $transAbstractContent = '';
            foreach ($transAbstract->childNodes as $childNode) {
                if ($childNode->nodeName === 'title' && $childNode->parentNode->nodeName === 'trans-abstract') {
                    continue;
                }
                if ($childNode->nodeName === 'sec') {
                    $transAbstractContent .= processAbstractSecNode($childNode);
                } else if ($childNode->nodeName === 'p') {
                    $transAbstractContent .= processAbstractChildNodes($childNode);
                }
            }
            if (!empty($locale) && !empty($transAbstractContent)) {
                $localeAbstracts[$locale] = $transAbstractContent;
            }
        }
    }

    return $localeAbstracts;
}
function assignAbstracts($localeAbstracts, $allLanguages, $primaryLanguage, $publication)
{
    foreach ($allLanguages as $lang) {
        $abstractValue = isset($localeAbstracts[$lang]) ? $localeAbstracts[$lang] : ($localeAbstracts[$primaryLanguage] ?? '');
        if ($abstractValue) {
            $publication->setData('abstract', $abstractValue, $lang);
        }
    }
}
?>