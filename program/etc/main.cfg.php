<?php
define('RCMAIL_VERSION', 'vNext');

define('ROOT', $_SERVER['DOCUMENT_ROOT'] . '/devel-vnext');

// define global vars
$CHARSET = 'UTF-8';
$OUTPUT_TYPE = 'html';
$JS_OBJECT_NAME = 'rcmail';
$INSTALL_PATH = dirname(__FILE__);
$MAIN_TASKS = array('mail','settings','addressbook','logout');

$INSTALL_PATH = ROOT;

if (empty($INSTALL_PATH))
  $INSTALL_PATH = './';
else
  $INSTALL_PATH .= '/';


// make sure path_separator is defined
if (!defined('PATH_SEPARATOR'))
  define('PATH_SEPARATOR', (eregi('win', PHP_OS) ? ';' : ':'));

// Pear dependencies - using the global install
// TODO: add to local lib folder to distribute libs with it
// TODO: think about a PEAR package or at least a PEAR based installer
require_once 'PEAR/Exception.php';
require_once 'PEAR.php';
require_once 'Services/JSON.php';

// RC include folders MUST be included FIRST to avoid other
// possible not compatible libraries (i.e PEAR) to be included
// instead the ones provided by RC
ini_set('include_path', $INSTALL_PATH.PATH_SEPARATOR.$INSTALL_PATH.'program'.PATH_SEPARATOR.$INSTALL_PATH.'program/lib'.PATH_SEPARATOR.ini_get('include_path'));

ini_set('session.name', 'sessid');
ini_set('session.use_cookies', 1);
ini_set('session.gc_maxlifetime', 21600);
ini_set('session.gc_divisor', 500);
ini_set('error_reporting', E_ALL); 

session_start();

// increase maximum execution time for php scripts
// (does not work in safe mode)
@set_time_limit(120);

// include base files
require_once ROOT . '/program/include/rcube_shared.inc.php';
require_once ROOT . '/program/include/rcube_imap.inc.php';
require_once ROOT . '/program/include/bugs.inc';
require_once ROOT . '/program/include/main.inc.php';
require_once ROOT . '/program/include/cache.inc';


// set PEAR error handling
// PEAR::setErrorHandling(PEAR_ERROR_TRIGGER, E_USER_NOTICE);
?>
