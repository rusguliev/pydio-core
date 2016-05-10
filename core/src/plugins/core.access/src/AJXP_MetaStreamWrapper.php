<?php
/*
 * Copyright 2007-2015 Abstrium <contact (at) pydio.com>
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

namespace Pydio\Access\Core;

use Pydio\Access\Core\Filter\ContentFilter;
use Pydio\Core\PluginFramework\PluginsService;
use Pydio\Core\Services\ConfService;

defined('AJXP_EXEC') or die('Access not allowed');

/**
 * Class AJXP_MetaStreamWrapper
 *
 * Global streamWrapper that encapsulates all wrappers to access a driver's resource.
 * Registered under the "pydio" protocol, it should replace all the old ajxp.XX direct calls.
 * The static "appendMetaWrapper" method allows to add additional wrapper that will be sequentially called until
 * reaching the driver ajxp.XX wrapper.
 *
 * @package Pydio
 * @subpackage Core
 */
class AJXP_MetaStreamWrapper implements IAjxpWrapper
{
    /**
     * @var resource
     */
    protected $handle;

    /**
     * @var string
     */
    protected $currentDirPath;

    protected static $metaWrappers = [
        'core' => [
            'pydio' => 'Pydio\Access\Core\AJXP_MetaStreamWrapper'
        ]
    ];

    protected static $cachedRepositoriesWrappers = array();

    protected $currentUniquePath;

    /**
     * Register the stack of protocols/wrappers.
     * @param null $registered_wrappers
     */
    public static function register($registered_wrappers = null){
        if($registered_wrappers == null){
            $registered_wrappers = stream_get_wrappers();
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveArrayIterator(self::$metaWrappers));
        foreach($it as $protocol => $className){
            if(!in_array($protocol, $registered_wrappers)){
                stream_wrapper_register($protocol, $className);
            }
        }
    }

    /**
     * Register an addition protocol/wrapper in the stack
     * @param $name string
     * @param $className string
     */
    public static function appendMetaWrapper($name, $className, $parent = "core"){
        self::$metaWrappers[$parent][$name] = $className;
        self::register();
    }

    protected static function getMetaWrappers($scheme) {
        return array_merge((array) self::$metaWrappers['core'], (array) self::$metaWrappers[$scheme]);
    }

    protected static function getNextScheme($url, $context='core'){
        $parts = parse_url($url);
        $metaWrapperKeys = array_keys(self::getMetaWrappers($context));
        $key = array_search($parts["scheme"], $metaWrapperKeys);
        if($key < count($metaWrapperKeys) - 1){
            // Return next registered meta wrapper
            return $metaWrapperKeys[$key + 1];
        }else{
            // Otherwise return repository wrapper
            $data = self::actualRepositoryWrapperData($parts["host"]);
            return $data["protocol"];
        }
    }

    /**
     * @param string $url
     * @param AJXP_MetaStreamWrapper $crtInstance
     * @return string
     * @throws \Exception
     */
    protected static function translateScheme($url, $crtInstance = null){
        $parts=parse_url($url);
        $currentScheme = $parts['scheme'];
        $context = self::actualRepositoryWrapperProtocol($parts['host']);
        $newScheme = self::getNextScheme($url, $context);
        $repository = ConfService::getRepositoryById(parse_url($url, PHP_URL_HOST));
        if($currentScheme == "pydio" && $repository->hasContentFilter()){

            $contentFilter = $repository->getContentFilter();

            if ($contentFilter instanceof ContentFilter) {
                $baseDir = $contentFilter->getBaseDir();

                if ($crtInstance != null) {
                    $crtInstance->currentUniquePath = $contentFilter->getUniquePath();
                }

                if (!empty($baseDir) || $baseDir != "/") {
                    $crtPath = parse_url($url, PHP_URL_PATH);
                    $crtBase = basename($crtPath);
                    if (!empty($crtPath) && $crtPath != "/" && $crtBase != $contentFilter->getUniquePath() && $crtBase != ".ajxp_meta") {
                        throw new \Exception("Cannot find file " . $crtBase);
                    }
                    // Prepend baseDir in path
                    $url = str_replace($currentScheme . "://" . $repository->getId() . $crtPath, $currentScheme . "://" . $repository->getId() . rtrim($baseDir . $crtPath, "/"), $url);
                }
            }
        }

        $newUrl = str_replace($currentScheme."://", $newScheme."://", $url);

        self::applyInitPathHook($newUrl, $context);

        return $newUrl;
    }

    protected static function findWrapperClassName($scheme, $context = "core"){

        $metaWrappers = self::getMetaWrappers($context);

        if(isSet($metaWrappers[$scheme])){
            $wrapper = $metaWrappers[$scheme];
        }else{
            $wrapper = PluginsService::getInstance()->getWrapperClassName($scheme);
        }
        if(empty($wrapper)) {
            throw new \Exception("Cannot find any wrapper for the scheme " . $scheme . " in context " . $context);
        }
        return $wrapper;
    }

    protected static function findSubWrapperClassName($url){
        $repositoryId = parse_url($url, PHP_URL_HOST);
        $context = self::actualRepositoryWrapperProtocol($repositoryId);
        $nextScheme = self::getNextScheme($url, $context);

        return self::findWrapperClassName($nextScheme, $context);
    }

    protected static function actualRepositoryWrapperData($repositoryId){
        if(isSet(self::$cachedRepositoriesWrappers[$repositoryId])){
            return self::$cachedRepositoriesWrappers[$repositoryId];
        }
        $repository = ConfService::getRepositoryById($repositoryId);
        if(!is_a($repository, "Repository")){
            throw new \Exception("Cannot find repository with this id!");
        }
        if($repository->detectStreamWrapper(false)){
            self::$cachedRepositoriesWrappers[$repositoryId] = $repository->streamData;
            return $repository->streamData;
        }else{
            throw new \Exception("Repository does not provide a stream wrapper!");
        }
    }

    /**
     * Return the final ajxp.XX wrapper class name.
     * @param $repositoryId
     * @return string mixed
     * @throws \Exception
     */
    public static function actualRepositoryWrapperClass($repositoryId){
        $data = self::actualRepositoryWrapperData($repositoryId);
        return $data["classname"];
    }

    /**
     * Return the final ajxp.XX wrapper protocol.
     * @param $repositoryId
     * @return string mixed
     * @throws \Exception
     */
    public static function actualRepositoryWrapperProtocol($repositoryId){
        $data = self::actualRepositoryWrapperData($repositoryId);
        return $data["protocol"];
    }



    /**
     * Call Init function for a translated Path if defined
     *
     * @param string $path
     */
    public static function applyInitPathHook($path, $context = 'core') {
        $currentScheme = parse_url($path, PHP_URL_SCHEME);
        $wrapper = self::findWrapperClassName($currentScheme, $context);

        if (is_callable(array($wrapper, "applyInitPathHook"))){
            call_user_func(array($wrapper, "applyInitPathHook"), $path);
        }
    }

    /**
     * Get a "usable" reference to a file : the real file or a tmp copy.
     *
     * @param string $path
     * @param bool $persistent
     * @return string
     * @throws \Exception
     */
    public static function getRealFSReference($path, $persistent = false)
    {
        $wrapper = self::findSubWrapperClassName($path);
        return call_user_func(array($wrapper, "getRealFSReference"), self::translateScheme($path), $persistent);
    }

    /**
     * Read a file (by chunks) and copy the data directly inside the given stream.
     *
     * @param string $path
     * @param resource $stream
     */
    public static function copyFileInStream($path, $stream)
    {
        $wrapper = self::findSubWrapperClassName($path);
        call_user_func(array($wrapper, "copyFileInStream"), self::translateScheme($path), $stream);
    }

    /**
     * Chmod implementation for this type of access.
     *
     * @param string $path
     * @param number $chmodValue
     */
    public static function changeMode($path, $chmodValue)
    {
        $wrapper = self::findSubWrapperClassName($path);
        call_user_func(array($wrapper, "changeMode"), self::translateScheme($path), $chmodValue);
    }

    /**
     * Describe whether the current wrapper operates on a remote server or not.
     * @static
     * @return boolean
     * @throws \Exception
     */
    public static function isRemote()
    {
        throw new \Exception("Do not call this method directly, but AJXP_MetaStreamWrapper::wrapperIsRemote() instead");
    }

    /**
     * Describe whether the current wrapper operates on a remote server or not.
     * @param String $url Url of the resource
     * @static
     * @return boolean
     * @throws \Exception
     */
    public static function isSeekable($url)
    {
        throw new \Exception("Do not call this method directly, but AJXP_MetaStreamWrapper::wrapperIsSeekable() instead");
    }

    /**
     * @param string $url
     * @return boolean
     */
    public static function wrapperIsRemote($url){
        $repositoryId = parse_url($url, PHP_URL_HOST);
        return call_user_func(array(self::actualRepositoryWrapperClass($repositoryId), "isRemote"));
    }

    /**
     * @param string $url
     * @return boolean
     */
    public static function wrapperIsSeekable($url){
        $repositoryId = parse_url($url, PHP_URL_HOST);
        return call_user_func(array(self::actualRepositoryWrapperClass($repositoryId), "isSeekable"), $url);
    }

    public static function nodesUseSameWrappers($url1, $url2){
        $w1 = self::actualRepositoryWrapperClass(parse_url($url1, PHP_URL_HOST));
        $w2 = self::actualRepositoryWrapperClass(parse_url($url2, PHP_URL_HOST));
        return $w1 == $w2;
    }

    /**
     *
     *
     * @return bool
     */
    public function dir_closedir()
    {
        if(isSet($this->handle) && is_resource($this->handle)){
            closedir($this->handle);
        }
    }

    /**
     * Enter description here...
     *
     * @param string $path
     * @param int $options
     * @return bool
     */
    public function dir_opendir($path, $options)
    {
        $newPath = self::translateScheme($path, $this);
        $this->handle = opendir($newPath);
        if($this->handle !== false){
            $this->currentDirPath = parse_url($path, PHP_URL_PATH);
            return true;
        }else{
            return false;
        }
    }

    /**
     * Standard readdir() implementation
     *
     * @return string
     */
    public function dir_readdir()
    {
        if(isSet($this->handle) && is_resource($this->handle)){
            if($this->currentUniquePath != null){
                return $this->innerReadDirFiltered($this->handle);
            }else{
                return readdir($this->handle);
            }
        }
        return false;
    }

    /**
     * Skip values until correct one is found
     * @param Resource $resource
     * @return string
     */
    protected function innerReadDirFiltered($resource){
        $test = readdir($resource);
        if($test === false || $test == "." || $test == ".."){
            return $test;
        }
        if($this->currentUniquePath == $test) {
            return $test;
        }
        // Return next one
        return $this->innerReadDirFiltered($resource);
    }


    /**
     * Enter description here...
     *
     * @return bool
     */
    public function dir_rewinddir()
    {
        $this->currentDirPath = null;
        if(isSet($this->handle) && is_resource($this->handle)){
            return rewind($this->handle);
        }
        return false;
    }

    /**
     * Enter description here...
     *
     * @param string $path
     * @param int $mode
     * @param int $options
     * @return bool
     */
    public function mkdir($path, $mode, $options)
    {
        return mkdir($this->translateScheme($path), $mode, $options);
    }

    /**
     * Enter description here...
     *
     * @param string $path_from
     * @param string $path_to
     * @return bool
     */
    public function rename($path_from, $path_to)
    {
        return rename($this->translateScheme($path_from), $this->translateScheme($path_to));
    }

    /**
     * Enter description here...
     *
     * @param string $path
     * @param int $options
     * @return bool
     */
    public function rmdir($path, $options)
    {
        if(is_resource($options)){
            return rmdir(AJXP_MetaStreamWrapper::translateScheme($path), $options);
        }else{
            return rmdir(AJXP_MetaStreamWrapper::translateScheme($path));
        }
    }

    /**
     * Enter description here...
     *
     */
    public function stream_close()
    {
        if(isSet($this->handle) && is_resource($this->handle)){
            return fclose($this->handle);
        }
        return false;
    }

    /**
     * Enter description here...
     *
     * @return bool
     */
    public function stream_eof()
    {
        if(isSet($this->handle) && is_resource($this->handle)){
            return feof($this->handle);
        }
        return false;
    }

    /**
     * Enter description here...
     *
     * @return bool
     */
    public function stream_flush()
    {
        if(isSet($this->handle) && is_resource($this->handle)){
            return fflush($this->handle);
        }
        return false;
    }

    /**
     * Enter description here...
     *
     * @param string $path
     * @param string $mode
     * @param int $options
     * @param string &$context
     * @return bool
     */
    public function stream_open($path, $mode, $options, &$context)
    {
        if(is_resource($context)){
            $this->handle = fopen($this->translateScheme($path), $mode, $options, $context);
        }else{
            $this->handle = fopen($this->translateScheme($path), $mode, $options);
        }
        return ($this->handle !== false);
    }

    /**
     * Enter description here...
     *
     * @param int $count
     * @return string
     */
    public function stream_read($count)
    {
        if(isSet($this->handle) && is_resource($this->handle)){
            return fread($this->handle, $count);
        }
        return null;
    }

    /**
     * Enter description here...
     *
     * @param int $offset
     * @param int $whence = SEEK_SET
     * @return bool
     */
    public function stream_seek($offset, $whence = SEEK_SET)
    {
        if(isSet($this->handle) && is_resource($this->handle)){
            return fseek($this->handle, $offset, $whence);
        }
        return false;
    }

    /**
     * Enter description here...
     *
     * @return array
     */
    public function stream_stat()
    {
        if(isSet($this->handle) && is_resource($this->handle)){
            return fstat($this->handle);
        }
        return false;
    }

    /**
     * Enter description here...
     *
     * @return int
     */
    public function stream_tell()
    {
        if(isSet($this->handle) && is_resource($this->handle)){
            return ftell($this->handle);
        }
        return false;
    }

    /**
     * Enter description here...
     *
     * @param string $data
     * @return int
     */
    public function stream_write($data)
    {
        if(isSet($this->handle) && is_resource($this->handle)){
            return fwrite($this->handle, $data);
        }
        return false;
    }

    /**
     * Enter description here...
     *
     * @param string $path
     * @return bool
     */
    public function unlink($path)
    {
        return unlink($this->translateScheme($path));
    }

    /**
     * Enter description here...
     *
     * @param string $path
     * @param int $flags
     * @return array
     */
    public function url_stat($path, $flags)
    {
        $stat = @stat($this->translateScheme($path));
        if($stat === false){
            return null;
        }
        $bytesize = $stat["size"];
        $wrapper = self::actualRepositoryWrapperClass(parse_url($path, PHP_URL_HOST));
        if(method_exists($wrapper, "getLastRealSize")){
            $custom = call_user_func(array($wrapper, "getLastRealSize"));
            if ($custom !== false) {
                $bytesize = $custom;
            }
        }
        if ($bytesize < 0) {
            $bytesize = sprintf("%u", $bytesize);
        }
        $stat["size"] = $stat[7] = $bytesize;

        return $stat;
    }
}
