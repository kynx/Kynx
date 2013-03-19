<?php
/**
 * @category   Kynx
 * @package    Kynx_Service
 * @subpackage Rackspace
 * @copyright  Copyright (c) 2012 Matt Kynaston (http://www.kynx.org)
 * @license    https://github.com/kynx/Kynx/blob/master/LICENSE New BSD
 */
/**
 * @see Zend_Service_Rackspace_Files
 */
require_once 'Zend/Service/Rackspace/Files.php';
/**
 * Extends Zend_Service_Rackspace_Files to provide streaming capabilities
 * 
 * @category   Kynx
 * @package    Kynx_Service
 * @subpackage Rackspace
 * @copyright  Copyright (c) 2012 Matt Kynaston (http://www.kynx.org)
 * @license    https://github.com/kynx/Kynx/blob/master/LICENSE New BSD
 */
class Kynx_Service_Rackspace_Files extends Zend_Service_Rackspace_Files
{
    const HEADER_RANGE                         = 'Range';
    const HEADER_TRANSFER_ENCODING             = 'Transfer-Encoding';
    const WRAPPER_NAME                         = 'rscf';
    
    /**
     * Array of wrapper clients
     * 
     * @var Kynx_Service_Rackspace_Files_Stream[]
     */
    protected static $wrapperClients = array();
    
    /**
     * Options for wrapper clients
     * @var array
     */
    protected $wrapperOptions = array();
    
    /**
     * Object being sent using chunked encoding
     * 
     * @var array
     */
    protected $chunkedObject;
    
    /**
     * Class to perform checksums
     * 
     * @var Kynx_Service_Rackspace_Files_Checksum_Interface
     */
    protected $checkSummer;
    
    protected $clientConfig = array();
    
    /**
     * Sets config for httpd client
     * 
     * @see Zend_Httpd_Client::setConfig();
     * @param array $config
     */
    public function setClientConfig($config) 
    {
        $this->clientConfig = $config;
    }
    
    /**
     * Get an object using streaming
     *
     * Can use either provided filename for storage or create a temp file if none provided.
     * 
     * @param string $container
     * @param string $object
     * @param mixed $streamfile  resource|string
     */
    public function getObjectIntoStream($container, $object, $streamfile = null)
    {
        $this->getHttpClient()->setStream($streamfile?$streamfile:true);
        $rv = $this->getObject($container, $object);
        $this->getHttpClient()->setStream(null);

        return $rv;
    }

    /**
     * Gets range of bytes of an object
     *
     * @param string $container
     * @param string $object
     * @param integer $start
     * @param integer $end
     * @param array $headers
     * @return Zend_Service_Rackspace_Files_Object|boolean
     * @throws Kynx_Service_Rackspace_Files_Exception
     */
    public function getObjectRange($container,$object,$start='',$end='',$headers=array())
    {
        if (empty($container)) {
            /**
             * @see Kynx_Service_Rackspace_Files_Exception
             */
            require_once 'Kynx/Service/Rackspace/Files/Exception.php';
            throw new Kynx_Service_Rackspace_Files_Exception(self::ERROR_PARAM_NO_NAME_CONTAINER);
        }
        if (empty($object)) {
            /**
             * @see Kynx_Service_Rackspace_Files_Exception
             */
            require_once 'Kynx/Service/Rackspace/Files/Exception.php';
            throw new Kynx_Service_Rackspace_Files_Exception(self::ERROR_PARAM_NO_NAME_OBJECT);
        }
        if ($start || $end) {
            $headers[self::HEADER_RANGE] = "bytes=$start-$end";
        }
        return $this->httpCall($this->getObjectUrl($container, $object),'GET',$headers);
    }
    
    /**
     * Store a file in a container using chunked transfer encoding
     *
     * @param string $container
     * @param string $object
     * @param string $content
     * @param array $metadata
     * @return boolean
     * @throws Kynx_Service_Rackspace_Files_Exception
     */
    public function beginStoreChunks($container,$object,$content,$metadata=array()) {
        if (empty($container)) {
            /**
             * @see Kynx_Service_Rackspace_Files_Exception
             */
            require_once 'Kynx/Service/Rackspace/Files/Exception.php';
            throw new Kynx_Service_Rackspace_Files_Exception(self::ERROR_PARAM_NO_NAME_CONTAINER);
        }
        if (empty($object)) {
            /**
             * @see Kynx_Service_Rackspace_Files_Exception
             */
            require_once 'Kynx/Service/Rackspace/Files/Exception.php';
            throw new Kynx_Service_Rackspace_Files_Exception(self::ERROR_PARAM_NO_NAME_OBJECT);
        }
        
        $headers = array(
            self::HEADER_CONTENT_TYPE => $this->getMimeTypeFromString($content),
            self::HEADER_TRANSFER_ENCODING => 'chunked');
        
        if (!empty($metadata) && is_array($metadata)) {
            foreach ($metadata as $key => $value) {
                $headers[self::METADATA_OBJECT_HEADER.$key]= $value;
            }
        }
        $this->chunkedObject = array('container' => $container, 'object' => $object);
        $this->getChecksummer()->open();
        $this->httpCall($this->getObjectUrl($container, $object), 'PUT', $headers, null, $content);
  
        return $this->getChecksummer()->append($content);
    }
    
    /**
     * Appends chunk to transfer
     * 
     * @todo Handle splitting files > 5G
     * 
     * @param string $data
     * @return integer     lenght appended, false on failu
     */
    public function appendStoreChunk($data)
    {
        $len = false;
        if ($this->getHttpClient()->appendChunk($data)) {
            $len = $this->getChecksummer()->append($data);
        }
        return $len;
    }
    
    /**
     * Signal that chunked transfer is ended
     * @return boolean
     */
    public function endStoreChunks() 
    {
        $result = $this->httpClient->endChunkedSend();
        $status = $result->getStatus();
        $rv = false;
        switch ($status) {
            case '201': 
                // only successful if MD5s match
                $rv = $result->getHeader(self::HEADER_HASH) == $this->getChecksummer()->getSum();
                if (!$rv) {
                    $this->deleteObject($this->chunkedObject['container'], $this->chunkedObject['object']);
                    $this->errorMsg = self::ERROR_OBJECT_CHECKSUM;
                }
                break;
            case '412':
                $this->errorMsg= self::ERROR_OBJECT_MISSING_PARAM;
                break;
            case '422':
                $this->errorMsg= self::ERROR_OBJECT_CHECKSUM;
                break;
            default:
                $this->errorMsg= $result->getBody();
                break;
        }
        if (!$rv) {
            $this->errorCode= $status;
        }
        $this->getChecksummer()->close();
        $this->chunkedObject = false;
        return $rv;
    }
    
    /**
     * Returns true if transfer in progress
     * @return boolean
     */
    public function isTransfering() 
    {
        return $this->getHttpClient()->isSendingChunked();
    }
    
    /**
     * Override base class to add keepalive option
     * @return Kynx_Http_Client_Chunked
     */
    public function getHttpClient()
    {
        if (empty($this->httpClient)) {
            /**
             * @see Kynx_Http_Client_Chunked 
             */
            require_once 'Kynx/Http/Client/Chunked.php';
            $this->httpClient = new Kynx_Http_Client_Chunked(null, array(/*'keepalive' => true*/));
        }
        return $this->httpClient;
    }
    
    /**
     * Register this object as stream wrapper client
     *
     * @param  string $name
     * @return Kynx_Service_Rackspace_Files
     */
    public function registerAsClient($name)
    {
        self::$wrapperClients[$name] = $this;
        return $this;
    }

    /**
     * Unregister this object as stream wrapper client
     *
     * @param  string $name
     * @return Kynx_Service_Rackspace_Files
     */
    public function unregisterAsClient($name)
    {
        unset(self::$wrapperClients[$name]);
        return $this;
    }
 
    /**
     * Get wrapper client for stream type
     *
     * @param  string $name
     * @return Kynx_Service_Rackspace_Files
     */
    public static function getWrapperClient($name)
    {
        return self::$wrapperClients[$name];
    }
    
    /**
     * Returns options for stream wrapper
     * 
     * @return array
     */
    public function getWrapperOptions()
    {
        return $this->wrapperOptions;
    }

    /**
     * Register this object as stream wrapper
     *
     * @param  string $name
     * @param integer $rangeSize Size of range to fetch when reading
     */
    public function registerStreamWrapper($name=self::WRAPPER_NAME, $options = array())
    {
        /**
         * @see Kynx_Service_Rackspace_Files_Stream
         */
        require_once 'Kynx/Service/Rackspace/Files/Stream.php';
        stream_register_wrapper($name, 'Kynx_Service_Rackspace_Files_Stream');
        $this->registerAsClient($name);
        $this->wrapperOptions = $options;
    }

    /**
     * Unregister this object as stream wrapper
     *
     * @param  string $name
     */
    public function unregisterStreamWrapper($name=self::WRAPPER_NAME)
    {
        stream_wrapper_unregister($name);
        $this->unregisterAsClient($name);
    }
    
    /**
     * Gets checksumming class
     *
     * @return Kynx_Service_Rackspace_Files_Checksum_Interface
     */
    public function getChecksummer()
    {
        if (!($this->checkSummer instanceof Kynx_Service_Rackspace_Files_Checksum_Interface)) {
            /**
             * @see Kynx_Service_Rackspace_Files_Checksum_Tempfile
             */
            require_once 'Kynx/Service/Rackspace/Files/Checksum/Tempfile.php';
            $this->checkSummer = new Kynx_Service_Rackspace_Files_Checksum_Tempfile();
        }
        return $this->checkSummer;
    }
    
    /**
     * Sets checksumming class
     * 
     * $param Kynx_Service_Rackspace_Files_Checkum $checksummer
     */
    public function setChecksummer(Kynx_Service_Rackspace_Files_Checksum_Interface $checksummer)
    {
        $this->checkSummer = $checksummer;
    }
 
    /**
     * Returns URL for object in given container
     * 
     * @param type $container
     * @param type $object
     * @return type
     * @throws Kynx_Service_Rackspace_Files_Exception
     */
     protected function getObjectUrl($container, $object) {
        if (strlen(rawurlencode($container)) > 256 || strstr($container, '/')) {
            /**
             * @see Kynx_Service_Rackspace_Files_Exception
             */
            require_once 'Kynx/Service/Rackspace/Files/Exception.php';
            throw new Kynx_Service_Rackspace_Files_Exception("Invalid container name");
        }
        $path = explode('/', $object);
        if (count($path) && empty($path[0])) unset($path[0]);

        $container = rawurlencode($container);
        $object = rawurlencode(join('/', $path));
        if (strlen($object) > 1024) {
            /**
             * @see Kynx_Service_Rackspace_Files_Exception
             */
            require_once 'Kynx/Service/Rackspace/Files/Exception.php';
            throw new Kynx_Service_Rackspace_Files_Exception("Object name must not excede 1024 characters");
        }
        return $this->getStorageUrl() . '/' . $container . '/' . $object;
    }
    
    /**
     * Attempts to determine mime type of given content
     * @param string $content
     * @return string
     */
    protected function getMimeTypeFromString($content)
    {
        static $finfo = false;
        $mimeType = '';
        if (class_exists('finfo')) {
            if (!$finfo) {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
            }
            $mimeType = $finfo->buffer($content);
        }
        elseif (function_exists('mime_content_type')) {
            $tmpname = tempnam(sys_get_temp_dir(), 'mime');
            if (file_put_contents($tmpname, $content)) {
                $mimeType = mime_content_type($tmpname);
            }
            @unlink($tmpname);
        }
        return $mimeType ? $mimeType : 'application/octet-stream';
     }
     
    /**
     * Overrides Zend_Service_Rackpsace_Abstract::httpCall() to send Transfer-Encoding: chunked
     *
     * @param string $url
     * @param string $method
     * @param array $headers
     * @param array $get
     * @param string $body
     * @return Zend_Http_Response
     */
    protected function httpCall($url,$method,$headers=array(),$data=array(),$body=null)
    {
        $client = $this->getHttpClient();
        $client->resetParameters(true);
        if (empty($headers[self::AUTHUSER_HEADER])) {
            $headers[self::AUTHTOKEN]= $this->getToken();
        } 
        $client->setMethod($method);
        if (empty($data['format'])) {
            $data['format']= self::API_FORMAT;
        }
        $client->setParameterGet($data);    
        if (!empty($body)) {
            $client->setRawData($body);
            if (!isset($headers['Content-Type'])) {
                $headers['Content-Type']= 'application/json';
            }
        }
        $client->setHeaders($headers);
        $client->setUri($url);
        $this->errorMsg='';
        $this->errorCode='';
        
        if (!empty($headers[self::HEADER_TRANSFER_ENCODING]) 
                && $headers[self::HEADER_TRANSFER_ENCODING] == 'chunked') {
            return $client->startChunkedSend();
        }
        return $client->request();
    }
}