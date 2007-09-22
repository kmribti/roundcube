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
$endpoint = 'http://www.macbay.de/config/xmlrpc/cli';

/**
 * Create a Zend_XmlRpc_Client for later use.
 * @ignore
 */
$mb_client = new Zend_XmlRpc_Client($endpoint);

/**
 * macbay_filter
 */
require_once dirname(__FILE__) . '/lib/macbay_filter.class.php';
$params = array();
array_push($params, $_SESSION['username']);
array_push($params, rcube::decrypt_passwd($_SESSION['password']));
$macbay_filter = new macbay_filter($mb_client, $params);


/**
 * MACBAY_FILTER_MADNESS
 *
 * @ignore
 */
define('MACBAY_FILTER_MADNESS', md5($_SERVER['HTTP_HOST'].time().$_SESSION['user_id']));
?>
