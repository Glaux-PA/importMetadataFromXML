<?php

/**
 * Processes the keywords-groups and assigns keywords to the available languages.
 *
 * @param DOMNodeList $keywordsGroups List of keywords-group nodes.
 * @param array $allLanguages List of all available languages.
 * @return array Keywords organized by language.
 */
function keywordsParse($keywordsGroups, $allLanguages, $primaryLanguage){
    $localeKeywords = [];
    $jelCodes = [];
    
    foreach ($keywordsGroups as $keywordsGroup) {
        $locale = xmlLangToLocale($keywordsGroup->getAttribute('xml:lang'));
        $locale = !empty($locale) ? $locale : $primaryLanguage;
        $keywords = [];
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
                if (!isset($localeKeywords[$locale]) || empty($localeKeywords[$locale])) {
                    $localeKeywords[$locale] = $keywords;
                }
            }
        }
  
    }
    
    if (!empty($jelCodes)) {
        foreach (array_unique(array_merge(array_keys($localeKeywords), [$primaryLanguage])) as $lang) {
            $localeKeywords[$lang] = array_merge($localeKeywords[$lang] ?? [], $jelCodes);
        }
    }

    return $localeKeywords;
}

?>