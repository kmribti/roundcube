<?php

/*
 +-----------------------------------------------------------------------+
 | program/steps/mail/addcontact.inc                                     |
 |                                                                       |
 | This file is part of the RoundCube Webmail client                     |
 | Copyright (C) 2005-2007, RoundCube Dev. - Switzerland                 |
 | Licensed under the GNU GPL                                            |
 |                                                                       |
 | PURPOSE:                                                              |
 |   Add the submitted contact to the users address book                 |
 |                                                                       |
 +-----------------------------------------------------------------------+
 | Author: Thomas Bruederli <roundcube@gmail.com>                        |
 +-----------------------------------------------------------------------+

 $Id$

*/

require_once('include/rcube_contacts.inc');

$REMOTE_REQUEST = TRUE;
$commands = '';

if (!empty($_POST['_address']))
{
  $CONTACTS = new rcube_contacts($DB, $_SESSION['user_id']);
  $contact_arr = $IMAP->decode_address_list(get_input_value('_address', RCUBE_INPUT_POST, TRUE));
  
  if (!empty($contact_arr[1]['mailto']))
  {
    $contact = array('email' => $contact_arr[1]['mailto'],
                     'name' => $contact_arr[1]['name']);
    
    // check for existing contacts
    $existing = $CONTACTS->search('email', $contact['email'], false);
    if ($existing['count'])
      $commands = show_message('contactexists', 'warning');
    else if ($CONTACTS->insert($contact))
      $commands = show_message('addedsuccessfully', 'confirmation');
  }
}

if (empty($commands))
  $commands = show_message('errorsavingcontact', 'warning');

rcube_remote_response($commands);  
exit;
?>