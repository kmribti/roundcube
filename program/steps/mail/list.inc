<?php
/*
 +-----------------------------------------------------------------------+
 | program/steps/mail/list.inc                                           |
 |                                                                       |
 | This file is part of the RoundCube Webmail client                     |
 | Copyright (C) 2005, RoundCube Dev. - Switzerland                      |
 | Licensed under the GNU GPL                                            |
 |                                                                       |
 | PURPOSE:                                                              |
 |   Send message list to client (as remote response)                    |
 |                                                                       |
 +-----------------------------------------------------------------------+
 | Author: Thomas Bruederli <roundcube@gmail.com>                        |
 +-----------------------------------------------------------------------+

 $Id: list.inc 575 2007-05-18 12:35:28Z thomasb $

*/

$registry = rcube_registry::get_instance();
$OUTPUT   = $registry->get('OUTPUT', 'core');
$IMAP     = $registry->get('IMAP', 'core');
$CONFIG   = $registry->get_all('config');

$OUTPUT_TYPE = 'js';
$registry->set('OUTPUT_TYPE', $OUTPUT_TYPE, 'core');

// is there a sort type for this request?
if ($sort = rcube::get_input_value('_sort', rcube::INPUT_GET)) {
    // yes, so set the sort vars
    list($sort_col, $sort_order) = explode('_', $sort);

    // set session vars for sort (so next page and task switch know how to sort)
    $_SESSION['sort_col'] = $sort_col;
    $_SESSION['sort_order'] = $sort_order;
}
else {
    // use session settings if set, defaults if not
    $sort_col   = isset($_SESSION['sort_col'])   ? $_SESSION['sort_col']   : $CONFIG['message_sort_col'];
    $sort_order = isset($_SESSION['sort_order']) ? $_SESSION['sort_order'] : $CONFIG['message_sort_order'];
}

$mbox_name = $IMAP->get_mailbox_name();

// fetch message headers
if ($count = $IMAP->messagecount($mbox_name, 'ALL', !empty($_REQUEST['_refresh']))) {
    $a_headers = $IMAP->list_headers($mbox_name, NULL, $sort_col, $sort_order);
}
$unseen = $IMAP->messagecount($mbox_name, 'UNSEEN', !empty($_REQUEST['_refresh']));

// update message count display
$pages = ceil($count/$IMAP->page_size);
$OUTPUT->set_env('messagecount', $count);
$OUTPUT->set_env('pagecount', $pages);
$OUTPUT->command('set_rowcount', rcmail_get_messagecount_text($count));

// update mailboxlist
$OUTPUT->command('set_unread_count', $mbox_name, $unseen);

// add message rows
if (isset($a_headers) && count($a_headers)) {
    rcmail_js_message_list($a_headers);
}

// send response
$OUTPUT->send();
?>