<?php

/*
 +-----------------------------------------------------------------------+
 | program/steps/settings/delete_identity.inc                            |
 |                                                                       |
 | This file is part of the RoundCube Webmail client                     |
 | Copyright (C) 2005, RoundCube Dev. - Switzerland                      |
 | Licensed under the GNU GPL                                            |
 |                                                                       |
 | PURPOSE:                                                              |
 |   Delete the submitted identities (IIDs) from the database            |
 |                                                                       |
 +-----------------------------------------------------------------------+
 | Author: Thomas Bruederli <roundcube@gmail.com>                        |
 +-----------------------------------------------------------------------+

 $Id$

*/

if ($_GET['_iid'] && preg_match('/^[0-9]+(,[0-9]+)*$/',$_GET['_iid']))
  {
  $DB->query("UPDATE ".get_table_name('identities')."
              SET    del=1
              WHERE  user_id=?
              AND    identity_id IN (".$_GET['_iid'].")",
              $_SESSION['user_id']);

  $count = $DB->affected_rows();
  if ($count)
    $OUTPUT->show_message('deletedsuccessfully', 'confirmation');

  // send response
  if ($OUTPUT->ajax_call)
    $OUTPUT->send();
  }


if ($OUTPUT->ajax_call)
  exit;


// go to identities page
rcmail_overwrite_action('identities');

?>
