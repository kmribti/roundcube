<?php
// application constants
define('RCMAIL_VERSION', 'devel-vnext (0.1-rc1)');
define('RCMAIL_CHARSET', 'UTF-8');
define('JS_OBJECT_NAME', 'rcmail');

// define global vars
$OUTPUT_TYPE  = 'html';
$MAIN_TASKS   = array(
                    'mail',
                    'settings',
                    'logout',
                    'plugin'
); // addressbook

if (isset($INSTALL_PATH) === false || empty($INSTALL_PATH) === true) {
    $INSTALL_PATH = './';
}
else {
    if (substr($INSTALL_PATH, -16) == '/program/include') {
        $INSTALL_PATH = str_replace('/program/include', '', $INSTALL_PATH);
    }
    $INSTALL_PATH.= '/';
}

// make sure path_separator is defined
if (!defined('PATH_SEPARATOR')) {
    define('PATH_SEPARATOR', (eregi('win', PHP_OS) ? ';' : ':'));
}

// RC include folders MUST be included FIRST to avoid other
// possible not compatible libraries (i.e PEAR) to be included
// instead the ones provided by RC
$include_path = $INSTALL_PATH . PATH_SEPARATOR;
$include_path.= $INSTALL_PATH . 'program' . PATH_SEPARATOR;
$include_path.= $INSTALL_PATH . 'program/lib' . PATH_SEPARATOR;
$include_path.= '/usr/share/Zend-SVN/library' . PATH_SEPARATOR;
$include_path.= ini_get('include_path');

//echo 'Before: ' . $include_path;
$status = ini_set('include_path', $include_path);
if ($status === false) {
    die('Fatal error: ini_set does not work.');
}

ini_set('session.name', 'sessid');
ini_set('session.use_cookies', 1);
ini_set('session.gc_maxlifetime', 21600);
ini_set('session.gc_divisor', 500);
ini_set('error_reporting', E_ALL&~E_NOTICE);

// increase maximum execution time for php scripts
// (does not work in safe mode)
if (!ini_get('safe_mode')) {
    @set_time_limit(120);
}

require_once 'rcube/registry.php';
?>