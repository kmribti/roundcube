<?php

/*
 +-----------------------------------------------------------------------+
 | program/steps/mail/folders.inc                                        |
 |                                                                       |
 | This file is part of the RoundCube Webmail client                     |
 | Copyright (C) 2005, RoundCube Dev. - Switzerland                      |
 | Licensed under the GNU GPL                                            |
 |                                                                       |
 | PURPOSE:                                                              |
 |   Implement folder operations line EXPUNGE and Clear                  |
 |                                                                       |
 +-----------------------------------------------------------------------+
 | Author: Thomas Bruederli <roundcube@gmail.com>                        |
 +-----------------------------------------------------------------------+

 $Id: folders.inc 573 2007-05-18 11:29:25Z thomasb $
*/
$registry  = rcube_registry::get_instance();
$IMAP      = $registry->get('IMAP', 'core');
$mbox_name = $IMAP->get_mailbox_name();

// send EXPUNGE command
if ($_action=='expunge' && ($mbox = rcube::get_input_value('_mbox', rcube::INPUT_POST))) {
    $success = $IMAP->expunge($mbox);

    //rcube::tfk_debug('Expung: ' . $mbox);

    // reload message list if current mailbox
    if ($success && empty($_REQUEST['_reload']) === false) {
        $OUTPUT->command('message_list.clear');
        $_action = 'list';

        //rcube::tfk_debug('Trying to reload list.');

        $_file = dirname(__FILE__);
        $_file.= '/list.inc';
        include $_file;

        //$OUTPUT->send($_task);

        return;
    }
    else {
        $commands = "// expunged: $success\n";
    }
}
// clear mailbox
elseif ($_action=='purge' && ($mbox = rcube::get_input_value('_mbox', rcube::INPUT_POST))) {
    $success = $IMAP->clear_mailbox($mbox);

    if ($success && !empty($_REQUEST['_reload'])) {
        $OUTPUT->set_env('messagecount', 0);
        $OUTPUT->set_env('pagecount', 0);
        $OUTPUT->command('message_list.clear');
        $OUTPUT->command('set_rowcount', rcmail_get_messagecount_text());
        $OUTPUT->command('set_unread_count', $mbox_name, 0);
    }
    else {
        $commands = "// purged: $success";
    }
}
$OUTPUT->send($commands);
?>
