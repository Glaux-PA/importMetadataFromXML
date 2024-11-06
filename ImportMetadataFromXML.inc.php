<?php

/**
 * @file plugins/generic/importMetadataFromXML/ImportMetadataFromXML.inc.php
 *
 * Copyright (c) 2014-2023 Simon Fraser University
 * Copyright (c) 2000-2021 John Willinsky
 * Copyright (c) 2023 Glaux Publicaciones Académicas S.L.U.
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ImportMetadataFromXML
 * @brief Plugin class for the ImportMetadataFromXML plugin.
 */


import('lib.pkp.classes.plugins.GenericPlugin');

class ImportMetadataFromXML extends GenericPlugin
{
	/**
	 * @copydoc Plugin::getDisplayName()
	 */
	public function getDisplayName()
	{
		return 'Import Metadata From XML';
	}

	/**
	 * @copydoc Plugin::getDescription()
	 */
	public function getDescription()
	{
		return 'Import Metadata From XML';
	}

	/**
	 * @copydoc Plugin::register()
	 */
	public function register($category, $path, $mainContextId = null)
	{
		if (!parent::register($category, $path, $mainContextId)) return false;

		if ($this->getEnabled($mainContextId)) {
			HookRegistry::register('Form::config::after', [$this, 'loadButton']);
			HookRegistry::register('LoadHandler', array($this, 'setPageHandler'));
		}
		return true;
	}

	private $primaryLocale;

	public function loadButton($hookName, $args)
	{
		$request = Application::get()->getRequest();


		$templateMgr = TemplateManager::getManager($request);

		$fileImport =  $request->getBaseUrl() . DIRECTORY_SEPARATOR . 'index.php' . DIRECTORY_SEPARATOR . $request->getRequestedJournalPath() . DIRECTORY_SEPARATOR . 'importMetadataFromXML';
		$scriptCode = '
		document.addEventListener("DOMContentLoaded", () => {
			const menu = document.querySelector(".pkpPublication__tabs .pkpTabs__buttons");

			if (menu) {
				const buttonImport = document.createElement("button");
				buttonImport.innerText = "' . __('plugins.generic.importMetadata.name') . '";
				buttonImport.setAttribute("aria-controls", "Import");
				buttonImport.id = "importMetadata-button";
				buttonImport.className = "pkpTabs__button";

				buttonImport.addEventListener("click", () => {
					if (confirm("' . __('plugins.generic.importMetadata.confirmImport') . '")) {
						fetch("' . $fileImport . '?submissionId="+document.querySelector(".pkpWorkflow__identificationId").innerText)
							.then(response => response.json())
							.then(data => {
								if(!alert(data)){window.location.reload();}
							});
					}

				});
				menu.append(buttonImport);
			}
		});
		';



		$templateMgr->addJavaScript(
			'importMetadataFromXML',
			$scriptCode,
			[
				'contexts' => 'backend',
				'priority' => STYLE_SEQUENCE_CORE,
				'inline'   => true,
			]
		);
	}

	public function setPageHandler($hookName, $params)
	{
		$page = $params[0];
		if ($page == 'importMetadataFromXML') {
			$submissionDao = DAORegistry::getDAO('SubmissionDAO');
			$publicationDao = DAORegistry::getDAO('PublicationDAO');
			$submission = $submissionDao->getById($_GET['submissionId']);
			$publication = $submission->getCurrentPublication();

			$submissionGalleyDao = DAORegistry::getDAO('SubmissionFileDAO');
			$submissionGalleys = $submission->getGalleys();

			$xmlSubmissionFile = null;
			foreach ($submissionGalleys as $submissionGalley) {
				$submissionFile = $submissionGalley->getFile();
				if ($submissionFile->getData('mimetype') === 'text/xml') {
					$xmlSubmissionFile = $submissionFile;
					break;
				}
			}

			if (!$xmlSubmissionFile) {
				echo json_encode(__('plugins.generic.importMetadata.notGalley'));
				die;
			}

			$contents = Services::get('file')->fs->read($submissionFile->getData('path'));


			$dom = new DOMDocument();
			$dom->loadXML($contents);


			$primaryLanguage = 'es_ES';
			$secondaryaLanguage = 'en_US';

			$pattern = '/xml:lang="([^"]+)"/';
			if (preg_match($pattern, $contents, $matches)) {
				$iso1Code = $matches[1];
				$iso3Code = PKPLocale::getIso3FromIso1($iso1Code);
				$primaryLanguage = PKPLocale::getLocaleFromIso3($iso3Code);
				$secondaryLanguage = ($primaryLanguage === 'en_US') ? 'es_ES' : 'en_US';
			}

			$this->primaryLocale = $primaryLanguage;

			$warnings = [];


			$front = $dom->getElementsByTagName('front')->item(0);
			$articleMeta = $front->getElementsByTagName('article-meta')->item(0);


			$dato = $articleMeta->getElementsByTagName('title-group')->item(0)->getElementsByTagName('article-title')->item(0)->nodeValue;
			$publication->setData('title', $dato, $primaryLanguage);

			$dato = @$articleMeta->getElementsByTagName('title-group')->item(0)->getElementsByTagName('subtitle')->item(0)->nodeValue;
			$publication->setData('subtitle', $dato, $primaryLanguage);

			if ($articleMeta->getElementsByTagName('trans-title-group')->count()) {
				$dato = @$articleMeta->getElementsByTagName('trans-title-group')->item(0)->getElementsByTagName('trans-title')->item(0)->nodeValue;
				$publication->setData('title', $dato, $secondaryaLanguage);
				$dato = @$articleMeta->getElementsByTagName('trans-title-group')->item(0)->getElementsByTagName('trans-subtitle')->item(0)->nodeValue;
				$publication->setData('subtitle', $dato, $secondaryaLanguage);
			}

			$abstractNode = @$articleMeta->getElementsByTagName('abstract')->item(0);
			if ($abstractNode) {
				$abstractNode->removeChild($abstractNode->getElementsByTagName('title')->item(0));
				$publication->setData('abstract', $abstractNode->nodeValue, $primaryLanguage);
			}
			if ($articleMeta->getElementsByTagName('trans-abstract')->count()) {
				$element = $articleMeta->getElementsByTagName('trans-abstract')->item(0);
				$element->removeChild($element->getElementsByTagName('title')->item(0));
				$publication->setData('abstract', $element->nodeValue, $secondaryaLanguage);
			}

			$authorDao = DAORegistry::getDAO('AuthorDAO');
			$authors = $submission->getAuthors();
			foreach ($authors as $author) {
				$authorDao->deleteObject($author);
			}

			$contribGroup = @$articleMeta->getElementsByTagName('contrib-group')->item(0);

			$primaryContactAuthor = false;
			$cont = 0;
			$request = Application::get()->getRequest();
			$userGroupDao = DAORegistry::getDAO('UserGroupDAO'); /* @var $userGroupDao UserGroupDAO */
			$authorUserGroups = $userGroupDao->getByRoleId($request->getContext()->getId(), ROLE_ID_AUTHOR)->toArray();

			$userGroupId = null;
			foreach ($authorUserGroups as $authorUserGroup) {
				if ($authorUserGroup->getAbbrev('en_US') === 'AU') {
					$userGroupId = $authorUserGroup->getId();
					continue;
				}
			}


			foreach ($contribGroup->getElementsByTagName('contrib') as $contrib) {
				$newAuthor = $authorDao->newDataObject();
				$newAuthor->setData('publicationId', $publication->getId());
				$newAuthor->setData('seq', $cont);

				$primaryContact = @$contrib->getAttribute('corresp');

				if (!empty($primaryContact) && $primaryContact === 'yes') {
					$newAuthor->setPrimaryContact(true);
				}

				$data = $contrib->getElementsByTagName('name')->item(0)->getElementsByTagName('given-names')->item(0)->nodeValue;
				$data = mb_convert_case(mb_strtolower($data), MB_CASE_TITLE, 'UTF-8');
				$newAuthor->setGivenName([$this->primaryLocale => $data], null);
				$newAuthor->setGivenName(['es_ES' => $data], null);


				$data = $contrib->getElementsByTagName('name')->item(0)->getElementsByTagName('surname')->item(0)->nodeValue;
				$data = mb_convert_case(mb_strtolower($data), MB_CASE_TITLE, 'UTF-8');
				$newAuthor->setFamilyName([$this->primaryLocale => $data], null);
				$newAuthor->setFamilyName(['es_ES' => $data], null);

				$data = @$contrib->getElementsByTagName('bio')->item(0)->nodeValue;
				$newAuthor->setBiography([$this->primaryLocale => $data], null);


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
						foreach (@$contribGroup->getElementsByTagName('aff') as $aff) {
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
				$newAuthor->setAffiliation([$this->primaryLocale => $affiliation], null);
				$newAuthor->setOrcid($orcid);


				$newAuthor->setUserGroupId($userGroupId);

				/*
				$newAuthor->setUrl($this->getData('userUrl'));
				$newAuthor->setIncludeInBrowse(($this->getData('includeInBrowse') ? true : false));
				*/


				//Cambiar o autor principal da publicacion
				$authorId = $authorDao->insertObject($newAuthor);
				if ($newAuthor->getPrimaryContact()) {
					$primaryContactAuthor = $authorId;
				}
				$cont++;
			}

			if ($primaryContactAuthor) {
				$publication->setData('primaryContactId', $primaryContactAuthor);
			}
			$articlesId = @$articleMeta->getElementsByTagName('article-id');
			foreach ($articlesId as $id) {
				if ($id->getAttribute('pub-id-type') === 'doi') {
					$publication->setData('pub-id::doi', $id->nodeValue);
				}
			}

			/*
			$volume = @$articleMeta->getElementsByTagName('volume')->item(0)->nodeValue;
			$publication->setData('volume', $volume);

			$issue = @$articleMeta->getElementsByTagName('issue')->item(0)->nodeValue;
			$publication->setData('issueId', $issue);
			*/

			$page = @$articleMeta->getElementsByTagName('elocation-id')->item(0)->nodeValue;
			if (empty($page)) {
				$page = @$articleMeta->getElementsByTagName('fpage')->item(0)->nodeValue;
				$endPage = @$articleMeta->getElementsByTagName('lpage')->item(0)->nodeValue;
				if ($endPage) {
					$page .= '-' . $endPage;
				}
			}
			$publication->setData('pages', $page);

			$pubDate = $articleMeta->getElementsByTagName('pub-date')->item(0);
			$dayPublished = '01';
			if ($pubDate->getElementsByTagName('day')->count()) {
				$dayPublished = $pubDate->getElementsByTagName('day')->item(0)->nodeValue;
			}
			$monthPublished = @$pubDate->getElementsByTagName('month')->item(0)->nodeValue;
			$yearPublished = @$pubDate->getElementsByTagName('year')->item(0)->nodeValue;
			$publication->setData('datePublished', $yearPublished . '-' . $monthPublished . '-' . $dayPublished);




			$licenses = $articleMeta->getElementsByTagName('permissions')->item(0)->getElementsByTagName('license');
			foreach ($licenses as $li) {
				if ($li->getAttribute('license-type') === 'open-access') {
					$publication->setData('licenseUrl', $li->getAttribute('xlink:href'));
				}
			}

			if ($dom->getElementsByTagName('back')->count()) {
				$citations = [];
				$mixedCitations = $dom->getElementsByTagName('back')->item(0)->getElementsByTagName('mixed-citation');
				foreach ($mixedCitations as $citation) {
					$citations[] = $citation->nodeValue;
				}
				$publication->setData('citationsRaw', implode("\n", $citations));
			}

			$publicationDao->updateObject($publication);



			$keywordsGroups = @$articleMeta->getElementsByTagName('kwd-group');
			$localeKeywords = [];
			foreach ($keywordsGroups as $keywordsGroup) {
				$locale = $keywordsGroup->getAttribute('xml:lang');
				$keywords = [];
				foreach ($keywordsGroup->getElementsByTagName('kwd') as $keywordNode) {
					$keywords[] = $keywordNode->nodeValue;
				}
				$localeKeywords[$this->transformLocale($locale)] = $keywords;
			}
			$submissionKeywordDao = DAORegistry::getDAO('SubmissionKeywordDAO');
			$submissionKeywordDao->insertKeywords($localeKeywords, $publication->getId());


			$foundingGroups = @$front->getElementsByTagName('funding-group');
			$localeFoundings = [];
			foreach ($foundingGroups as $foundingGroup) {
				$institution = $foundingGroup->getElementsByTagName('institution')->item(0)->nodeValue;
				$localeFoundings[$this->primaryLocale][] = $institution;
			}
			$submissionAgencyDao = DAORegistry::getDAO('SubmissionAgencyDAO');
			$submissionAgencyDao->insertAgencies($localeFoundings, $publication->getId());



			$return = __('plugins.generic.importMetadata.importSuccessful');
			foreach ($warnings as $warning) {
				$return .= "\n" . $warning;
			}
			$salida = json_encode($return);
			ob_start();
			echo $salida;
			ob_flush();
			die;
		}
	}

	private function transformLocale($locale)
	{

		$transformLocale = [
			'es' => 'es_ES',
			'en' => 'en_US'
		];

		if (isset($transformLocale[$locale])) {
			return $transformLocale[$locale];
		}

		return $this->primaryLocale;
	}
}
