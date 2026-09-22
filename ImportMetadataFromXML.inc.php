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
use APP\core\Services;
use PKP\controlledVocab\ControlledVocab;
use PKP\plugins\GenericPlugin;
use PKP\security\Role;
use PKP\userGroup\UserGroup;


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
			$fileImport = $request->getBaseUrl() . '/index.php/' . $contextPath . '/importMetadataFromXML';
		} else {
			$fileImport = $request->getBaseUrl() . '/index.php/importMetadataFromXML';
		}

		if (!$user) {
			return false;
		}
		$userGroups = Repo::userGroup()
			->userUserGroups($user->getId(), $context->getId());

		//Add security to hide the button based on user role, ROLE_ID_MANAGER
		$hasRequiredRole = false;
		foreach ($userGroups as $userGroup) {
			if ($userGroup->roleId == ROLE_ID_MANAGER) {
				$hasRequiredRole = true;
				break;
			}
		}
		if ($hasRequiredRole) {
			$scriptCode = '
			(function () {
				var importUrl = ' . json_encode($fileImport) . ';
				var importLabel = ' . json_encode(__('plugins.generic.importMetadata.name')) . ';
				var confirmText = ' . json_encode(__('plugins.generic.importMetadata.confirmImport')) . ';

				function runImport(submissionId) {
					if (!window.confirm(confirmText)) {
						return;
					}
					fetch(importUrl + "?submissionId=" + submissionId)
						.then(function (response) { return response.json(); })
						.then(function (data) {
							window.alert(data);
							window.location.reload();
						})
						.catch(function (error) {
							console.error("importMetadataFromXML:", error);
						});
				}

				function register() {
					if (!window.pkp || !pkp.registry || !pkp.registry.storeExtend) {
						return false;
					}
					pkp.registry.storeExtend("workflow", function (piniaContext) {
						piniaContext.store.extender.extendFn("getMenuItems", function (menuItems, args) {
							var publication = menuItems.find(function (item) {
								return item.key === "publication";
							});
							if (!publication || !publication.items || !args.submission) {
								return menuItems;
							}
							if (publication.items.some(function (item) { return item.key === "importMetadataFromXML"; })) {
								return menuItems;
							}
							var submissionId = args.submission.id;
							publication.items.push({
								key: "importMetadataFromXML",
								label: importLabel,
								command: function () { runImport(submissionId); }
							});
							return menuItems;
						});
					});
					return true;
				}

				if (!register()) {
					document.addEventListener("DOMContentLoaded", register);
				}
			})();
			';

			$templateMgr->addJavaScript(
				'importMetadataFromXML',
				$scriptCode,
				[
					'contexts' => 'backend',
					'priority' => STYLE_SEQUENCE_LAST,
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
			if (!@$dom->loadXML($contents) || !$dom->documentElement) {
				echo json_encode(__('plugins.generic.importMetadata.notGalley'));
				die;
			}

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

			// Import content only for languages present in the XML, not for every
			// journal locale (don't copy one language into all of them)
			$allLanguages = array_unique(array_merge(
				$primaryLanguage ? [$primaryLanguage] : [],
				$xmlLanguages
			));

			$warnings = [];
			$front = $dom->getElementsByTagName('front')->item(0);
			if (!$front) {
				echo json_encode(__('plugins.generic.importMetadata.notGalley'));
				die;
			}

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
				if (!empty($localeTitles[$lang])) {
					$publication->setData('title', $localeTitles[$lang], $lang);
				}
				if (!empty($localeSubtitles[$lang])) {
					$publication->setData('subtitle', $localeSubtitles[$lang], $lang);
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
			$authorUserGroups = UserGroup::withRoleIds([ROLE_ID_AUTHOR])
				->withContextIds([$contextId])
				->get();

			$userGroupId = null;
			foreach ($authorUserGroups as $authorUserGroup) {
				if (($authorUserGroup->abbrev['en'] ?? null) === 'AU') {
					$userGroupId = $authorUserGroup->id;
					continue;
				}
			}
			$authorNotes = @$articleMeta->getElementsByTagName('author-notes')->item(0);

			$affById = [];
			foreach ($articleMeta->getElementsByTagName('aff') as $affNode) {
				$affId = $affNode->getAttribute('id');
				if ($affId !== '' && !isset($affById[$affId])) {
					$affById[$affId] = $affNode;
				}
			}

			foreach ($contribGroup->getElementsByTagName('contrib') as $contrib) {
				$newAuthor = authorParse($contrib, $allLanguages, $publication, $request, $userGroupId, $cont, $authorNotes, $affById);

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
			$monthPublished = '01';
			if ($pubDate->getElementsByTagName('month')->count()) {
				$monthPublished = $pubDate->getElementsByTagName('month')->item(0)->nodeValue;
			}
			$yearPublished = @$pubDate->getElementsByTagName('year')->item(0)->nodeValue;
			if ($yearPublished) {
				$publication->setData('datePublished', $yearPublished . '-' . $monthPublished . '-' . $dayPublished);
			}

			$permissions = $articleMeta->getElementsByTagName('permissions')->item(0);
			if ($permissions) {
				$licenses = $permissions->getElementsByTagName('license');
				foreach ($licenses as $li) {
					if ($li->getAttribute('license-type') === 'open-access') {
						$publication->setData('licenseUrl', $li->getAttribute('xlink:href'));
					}
				}
			}

			/***
			 * References
			 */
			$editParams = [];
			if ($dom->getElementsByTagName('back')->count()) {
				$citations = [];
				$mixedCitations = $dom->getElementsByTagName('back')->item(0)->getElementsByTagName('mixed-citation');

				foreach ($mixedCitations as $citation) {
					$citationContent = normalizeSpaces(getNodeText($citation));
					$citations[] = $citationContent;
				}
				$editParams['citationsRaw'] = implode("\n", $citations);
			}


			$currentUser = Application::get()->getRequest()->getUser();
			$userId = $currentUser ? $currentUser->getId() : 1; // Use 1 as default

			$editParams['userId'] = $userId;
			Repo::publication()->edit($publication, $editParams);


			/***
			 * Keywords
			 */
			$keywordsGroups = @$articleMeta->getElementsByTagName('kwd-group');

			$localeKeywords = keywordsParse($keywordsGroups, $allLanguages, $primaryLanguage);

			Repo::controlledVocab()->insertBySymbolic(
				ControlledVocab::CONTROLLED_VOCAB_SUBMISSION_KEYWORD,
				$localeKeywords,
				Application::ASSOC_TYPE_PUBLICATION,
				$publication->getId()
			);


			/****
			 * Funding
			 */
			$fundingGroups = @$front->getElementsByTagName('funding-group');

			$localeFoundings = fundingParse($fundingGroups, $allLanguages);

			Repo::controlledVocab()->insertBySymbolic(
				ControlledVocab::CONTROLLED_VOCAB_SUBMISSION_AGENCY,
				$localeFoundings,
				Application::ASSOC_TYPE_PUBLICATION,
				$publication->getId()
			);


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
