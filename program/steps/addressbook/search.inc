<?php

/*
 +-----------------------------------------------------------------------+
 | program/steps/addressbook/search.inc                                  |
 |                                                                       |
 | This file is part of the RoundCube Webmail client                     |
 | Copyright (C) 2005-2007, RoundCube Dev. - Switzerland                 |
 | Licensed under the GNU GPL                                            |
 |                                                                       |
 | PURPOSE:                                                              |
 |   Search step for address book contacts                               |
 |                                                                       |
 +-----------------------------------------------------------------------+
 | Author: Thomas Bruederli <roundcube@gmail.com>                        |
 +-----------------------------------------------------------------------+

 $Id: search.inc 456 2007-01-10 12:34:33Z thomasb $

*/

$REMOTE_REQUEST = TRUE;

$search = trim(get_input_value('_search', RCUBE_INPUT_GET));
$search_request = md5('addr'.$search);
$commands = '';

// get contacts for this user
$result = $CONTACTS->search(array('name','email'), $search);

if ($result['count'] > 0)
{
  // save search settings in session
  $_SESSION['search'][$search_request] = $CONTACTS->get_search_set();
  
  // create javascript list
  $commands .= rcmail_js_contacts_list($result);
}
else
{
  $commands = show_message('nocontactsfound', 'warning');
  $search_request = -1;
}

// update message count display
$commands .= sprintf("this.set_env('search_request', '%s')\n", $search_request);
$commands .= sprintf("this.set_env('pagecount', %d);\n", ceil($result['count'] / $CONTACTS->page_size));
$commands .= sprintf("this.set_rowcount('%s');\n", rcmail_get_rowcount_text());
  
// send response
rcube_remote_response($commands);

?>