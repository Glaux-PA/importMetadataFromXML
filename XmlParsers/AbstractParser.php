<?php
function abstractParse($abstractNode, $allLanguages, $primaryLanguage, $publication){
    if ($abstractNode) {
        $abstractContent = '';
    
        foreach ($abstractNode->childNodes as $childNode) {
            if ($childNode->nodeName === 'title' && $childNode->parentNode->nodeName === 'abstract') {
                continue;
            }
            if ($childNode->nodeName === 'sec') {
                $abstractContent .= processAbstractSecNode($childNode);
            }else if ($childNode->nodeName === 'p') {
               $abstractContent .= processAbstractChildNodes($childNode);
           }
        }
        foreach ($allLanguages as $language) {
           $publication->setData('abstract', $abstractContent, $language);
       }
        $publication->setData('abstract', $abstractContent, $primaryLanguage);
    }
}


function transAbstractParse($transAbstracts, $publication){
    if ($transAbstracts) {
        $transAbstractContent = '';
        foreach ($transAbstracts as $transAbstract) {
            $locale = $transAbstract->getAttribute('xml:lang');
        
            foreach ($transAbstract->childNodes as $childNode) {
                if ($childNode->nodeName === 'title'&& $childNode->parentNode->nodeName === 'trans-abstract') {
                    continue;
                }
                if ($childNode->nodeName === 'sec') {
                    $transAbstractContent .= processAbstractSecNode($childNode);
                }else if ($childNode->nodeName === 'p') {
                    $transAbstractContent .= processAbstractChildNodes($childNode);
                }
            }
            if (!empty($locale) && !empty($transAbstractContent)) {
                $iso3Code = PKPLocale::getIso3FromIso1($locale);
                $language = PKPLocale::getLocaleFromIso3($iso3Code);
                $publication->setData('abstract', $transAbstractContent, $language);
            }
        }		
    }
}

?>