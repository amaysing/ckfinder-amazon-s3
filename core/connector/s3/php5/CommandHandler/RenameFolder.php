<?php
/*
 * CKFinder
 * ========
 * http://cksource.com/ckfinder
 * Copyright (C) 2007-2013, CKSource - Frederico Knabben. All rights reserved.
 *
 * The software, this file and its contents are subject to the CKFinder
 * License. Please read the license.txt file before using, installing, copying,
 * modifying or distribute this file or part of its contents. The contents of
 * this file is part of the Source Code of CKFinder.
 */
if (!defined('IN_CKFINDER')) exit;

/**
 * @package CKFinder
 * @subpackage CommandHandlers
 * @copyright CKSource - Frederico Knabben
 */

/**
 * Include base XML command handler
 */
require_once CKFINDER_CONNECTOR_LIB_DIR . "/CommandHandler/XmlCommandHandlerBase.php";

/**
 * Handle RenameFolder command
 *
 * @package CKFinder
 * @subpackage CommandHandlers
 * @copyright CKSource - Frederico Knabben
 */
class CKFinder_Connector_CommandHandler_RenameFolder extends CKFinder_Connector_CommandHandler_XmlCommandHandlerBase
{
    /**
     * Command name
     *
     * @access private
     * @var string
     */
    private $command = "RenameFolder";


    /**
     * handle request and build XML
     * @access protected
     *
     */
    protected function buildXml()
    {
        if (empty($_POST['CKFinderCommand']) || $_POST['CKFinderCommand'] != 'true') {
            $this->_errorHandler->throwError(CKFINDER_CONNECTOR_ERROR_INVALID_REQUEST);
        }

        if (!$this->_currentFolder->checkAcl(CKFINDER_CONNECTOR_ACL_FOLDER_RENAME)) {
            $this->_errorHandler->throwError(CKFINDER_CONNECTOR_ERROR_UNAUTHORIZED);
        }

        if (!isset($_GET["NewFolderName"])) {
            $this->_errorHandler->throwError(CKFINDER_CONNECTOR_ERROR_INVALID_NAME);
        }

        $newFolderName = CKFinder_Connector_Utils_FileSystem::convertToFilesystemEncoding($_GET["NewFolderName"]);
        $_config =& CKFinder_Connector_Core_Factory::getInstance("Core_Config");
        if ($_config->forceAscii()) {
            $newFolderName = CKFinder_Connector_Utils_FileSystem::convertToAscii($newFolderName);
        }
        $resourceTypeInfo = $this->_currentFolder->getResourceTypeConfig();

        if (!CKFinder_Connector_Utils_FileSystem::checkFolderName($newFolderName) || $resourceTypeInfo->checkIsHiddenFolder($newFolderName)) {
            $this->_errorHandler->throwError(CKFINDER_CONNECTOR_ERROR_INVALID_NAME);
        }

        // The root folder cannot be deleted.
        if ($this->_currentFolder->getClientPath() == "/") {
            $this->_errorHandler->throwError(CKFINDER_CONNECTOR_ERROR_INVALID_REQUEST);
        }

        $oldFolderPath = substr($this->_currentFolder->getServerPath(), 1, -1);
        $newFolderPath = ltrim(dirname($this->_currentFolder->getServerPath()).$newFolderName, '/');

        global $config;
        $s3 = s3_con();

        $copied = true;
        $deleted = true;
        $items = $s3->getBucket($config['AmazonS3']['Bucket'], $oldFolderPath);
        foreach ($items as $item) {
            if (rtrim($item['name'], '/') !== $oldFolderPath) {
                $newItemName = str_replace($oldFolderPath, $newFolderPath, $item['name']);
                error_log('$item[name] = ' . $item['name']);
                error_log('$newItemName = ' . $newItemName);
                $copy = $s3->copyObject($config['AmazonS3']['Bucket'], $item['name'], $config['AmazonS3']['Bucket'], $newItemName, $s3::ACL_PUBLIC_READ);
                if ($copy === false) {
                    $copied = false;
                } else {
                    $deleted = $deleted && $s3->deleteObject($config['AmazonS3']['Bucket'], $item['name']);
                }
            }
        }

        if (!$copied || !$deleted) {
            $this->_errorHandler->throwError(CKFINDER_CONNECTOR_ERROR_ACCESS_DENIED);
        } else {
            $s3->deleteObject($config['AmazonS3']['Bucket'], $oldFolderPath);
            $newThumbsServerPath = dirname($this->_currentFolder->getThumbsServerPath()) . '/' . $newFolderName . '/';
            if (is_dir($this->_currentFolder->getThumbsServerPath()) && !@rename($this->_currentFolder->getThumbsServerPath(), $newThumbsServerPath)) {
                CKFinder_Connector_Utils_FileSystem::unlink($this->_currentFolder->getThumbsServerPath());
            }
        }

        $newFolderPath = preg_replace(",[^/]+/?$,", $newFolderName, $this->_currentFolder->getClientPath()) . '/';
        $newFolderUrl = $resourceTypeInfo->getUrl() . ltrim($newFolderPath, '/');

        $oRenameNode = new Ckfinder_Connector_Utils_XmlNode("RenamedFolder");
        $this->_connectorNode->addChild($oRenameNode);

        $oRenameNode->addAttribute("newName", CKFinder_Connector_Utils_FileSystem::convertToConnectorEncoding($newFolderName));
        $oRenameNode->addAttribute("newPath", CKFinder_Connector_Utils_FileSystem::convertToConnectorEncoding($newFolderPath));
        $oRenameNode->addAttribute("newUrl", CKFinder_Connector_Utils_FileSystem::convertToConnectorEncoding($newFolderUrl));
    }
}
