<?php
/**
 * @category   Kynx
 * @package    Kynx_Service
 * @subpackage Rackspace
 * @copyright  Copyright (c) 2012 Matt Kynaston (http://www.kynx.org)
 * @license    https://github.com/kynx/Kynx/blob/master/LICENSE New BSD
 */
/**
 * @see Zend_Service_Rackspace_Files_Object
 */
require_once 'Zend/Service/Rackspace/Files/Object.php';
/**
 * Extends Zend_Service_Rackspace_Files_Object to allow listing of psuedo-directories
 * 
 * @category   Kynx
 * @package    Kynx_Service
 * @subpackage Rackspace
 * @copyright  Copyright (c) 2012 Matt Kynaston (http://www.kynx.org)
 * @license    https://github.com/kynx/Kynx/blob/master/LICENSE New BSD
 */
class Kynx_Service_Rackspace_Files_Object extends Zend_Service_Rackspace_Files_Object
{
    public function __construct($service,$data)
    {
        if (array_key_exists('subdir', $data)) {
            $this->name = $data['subdir'];
            $this->container = $data['container'];
            $this->service = $service;
        }
        else {
            parent::__construct($service, $data);
        }
    }
}