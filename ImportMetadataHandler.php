<?php

/**
 * @file plugins/generic/importMetadataFromXML/ImportMetadataHandler.php
 *
 * Copyright (c) 2014-2023 Simon Fraser University
 * Copyright (c) 2000-2021 John Willinsky
 * Copyright (c) 2023 Glaux Publicaciones Académicas S.L.U.
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ImportMetadataHandler
 * @brief Handler for the metadata import, subject to OJS authorization policies.
 */

require_once __DIR__ . '/helpers/AbstractProcessor.php';
require_once __DIR__ . '/helpers/TextFormater.php';
require_once __DIR__ . '/helpers/LocaleHelper.php';

require_once __DIR__ . '/XmlParsers/FundingParser.php';
require_once __DIR__ . '/XmlParsers/KeywordsParser.php';
require_once __DIR__ . '/XmlParsers/AbstractParser.php';
require_once __DIR__ . '/XmlParsers/DOIParser.php';
require_once __DIR__ . '/XmlParsers/AuthorParser.php';

use APP\core\Services;
use APP\facades\Repo;
use APP\handler\Handler;
use PKP\controlledVocab\ControlledVocab;
use PKP\core\JSONMessage;
use PKP\security\authorization\SubmissionAccessPolicy;
use PKP\security\Role;
use PKP\userGroup\UserGroup;

class ImportMetadataHandler extends Handler
{
	/** @copydoc PKPHandler::_isBackendPage */
	public $_isBackendPage = true;

	public function __construct()
	{
		parent::__construct();

		$this->addRoleAssignment(
			[Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR],
			['import']
		);
	}

	/**
	 * @copydoc PKPHandler::authorize()
	 */
	public function authorize($request, &$args, $roleAssignments)
	{
		$this->addPolicy(new SubmissionAccessPolicy($request, $args, $roleAssignments));

		return parent::authorize($request, $args, $roleAssignments);
	}

	/**
	 * Import the publication metadata from the submission's XML galley.
	 *
	 * @param array $args
	 * @param PKPRequest $request
	 */
	public function import($args, $request)
	{
		if ($request->getRequestMethod() !== 'POST' || !$request->checkCSRF()) {
			return new JSONMessage(false, __('form.csrfInvalid'));
		}

		$context = $request->getContext();
		$contextId = $context->getId();

		$submission = $this->getAuthorizedContextObject(Application::ASSOC_TYPE_SUBMISSION);

		$publication = $submission->getCurrentPublication();
		if (!$publication) {
			return new JSONMessage(false, __('plugins.generic.importMetadata.notGalley'));
		}

		$xmlSubmissionFile = null;
		foreach ($submission->getGalleys() as $submissionGalley) {
			$submissionFile = $submissionGalley->getFile();
			if ($submissionFile && $submissionFile->getData('mimetype') === 'text/xml') {
				$xmlSubmissionFile = $submissionFile;
				break;
			}
		}

		if (!$xmlSubmissionFile) {
			return new JSONMessage(false, __('plugins.generic.importMetadata.notGalley'));
		}

		$contents = Services::get('file')->fs->read($xmlSubmissionFile->getData('path'));

		$dom = new DOMDocument();
		if (!@$dom->loadXML($contents) || !$dom->documentElement) {
			return new JSONMessage(false, __('plugins.generic.importMetadata.notGalley'));
		}

		$rootElement = $dom->documentElement;
		$xmlLang = $rootElement->getAttribute('xml:lang');

		if (!empty($xmlLang)) {
			$primaryLanguage = $xmlLang;
		} else {
			$primaryLanguage = $context->getPrimaryLocale();
		}

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
			return new JSONMessage(false, __('plugins.generic.importMetadata.notGalley'));
		}

		$articleMeta = $front->getElementsByTagName('article-meta')->item(0);
		if (!$articleMeta) {
			return new JSONMessage(false, __('plugins.generic.importMetadata.notGalley'));
		}

		$titleGroup = $articleMeta->getElementsByTagName('title-group')->item(0);
		$titleNode = $titleGroup ? $titleGroup->getElementsByTagName('article-title')->item(0) : null;
		$title = $titleNode ? $titleNode->nodeValue : '';
		$titleLang = $titleNode && $titleNode->hasAttribute('xml:lang') ? $titleNode->getAttribute('xml:lang') : $primaryLanguage;

		$subtitleNode = $titleGroup ? $titleGroup->getElementsByTagName('subtitle')->item(0) : null;
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
				$lang = $transTitle && $transTitle->hasAttribute('xml:lang') ? $transTitle->getAttribute('xml:lang') : null;
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
		$contribGroup = $articleMeta->getElementsByTagName('contrib-group')->item(0);

		if ($contribGroup) {
			$authors = $publication->getData('authors');

			foreach ($authors ?? [] as $author) {
				Repo::author()->delete($author);
			}

			$primaryContactAuthor = false;
			$cont = 0;
			$authorUserGroups = UserGroup::withRoleIds([Role::ROLE_ID_AUTHOR])
				->withContextIds([$contextId])
				->get();

			$userGroupId = null;
			foreach ($authorUserGroups as $authorUserGroup) {
				if (($authorUserGroup->abbrev['en'] ?? null) === 'AU') {
					$userGroupId = $authorUserGroup->id;
					break;
				}
			}
			$authorNotes = $articleMeta->getElementsByTagName('author-notes')->item(0);

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

				if ($newAuthor->getPrimaryContact() || !$primaryContactAuthor) {
					$primaryContactAuthor = $authorId;
				}
				$cont++;
			}

			$publication->setData('primaryContactId', $primaryContactAuthor ?: null);
		}

		/***
		 * DOI
		 */
		$articlesId = $articleMeta->getElementsByTagName('article-id');
		doiParse($articlesId, $contextId, $publication);

		$pageNode = $articleMeta->getElementsByTagName('elocation-id')->item(0);
		$page = $pageNode ? $pageNode->nodeValue : '';
		if (empty($page)) {
			$fpageNode = $articleMeta->getElementsByTagName('fpage')->item(0);
			$page = $fpageNode ? $fpageNode->nodeValue : '';
			$lpageNode = $articleMeta->getElementsByTagName('lpage')->item(0);
			if ($page && $lpageNode && $lpageNode->nodeValue) {
				$page .= '-' . $lpageNode->nodeValue;
			}
		}
		if (!empty($page)) {
			$publication->setData('pages', $page);
		}

		$pubDate = $articleMeta->getElementsByTagName('pub-date')->item(0);
		if ($pubDate) {
			$dayPublished = '01';
			if ($pubDate->getElementsByTagName('day')->count()) {
				$dayPublished = $pubDate->getElementsByTagName('day')->item(0)->nodeValue;
			}
			$monthPublished = '01';
			if ($pubDate->getElementsByTagName('month')->count()) {
				$monthPublished = $pubDate->getElementsByTagName('month')->item(0)->nodeValue;
			}
			$yearNode = $pubDate->getElementsByTagName('year')->item(0);
			$yearPublished = $yearNode ? $yearNode->nodeValue : null;
			if ($yearPublished) {
				$publication->setData('datePublished', $yearPublished . '-' . $monthPublished . '-' . $dayPublished);
			}
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

		Repo::publication()->edit($publication, $editParams);

		/***
		 * Keywords
		 */
		$keywordsGroups = $articleMeta->getElementsByTagName('kwd-group');

		$localeKeywords = keywordsParse($keywordsGroups, $allLanguages, $primaryLanguage);
		$localeKeywords = array_merge(Repo::controlledVocab()->getBySymbolic(ControlledVocab::CONTROLLED_VOCAB_SUBMISSION_KEYWORD, Application::ASSOC_TYPE_PUBLICATION, $publication->getId()), $localeKeywords);

		Repo::controlledVocab()->insertBySymbolic(
			ControlledVocab::CONTROLLED_VOCAB_SUBMISSION_KEYWORD,
			$localeKeywords,
			Application::ASSOC_TYPE_PUBLICATION,
			$publication->getId()
		);

		/****
		 * Funding
		 */
		$fundingGroups = $front->getElementsByTagName('funding-group');

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

		return new JSONMessage(true, $return);
	}
}
