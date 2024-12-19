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
require_once __DIR__ . '/helpers/AbstractProcessor.php';
require_once __DIR__ . '/helpers/TextFormater.php';
require_once __DIR__ . '/helpers/LocaleHelper.php';

use APP\facades\Repo;
use APP\author\Author;
use PKP\security\Role;

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
		$context = $request->getContext();

		$user = $request->getUser();
		if ($context) {
			$contextPath = $context->getPath();
			$fileImport = $request->getBaseUrl() . DIRECTORY_SEPARATOR . 'index.php' . DIRECTORY_SEPARATOR . $contextPath . '/importMetadataFromXML';
		} else {
			$fileImport = $request->getBaseUrl() . '/index.php/importMetadataFromXML';
		}

		if (!$user) {
			return false;
		}
		$userGroups = Repo::userGroup()
        ->getCollector()
        ->filterByUserIds([$user->getId()])
        ->filterByContextIds([$context->getId()])
        ->getMany();

		// Verificar si el usuario tiene el rol requerido (por ejemplo, ROLE_ID_MANAGER)
		$hasRequiredRole = false;
		foreach ($userGroups as $userGroup) {
			if ($userGroup->getRoleId() === ROLE_ID_MANAGER) {
				$hasRequiredRole = true;
				break;
			}
		}
		if ($hasRequiredRole) {
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
						const submissionId = document.querySelector(".pkpWorkflow__identificationId").innerText;
						const fetchUrl = "' . $fileImport . '?submissionId=" + submissionId;
						
						if (confirm("' . __('plugins.generic.importMetadata.confirmImport') . '")) {
							fetch(fetchUrl)
								.then(response => response.json())
								.then(data => {
									if (!alert(data)) {
										window.location.reload();
									}
								})
								.catch(error => {
									console.error("Error in request:", error);
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
		} else {
			// No mostrar el botón si no tiene el rol requerido
			return false;
		}		


	}

	public function setPageHandler($hookName, $params)
	{
		$page = $params[0];
		if ($page == 'importMetadataFromXML') {

			$submissionId = $_GET['submissionId'];
        
			$submission = Repo::submission()->get($submissionId);	
			if (!$submission) {
				throw new Exception("Submission not found, ID: $submissionId");
			}
			$publication = $submission->getCurrentPublication();
			if (!$publication) {
				throw new Exception("Publication for submission not found, submissionID: $submissionId");
			}
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

			$primaryLanguage = 'es';
			$secondaryaLanguage = 'en';

			$pattern = '/xml:lang="([^"]+)"/';
			if (preg_match($pattern, $contents, $matches)) {
				$primaryLanguage = $matches[1];
				$secondaryLanguage = ($primaryLanguage === 'en') ? 'es' : 'en';
			}
			
			$this->primaryLocale = $primaryLanguage;

			//Generic method for manage locales
			$detectedLocales = detectLocales($dom);

			if (empty($detectedLocales)) {
				$detectedLocales[] = $this->primaryLocale;
			}

			$warnings = [];

			$front = $dom->getElementsByTagName('front')->item(0);
			$articleMeta = $front->getElementsByTagName('article-meta')->item(0);

			$dato = $articleMeta->getElementsByTagName('title-group')->item(0)->getElementsByTagName('article-title')->item(0)->nodeValue;
			$publication->setData('title', $dato, $primaryLanguage);

			$dato = @$articleMeta->getElementsByTagName('title-group')->item(0)->getElementsByTagName('subtitle')->item(0)->nodeValue;
			$publication->setData('subtitle', $dato, $primaryLanguage);

			if ($articleMeta->getElementsByTagName('trans-title-group')->count()) {
				$transTitleGroup = $articleMeta->getElementsByTagName('trans-title-group')->item(0);
				foreach ($transTitleGroup->getElementsByTagName('trans-title') as $transTitle) {
					$lang = $transTitle->getAttribute('xml:lang') ?: $secondaryLanguage;
					$dato = $transTitle->nodeValue;
					$publication->setData('title', $dato, $lang);
				}
				foreach ($transTitleGroup->getElementsByTagName('trans-subtitle') as $transSubtitle) {
					$lang = $transSubtitle->getAttribute('xml:lang') ?: $secondaryLanguage;
					$dato = $transSubtitle->nodeValue;
					$publication->setData('subtitle', $dato, $lang);
				}
			}


			/***
			 * Abstract
			 */
			 $abstractNode = @$articleMeta->getElementsByTagName('abstract')->item(0);

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
				 $publication->setData('abstract', $abstractContent, $primaryLanguage);
			 }

			$transAbstracts = $articleMeta->getElementsByTagName('trans-abstract');
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
						$publication->setData('abstract', $transAbstractContent, $locale);
					}
				}		
			}	
			
			/***
			 * Authors
			 */
			$authors = $publication->getData('authors');

			foreach ($authors as $author) {
				Repo::author()->delete($author);
			}

			$contribGroup = @$articleMeta->getElementsByTagName('contrib-group')->item(0);

			$primaryContactAuthor = false;
			$cont = 0;
			$request = Application::get()->getRequest();
			$contextId = $request->getContext()->getId();
			$authorUserGroups = Repo::userGroup()
				->getCollector()
				->filterByContextIds([$contextId])
				->filterByRoleIds([ROLE_ID_AUTHOR])
				->getMany();
			
			$userGroupId = null;
			foreach ($authorUserGroups as $authorUserGroup) {
				if ($authorUserGroup->getAbbrev('en') === 'AU') {
					$userGroupId = $authorUserGroup->getId();
					//error_log("authorUserGroupID: " . print_r($authorUserGroup->getId(), true));
					continue;
				}
			}

			foreach ($contribGroup->getElementsByTagName('contrib') as $contrib) {

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
				foreach ($detectedLocales as $locale) {
					$localizedData[$locale] = $data;
				}
				$newAuthor->setGivenName($localizedData, null);


				$data = $contrib->getElementsByTagName('name')->item(0)->getElementsByTagName('surname')->item(0)->nodeValue;
				$data = mb_convert_case(mb_strtolower($data), MB_CASE_TITLE, 'UTF-8');
				$localizedData = [];
				foreach ($detectedLocales as $locale) {
					$localizedData[$locale] = $data;
				}
				$newAuthor->setFamilyName($localizedData, null);

				$data = @$contrib->getElementsByTagName('bio')->item(0)->nodeValue;
				$localizedData = [];
				foreach ($detectedLocales as $locale) {
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
				$localizedData = [];
				foreach ($detectedLocales as $locale) {
					$localizedData[$locale] = $affiliation;
				}
				$newAuthor->setAffiliation($localizedData, null);				
				
				$newAuthor->setOrcid($orcid);

				$newAuthor->setUserGroupId($userGroupId);

				$authorId = Repo::author()->add($newAuthor);

				if ($newAuthor->getPrimaryContact()) {
					$primaryContactAuthor = $authorId;
				}
				$cont++;
			}

			if ($primaryContactAuthor) {
				$publication->setData('primaryContactId', $primaryContactAuthor);
			}

			/***
			 * DOI
			 */
			$articlesId = @$articleMeta->getElementsByTagName('article-id');
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

			/***
			* References
			*/
			if ($dom->getElementsByTagName('back')->count()) {
				$citations = [];
				$mixedCitations = $dom->getElementsByTagName('back')->item(0)->getElementsByTagName('mixed-citation');
				
				foreach ($mixedCitations as $citation) {
					$citationContent = normalizeSpaces(getNodeText($citation));
					$citations[] = $citationContent;
				}
				$publication->setData('citationsRaw', implode("\n", $citations));
			}


			$currentUser = Application::get()->getRequest()->getUser();
			$userId = $currentUser ? $currentUser->getId() : 1; // Use 1 as default

			Repo::publication()->edit($publication, ['userId' => $userId]);

			/***
			 * Keywords
			 */
			$keywordsGroups = @$articleMeta->getElementsByTagName('kwd-group');
			$localeKeywords = [];
			$detectedLocales = [];
			foreach ($keywordsGroups as $keywordsGroup) {
				//TODO refactor for multiple uses and cases
				$locale = $keywordsGroup->getAttribute('xml:lang');
				$keywords = [];
				$isJELGroup = false;

				if (!empty($locale) && !in_array($locale, $detectedLocales)) {
					$detectedLocales[] = $locale;
				}				
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
				}else{
					foreach ($keywordsGroup->getElementsByTagName('kwd') as $keywordNode) {
						$keywords[] = trim($keywordNode->nodeValue);
					}
					if (!empty($keywords)) {
						if (!isset($localeKeywords[$locale])) {
							$localeKeywords[$locale] = [];
						}
						$localeKeywords[$locale] = array_merge($localeKeywords[$locale], $keywords);
					}
				}
			}
			if (!empty($jelCodes)) {
				if (empty($localeKeywords)) {
					foreach ($detectedLocales as $locale) {
						$localeKeywords[$locale] = $jelCodes;
					}
				} else {
					foreach ($localeKeywords as $lang => &$keywords) {
						$keywords = array_merge($keywords, $jelCodes);
					}
					unset($keywords);
				}
			}

			$submissionKeywordDao = DAORegistry::getDAO('SubmissionKeywordDAO');
			$submissionKeywordDao->insertKeywords($localeKeywords, $publication->getId());

			/****
			 * Founding
			 */

			$fundingGroups = @$front->getElementsByTagName('funding-group');
			$localeFoundings = [];
			$fundingStatement = null;
			
			foreach ($fundingGroups as $fundingGroup) {
				$statementNodes = $fundingGroup->getElementsByTagName('funding-statement');
				if ($statementNodes->length > 0) {
					$fundingStatement = trim($statementNodes->item(0)->nodeValue);
					break;
				}
			}
			
			if ($fundingStatement) {
				$detectedLocales = [];
			
				$xpath = new DOMXPath($front->ownerDocument);
				$nodesWithLang = $xpath->query('//*[@xml:lang]');
			
				foreach ($nodesWithLang as $node) {
					//TODO refactor for multiple uses
					$locale = $node->getAttribute('xml:lang');
					if (!empty($locale) && !in_array($locale, $detectedLocales)) {
						$detectedLocales[] = $locale;
					}
				}
			
				if (empty($detectedLocales)) {
					$detectedLocales[] = $this->primaryLocale;
				}
			
				foreach ($detectedLocales as $locale) {
					$localeFoundings[$locale][] = $fundingStatement;
				}
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

	//TODO review Deprecated function
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
