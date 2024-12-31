<?php

/**
 * Processes the funding-groups and assigns the funding-statement to the available languages.
 *
 * @param DOMNodeList $fundingGroups List of funding-group nodes.
 * @param array $allLanguages List of all available languages.
 * @return array Funding statements organized by language.
 */
function fundingParse($fundingGroups, $allLanguages){
	$localeFoundings = [];
	$fundingStatement = null;
	
	// Extract funding-statement
	foreach ($fundingGroups as $fundingGroup) {
		$statementNodes = $fundingGroup->getElementsByTagName('funding-statement');
		if ($statementNodes->length > 0) {
			$fundingStatement = trim($statementNodes->item(0)->nodeValue);
			break;
		}
	}
	
	// If there is a funding-statement, assign it to the corresponding languages
	if ($fundingStatement) {
		foreach ($allLanguages as $lang) {
			if (!isset($localeFoundings[$lang]) || empty($localeFoundings[$lang])) {
				$localeFoundings[$lang][] = $fundingStatement;
			}
		}
	}
	
	return $localeFoundings;
}
?>
