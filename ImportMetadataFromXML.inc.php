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
require_once __DIR__ . '/XmlParsers/AuthorParser.php';
require_once __DIR__ . '/XmlParsers/FundingParser.php';
require_once __DIR__ . '/XmlParsers/KeywordsParser.php';
require_once __DIR__ . '/XmlParsers/AbstractParser.php';

require_once __DIR__ . '/helpers/AbstractProcessor.php';
require_once __DIR__ . '/helpers/TextFormater.php';

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
		$userGroupDao = DAORegistry::getDAO('UserGroupDAO'); // Obtener el DAO de grupos de usuario
		$userGroups = $userGroupDao->getByUserId($user->getId(), $context->getId())->toArray();


		//Add security to hide the button based on user role, ROLE_ID_MANAGER
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
		} else {
			return false;
		}				
	}

	public function setPageHandler($hookName, $params)
	{
		$page = $params[0];
		$request = Application::get()->getRequest();
		$contextId = $request->getContext()->getId();
		$context = $request->getContext();
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

			
			$rootElement = $dom->documentElement;
			$xmlLang = $rootElement->getAttribute('xml:lang');

			if (!empty($xmlLang)) {
				$iso3Code = PKPLocale::getIso3FromIso1($xmlLang);
				$language = PKPLocale::getLocaleFromIso3($iso3Code);
				$primaryLanguage = $language;
			}else{
				$primaryLanguage = $context ? $context->getPrimaryLocale() : null;
			}

			$supportedLanguages = $context ? $context->getSupportedSubmissionLocales() : [];

			$xpath = new DOMXPath($dom);
			$nodesWithLang = $xpath->query('//front//*[@xml:lang]');			
			$xmlLanguages = [];
			foreach ($nodesWithLang as $node) {
				$lang = $node->getAttribute('xml:lang');
				$iso3Code = PKPLocale::getIso3FromIso1($lang);
				$language = PKPLocale::getLocaleFromIso3($iso3Code);
				if ($language && !in_array($language, $xmlLanguages)) {
					$xmlLanguages[] = $language;
				}
			}
			
			$allLanguages = array_unique(array_merge(
				$primaryLanguage ? [$primaryLanguage] : [],
				$supportedLanguages,
				$xmlLanguages
			));			

			$warnings = [];

			$front = $dom->getElementsByTagName('front')->item(0);

			$articleMeta = $front->getElementsByTagName('article-meta')->item(0);
			$title = $articleMeta->getElementsByTagName('title-group')->item(0)->getElementsByTagName('article-title')->item(0)->nodeValue;
			$subtitle = @$articleMeta->getElementsByTagName('title-group')->item(0)->getElementsByTagName('subtitle')->item(0)->nodeValue;
		
			foreach ($allLanguages as $language) {
				$publication->setData('title', $title, $language);
				$publication->setData('subtitle', $subtitle, $language);
			}

			$publication->setData('title', $title, $primaryLanguage);			
			$publication->setData('subtitle', $subtitle, $primaryLanguage);			

			if ($articleMeta->getElementsByTagName('trans-title-group')->count()) {
				$transTitleGroup = $articleMeta->getElementsByTagName('trans-title-group')->item(0);
				foreach ($transTitleGroup->getElementsByTagName('trans-title') as $transTitle) {
					$lang = $transTitle->getAttribute('xml:lang');
					if (!empty($lang)) {
						$iso3Code = PKPLocale::getIso3FromIso1($lang);
						$language = PKPLocale::getLocaleFromIso3($iso3Code);
						$title = $transTitle->nodeValue;
						$publication->setData('title', $title, $language);
					}
				}
				foreach ($transTitleGroup->getElementsByTagName('trans-subtitle') as $transSubtitle) {
					$lang = $transTitle->getAttribute('xml:lang');
					if (!empty($lang)) {
						$iso3Code = PKPLocale::getIso3FromIso1($lang);
						$language = PKPLocale::getLocaleFromIso3($iso3Code);
						$subtitle = $transSubtitle->nodeValue;
						$publication->setData('subtitle', $subtitle, $language);
					}	
				}
			}

			/***
			 * Abstract
			 */
			$abstractNode = @$articleMeta->getElementsByTagName('abstract')->item(0);

			abstractParse($abstractNode, $allLanguages, $primaryLanguage, $publication);

			$transAbstracts = $articleMeta->getElementsByTagName('trans-abstract');
			transAbstractParse($transAbstracts, $publication);

			/***
			 * Authors
			 */
			$authorDao = DAORegistry::getDAO('AuthorDAO');
			$authors = $publication->getData('authors');
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
				$newAuthor = authorParse($contrib, $allLanguages, $publication, $request, $userGroupId, $cont);

				$authorId = $authorDao->insertObject($newAuthor);

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

			$publicationDao->updateObject($publication);

			/***
			 * Keywords
			 */
			$keywordsGroups = @$articleMeta->getElementsByTagName('kwd-group');

			$localeKeywords = keywordsParse($keywordsGroups, $allLanguages, $primaryLanguage);
			
			$submissionKeywordDao = DAORegistry::getDAO('SubmissionKeywordDAO');
			$submissionKeywordDao->insertKeywords($localeKeywords, $publication->getId());

			/****
			 * Funding
			 */
			$fundingGroups = @$front->getElementsByTagName('funding-group');
			$localeFoundings = fundingParse($fundingGroups, $allLanguages);
			
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

}
