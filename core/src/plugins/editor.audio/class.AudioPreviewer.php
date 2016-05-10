<?php
/*
 * Copyright 2007-2013 Charles du Jeu - Abstrium SAS <team (at) pyd.io>
 * This file is part of Pydio.
 *
 * Pydio is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Pydio is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with Pydio.  If not, see <http://www.gnu.org/licenses/>.
 *
 * The latest code can be found at <http://pyd.io/>.
 */

use Pydio\Access\Core\AJXP_MetaStreamWrapper;
use Pydio\Access\Core\Model\AJXP_Node;
use Pydio\Access\Core\Model\UserSelection;
use Pydio\Core\Services\AuthService;
use Pydio\Core\Services\ConfService;
use Pydio\Core\Controller\Controller;
use Pydio\Core\Utils\Utils;
use Pydio\Core\Controller\XMLWriter;
use Pydio\Core\PluginFramework\Plugin;

defined('AJXP_EXEC') or die( 'Access not allowed');

/**
 * Streams MP3 files to the flash client
 * @package AjaXplorer_Plugins
 * @subpackage Editor
 */
class AudioPreviewer extends Plugin
{
    public function preProcessAction($action, &$httpVars, &$fileVars)
    {
        if ($action != "ls" || !isset($httpVars["playlist"])) {
            return ;
        }
        $httpVars["dir"] = base64_decode($httpVars["dir"]);
    }

    public function switchAction($action, $httpVars, $postProcessData)
    {
        $repository = ConfService::getRepository();
        if (!$repository->detectStreamWrapper(false)) {
            return false;
        }

        if ($action == "audio_proxy") {

            $selection = new UserSelection($repository, $httpVars);
            $destStreamURL = $selection->currentBaseUrl();

            $node = new AJXP_Node($destStreamURL."/".$selection->getUniqueFile());
            // Backward compat
            // May be a backward compatibility problem, try to base64decode the filepath
            if(!file_exists($node->getUrl()) && strpos($httpVars["file"], "base64encoded:") === false){
                $file = Utils::decodeSecureMagic(base64_decode($httpVars["file"]));
                if(!file_exists($destStreamURL.$file)){
                    throw new Exception("Cannot find file!");
                }else{
                    $node = new AJXP_Node($destStreamURL.$file);
                }
            }
            if(!is_readable($node->getUrl())){
                throw new Exception("Cannot find file!");
            }

            $fileUrl = $node->getUrl();
            $localName = basename($fileUrl);
            $cType = "audio/".array_pop(explode(".", $localName));
            $size = filesize($node->getUrl());

            header("Content-Type: ".$cType."; name=\"".$localName."\"");
            header("Content-Length: ".$size);

            $stream = fopen("php://output", "a");
            AJXP_MetaStreamWrapper::copyFileInStream($fileUrl, $stream);
            fflush($stream);
            fclose($stream);

            Controller::applyHook("node.read", array($node));
            $this->logInfo('Preview', 'Read content of '.$node->getUrl(), array("files" => $node->getUrl()));
            //exit(1);

        } else if ($action == "ls") {
            if (!isSet($httpVars["playlist"])) {
                // This should not happen anyway, because of the applyCondition.
                Controller::passProcessDataThrough($postProcessData);
                return false;
            }
            // We transform the XML into XSPF
            $xmlString = $postProcessData["ob_output"];
            $xmlDoc = new DOMDocument();
            $xmlDoc->loadXML($xmlString);
            $xElement = $xmlDoc->documentElement;
            header("Content-Type:application/xspf+xml;charset=UTF-8");
            print('<?xml version="1.0" encoding="UTF-8"?>');
            print('<playlist version="1" xmlns="http://xspf.org/ns/0/">');
            print("<trackList>");
            foreach ($xElement->childNodes as $child) {
                $isFile = ($child->getAttribute("is_file") == "true");
                $label = $child->getAttribute("text");
                $ar = explode(".", $label);
                $ext = strtolower(end($ar));
                if(!$isFile || $ext != "mp3") continue;
                print("<track><location>".AJXP_SERVER_ACCESS."?secure_token=".AuthService::getSecureToken()."&get_action=audio_proxy&file=".base64_encode($child->getAttribute("filename"))."</location><title>".$label."</title></track>");
            }
            print("</trackList>");
            XMLWriter::close("playlist");
        }
    }
}
