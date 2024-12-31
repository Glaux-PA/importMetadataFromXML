<?php
use APP\facades\Repo;
function doiParse($articlesId, $contextId, $publication){
    foreach ($articlesId as $id) {
        if ($id->getAttribute('pub-id-type') === 'doi') {
            
            $doiValue = $id->nodeValue;
            $currentDoiId = $publication->getData('doiId');
            $doiRepo = Repo::doi();
            $doi = null;

            if ($currentDoiId) {
                $doi = $doiRepo->get($currentDoiId);
            }
            if ($doi) {
                $doi->setData('doi', $doiValue);
                $doiRepo->edit($doi, []);
            } else {
                $doi = $doiRepo->newDataObject();
                $doi->setData('contextId', $contextId);
                $doi->setData('publicationId', $publication->getId());
                $doi->setData('doi', $doiValue);
                $doi->setData('doiType', 'doi');
                $doiId = $doiRepo->add($doi);
                $publication->setData('doiId', $doiId);
                Repo::publication()->edit($publication, []);
            }
        }
    }
}
?>