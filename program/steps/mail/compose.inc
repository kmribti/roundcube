<?php

/*
 +-----------------------------------------------------------------------+
 | program/steps/mail/compose.inc                                        |
 |                                                                       |
 | This file is part of the RoundCube Webmail client                     |
 | Copyright (C) 2005, RoundCube Dev. - Switzerland                      |
 | Licensed under the GNU GPL                                            |
 |                                                                       |
 | PURPOSE:                                                              |
 |   Compose a new mail message with all headers and attachments         |
 |                                                                       |
 +-----------------------------------------------------------------------+
 | Author: Thomas Bruederli <roundcube@gmail.com>                        |
 +-----------------------------------------------------------------------+

 $Id: compose.inc 579 2007-05-18 13:11:22Z thomasb $

*/

require_once 'Mail/mimeDecode.php';
require_once 'lib/html2text.inc';

// define constants for message compose mode
define('RCUBE_COMPOSE_REPLY',   0x0106);
define('RCUBE_COMPOSE_FORWARD', 0x0107);
define('RCUBE_COMPOSE_DRAFT',   0x0108);


// remove an attachment
if ($_action == 'remove-attachment' && preg_match('/^rcmfile([0-9]+)$/', $_POST['_file'], $regs)) {
    $id = $regs[1];
    if (is_array($_SESSION['compose']['attachments'][$id]) !== false) {
        $status = @unlink($_SESSION['compose']['attachments'][$id]['path']);
        if ($status === false) {
            tfk_debug('Could not delete attachment.');
        }
        $_SESSION['compose']['attachments'][$id] = NULL;
        $OUTPUT->command('remove_from_attachment_list', "rcmfile$id");
        $OUTPUT->send();
        exit;
    }
}

// this version does not support HTML mails
$CONFIG['htmleditor'] = false;

$MESSAGE_FORM = NULL;
$MESSAGE      = NULL;

// Nothing below is called during message composition, only at "new/forward/reply/draft" initialization or
// if a compose-ID is given (i.e. when the compose step is opened in a new window/tab).
// Since there are many ways to leave the compose page improperly, it seems necessary to clean-up an old
// compose when a "new/forward/reply/draft" is called - otherwise the old session attachments will appear

if (!is_array($_SESSION['compose']) || $_SESSION['compose']['id'] != get_input_value('_id', RCUBE_INPUT_GET)) {
    rcmail_compose_cleanup();
    $_SESSION['compose'] = array('id' => uniqid(rand()));
}

// add some labels to client
rcube_add_label(
    'nosubject',
    'norecipientwarning',
    'nosubjectwarning',
    'nobodywarning',
    'notsentwarning',
    'savingmessage',
    'sendingmessage',
    'messagesaved',
    'converting'
);

// add config parameter to client script
$OUTPUT->set_env(
        'draft_autosave',
        !empty($CONFIG['drafts_mbox']) ? $CONFIG['draft_autosave'] : 0
);


// get reference message and set compose mode
if ($msg_uid = get_input_value('_reply_uid', RCUBE_INPUT_GET)) {
    $compose_mode = RCUBE_COMPOSE_REPLY;
}
elseif ($msg_uid = get_input_value('_forward_uid', RCUBE_INPUT_GET)) {
    $compose_mode = RCUBE_COMPOSE_FORWARD;
}
elseif ($msg_uid = get_input_value('_draft_uid', RCUBE_INPUT_GET)) {
    $compose_mode = RCUBE_COMPOSE_DRAFT;
}

if (empty($msg_uid) === false) {

    tfk_debug('We got uid: ' . $msg_uid);
    tfk_debug('Compose mode: '  . $compose_mode);

    // similar as in program/steps/mail/show.inc
    $MESSAGE = array('UID' => $msg_uid);
    $MESSAGE['headers']   = $IMAP->get_headers($msg_uid);
    $MESSAGE['structure'] = $IMAP->get_structure($msg_uid);
    $MESSAGE['subject']   = $IMAP->decode_header($MESSAGE['headers']->subject);
    $MESSAGE['parts']     = $IMAP->get_mime_numbers($MESSAGE['structure']);

    //tfk_debug('Reference message: ' . var_export($MESSAGE, true));

    if ($compose_mode == RCUBE_COMPOSE_REPLY) {
        $_SESSION['compose']['reply_uid']   = $msg_uid;
        $_SESSION['compose']['reply_msgid'] = $MESSAGE['headers']->messageID;
        $_SESSION['compose']['references']  = $MESSAGE['headers']->reference;
        $_SESSION['compose']['references'] .= !empty($MESSAGE['headers']->reference) ? ' ' : '';
        $_SESSION['compose']['references'] .= $MESSAGE['headers']->messageID;

        if (!empty($_GET['_all'])) {
            $MESSAGE['reply_all'] = 1;
        }
    }
    elseif ($compose_mode == RCUBE_COMPOSE_FORWARD) {
        $_SESSION['compose']['forward_uid'] = $msg_uid;
    }
    elseif ($compose_mode == RCUBE_COMPOSE_DRAFT) {
        $_SESSION['compose']['draft_uid'] = $msg_uid;
    }
}

/****** compose mode functions ********/
require_once 'lib/rcube/rcmail_compose.php';

tfk_debug('Passed require for rcmail_compose functions.');

// register UI objects
$OUTPUT->add_handlers(
            array(
                'composeheaders'        => 'rcmail_compose_headers',
                'composesubject'        => 'rcmail_compose_subject',
                'composebody'           => 'rcmail_compose_body',
                'composeattachmentlist' => 'rcmail_compose_attachment_list',
                'composeattachmentform' => 'rcmail_compose_attachment_form',
                'composeattachment'     => 'rcmail_compose_attachment_field',
                'priorityselector'      => 'rcmail_priority_selector',
                'receiptcheckbox'       => 'rcmail_receipt_checkbox',
            )
);


/****** get contacts for this user and add them to client scripts ********/
require_once 'include/rcube_contacts.inc';

$CONTACTS = new rcube_contacts($DB, $_SESSION['user_id']);
$CONTACTS->set_pagesize(1000);

if ($result = $CONTACTS->list_records()) {
    $a_contacts = array();
    while ($sql_arr = $result->iterate()) {
        if ($sql_arr['email']) {
            $a_contacts[] = format_email_recipient($sql_arr['email'], JQ($sql_arr['name']));
        }
    }
    $OUTPUT->set_env('contacts', $a_contacts);
}
$OUTPUT->parse('compose', true);
?>