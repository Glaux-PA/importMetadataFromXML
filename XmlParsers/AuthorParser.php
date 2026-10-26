<?php
function authorParse($contrib, $allLanguages,$publication, $request, $userGroupId, $cont, $authorNotes = null, $affById = []) {

    $newAuthor = new Author();
    $newAuthor->setData('publicationId', $publication->getId());
    $newAuthor->setData('seq', $cont);
    
    $primaryContact = @$contrib->getAttribute('corresp');
    if (!empty($primaryContact) && $primaryContact === 'yes') {
        $newAuthor->setPrimaryContact(true);
    }
    
    $data = $contrib->getElementsByTagName('name')->item(0)->getElementsByTagName('given-names')->item(0)->nodeValue;
    if (mb_strtoupper($data) === $data || mb_strtolower($data) === $data) {
        $data = mb_convert_case(mb_strtolower($data), MB_CASE_TITLE, 'UTF-8');
    }

    $localizedData = [];
    foreach ($allLanguages as $locale) {
        $localizedData[$locale] = $data;
    }
    $newAuthor->setGivenName($localizedData, null);


    $data = $contrib->getElementsByTagName('name')->item(0)->getElementsByTagName('surname')->item(0)->nodeValue;
    if (mb_strtoupper($data) === $data || mb_strtolower($data) === $data) {
        $data = mb_convert_case(mb_strtolower($data), MB_CASE_TITLE, 'UTF-8');
    }
    $localizedData = [];
    foreach ($allLanguages as $locale) {
        $localizedData[$locale] = $data;
    }
    $newAuthor->setFamilyName($localizedData, null);

    $data = @$contrib->getElementsByTagName('bio')->item(0)->nodeValue;
    $localizedData = [];
    foreach ($allLanguages as $locale) {
        $localizedData[$locale] = $data;
    }
    $newAuthor->setBiography($localizedData, null);
    

    $email = '';
    $orcid = '';
    $country = '';
    $affiliation = '';

    $correspRef = '';
    $affRefs = [];


    foreach ($contrib->getElementsByTagName('xref') as $ref) {
        $refType = $ref->getAttribute('ref-type');
        if ($refType === 'corresp') {
            $correspRef =  $ref->getAttribute('rid');
        } elseif ($refType === 'aff') {
            $affRefs = array_merge($affRefs, preg_split('/\s+/', trim($ref->getAttribute('rid'))));
        }
    }

    if ($contrib->getElementsByTagName('aff')->count()) {
        $email = @$contrib->getElementsByTagName('aff')->item(0)->getElementsByTagName('email')->item(0)->nodeValue;

        if ($contrib->getElementsByTagName('aff')->item(0)->getElementsByTagName('country')->count()) {
            $country = @$contrib->getElementsByTagName('aff')->item(0)->getElementsByTagName('country')->item(0)->getAttribute('country');
        }
        $orcid = @$contrib->getElementsByTagName('aff')->item(0)->getElementsByTagName('ext-link')->item(0)->nodeValue;
        $affiliation = @$contrib->getElementsByTagName('aff')->item(0)->getElementsByTagName('institution')->item(0)->nodeValue;
    }
    if (empty($email)) {
        if ($contrib->getElementsByTagName('email')->count()) {
            $email = @$contrib->getElementsByTagName('email')->item(0)->nodeValue;
        }
    }

    if (empty($email) && isset($authorNotes)) {
        if (isset($correspRef) && !empty($correspRef)) {
            foreach ($authorNotes->getElementsByTagName('corresp') as $corresp) {
                if ($corresp->getAttribute('id') === $correspRef) {
                    $emailNode = $corresp->getElementsByTagName('email')->item(0);
                    if ($emailNode) {
                        $email = $emailNode->nodeValue;
                    }
                    break;
                }
            }
        }
    }

    try {
    $contactEmail = $request->getContext()->getData('contactEmail');

    } catch (Exception $e) {
        error_log('Error retrieving default contact email: ' . $e->getMessage());
        $contactEmail = 'default@example.com';
    }
    if (empty($email)) {
        $email = $contactEmail;
    }

    if (empty($orcid)) {
        $orcid = @$contrib->getElementsByTagName('contrib-id')->item(0)->nodeValue;
    }

    $affiliations = !empty($affiliation) ? [$affiliation] : [];
    foreach ($affRefs as $affRef) {
        if (!isset($affById[$affRef])) {
            continue;
        }
        $aff = $affById[$affRef];
        if (empty($country) && $aff->getElementsByTagName('country')->count()) {
            $country = @$aff->getElementsByTagName('country')->item(0)->getAttribute('country');
        }
        if ($aff->getElementsByTagName('institution')->count()) {
            $affiliations[] = @$aff->getElementsByTagName('institution')->item(0)->nodeValue;
        }
    }
    $affiliation = implode('; ', array_unique(array_filter($affiliations)));

    $newAuthor->setEmail($email);
    $newAuthor->setCountry($country);
    $localizedData = [];
    foreach ($allLanguages as $locale) {
        $localizedData[$locale] = $affiliation;
    }
    $newAuthor->setAffiliation($localizedData, null);				
    
    $newAuthor->setOrcid($orcid);

    $newAuthor->setUserGroupId($userGroupId);


    return $newAuthor;

}

function authorKey($author) {
    $givenNames = array_filter((array) $author->getData('givenName'));
    $familyNames = array_filter((array) $author->getData('familyName'));
    $name = reset($givenNames) . ' ' . reset($familyNames);
    if (class_exists('Normalizer')) {
        $name = preg_replace('/\p{Mn}/u', '', Normalizer::normalize($name, Normalizer::FORM_D));
    }
    return trim(preg_replace('/\s+/', ' ', mb_strtolower($name)));
}

function findOldAuthor($newAuthor, &$oldAuthors) {
    $key = authorKey($newAuthor);
    foreach ($oldAuthors as $i => $oldAuthor) {
        if ($key !== '' && authorKey($oldAuthor) === $key) {
            unset($oldAuthors[$i]);
            return $oldAuthor;
        }
    }
    return null;
}

function keepAuthorData($newAuthor, $oldAuthor, $contactEmail) {
    foreach ($oldAuthor->getAllData() as $key => $value) {
        if (in_array($key, ['id', 'publicationId', 'seq', 'primaryContact'])) {
            continue;
        }
        $newValue = $newAuthor->getData($key);
        if ($key === 'email' && $newValue === $contactEmail) {
            $newValue = null;
        }
        if ($key === 'userGroupId') {
            $newAuthor->setData($key, $value ?: $newValue);
        } elseif (is_array($value)) {
            $newAuthor->setData($key, array_merge($value, array_filter((array) $newValue, function ($v) {
                return $v !== null && $v !== '';
            })));
        } elseif ($newValue === null || $newValue === '') {
            $newAuthor->setData($key, $value);
        }
    }
}
?>
