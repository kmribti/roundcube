<?php

/*
 +-----------------------------------------------------------------------+
 | program/include/bootstrap.php                                         |
 |                                                                       |
 | This file is part of the RoundCube Webmail client                     |
 | Copyright (C) 2007, RoundCube Dev, - Switzerland                      |
 | Licensed under the GNU GPL                                            |
 |                                                                       |
 | PURPOSE:                                                              |
 |   Setup the application envoronment required to process               |
 |   any request.                                                        |
 +-----------------------------------------------------------------------+
 | Author: Tll Klampaeckel <till@php.net>                                |
 |         Thomas Bruederli <roundcube@gmail.com>                        |
 +-----------------------------------------------------------------------+

 $Id: cache.inc 88 2005-12-03 16:54:12Z roundcube $

*/


// application constants
define('RCMAIL_VERSION', 'devel-vnext (0.1-rc1)');
define('RCMAIL_CHARSET', 'UTF-8');
define('JS_OBJECT_NAME', 'rcmail');

if (!defined('INSTALL_PATH'))
  define('INSTALL_PATH', dirname($_SERVER['SCRIPT_FILENAME']).'/');

// make sure path_separator is defined
if (!defined('PATH_SEPARATOR')) {
    define('PATH_SEPARATOR', (eregi('win', PHP_OS) ? ';' : ':'));
}

// RC include folders MUST be included FIRST to avoid other
// possible not compatible libraries (i.e PEAR) to be included
// instead the ones provided by RC
$include_path = INSTALL_PATH . PATH_SEPARATOR;
$include_path.= INSTALL_PATH . 'program' . PATH_SEPARATOR;
$include_path.= INSTALL_PATH . 'program/lib' . PATH_SEPARATOR;
$include_path.= ini_get('include_path');

if (set_include_path($include_path) === false) {
    die('Fatal error: ini_set/set_include_path does not work.');
}

ini_set('session.name', 'roundcube_sessid');
ini_set('session.use_cookies', 1);
ini_set('session.gc_maxlifetime', 21600);
ini_set('session.gc_divisor', 500);
ini_set('error_reporting', E_ALL|E_STRICT);
set_magic_quotes_runtime(0);

// increase maximum execution time for php scripts
// (does not work in safe mode)
if (!ini_get('safe_mode')) {
    set_time_limit(120);
}

/**
 * Use PHP5 autoload for dynamic class loading
 */
function __autoload($classname) {
    $filename = preg_replace(
        array('/MDB2_(.+)/', '/Mail_(.+)/', '/^html_.+/'),
        array("MDB2/\\1", "Mail/\\1", "html"),
        $classname
    );

    include_once $filename. '.php';
}

// include global functions
require_once 'globals.php';

/**
 * Local callback function for PEAR errors
 */
function rcube_pear_error($err) {
  error_log(sprintf("%s (%s): %s",
    $err->getMessage(),
    $err->getCode(),
    $err->getUserinfo()), 0);
}

// set PEAR error handling
PEAR::setErrorHandling(PEAR_ERROR_CALLBACK, 'rcube_pear_error');

// create registry and set some global properties
$registry = rcube_registry::get_instance();
$registry->set('mbstring_loaded', null, 'core');


?>