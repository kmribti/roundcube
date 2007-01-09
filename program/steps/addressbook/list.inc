<?php

/*
 +-----------------------------------------------------------------------+
 | program/steps/addressbook/list.inc                                    |
 |                                                                       |
 | This file is part of the RoundCube Webmail client                     |
 | Copyright (C) 2005-2006, RoundCube Dev. - Switzerland                 |
 | Licensed under the GNU GPL                                            |
 |                                                                       |
 | PURPOSE:                                                              |
 |   Send contacts list to client (as remote response)                   |
 |                                                                       |
 +-----------------------------------------------------------------------+
 | Author: Thomas Bruederli <roundcube@gmail.com>                        |
 +-----------------------------------------------------------------------+

 $Id$

*/

$REMOTE_REQUEST = TRUE;

// get contacts for this user
$result = $CONTACTS->list_records();

// update message count display
$commands = sprintf("this.set_env('pagecount', %d);\n", ceil($result['count'] / $CONTACTS->page_size));
$commands .= sprintf("this.set_rowcount('%s');\n", rcmail_get_rowcount_text($rowcount));

// create javascript list
$commands .= rcmail_js_contacts_list($result);
  
// send response
rcube_remote_response($commands);

?>