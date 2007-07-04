<?php
/**
 * bootstrap.php
 *
 * @package macbay_filter
 */

/**
 * add to include path
 * @ignore
 */
set_include_path(get_include_path() . PATH_SEPARATOR . '/usr/share/Zend-SVN/library/');

/**
 * Zend_XmlRpc_Client
 * @ignore
 */
require_once 'Zend/XmlRpc/Client.php';
$endpoint = 'http://preview.macbay.de/config/xmlrpc/cli';

/**
 * Create a Zend_XmlRpc_Client for later use.
 * @ignore
 */
$mb_client = new Zend_XmlRpc_Client($endpoint);
?>