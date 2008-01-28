<?php
/*
 +-----------------------------------------------------------------------+
 | program/steps/mail/move_del.inc                                       |
 |                                                                       |
 | This file is part of the RoundCube Webmail client                     |
 | Copyright (C) 2005-2008, RoundCube Dev. - Switzerland                 |
 | Licensed under the GNU GPL                                            |
 |                                                                       |
 | PURPOSE:                                                              |
 |   Move the submitted messages to a specific mailbox or delete them    |
 |                                                                       |
 +-----------------------------------------------------------------------+
 | Author: Thomas Bruederli <roundcube@gmail.com>                        |
 +-----------------------------------------------------------------------+

 $Id: move_del.inc 573 2007-05-18 11:29:25Z thomasb $

*/

$registry = rcube_registry::get_instance();
$IMAP     = $registry->get('IMAP', 'core');
$OUTPUT   = $registry->get('OUTPUT', 'core');

// count messages before changing anything
$old_count = $IMAP->messagecount();
$old_pages = ceil($old_count / $IMAP->page_size);

// move messages
if ($_action=='moveto' && !empty($_POST['_uid']) && !empty($_POST['_target_mbox'])) {
    $count  = sizeof(explode(',', ($uids = rcube::get_input_value('_uid', rcube::INPUT_POST))));
    $target = rcube::get_input_value('_target_mbox', rcube::INPUT_POST);
    $moved  = $IMAP->move_message($uids, $target, rcube::get_input_value('_mbox', rcube::INPUT_POST));

    //rcube::tfk_debug('Moved?: ' . $target . '/' . $uids . '/' . $moved);

    if (!$moved) {
        // send error message
        $OUTPUT->command('list_mailbox');
        $OUTPUT->show_message('errormoving', 'error');
        $OUTPUT->send();
        exit;
    }
} else if ($_action=='delete' && !empty($_POST['_uid'])) { // delete messages
    $count = sizeof(explode(',', ($uids = rcube::get_input_value('_uid', rcube::INPUT_POST))));
    $del   = $IMAP->delete_message($uids, rcube::get_input_value('_mbox', rcube::INPUT_POST));

    if (!$del) {
        // send error message
        $OUTPUT->command('list_mailbox');
        $OUTPUT->show_message('errordeleting', 'error');
        $OUTPUT->send();
        exit;
    }
} else { // unknown action or missing query param
    //rcube::tfk_debug('/ unknown action');
    exit;
}

// refresh saved seacrh set after moving some messages
if (
    ($search_request = rcube::get_input_value('_search', rcube::INPUT_GPC))
    && $IMAP->search_set) {
    $_SESSION['search'][$search_request] = $IMAP->refresh_search();
}

$msg_count      = $IMAP->messagecount();
$pages          = ceil($msg_count / $IMAP->page_size);
$nextpage_count = $old_count - $IMAP->page_size * $IMAP->list_page;
$remaining      = $msg_count - $IMAP->page_size * ($IMAP->list_page - 1);

// jump back one page (user removed the whole last page)
if ($IMAP->list_page > 1 && $nextpage_count <= 0 && $remaining == 0) {
    $IMAP->set_page($IMAP->list_page-1);
    $_SESSION['page'] = $IMAP->list_page;
    $jump_back = true;
}

// update message count display
$OUTPUT->set_env('pagecount', $pages);
$OUTPUT->set_env('current_page', $IMAP->list_page);
$OUTPUT->command('set_rowcount', rcmail_get_messagecount_text($msg_count));

// update mailboxlist
$mbox = $IMAP->get_mailbox_name();
$OUTPUT->command('set_unread_count', $mbox, $IMAP->messagecount($mbox, 'UNSEEN'));

if ($_action=='moveto' && $target) {
    $OUTPUT->command('set_unread_count', $target, $IMAP->messagecount($target, 'UNSEEN'));
}
$OUTPUT->command('set_quota', $IMAP->get_quota());

// add new rows from next page (if any)
if ($_POST['_from'] != 'show' && ($jump_back || $nextpage_count > 0)) {
    $sort_col   = isset($_SESSION['sort_col'])   ? $_SESSION['sort_col']   : $CONFIG['message_sort_col'];
    $sort_order = isset($_SESSION['sort_order']) ? $_SESSION['sort_order'] : $CONFIG['message_sort_order'];

    $a_headers = $IMAP->list_headers($mbox, NULL, $sort_col, $sort_order);
    if (!$jump_back) {
        $a_headers = array_slice($a_headers, -$count, $count);
    }

    rcmail_js_message_list($a_headers);
}

// send response
$OUTPUT->send();