<?php

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
