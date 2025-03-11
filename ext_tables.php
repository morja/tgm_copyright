<?php

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\ApplicationType;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\GeneralUtility;

if (!defined('TYPO3')) {
    die('Access denied.');
}

(function (): void {
    if (true === (bool)$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['tgm_copyright']['copyrightRequired']
        && (
            !(($GLOBALS['TYPO3_REQUEST'] ?? null) instanceof ServerRequestInterface)
            || false === ApplicationType::fromRequest($GLOBALS['TYPO3_REQUEST'])->isFrontend()
        )
    ) {
        try {
            $pageRenderer = GeneralUtility::makeInstance(PageRenderer::class);
            $pageRenderer->loadJavaScriptModule('@tgm/tgm-copyright/required-file-reference-fields.js');
        } catch (Exception) {
        }
    }
})();
