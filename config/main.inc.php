<?php

/*
 +-----------------------------------------------------------------------+
 | Main configuration file                                               |
 |                                                                       |
 | This file is part of the RoundCube Webmail client                     |
 | Copyright (C) 2005, RoundCube Dev. - Switzerland                      |
 | All rights reserved.                                                  |
 |                                                                       |
 +-----------------------------------------------------------------------+

*/

$rcmail_config = array();


// system error reporting: 1 = log; 2 = report (not implemented yet), 4 = show
$rcmail_config['debug_level'] = 5;

// automatically create a new user when log-in the first time
// set to false if only registered users can use this service
$rcmail_config['auto_create_user'] = TRUE;

// the mail host chosen to perform the log-in
// leave blank to show a textbox at login, give a list of hosts
// to display a pulldown menu or set one host as string
$rcmail_config['default_host'] = '';

// use this host for sending mails.
// if left blank, the PHP mail() function is used
$rcmail_config['smtp_server'] = '';

// SMTP username (if required)
$rcmail_config['smtp_user'] = '';

// SMTP password (if required)
$rcmail_config['smtp_pass'] = '';

// Log sent messages
$rcmail_config['smtp_log'] = TRUE;

// these cols are shown in the message list
// available cols are: subject, from, to, cc, replyto, date, size, encoding
$rcmail_config['list_cols'] = array('subject', 'from', 'date', 'size');

// relative path to the skin folder
$rcmail_config['skin_path'] = 'skins/default/';

// use this folder to store temp files (must be writebale for apache user)
$rcmail_config['temp_dir'] = 'temp/';

// check client IP in session athorization
$rcmail_config['ip_check'] = TRUE;

// not shure what this was good for :-) 
$rcmail_config['locale_string'] = 'de_DE';

// use this format for short date display
$rcmail_config['date_short'] = 'D H:i';

// use this format for detailed date/time formatting
$rcmail_config['date_long'] = 'd.m.Y H:i';

// add this user-agent to message headers when sending
$rcmail_config['useragent'] = 'RoundCube Webmail/0.1a';

// only list folders within this path
$rcmail_config['imap_root'] = '';

// store sent message is this mailbox
// leave blank if sent messages should not be stored
$rcmail_config['sent_mbox'] = 'Sent';

// move messages to this folder when deleting them
// leave blank if they should be deleted directly
$rcmail_config['trash_mbox'] = 'Trash';

// display these folders separately in the mailbox list
$rcmail_config['default_imap_folders'] = array('INBOX', 'Drafts', 'Sent', 'Junk', 'Trash');


/***** these settings can be overwritten by user's preferences *****/

// show up to X items in list view
$rcmail_config['pagesize'] = 40;

// use this timezone to display date/time
$rcmail_config['timezone'] = 1;

// prefer displaying HTML messages
$rcmail_config['prefer_html'] = TRUE;

// show pretty dates as standard
$rcmail_config['prettydate'] = TRUE;


// end of config file
?>
