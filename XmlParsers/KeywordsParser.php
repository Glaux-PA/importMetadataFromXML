<?php

/**
 * Processes the keywords-groups and assigns keywords to the available languages.
 *
 * @param DOMNodeList $keywordsGroups List of keywords-group nodes.
 * @param array $allLanguages List of all available languages.
 * @return array Keywords organized by language.
 */

    function keywordsParse($keywordsGroups, $allLanguages, $primaryLanguage) {
        $localeKeywords = [];
        $localeJELCodes = [];
        $primaryKeywords = [];
    
        foreach ($keywordsGroups as $keywordsGroup) {
            $locale = $keywordsGroup->getAttribute('xml:lang');
    
            $locale = $locale ?? null;
            $iso3Code = $locale ? PKPLocale::getIso3FromIso1($locale) : null;
            $locale = $iso3Code ? PKPLocale::getLocaleFromIso3($iso3Code) : null;
    
            $keywords = [];
            $jelCodes = [];
            $isJELGroup = false;
    
            foreach ($keywordsGroup->getElementsByTagName('title') as $titleNode) {
                $titleText = trim($titleNode->nodeValue);
                if (stripos($titleText, 'JEL') !== false) {
                    $isJELGroup = true;
                    break;
                }
            }
    
            if ($isJELGroup) {
                foreach ($keywordsGroup->getElementsByTagName('compound-kwd-part') as $compoundNode) {
                    $jelCodes[] = trim($compoundNode->nodeValue);
                }
            } else {
                foreach ($keywordsGroup->getElementsByTagName('kwd') as $keywordNode) {
                    $keywords[] = trim($keywordNode->nodeValue);
                }
    
                if (!empty($keywords)) {
                    if (!isset($localeKeywords[$locale])) {
                        $localeKeywords[$locale] = $keywords;
                    }
                }
    
                if ($locale == $primaryLanguage) {
                    $primaryKeywords = $keywords;
                }
            }
        }
    
        if (!empty($primaryKeywords)) {
            foreach ($allLanguages as $lang) {
                if (!isset($localeKeywords[$lang]) || empty($localeKeywords[$lang])) {
                    $localeKeywords[$lang] = $primaryKeywords;
                }
            }
        }
    
        if (!empty($jelCodes)) {
            foreach ($allLanguages as $lang) {
                if (!isset($localeJELCodes[$lang])) {
                    $localeJELCodes[$lang] = [];
                }
                if (!is_array($localeJELCodes[$lang])) {
                    $localeJELCodes[$lang] = [];
                }
                if (is_array($jelCodes)) {
                    $localeJELCodes[$lang] = array_merge($localeJELCodes[$lang], $jelCodes);
                }
            }
        }
    
        foreach ($allLanguages as $lang) {
            if (!isset($localeKeywords[$lang])) {
                $localeKeywords[$lang] = [];
            }
            if (isset($localeJELCodes[$lang]) && is_array($localeJELCodes[$lang])) {
                $localeKeywords[$lang] = array_merge($localeKeywords[$lang], $localeJELCodes[$lang]);
            }
        }
    
        return $localeKeywords;
    }
    

?>