<?php
/*
 +-----------------------------------------------------------------------+
 | RoundCube Webmail IMAP Client                                         |
 | Version 0.1-devel-vnext                                               |
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

// bootstrap
require_once 'program/include/bootstrap.php';

$BASE_URI = str_replace($_SERVER['QUERY_STRING'], '', $_SERVER['REQUEST_URI']);
if (substr($BASE_URI, -1, 1) == '?') {
    $BASE_URI = substr($BASE_URI, 0, -1);
}

$MAIN_TASKS = array(
    'mail',
    'settings',
    'logout',
    'plugin',
    'addressbook',
);

// catch some url/post parameters
$_task   = strip_quotes(rcube::get_input_value('_task', rcube::INPUT_GPC));
$_action = strip_quotes(rcube::get_input_value('_action', rcube::INPUT_GPC));
$_framed = (!empty($_GET['_framed']) || !empty($_POST['_framed']));

// use main task if empty or invalid value
if (empty($_task) || !in_array($_task, $MAIN_TASKS)) {
    $_task = 'mail';
}

// start session with requested task
rcube::startup($_task);

//rcube::tfk_debug('// startup');

// set session related variables
$COMM_PATH = sprintf('%s?_task=%s', $BASE_URI, $_task);
$SESS_HIDDEN_FIELD = '';

// add framed parameter
if ($_framed) {
    $COMM_PATH .= '&_framed=1';
    $SESS_HIDDEN_FIELD .= "\n" . html::tag('input', array('type' => "hidden", 'name' => "_framed", 'value' => 1));
}

// set some global properties
$registry = rcube_registry::get_instance();
$registry->set('MAIN_TASKS', $MAIN_TASKS, 'core');
$registry->set('BASE_URI', $BASE_URI, 'core');
$registry->set('COMM_PATH', $COMM_PATH, 'core');
$registry->set('OUTPUT_TYPE', 'html', 'core');
$registry->set('OUTPUT_CHARSET', RCMAIL_CHARSET, 'core');
$registry->set('SESS_HIDDEN_FIELD', $SESS_HIDDEN_FIELD, 'core');


// init output class
if (!empty($_GET['_remote']) || !empty($_POST['_remote'])) {
    $registry->set('ajax_call', true, 'core');
    rcube::init_json();
}
else {
    $registry->set('ajax_call', false, 'core');
    rcube::load_gui();
}


$OUTPUT = $registry->get('OUTPUT', 'core');
$DB     = $registry->get('DB', 'core');


$OUTPUT->set_env('comm_path', $COMM_PATH);


// check DB connections and exit on failure
if (is_null($DB)) {
    rcube_error::raise(array(
        'code' => 603,
        'type' => 'db',
        'message' => 'No connection.'), false, true
    );
}
if ($err_str = $DB->is_error()) {
    rcube_error::raise(array(
        'code' => 603,
        'type' => 'db',
        'message' => $err_str), false, true
    );
}

//rcube::tfk_debug('// NO DB ERROR');

// error steps
if ($_action=='error' && !empty($_GET['_code'])) {
    rcube_error::raise(array('code' => hexdec($_GET['_code'])), false, true);
}

//rcube::tfk_debug('// going');

//rcube::tfk_debug("task {$_task} / action {$_action}");

// try to log in
if ($_action=='login' && $_task=='mail') {

    //rcube::tfk_debug('Here we go, a login.');

    $host = rcube::autoselect_host();

    //rcube::tfk_debug('Selected host: ' . $host);

    // check if client supports cookies
    if (empty($_COOKIE)) {
        $OUTPUT->show_message("cookiesdisabled", 'warning');
    }
    else if (
        $_SESSION['temp']
        && !empty($_POST['_user'])
        && isset($_POST['_pass'])
        && rcube::login(
                rcube::get_input_value('_user', rcube::INPUT_POST),
                rcube::get_input_value('_pass', rcube::INPUT_POST, true, 'ISO-8859-1'),
                $host
        )
    ) {
        // create new session ID
        unset($_SESSION['temp']);
        sess_regenerate_id();

        //rcube::tfk_debug('Yay, we log in.');

        // send auth cookie if necessary
        rcube::authenticate_session();

        // send redirect
        header("Location: $COMM_PATH");
        exit;
    }
    else {

        //rcube::tfk_debug('Oops, failed.');
        if (empty($_POST['_user']) === true) {
            //rcube::tfk_debug('Login: no _user');
        }
        if (isset($_POST['_pass']) === false) {
            //rcube::tfk_debug('Login: no _pass');
        }
        $status = rcube::login(
                    rcube::get_input_value('_user', rcube::INPUT_POST),
                    rcube::get_input_value('_pass', rcube::INPUT_POST, true, 'ISO-8859-1'),
                    $host
        );
        //rcube::tfk_debug('Login: status: ' . $status);

        //rcube::tfk_debug(var_export($_SESSION['temp'], true));
        //rcube::tfk_debug(date('Y-m-d H:i:s', $_SESSION['auth_time']));

        $OUTPUT->show_message("loginfailed", 'warning');
        $_SESSION['user_id'] = '';
    }
}

// end session
else if (($_task=='logout' || $_action=='logout') && isset($_SESSION['user_id'])) {
    $external_logout = $registry->get('external_logout', 'config');
    if (empty($external_logout) === false) {
        rcube::kill_session();
        header('Location:' . $external_logout);
        exit;
    }
    
    $OUTPUT->show_message('loggedout');
    rcube::kill_session();
}

// check session and auth cookie
else if ($_action != 'login' && $_SESSION['user_id'] && $_action != 'send') {
    if (!rcube::authenticate_session()) {
        $OUTPUT->show_message('sessionerror', 'error');
        rcube::kill_session();
    }
}

//rcube::tfk_debug('// going #2');

$IMAP = $registry->get('IMAP', 'core');
//rcube::tfk_debug(var_export($IMAP, true) . "\n\nIMAP LOADED.");

// log in to imap server
if (!empty($_SESSION['user_id']) && $_task == 'mail') {

    //rcube::tfk_debug('// trying to login');

    $conn = $IMAP->connect(
        $_SESSION['imap_host'],
        $_SESSION['username'],
        rcube::decrypt_passwd($_SESSION['password']),
        $_SESSION['imap_port'],
        $_SESSION['imap_ssl']
    );
    if (!$conn) {
        $OUTPUT->show_message('imaperror', 'error');
        $_SESSION['user_id'] = '';
    }
    else {
        rcube::set_imap_prop();
    }
}


// not logged in -> set task to 'login
if (empty($_SESSION['user_id'])) {

    //rcube::tfk_debug('// we need a login');

    if ($OUTPUT->ajax_call){
        $OUTPUT->reset();
        $OUTPUT->remote_response("setTimeout(\"location.href='\"+this.env.comm_path+\"'\", 2000);");
    }
    $_task = 'login';
}

//rcube::tfk_debug("// task {$_task} action {$_action}");

// check client X-header to verify request origin
if ($OUTPUT->ajax_call) {
    if (!$registry->get('devel_mode', 'config') && !rcube::get_request_header('X-RoundCube-Referer')) {
        header('HTTP/1.1 404 Not Found');
        die("Invalid Request");
    }
}

// set task and action to client
$OUTPUT->set_env('task', $_task);
if (empty($_action) === FALSE) {
    $OUTPUT->set_env('action', $_action);
}

// not logged in -> show login page
if (!$_SESSION['user_id']) {

    rcube::tfk_debug('// finally: login');

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

//rcube::tfk_debug("testing: $_task / $_action");

// include task specific files
if ($_task == 'mail') {
    include_once 'program/steps/mail/func.inc';

    switch($_action) {
        default:
            $_name.= $_action;
            break;

        case 'check-recent':
            $_name.= 'check_recent';
            //rcube::tfk_debug('We check recent!');
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

    //rcube::tfk_debug('Mail: ' . $_name);

    // make sure the message count is refreshed
    $IMAP->messagecount($_SESSION['mbox'], 'ALL', TRUE);
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

//rcube::tfk_debug($_task);

/**
 * plugin hook
 */
if ($_task == 'plugin') {
    $_name   = '';
    $_plugin = dirname(__FILE__) . '/plugins/' . $_action;
    if (file_exists($_plugin) !== TRUE) {
        //rcube::tfk_debug("$_plugin does not exist.");
        $_plugin = '';
    }
    else {
        $_plugin  = realpath($_plugin);
        $path_len = strlen(dirname(__FILE__) . '/plugins/');
        if (substr($_plugin, 0, $path_len) != dirname(__FILE__). '/plugins/') {
            rcube_error::raise(
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
            //rcube::tfk_debug('Possible hack.');
            exit;
        }
        $status = @include $_plugin;
        if ($status === FALSE) {
            //rcube::tfk_debug("Could not include: $_plugin");
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
        //rcube::tfk_debug('Does not exist: ' . $_file);
    }
}

// parse main template
$OUTPUT->send($_task);

// if we arrive here, something went wrong
rcube_error::raise(
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
