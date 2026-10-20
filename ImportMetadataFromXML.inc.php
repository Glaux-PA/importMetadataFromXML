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





use APP\facades\Repo;
use PKP\plugins\GenericPlugin;
use PKP\security\Role;


class ImportMetadataFromXML extends GenericPlugin
{
	/**
	 * @copydoc Plugin::getDisplayName()
	 */
	public function getDisplayName()
	{
		return __('plugins.generic.importMetadata.displayName');
	}

	/**
	 * @copydoc Plugin::getDescription()
	 */
	public function getDescription()
	{
		return __('plugins.generic.importMetadata.description');
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

		if (!$context || !$user) {
			return false;
		}

		$fileImport = $request->getDispatcher()->url(
			$request,
			Application::ROUTE_PAGE,
			$context->getPath(),
			'importMetadataFromXML',
			'import'
		);

		$userGroups = Repo::userGroup()
			->userUserGroups($user->getId(), $context->getId());

		$allowedRoles = [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR];
		$hasRequiredRole = false;
		foreach ($userGroups as $userGroup) {
			if (in_array($userGroup->roleId, $allowedRoles)) {
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
					var body = new URLSearchParams();
					body.append("submissionId", submissionId);
					body.append("csrfToken", (window.pkp && pkp.currentUser) ? pkp.currentUser.csrfToken : "");
					fetch(importUrl, {
						method: "POST",
						credentials: "same-origin",
						body: body
					})
						.then(function (response) { return response.json(); })
						.then(function (data) {
							window.alert(data.content);
							if (data.status) {
								window.location.reload();
							}
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

		if ($page !== 'importMetadataFromXML') {
			return false;
		}

		$op = &$params[1];
		$handler = &$params[3];

		require_once __DIR__ . '/ImportMetadataHandler.php';

		$op = 'import';
		$handler = new ImportMetadataHandler();

		return true;
	}

}
