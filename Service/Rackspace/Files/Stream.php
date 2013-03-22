<?php
/**
 * @category   Kynx
 * @package    Kynx_Service
 * @subpackage Rackspace_Files
 * @copyright  Copyright (c) 2012 Matt Kynaston (http://www.kynx.org)
 * @license    https://github.com/kynx/Kynx/blob/master/LICENSE New BSD
 */
/**
 * Stream wrapper for Kynx_Service_Rackspace_Files
 * 
 * @category   Kynx
 * @package    Kynx_Service
 * @subpackage Rackspace_Files
 * @copyright  Copyright (c) 2012 Matt Kynaston (http://www.kynx.org)
 * @license    https://github.com/kynx/Kynx/blob/master/LICENSE New BSD
 */
class Kynx_Service_Rackspace_Files_Stream
{
    /**
     * @var boolean Write the buffer on stream_write()?
     */
    private $_writeBuffer = false;

    /**
     * @var integer Current read/write position
     */
    private $_position = 0;
    
    /**
     * @var integer Total size of the object as returned by S3 (Content-length)
     */
    private $_objectSize = 0;

    private $_container;
    
    /**
     * @var string File name to interact with
     */
    private $_objectName = null;

    /**
     * @var string Current read/write buffer
     */
    private $_objectBuffer = null;
    
    /**
     * @var array Available containers/objects
     */
    private $_objects = array();

    /**
     * @var Kynx_Service_Rackspace_Files
     */
    private $_rack = null;
    
    protected $_options = array(
        'range_size' => 81920
    );
    
    /**
     * Open the stream
     *
     * @param  string  $path
     * @param  string  $mode
     * @param  integer $options
     * @param  string  $opened_path
     * @return boolean
     */
    public function stream_open($path, $mode, $options, &$opened_path)
    {
        if (strpbrk($mode, 'a+')) {
            if ($options & STREAM_REPORT_ERRORS) {
                trigger_error("Cannot open in read/write or append mode", E_USER_ERROR);
            }
            return false;
        }
        
        $parsed = $this->parsePath($path);
        if (empty($parsed['object'])) {
            if ($options & STREAM_REPORT_ERRORS) {
                trigger_error("Containers cannot be opened", E_USER_ERROR);
            }
            return false;
        }

        $this->getRackClient($path);

        if (strstr($mode, 'x') && $this->_rack->getMetadataObject($parsed['container'], $parsed['object'])) {
            if ($options & STREAM_REPORT_ERRORS) {
                // fopen() issues warning, so that's what we do
                trigger_error("File '$path' already exists", E_USER_WARNING);
            }
            return false;
        }
        
        if (strpbrk($mode, 'wx')) {
            $this->_container = $parsed['container'];
            $this->_objectName = $parsed['object'];
            $this->_objectBuffer = null;
            $this->_objectSize = 0;
            $this->_position = 0;
            $this->_writeBuffer = true;
            return true;
        } else {
            // Otherwise, just see if the file exists or not
            $info = $this->_rack->getMetadataObject($parsed['container'], $parsed['object']);
            if ($info) {
                $this->_container = $parsed['container'];
                $this->_objectName = $parsed['object'];
                $this->_objectBuffer = null;
                $this->_objectSize = $info['bytes'];
                $this->_position = 0;
                $this->_writeBuffer = false;
                return true;
            }
        }
        return false;
    }

    /**
     * Close the stream
     *
     * @return void
     */
    public function stream_close()
    {
        $this->_container = null;
        $this->_objectName = null;
        $this->_objectBuffer = null;
        $this->_objectSize = 0;
        $this->_position = 0;
        $this->_writeBuffer = false;
    }

    /**
     * Read from the stream
     *
     * http://bugs.php.net/21641 - stream_read() is always passed PHP's
     * internal read buffer size (8192) no matter what is passed as $count
     * parameter to fread().
     *
     * @param  integer $count
     * @return string
     */
    public function stream_read($count)
    {
        if (!$this->_objectName) {
            return false;
        }

        // make sure that count doesn't exceed object size
        if ($count + $this->_position > $this->_objectSize) {
            $count = $this->_objectSize - $this->_position;
        }

        $bufferSize = $this->_objectBuffer ? strlen($this->_objectBuffer) : 0;
        $range_start = $this->_position + $bufferSize;
        $range_end   = $range_start + $this->_options['range_size'] - 1;
        if ($range_end > $this->_objectSize) $range_end = $this->_objectSize;

        // Only fetch more data from CF if we haven't fetched any data yet (postion=0)
        // OR, the range end position plus 1 is greater than the size of the current
        // object buffer
        if (!$this->_objectBuffer  ||  $bufferSize < $count) {
            $response = $this->_rack->getObjectRange($this->_container, $this->_objectName, $range_start, $range_end);
            if ($response->getStatus() == 206) { // Docs say 200, but shouldn't it be 206 Partial Content?
                $this->_objectBuffer .= $response->getBody();
            }
        }

        $data = substr($this->_objectBuffer, 0, $count);
        $this->_objectBuffer = substr($this->_objectBuffer, $count);
        $this->_position += $count;
        return $data;
    }

    /**
     * Write to the stream
     *
     * @param  string $data
     * @return integer
     */
    public function stream_write($data)
    {
        if (!$this->_objectName) {
            return 0;
        }
        
        if (!$this->_rack->isTransfering()) {
            $len = $this->_rack->beginStoreChunks($this->_container, $this->_objectName, $data);
        }
        else {
            $len = $this->_rack->appendStoreChunk($data);
        }
        $this->_position += $len;
        return $len;
    }

    /**
     * End of the stream?
     *
     * @return boolean
     */
    public function stream_eof()
    {
        if (!$this->_objectName) {
            return true;
        }

        return ($this->_position >= $this->_objectSize);
    }

    /**
     * Flush current cached stream data to storage
     *
     * @return boolean
     */
    public function stream_flush()
    {
        // If the stream wasn't opened for writing, just return false
        if (!$this->_writeBuffer) {
            return false;
        }
        
        $this->_objectBuffer = null;
        return $this->_rack->endStoreChunks();
    }

    /**
     * Returns data array of stream variables
     *
     * @return array
     */
    public function stream_stat()
    {
        if (!($this->_rack && $this->_container)) {
            return false;
        }

        return self::stat($this->_rack, 
                array('container' => $this->_container, 'object' => $this->_objectName), 
                $this->_options);
    }

    /**
     * Attempt to delete the item
     *
     * @param  string $path
     * @return boolean
     */
    public function unlink($path)
    {
        $parsed = $this->parsePath($path);
        return $this->getRackClient($path)->deleteObject($parsed['container'], $parsed['object']);
    }

    /**
     * Attempt to rename the item
     *
     * @param  string  $path_from
     * @param  string  $path_to
     * @return boolean False
     */
    public function rename($path_from, $path_to)
    {
        $from = $this->parsePath($path_from);
        $to = $this->parsePath($path_to);
        $rack = $this->getRackClient($path_from);
        
        $fromInfo = $rack->getMetadataObject($from['container'], $from['object']);
        if ($rack->copyObject($from['container'], $from['object'], $to['container'], $to['object'])) {
            $toInfo = $rack->getMetadataObject($to['container'], $to['object']);
            if ($fromInfo['hash'] == $toInfo['hash']) {
                return $rack->deleteObject($from['container'], $from['object']);
            }
        }
        return false;
    }

    /**
     * Create a new directory
     *
     * @param  string  $path
     * @param  integer $mode
     * @param  integer $options
     * @return boolean
     */
    public function mkdir($path, $mode, $options)
    {
        $parsed = $this->parsePath($path);
        // can only create containers
        if (empty($parsed['object'])) {
            return $this->getRackClient($path)->createContainer($parsed['container']);
        }
        return true;
    }

    /**
     * Remove a directory
     *
     * @param  string  $path
     * @param  integer $options
     * @return boolean
     */
    public function rmdir($path, $options)
    {
        $parsed = $this->parsePath($path);
        // can only delete containers
        if (empty($parsed['object'])) {
            return $this->getRackClient($path)->deleteContainer($parsed['container']);
        }
        return true;
    }

    /**
     * Attempt to open a directory
     *
     * @param  string $path
     * @param  integer $options
     * @return boolean
     */
    public function dir_opendir($path, $options)
    {
        $parsed = $this->parsePath($path);
        if (empty($parsed['container'])) {
            $objectList = $this->getRackClient($path)->getContainers();
        }
        else {
            // allow listing psuedo-directories
            $container = $parsed['container'];
            $params = array();
            if (!empty($parsed['object'])) {
                $params = array(
                    'delimiter' => '/',
                    'prefix' => preg_match('|/$|', $parsed['object']) ? $parsed['object'] : $parsed['object'] . '/'
                );
            }
            $objectList = $this->getRackClient($path)->getObjects($container, $params);
        }
        if ($objectList) {
            foreach ($objectList as $object) {
                // if there are placeholder files for directories we'll end up
                // with duplicates
                $name = preg_replace('|/$|', '', $object->getName());
                if (!in_array($name, $this->_objects)) {
                    $this->_objects[] = $name;
                }
            }
        }

        return ($objectList !== false);
    }

    /**
     * Return array of URL variables
     *
     * @param  string $path
     * @param  integer $flags
     * @return array
     */
    public function url_stat($path, $flags)
    {
        /* @see http://www.php.net/manual/en/streamwrapper.url-stat.php
         * For resources with the ability to link to other resource (such as an 
         * HTTP Location: forward, or a filesystem symlink). This flag specified 
         * that only information about the link itself should be returned, not 
         * the resource pointed to by the link. This flag is set in response to 
         * calls to lstat(), is_link(), or filetype().
         */
        if ($flags & STREAM_URL_STAT_LINK) {
            return array();
        }
        return self::stat($this->getRackClient($path), $this->parsePath($path), $this->_options);
    }

    /**
     * Return the next filename in the directory
     *
     * @return string
     */
    public function dir_readdir()
    {
        $object = current($this->_objects);
        if ($object !== false) {
            next($this->_objects);
        }
        return basename($object);
    }

    /**
     * Reset the directory pointer
     *
     * @return boolean True
     */
    public function dir_rewinddir()
    {
        reset($this->_objects);
        return true;
    }

    /**
     * Close a directory
     *
     * @return boolean True
     */
    public function dir_closedir()
    {
        $this->_objects = array();
        return true;
    }
    
    /**
     * Performs stat operations
     * @param Kynx_Service_Rackspace_Files $rack
     * @param array $parsed
     * @return mixed array|false
     */
    protected static function stat(Kynx_Service_Rackspace_Files $rack, $parsed, $options)
    {
        $stat = array();
        $stat['dev'] = 0;
        $stat['ino'] = 0;
        $stat['mode'] = 0777;
        $stat['nlink'] = 0;
        $stat['uid'] = 0;
        $stat['gid'] = 0;
        $stat['rdev'] = 0;
        $stat['size'] = 0;
        $stat['atime'] = 0;
        $stat['mtime'] = 0;
        $stat['ctime'] = 0;
        $stat['blksize'] = 0;
        $stat['blocks'] = 0;

        $isDir = $info = false;
        
        // check if we're a container
        if (empty($parsed['object'])) {
            $isDir = $rack->getMetadataContainer($parsed['container']);
        }
        if ($stat && !$isDir) {
            // try and get object itself
            $info = $rack->getMetadataObject($parsed['container'], $parsed['object']);
            // Cyberduck and others add placeholder files to mark directories
            if (!empty($info) && $info['content_type'] == 'application/directory') {
                $isDir = true;
            }
            elseif (!empty($info)) {
                $stat['size']  = $info['bytes'];
                $stat['atime'] = time();
                $stat['mtime'] = strtotime($info['last_modified']);
                $stat['mode'] |= 0100000;
            }
            // see if it is a pseudo-directory
            else {
                $path = preg_match('|/$|', $parsed['object']) ? $parsed['object'] : $parsed['object'] . '/';
                $files = $rack->getObjects($parsed['container'], array('delimiter' => '/', 'path' => $path));
                if (count($files)) {
                    $isDir = true;
                }
                else {
                    $stat = false;
                }
            }
        }
        if ($isDir && $stat) {
            $stat['mode'] |= 040000;
        }        
        return $stat;
    }
    
    /**
     * Retrieve client for this stream type
     *
     * @param  string $path
     * @return Kynx_Service_Rackspace_Files
     */
    protected function getRackClient($path)
    {
        if ($this->_rack === null) {
            $url = explode(':', $path);

            if (!$url) {
                /**
                 * @see Kynx_Service_Rackspace_Files_Exception
                 */
                require_once 'Kynx/Service/Rackspace/Files/Exception.php';
                throw new Kynx_Service_Rackspace_Files_Exception("Unable to parse URL $path");
            }

            /**
             * @see Kynx_Service_Rackspace_Files
             */
            require_once 'Kynx/Service/Rackspace/Files.php';
            $this->_rack = Kynx_Service_Rackspace_Files::getWrapperClient($url[0]);
            if (!$this->_rack) {
                /**
                 * @see Kynx_Service_Rackspace_Files_Exception
                 */
                require_once 'Kynx/Service/Rackspace/Files/Exception.php';
                throw new Kynx_Service_Rackspace_Files_Exception("Unknown client for wrapper '" . $url[0] . "'");
            }
            $this->_options = array_merge($this->_options, $this->_rack->getWrapperOptions());
        }

        return $this->_rack;
    }

    /**
     * Extract container and object from path
     *
     * @param string $path
     * @return string
     */
    protected function parsePath($path)
    {
        $parsed = array('container' => '', 'object' => '');
        $url = parse_url($path);
        if ($url['host']) {
            $parsed['container'] = $url['host'];
            $parsed['object'] = preg_replace('|^/|', '', $url['path']);
            if (strlen(rawurlencode($parsed['object'])) > 1024) {
                /**
                 * @see Kynx_Service_Rackspace_Files_Exception
                 */
                throw new Kynx_Service_Rackspace_Files_Exception("Object name must not exceed 1024 characters");
            }
        }

        return $parsed;
    }
}
