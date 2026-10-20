<?php
function authorParse($contrib, $allLanguages,$publication, $request, $userGroupId, $cont, $articleMeta = null){


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

    if (empty($email)) {
        if (isset($correspRef) && !empty($correspRef) && $articleMeta) {
            $authorNotes = $articleMeta->getElementsByTagName('author-notes')->item(0);
            if ($authorNotes) {
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
    $affs = $articleMeta ? $articleMeta->getElementsByTagName('aff') : $contrib->getElementsByTagName('aff');
    foreach ($affRefs as $affRef) {
        foreach ($affs as $aff) {
            if ($aff->getAttribute('id') !== $affRef) {
                continue;
            }
            if (empty($country) && $aff->getElementsByTagName('country')->count()) {
                $country = @$aff->getElementsByTagName('country')->item(0)->getAttribute('country');
            }
            if ($aff->getElementsByTagName('institution')->count()) {
                $affiliations[] = @$aff->getElementsByTagName('institution')->item(0)->nodeValue;
            }
            break;
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
?>