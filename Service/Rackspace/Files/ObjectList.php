<?php
/**
 * @category   Kynx
 * @package    Kynx_Service
 * @subpackage Rackspace
 * @copyright  Copyright (c) 2012 Matt Kynaston (http://www.kynx.org)
 * @license    https://github.com/kynx/Kynx/blob/master/LICENSE New BSD
 */
/**
 * @see Zend_Service_Rackspace_Files_ObjectList
 */
require_once 'Zend/Service/Rackspace/Files/ObjectList.php';
/**
 * Extends Zend_Service_Rackspace_Files_ObjectList to allow listing of psuedo-directories
 * 
 * @category   Kynx
 * @package    Kynx_Service
 * @subpackage Rackspace
 * @copyright  Copyright (c) 2012 Matt Kynaston (http://www.kynx.org)
 * @license    https://github.com/kynx/Kynx/blob/master/LICENSE New BSD
 */
class Kynx_Service_Rackspace_Files_ObjectList extends Zend_Service_Rackspace_Files_ObjectList implements Countable, Iterator, ArrayAccess
{
    
    /**
     * Construct
     *
     * @param  array $list
     * @return boolean
     */
    public function __construct($service,$list,$container)
    {
        if (!($service instanceof Zend_Service_Rackspace_Files)) {
            require_once 'Zend/Service/Rackspace/Files/Exception.php';
            throw new Zend_Service_Rackspace_Files_Exception("You must pass a Zend_Service_Rackspace_Files object");
        }
        if (!is_array($list)) {
            require_once 'Zend/Service/Rackspace/Files/Exception.php';
            throw new Zend_Service_Rackspace_Files_Exception("You must pass an array of data objects");
        }
        if (empty($container)) {
            require_once 'Zend/Service/Rackspace/Files/Exception.php';
            throw new Zend_Service_Rackspace_Files_Exception("You must pass the container of the object list");
        }
        $this->service= $service;
        $this->container= $container;
        $this->_constructFromArray($list);
    }
    /**
     * Transforms the Array to array of container
     *
     * @todo Why is this private in the base class?
     * @param  array $list
     * @return void
     */
    private function _constructFromArray(array $list)
    {
        foreach ($list as $obj) {
            $obj['container']= $this->container;
            $this->_addObject(new Kynx_Service_Rackspace_Files_Object($this->service,$obj));
        }
    }
}