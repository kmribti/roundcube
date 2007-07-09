<?php

/*
 +-----------------------------------------------------------------------+
 | program/steps/mail/viewsource.inc                                     |
 |                                                                       |
 | This file is part of the RoundCube Webmail client                     |
 | Copyright (C) 2005, RoundCube Dev. - Switzerland                      |
 | Licensed under the GNU GPL                                            |
 |                                                                       |
 | PURPOSE:                                                              |
 |   Display a mail message similar as a usual mail application does     |
 |                                                                       |
 +-----------------------------------------------------------------------+
 | Author: Thomas Bruederli <roundcube@gmail.com>                        |
 +-----------------------------------------------------------------------+

 $Id: viewsource.inc 320 2006-08-18 12:48:33Z thomasb $

*/


// similar code as in program/steps/mail/get.inc
if ($uid = rc_main::get_input_value('_uid', RCUBE_INPUT_GET)) {
    header('Content-Type: text/plain');
    //@ob_end_clean();
    $IMAP->print_raw_body($uid);
}
else {
    rc_bugs::raise_error(
        array(
            'code' => 500,
            'type' => 'php',
            'message' => 'Message UID '.$uid.' not found'
        ),
        TRUE,
        TRUE
    );
}
exit;
?>