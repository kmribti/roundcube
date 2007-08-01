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

$INSTALL_PATH = dirname(__FILE__);
require_once dirname(__FILE__) . '/program/include/bootstrap.php';

// include base files
require_once 'include/rcube_shared.inc';
require_once 'include/rcube_imap.inc';
require_once 'include/bugs.inc';
require_once 'include/main.inc';
require_once 'include/cache.inc';
require_once 'PEAR.php';

$RC_URI = str_replace(
                $_SERVER['QUERY_STRING'],
                '',
                $_SERVER['REQUEST_URI']
);
if (substr($RC_URI, -1, 1) == '?') {
    $RC_URI = substr($RC_URI, 0, -1);
}

//rc_main::tfk_debug($RC_URI);

$registry = rc_registry::getInstance();
$registry->set('INSTALL_PATH', $INSTALL_PATH, 'core');
$registry->set('s_mbstring_loaded', null, 'core');
$registry->set('sa_languages', null, 'core');
$registry->set('MAIN_TASKS', $MAIN_TASKS, 'core');
$registry->set('RC_URI', $RC_URI, 'core');

/**
 * log all $_POST
 * @author Till Klampaeckel <till@php.net>
 * @ignore
 */
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    //rc_main::tfk_debug(var_export($_POST, true));
    //rc_main::tfk_debug(var_export($_GET, true));
}
else {
    //rc_main::tfk_debug('We are GET.');
}

//rc_main::tfk_debug('Included all files!');

// set PEAR error handling
// PEAR::setErrorHandling(PEAR_ERROR_TRIGGER, E_USER_NOTICE);


// catch some url/post parameters
$_task   = rc_main::strip_quotes(rc_main::get_input_value('_task', RCUBE_INPUT_GPC));
$_action = rc_main::strip_quotes(rc_main::get_input_value('_action', RCUBE_INPUT_GPC));
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
rc_main::rcmail_startup($_task);

//rc_main::tfk_debug('// rcmail_startup');

// set session related variables
$COMM_PATH = sprintf('./?_task=%s', $_task);
$SESS_HIDDEN_FIELD = '';


// add framed parameter
if ($_framed) {
    $COMM_PATH .= '&_framed=1';
    $SESS_HIDDEN_FIELD .= "\n".'<input type="hidden" name="_framed" value="1" />';
}
$registry->set('COMM_PATH', $COMM_PATH, 'core');
$registry->set('SESS_HIDDEN_FIELD', $SESS_HIDDEN_FIELD, 'core');
$registry->set('s_username', '', 'core');

// init necessary objects for GUI
rc_main::rcmail_load_gui();

//rc_main::tfk_debug('// rcmail_load_gui');

$OUTPUT = $registry->get('OUTPUT', 'core');
$DB     = $registry->get('DB', 'core');

// check DB connections and exit on failure
if (is_null($DB)) {
    var_dump($DB); exit;
    rc_bugs::raise_error(array(
        'code' => 666,
        'type' => 'db',
        'message' => 'No connection.'), FALSE, TRUE
    );
}
if ($err_str = $DB->is_error()) {
    rc_bugs::raise_error(array(
        'code' => 603,
        'type' => 'db',
        'message' => $err_str), FALSE, TRUE
    );

    //rc_main::tfk_debug('// DB ERROR');
}

//rc_main::tfk_debug('// NO DB ERROR');

// error steps
if ($_action=='error' && !empty($_GET['_code'])) {
    rc_bugs::raise_error(array('code' => hexdec($_GET['_code'])), FALSE, TRUE);
}

//rc_main::tfk_debug('// going');

//rc_main::tfk_debug("task {$_task} / action {$_action}");

// try to log in
if ($_action=='login' && $_task=='mail') {

    //rc_main::tfk_debug('Here we go, a login.');

    $host = rc_main::rcmail_autoselect_host();

    //rc_main::tfk_debug('Selected host: ' . $host);

    // check if client supports cookies
    if (empty($_COOKIE)) {
        $OUTPUT->show_message("cookiesdisabled", 'warning');
    }
    elseif (
        $_SESSION['temp']
        && !empty($_POST['_user'])
        && isset($_POST['_pass'])
        && rc_main::rcmail_login(
                rc_main::get_input_value('_user', RCUBE_INPUT_POST),
                rc_main::get_input_value('_pass', RCUBE_INPUT_POST, true, 'ISO-8859-1'),
                $host
        )
    ) {
        // create new session ID
        unset($_SESSION['temp']);
        sess_regenerate_id();

        //rc_main::tfk_debug('Yay, we log in.');

        // send auth cookie if necessary
        rc_main::rcmail_authenticate_session();

        // send redirect
        header("Location: $COMM_PATH");
        exit;
    }
    else {

        //rc_main::tfk_debug('Oops, failed.');
        if (empty($_POST['_user']) === true) {
            //rc_main::tfk_debug('Login: no _user');
        }
        if (isset($_POST['_pass']) === false) {
            //rc_main::tfk_debug('Login: no _pass');
        }
        $status = rc_main::rcmail_login(
                    rc_main::get_input_value('_user', RCUBE_INPUT_POST),
                    rc_main::get_input_value('_pass', RCUBE_INPUT_POST, true, 'ISO-8859-1'),
                    $host
        );
        //rc_main::tfk_debug('Login: status: ' . $status);

        //rc_main::tfk_debug(var_export($_SESSION['temp'], true));
        //rc_main::tfk_debug(date('Y-m-d H:i:s', $_SESSION['auth_time']));

        $OUTPUT->show_message("loginfailed", 'warning');
        $_SESSION['user_id'] = '';
    }
}

// end session
else if (($_task=='logout' || $_action=='logout') && isset($_SESSION['user_id'])) {
    $OUTPUT->show_message('loggedout');
    rc_main::rcmail_kill_session();
}

// check session and auth cookie
else if ($_action != 'login' && $_SESSION['user_id'] && $_action != 'send') {
    if (!rc_main::rcmail_authenticate_session()) {
        $OUTPUT->show_message('sessionerror', 'error');
        rc_main::rcmail_kill_session();
    }
}

//rc_main::tfk_debug('// going #2');

$IMAP = $registry->get('IMAP', 'core');
//rc_main::tfk_debug(var_export($IMAP, true) . "\n\nIMAP LOADED.");

// log in to imap server
if (!empty($_SESSION['user_id']) && $_task=='mail') {

    //rc_main::tfk_debug('// trying to login');

    $conn = $IMAP->connect(
                $_SESSION['imap_host'],
                $_SESSION['username'],
                rc_main::decrypt_passwd($_SESSION['password']),
                $_SESSION['imap_port'],
                $_SESSION['imap_ssl']
    );
    if (!$conn) {
        $OUTPUT->show_message('imaperror', 'error');
        $_SESSION['user_id'] = '';
    }
    else {
        rc_main::rcmail_set_imap_prop();
    }
}


// not logged in -> set task to 'login
if (empty($_SESSION['user_id'])) {

    //rc_main::tfk_debug('// we need a login');

    if ($OUTPUT->ajax_call){
        $OUTPUT->remote_response("setTimeout(\"location.href='\"+this.env.comm_path+\"'\", 2000);");
    }
    $_task = 'login';
}

//rc_main::tfk_debug("// task {$_task} action {$_action}");

// set task and action to client
$OUTPUT->set_env('task', $_task);
if (empty($_action) === FALSE) {
    $OUTPUT->set_env('action', $_action);
}


// not logged in -> show login page
if (!$_SESSION['user_id']) {

    //rc_main::tfk_debug('// finally: login');

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

//rc_main::tfk_debug("testing: $_task / $_action");

// include task specific files
if ($_task == 'mail') {
    include_once 'program/steps/mail/func.inc';

    switch($_action) {
        default:
            $_name.= $_action;
            break;

        case 'check-recent':
            $_name.= 'check_recent';
            //rc_main::tfk_debug('We check recent!');
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

    //rc_main::tfk_debug('Mail: ' . $_name);

    // make sure the message count is refreshed
    $IMAP->messagecount($_SESSION['mbox'], 'ALL', TRUE);
    $registry->set('IMAP', $IMAP, 'core');
}

// include task specific files
if ($_task == 'addressbook') {
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
if ($_task == 'settings') {
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

//rc_main::tfk_debug($_task);

/**
 * plugin hook
 */
if ($_task == 'plugin') {
    $_name   = '';
    $_plugin = dirname(__FILE__) . '/plugins/' . $_action;
    if (file_exists($_plugin) !== TRUE) {
        //rc_main::tfk_debug("$_plugin does not exist.");
        $_plugin = '';
    }
    else {
        $_plugin  = realpath($_plugin);
        $path_len = strlen(dirname(__FILE__) . '/plugins/');
        if (substr($_plugin, 0, $path_len) != dirname(__FILE__). '/plugins/') {
            rc_bugs::raise_error(
                array(
                    'code'    => 500,
                    'type'    => 'php',
                    'line'    => __LINE__,
                    'file'    => __FILE__,
                    'message' => 'Plugin request not within webmail directory.'
                ),
                TRUE,
                TRUE
            );
            //rc_main::tfk_debug('Possible hack.');
            exit;
        }
        $status = @include $_plugin;
        if ($status === FALSE) {
            //rc_main::tfk_debug("Could not include: $_plugin");
        }
        exit;
    }
}

if (empty($_name) === false) {
    $_file = dirname(__FILE__) . '/program/steps/';
    $_file.= $_task . '/';
    $_file.= $_name . '.inc';
    if (file_exists($_file) === true) {
        include $_file;
    }
    else {
        //rc_main::tfk_debug('Does not exist: ' . $_file);
    }
}

// parse main template
$OUTPUT->send($_task);

// if we arrive here, something went wrong
rc_bugs::raise_error(
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
