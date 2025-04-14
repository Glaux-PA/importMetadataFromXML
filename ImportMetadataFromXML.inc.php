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

require_once __DIR__ . '/XmlParsers/FundingParser.php';
require_once __DIR__ . '/XmlParsers/KeywordsParser.php';
require_once __DIR__ . '/XmlParsers/AbstractParser.php';
require_once __DIR__ . '/XmlParsers/DOIParser.php';
require_once __DIR__ . '/XmlParsers/AuthorParser.php';


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

		if (!parent::register($category, $path, $mainContextId))
			return false;

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
					'inline' => true,
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
		error_log($page);
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

			$rootElement = $dom->documentElement;
			$xmlLang = $rootElement->getAttribute('xml:lang');

			if (!empty($xmlLang)) {
				$primaryLanguage = $xmlLang;
			} else {
				$primaryLanguage = $context ? $context->getPrimaryLocale() : null;
			}

			$supportedLanguages = $context ? $context->getSupportedSubmissionLocales() : [];

			$xpath = new DOMXPath($dom);
			$nodesWithLang = $xpath->query('//front//*[@xml:lang]');

			$xmlLanguages = [];
			foreach ($nodesWithLang as $node) {
				$lang = $node->getAttribute('xml:lang');
				if ($lang && !in_array($lang, $xmlLanguages)) {
					$xmlLanguages[] = $lang;
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

			$titleNode = $articleMeta->getElementsByTagName('title-group')->item(0)->getElementsByTagName('article-title')->item(0);
			$title = $titleNode ? $titleNode->nodeValue : '';
			$titleLang = $titleNode && $titleNode->hasAttribute('xml:lang') ? $titleNode->getAttribute('xml:lang') : $primaryLanguage;

			$subtitleNode = $articleMeta->getElementsByTagName('title-group')->item(0)->getElementsByTagName('subtitle')->item(0);
			$subtitle = $subtitleNode ? $subtitleNode->nodeValue : '';
			$subtitleLang = $subtitleNode && $subtitleNode->hasAttribute('xml:lang') ? $subtitleNode->getAttribute('xml:lang') : $primaryLanguage;

			$localeTitles = [];
			$localeSubtitles = [];
			if ($title && $titleLang) {
				$localeTitles[$titleLang] = $title;
			}
			if ($subtitle && $subtitleLang) {
				$localeSubtitles[$subtitleLang] = $subtitle;
			}

			if ($articleMeta->getElementsByTagName('trans-title-group')->count()) {
				foreach ($articleMeta->getElementsByTagName('trans-title-group') as $transTitleGroup) {
					$transTitle = $transTitleGroup->getElementsByTagName('trans-title')->item(0);
					$transSubtitle = $transTitleGroup->getElementsByTagName('trans-subtitle')->item(0);
					$lang = $transTitle->hasAttribute('xml:lang') ? $transTitle->getAttribute('xml:lang') : null;
					if ($lang && $transTitle) {
						$localeTitles[$lang] = $transTitle->nodeValue;
					}
					if ($lang && $transSubtitle) {
						$localeSubtitles[$lang] = $transSubtitle->nodeValue;
					}
				}
			}



			foreach ($allLanguages as $lang) {

				$titleValue = isset($localeTitles[$lang]) ? $localeTitles[$lang] : ($localeTitles[$primaryLanguage] ?? '');
				$subtitleValue = isset($localeSubtitles[$lang]) ? $localeSubtitles[$lang] : ($localeSubtitles[$primaryLanguage] ?? '');

				if ($titleValue) {
					$publication->setData('title', $titleValue, $lang);
				}
				if ($subtitleValue) {
					$publication->setData('subtitle', $subtitleValue, $lang);
				}
			}
			/***
			 * Abstract
			 */
			$abstractNode = $articleMeta->getElementsByTagName('abstract')->item(0);
			$transAbstracts = $articleMeta->getElementsByTagName('trans-abstract');

			$localeAbstracts = abstractParse($abstractNode, $allLanguages, $primaryLanguage, $publication);
			$transLocaleAbstracts = transAbstractParse($transAbstracts, $publication);

			$allAbstracts = array_merge($localeAbstracts, $transLocaleAbstracts);
			assignAbstracts($allAbstracts, $allLanguages, $primaryLanguage, $publication);

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
			$authorUserGroups = Repo::userGroup()
				->getCollector()
				->filterByContextIds([$contextId])
				->filterByRoleIds([ROLE_ID_AUTHOR])
				->getMany();

			$userGroupId = null;
			foreach ($authorUserGroups as $authorUserGroup) {
				if ($authorUserGroup->getAbbrev('en') === 'AU') {
					$userGroupId = $authorUserGroup->getId();
					continue;
				}
			}

			foreach ($contribGroup->getElementsByTagName('contrib') as $contrib) {
				$newAuthor = authorParse($contrib, $allLanguages, $publication, $request, $userGroupId, $cont);

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
			doiParse($articlesId, $contextId, $publication);

			/*
			 *$volume = @$articleMeta->getElementsByTagName('volume')->item(0)->nodeValue;
			 *$publication->setData('volume', $volume);
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
