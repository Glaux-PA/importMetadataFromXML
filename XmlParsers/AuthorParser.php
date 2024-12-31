<?php
function authorParse($contrib, $allLanguages,$publication, $request, $userGroupId, $cont){


    $newAuthor = new Author();
    $newAuthor->setData('publicationId', $publication->getId());
    $newAuthor->setData('seq', $cont);
    
    $primaryContact = @$contrib->getAttribute('corresp');
    if (!empty($primaryContact) && $primaryContact === 'yes') {
        $newAuthor->setPrimaryContact(true);
    }
    
    $data = $contrib->getElementsByTagName('name')->item(0)->getElementsByTagName('given-names')->item(0)->nodeValue;
    $data = mb_convert_case(mb_strtolower($data), MB_CASE_TITLE, 'UTF-8');

    $localizedData = [];
    foreach ($allLanguages as $locale) {
        $localizedData[$locale] = $data;
    }
    $newAuthor->setGivenName($localizedData, null);


    $data = $contrib->getElementsByTagName('name')->item(0)->getElementsByTagName('surname')->item(0)->nodeValue;
    $data = mb_convert_case(mb_strtolower($data), MB_CASE_TITLE, 'UTF-8');
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
    $affRef = '';


    foreach ($contrib->getElementsByTagName('xref') as $ref) {
        $refType = $ref->getAttribute('ref-type');
        if ($refType === 'corresp') {
            $correspRef =  $ref->getAttribute('rid');
        } elseif ($refType === 'aff') {
            $affRef =  $ref->getAttribute('rid');
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
        if (isset($correspRef) && !empty($correspRef)) {
            foreach (@$articleMeta->getElementsByTagName('author-notes')->item(0)->getElementsByTagName('corresp') as $corresp) {
                if ($corresp->getAttribute('id') === $correspRef) {
                    $email =  $corresp->getElementsByTagName('email')->item(0)->nodeValue;
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
    if (empty($country) || empty($affiliation)) {
        if (isset($affRef) && !empty($affRef)) {
            foreach ($contrib->getElementsByTagName('aff') as $aff) {
                if ($aff->getAttribute('id') === $affRef) {
                    if ($aff->getElementsByTagName('country')->count()) {
                        $country = @$aff->getElementsByTagName('country')->item(0)->getAttribute('country');
                    }
                    $affiliation = @$aff->getElementsByTagName('institution')->item(0)->nodeValue;
                    break;
                }
            }
        }
    }

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