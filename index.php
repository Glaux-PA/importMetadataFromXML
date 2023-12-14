<?php

/**
 * @defgroup plugins_generic_acron
 */

/**
 * @file plugins/generic/index.php
 *
 * Copyright (c) 2013-2021 Simon Fraser University
 * Copyright (c) 2000-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @brief Wrapper for importMetadataFromXML plugin
 *
 */

require_once('ImportMetadataFromXML.inc.php');

return new ImportMetadataFromXML();


