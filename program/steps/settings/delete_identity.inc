<?php

/*
 +-----------------------------------------------------------------------+
 | program/steps/settings/delete_identity.inc                            |
 |                                                                       |
 | This file is part of the RoundCube Webmail client                     |
 | Copyright (C) 2005-2007, RoundCube Dev. - Switzerland                 |
 | Licensed under the GNU GPL                                            |
 |                                                                       |
 | PURPOSE:                                                              |
 |   Delete the submitted identities (IIDs) from the database            |
 |                                                                       |
 +-----------------------------------------------------------------------+
 | Author: Thomas Bruederli <roundcube@gmail.com>                        |
 +-----------------------------------------------------------------------+

 $Id: delete_identity.inc 543 2007-04-28 18:07:12Z thomasb $

*/

if (
    ($ids = rcube::get_input_value('_iid', rcube::INPUT_GET))
    && preg_match('/^[0-9]+(,[0-9]+)*$/', $ids)
) {
    $_query = "UPDATE " . rcube::get_table_name('identities');
    $_query.= " SET del=1";
    $_query.= " WHERE user_id=?";
    $_query.= " AND identity_id IN (" . $ids . ")";
    $DB->query($_query, $_SESSION['user_id']);

    $count = $DB->affected_rows();
    if ($count) {
        $OUTPUT->show_message('deletedsuccessfully', 'confirmation');
    }
    // send response
    if ($OUTPUT->ajax_call) {
        $OUTPUT->send();
    }
}
if ($OUTPUT->ajax_call) {
    exit;
}

// go to identities page
rcube::override_action('identities');
?>
