<?php

/*
 +-----------------------------------------------------------------------+
 | program/steps/addressbook/delete.inc                                  |
 |                                                                       |
 | This file is part of the RoundCube Webmail client                     |
 | Copyright (C) 2005, RoundCube Dev. - Switzerland                      |
 | Licensed under the GNU GPL                                            |
 |                                                                       |
 | PURPOSE:                                                              |
 |   Delete the submitted contacts (CIDs) from the users address book    |
 |                                                                       |
 +-----------------------------------------------------------------------+
 | Author: Thomas Bruederli <roundcube@gmail.com>                        |
 +-----------------------------------------------------------------------+

 $Id$

*/

$REMOTE_REQUEST = TRUE;

if (($cid = get_input_value('_cid', RCUBE_INPUT_GPC)) && preg_match('/^[0-9]+(,[0-9]+)*$/', $cid))
  {
  $deleted = $CONTACTS->delete($cid);
  if (!$deleted)
    {
    // send error message
    exit;
    }

  // count contacts for this user
  $result = $CONTACTS->count();

  // update message count display
  $commands = sprintf("this.set_env('pagecount', %d);\n", ceil($result['count'] / $CONTACTS->page_size));
  $commands .= sprintf("this.set_rowcount('%s');\n", rcmail_get_rowcount_text($result['count']));

  // add new rows from next page (if any)
  $pages = ceil($result['count']+$deleted / $CONTACTS->page_size);
  if ($_GET['_from'] != 'show' && $pages > 1 && $CONTACTS->list_page < $pages)
    {
    $result = $CONTACTS->list_records(null, -$deleted);
    $commands .= rcmail_js_contacts_list($result);
    }

  // send response
  rcube_remote_response($commands);
  }

exit;
?>
