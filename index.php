<?php
/*
 +-----------------------------------------------------------------------+
 | RoundCube Webmail IMAP Client                                         |
 | Version 0.1-rc1                                                       |
 |                                                                       |
 | Copyright (C) 2005-2007, RoundCube Dev. - Switzerland                 |
 | Licensed under the GNU GPL                                            |
 |                                                                       |
 | Redistribution and use in source and binary forms, with or without    |
 | modification, are permitted provided that the following conditions    |
 | are met:                                                              |
 |                                                                       |
 | o Redistributions of source code must retain the above copyright      |
 |   notice, this list of conditions and the following disclaimer.       |
 | o Redistributions in binary form must reproduce the above copyright   |
 |   notice, this list of conditions and the following disclaimer in the |
 |   documentation and/or other materials provided with the distribution.|
 | o The names of the authors may not be used to endorse or promote      |
 |   products derived from this software without specific prior written  |
 |   permission.                                                         |
 |                                                                       |
 | THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS   |
 | "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT     |
 | LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR |
 | A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT  |
 | OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, |
 | SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT      |
 | LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, |
 | DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY |
 | THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT   |
 | (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE |
 | OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.  |
 |                                                                       |
 +-----------------------------------------------------------------------+
 | Author: Thomas Bruederli <roundcube@gmail.com>                        |
 +-----------------------------------------------------------------------+

 $Id: index.php 579 2007-05-18 13:11:22Z thomasb $

*/

/**
 * tfk_debug
 *
 * @param string $str
 * @ignore
 */
function tfk_debug($str)
{
    $str = "\n\n" . @date('Y-m-d H:i:s') . "\n" . $str;
    $fp = @fopen(dirname(__FILE__) . '/logs/debug.tfk', 'a');
    if ($fp !== false) {
        @fwrite($fp, $str);
        @fclose($fp);
    } else {
        die('Could not open logs/debug.post.');
    }
}
/**
 * log all $_POST
 * @author Till Klampaeckel <till@php.net>
 * @ignore
 */
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    tfk_debug(var_export($_POST, true));
}


// application constants
define('RCMAIL_VERSION', '0.1-rc1');
define('RCMAIL_CHARSET', 'UTF-8');
define('JS_OBJECT_NAME', 'rcmail');

// define global vars
$OUTPUT_TYPE = 'html';
$INSTALL_PATH = dirname(__FILE__);
$MAIN_TASKS = array('mail','settings','addressbook','logout');

if (empty($INSTALL_PATH)) {
    $INSTALL_PATH = './';
}
else {
    $INSTALL_PATH .= '/';
}

// make sure path_separator is defined
if (!defined('PATH_SEPARATOR')) {
    define('PATH_SEPARATOR', (eregi('win', PHP_OS) ? ';' : ':'));
}

// RC include folders MUST be included FIRST to avoid other
// possible not compatible libraries (i.e PEAR) to be included
// instead the ones provided by RC
ini_set('include_path', $INSTALL_PATH.PATH_SEPARATOR.$INSTALL_PATH.'program'.PATH_SEPARATOR.$INSTALL_PATH.'program/lib'.PATH_SEPARATOR.ini_get('include_path'));

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

// include base files
require_once 'include/rcube_shared.inc';
require_once 'include/rcube_imap.inc';
require_once 'include/bugs.inc';
require_once 'include/main.inc';
require_once 'include/cache.inc';
require_once 'PEAR.php';


// set PEAR error handling
// PEAR::setErrorHandling(PEAR_ERROR_TRIGGER, E_USER_NOTICE);


// catch some url/post parameters
$_task = strip_quotes(get_input_value('_task', RCUBE_INPUT_GPC));
$_action = strip_quotes(get_input_value('_action', RCUBE_INPUT_GPC));
$_framed = (!empty($_GET['_framed']) || !empty($_POST['_framed']));

// use main task if empty or invalid value
if (empty($_task) || !in_array($_task, $MAIN_TASKS)) {
    $_task = 'mail';
}

// set output buffering
if ($_action != 'get' && $_action != 'viewsource') {
    // use gzip compression if supported
    if (function_exists('ob_gzhandler') && ini_get('zlib.output_compression')) {
        ob_start('ob_gzhandler');
    }
    else {
        ob_start();
    }
}


// start session with requested task
rcmail_startup($_task);

// set session related variables
$COMM_PATH = sprintf('./?_task=%s', $_task);
$SESS_HIDDEN_FIELD = '';


// add framed parameter
if ($_framed) {
    $COMM_PATH .= '&_framed=1';
    $SESS_HIDDEN_FIELD .= "\n".'<input type="hidden" name="_framed" value="1" />';
}


// init necessary objects for GUI
rcmail_load_gui();


// check DB connections and exit on failure
if ($err_str = $DB->is_error()) {
    raise_error(array(
        'code' => 603,
        'type' => 'db',
        'message' => $err_str), FALSE, TRUE
    );
}


// error steps
if ($_action=='error' && !empty($_GET['_code'])) {
    raise_error(array('code' => hexdec($_GET['_code'])), FALSE, TRUE);
}

// try to log in
if ($_action=='login' && $_task=='mail') {
    $host = rcmail_autoselect_host();

    // check if client supports cookies
    if (empty($_COOKIE)) {
        $OUTPUT->show_message("cookiesdisabled", 'warning');
    }
    elseif (
        $_SESSION['temp']
        && !empty($_POST['_user'])
        && isset($_POST['_pass'])
        && rcmail_login(
                get_input_value('_user', RCUBE_INPUT_POST),
                get_input_value('_pass', RCUBE_INPUT_POST, true, 'ISO-8859-1'),
                $host
        )
    ) {
        // create new session ID
        unset($_SESSION['temp']);
        sess_regenerate_id();

        // send auth cookie if necessary
        rcmail_authenticate_session();

        // send redirect
        header("Location: $COMM_PATH");
        exit;
    }
    else {
        $OUTPUT->show_message("loginfailed", 'warning');
        $_SESSION['user_id'] = '';
    }
}

// end session
else if (($_task=='logout' || $_action=='logout') && isset($_SESSION['user_id'])) {
    $OUTPUT->show_message('loggedout');
    rcmail_kill_session();
}

// check session and auth cookie
else if ($_action != 'login' && $_SESSION['user_id'] && $_action != 'send') {
    if (!rcmail_authenticate_session()) {
        $OUTPUT->show_message('sessionerror', 'error');
        rcmail_kill_session();
    }
}


// log in to imap server
if (!empty($_SESSION['user_id']) && $_task=='mail') {
    $conn = $IMAP->connect(
                $_SESSION['imap_host'],
                $_SESSION['username'],
                decrypt_passwd($_SESSION['password']),
                $_SESSION['imap_port'],
                $_SESSION['imap_ssl']
    );
    if (!$conn) {
        $OUTPUT->show_message('imaperror', 'error');
        $_SESSION['user_id'] = '';
    }
    else {
        rcmail_set_imap_prop();

    }
}


// not logged in -> set task to 'login
if (empty($_SESSION['user_id'])) {
    if ($OUTPUT->ajax_call){
        $OUTPUT->remote_response("setTimeout(\"location.href='\"+this.env.comm_path+\"'\", 2000);");
    }
    $_task = 'login';
}



// set task and action to client
$OUTPUT->set_env('task', $_task);
if (!empty($_action)) {
    $OUTPUT->set_env('action', $_action);
}


// not logged in -> show login page
if (!$_SESSION['user_id']) {
    $OUTPUT->task = 'login';
    $OUTPUT->send('login');
    exit;
}


// handle keep-alive signal
if ($_action=='keep-alive') {
    $OUTPUT->reset();
    $OUTPUT->send('');
    exit;
}

/**
 * $_name
 *
 * Used to build the filename for the include.
 * @var string
 */
$_name = '';

// include task specific files
if ($_task=='mail') {
    include_once 'program/steps/mail/func.inc';

    switch($_action) {
        default:
            $_name.= $_action;
            break;

        case 'preview':
        case 'print':
            $_name.= 'show';
            break;

        case 'moveto':
        case 'delete':
            $_name.= 'move_del';
            break;

        case 'send':
            $_name.= 'sendmail';
            break;

        case 'remove-attachment':
            $_name.= 'compose';
            break;

        case 'expunge':
        case 'purge':
            $_name.= 'folders';
            break;
        case 'list':
            if (isset($_REQUEST['_remote']) === true) {
                $_name.= 'list';
            }
            break;
    }

    // make sure the message count is refreshed
    $IMAP->messagecount($_SESSION['mbox'], 'ALL', TRUE);
}

// include task specific files
if ($_task=='addressbook') {
    include_once 'program/steps/addressbook/func.inc';

    switch($_action) {
        default:
            $_name.= $_action;
            break;
        case 'edit':
        case 'add':
            $_name.= 'edit';
            break;

        case 'list':
            if (isset($_REQUEST['_remote']) === true) {
                $_name.= $_action;
            }
            break;
    }
}

// include task specific files
if ($_task=='settings') {
    include_once 'program/steps/settings/func.inc';

    $_name = '';
    switch($_action) {
        default:
            $_name.= $_action;
            break;

        case 'add-identity':
            $_name.= 'edit_identity';
            break;

        case 'folders':
        case 'subscribe':
        case 'unsubscribe':
        case 'create-folder':
        case 'rename-folder':
        case 'delete-folder':
            $_name.= 'manage_folders';
            break;

    }
    $_name = str_replace('-', '_', $_name);
}

if (empty($_name) === false) {
    $_file = dirname(__FILE__) . '/program/steps/';
    $_file.= $_task . '/';
    $_file.= $_name . '.inc';
    if (file_exists($_file) === true) {
        include $_file;
    }
}

// parse main template
$OUTPUT->send($_task);

// if we arrive here, something went wrong
raise_error(
    array(
        'code' => 404,
        'type' => 'php',
        'line' => __LINE__,
        'file' => __FILE__,
        'message' => "Invalid request"
    ),
    TRUE,
    TRUE
);
?>