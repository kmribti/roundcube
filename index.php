<?php
/*
 +-----------------------------------------------------------------------+
 | RoundCube Webmail IMAP Client                                         |
 | Version 0.1-20080328                                                  |
 |                                                                       |
 | Copyright (C) 2005-2008, RoundCube Dev. - Switzerland                 |
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

 $Id$

*/

// include environment
require_once 'program/include/iniset.php';

// define global vars
$OUTPUT_TYPE = 'html';
$MAIN_TASKS = array('mail','settings','addressbook','logout');

// catch some url/post parameters
$_task = strip_quotes(get_input_value('_task', RCUBE_INPUT_GPC));
$_action = strip_quotes(get_input_value('_action', RCUBE_INPUT_GPC));
$_framed = (!empty($_GET['_framed']) || !empty($_POST['_framed']));

// use main task if empty or invalid value
if (empty($_task) || !in_array($_task, $MAIN_TASKS))
  $_task = 'mail';


// set output buffering
if ($_action != 'get' && $_action != 'viewsource') {
  // use gzip compression if supported
  if (function_exists('ob_gzhandler')
      && !ini_get('zlib.output_compression')
      && ini_get('output_handler') != 'ob_gzhandler') {
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


// init output class
if (!empty($_GET['_remote']) || !empty($_POST['_remote'])) {
  rcmail_init_json();
}
else {
  rcmail_load_gui();
}


// check DB connections and exit on failure
if ($err_str = $DB->is_error()) {
  raise_error(array(
    'code' => 603,
    'type' => 'db',
    'message' => $err_str), FALSE, TRUE);
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
  else if ($_SESSION['temp'] && !empty($_POST['_user']) && isset($_POST['_pass']) &&
           rcmail_login(trim(get_input_value('_user', RCUBE_INPUT_POST), ' '),
              get_input_value('_pass', RCUBE_INPUT_POST, true, 'ISO-8859-1'), $host)) {
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
    $OUTPUT->show_message($IMAP->error_code == -1 ? 'imaperror' : 'loginfailed', 'warning');
    rcmail_kill_session();
  }
}

// end session
else if (($_task=='logout' || $_action=='logout') && isset($_SESSION['user_id'])) {
  $OUTPUT->show_message('loggedout');
  rcmail_logout_actions();
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
if (!empty($USER->ID) && $_task=='mail') {
  $conn = $IMAP->connect($_SESSION['imap_host'], $_SESSION['username'], decrypt_passwd($_SESSION['password']), $_SESSION['imap_port'], $_SESSION['imap_ssl']);
  if (!$conn) {
    $OUTPUT->show_message($IMAP->error_code == -1 ? 'imaperror' : 'sessionerror', 'error');
    rcmail_kill_session();
  }
  else {
    rcmail_set_imap_prop();
  }
}


// not logged in -> set task to 'login
if (empty($USER->ID)) {
  if ($OUTPUT->ajax_call)
    $OUTPUT->remote_response("setTimeout(\"location.href='\"+this.env.comm_path+\"'\", 2000);");
  
  $_task = 'login';
}


// check client X-header to verify request origin
if ($OUTPUT->ajax_call) {
  if (empty($CONFIG['devel_mode']) && !rc_request_header('X-RoundCube-Referer')) {
    header('HTTP/1.1 404 Not Found');
    die("Invalid Request");
  }
}


// set task and action to client
$OUTPUT->set_env('task', $_task);
if (!empty($_action)) {
  $OUTPUT->set_env('action', $_action);
}



// not logged in -> show login page
if (empty($USER->ID)) {
  // check if installer is still active
  if ($CONFIG['enable_installer'] && is_readable('./installer/index.php')) {
    $OUTPUT->add_footer(html::div(array('style' => "background:#ef9398; border:2px solid #dc5757; padding:0.5em; margin:2em auto; width:50em"),
      html::tag('h2', array('style' => "margin-top:0.2em"), "Installer script is still accessible") .
      html::p(null, "The install script of your RoundCube installation is still stored in its default location!") .
      html::p(null, "Please <b>remove</b> the whole <tt>installer</tt> folder from the RoundCube directory because .
        these files may expose sensitive configuration data like server passwords and encryption keys
        to the public. Make sure you cannot access the <a href=\"./installer/\">installer script</a> from your browser.")
      )
    );
  }
  
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

// include task specific files
if ($_task=='mail') {
  include_once('program/steps/mail/func.inc');
  
  if ($_action=='show' || $_action=='preview' || $_action=='print')
    include('program/steps/mail/show.inc');

  if ($_action=='get')
    include('program/steps/mail/get.inc');

  if ($_action=='moveto' || $_action=='delete')
    include('program/steps/mail/move_del.inc');

  if ($_action=='mark')
    include('program/steps/mail/mark.inc');

  if ($_action=='viewsource')
    include('program/steps/mail/viewsource.inc');

  if ($_action=='sendmdn')
    include('program/steps/mail/sendmdn.inc');

  if ($_action=='send')
    include('program/steps/mail/sendmail.inc');

  if ($_action=='upload')
    include('program/steps/mail/upload.inc');

  if ($_action=='compose' || $_action=='remove-attachment' || $_action=='display-attachment')
    include('program/steps/mail/compose.inc');

  if ($_action=='addcontact')
    include('program/steps/mail/addcontact.inc');

  if ($_action=='expunge' || $_action=='purge')
    include('program/steps/mail/folders.inc');

  if ($_action=='check-recent')
    include('program/steps/mail/check_recent.inc');

  if ($_action=='getunread')
    include('program/steps/mail/getunread.inc');
    
  if ($_action=='list' && isset($_REQUEST['_remote']))
    include('program/steps/mail/list.inc');

   if ($_action=='search')
     include('program/steps/mail/search.inc');
     
  if ($_action=='spell')
    include('program/steps/mail/spell.inc');

  if ($_action=='rss')
    include('program/steps/mail/rss.inc');
    
  // make sure the message count is refreshed
  $IMAP->messagecount($_SESSION['mbox'], 'ALL', true);
}


// include task specific files
if ($_task=='addressbook') {
  include_once('program/steps/addressbook/func.inc');

  if ($_action=='save')
    include('program/steps/addressbook/save.inc');
  
  if ($_action=='edit' || $_action=='add')
    include('program/steps/addressbook/edit.inc');
  
  if ($_action=='delete')
    include('program/steps/addressbook/delete.inc');

  if ($_action=='show')
    include('program/steps/addressbook/show.inc');  

  if ($_action=='list' && $_REQUEST['_remote'])
    include('program/steps/addressbook/list.inc');

  if ($_action=='search')
    include('program/steps/addressbook/search.inc');

  if ($_action=='copy')
    include('program/steps/addressbook/copy.inc');

  if ($_action=='mailto')
    include('program/steps/addressbook/mailto.inc');
}


// include task specific files
if ($_task=='settings') {
  include_once('program/steps/settings/func.inc');

  if ($_action=='save-identity')
    include('program/steps/settings/save_identity.inc');

  if ($_action=='add-identity' || $_action=='edit-identity')
    include('program/steps/settings/edit_identity.inc');

  if ($_action=='delete-identity')
    include('program/steps/settings/delete_identity.inc');
  
  if ($_action=='identities')
    include('program/steps/settings/identities.inc');  

  if ($_action=='save-prefs')
    include('program/steps/settings/save_prefs.inc');  

  if ($_action=='folders' || $_action=='subscribe' || $_action=='unsubscribe' ||
      $_action=='create-folder' || $_action=='rename-folder' || $_action=='delete-folder')
    include('program/steps/settings/manage_folders.inc');
}


// parse main template
$OUTPUT->send($_task);


// if we arrive here, something went wrong
raise_error(array(
  'code' => 404,
  'type' => 'php',
  'line' => __LINE__,
  'file' => __FILE__,
  'message' => "Invalid request"), true, true);
                      
?>
