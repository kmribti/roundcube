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

 $Id: addcontact.inc 543 2007-04-28 18:07:12Z thomasb $

*/

$registry = rcube_registry::get_instance();
$IMAP     = $registry->get('IMAP', 'core');
$OUTPUT   = $registry->get('OUTPUT', 'core');

$done = false;

if (!empty($_POST['_address'])) {
    if (isset($CONFIG['addressbook_plugin']) && empty($CONFIG['addressbook_plugin']) === FALSE) {
        require_once 'plugins/addressbook/rcube_contacts_' . $CONFIG['addressbook_plugin'] . '.inc';
        $abook_class = 'rcube_contacts_' . $CONFIG['addressbook_plugin'];
        $CONTACTS = new $abook_class (
                            $_SESSION['username'],
                            $_SESSION['password']
        );
    }
    else {
        $DB       = $registry->get('DB', 'core');
        $CONTACTS = new rcube_contacts($DB, $_SESSION['user_id']);
    }
    $contact_arr = $IMAP->decode_address_list(
                    rcube::get_input_value(
                        '_address',
                        rcube::INPUT_POST,
                        true
                    ),
                    1,
                    false
    );

    if (!empty($contact_arr[1]['mailto'])) {
        $contact = array(
            'email' => $contact_arr[1]['mailto'],
            'name' => $contact_arr[1]['name']
        );

        // use email address part for name
        if (empty($contact['name']) || $contact['name'] == $contact['email']) {
            $contact['name'] = ucfirst(
                                preg_replace(
                                    '/[\.\-]/',
                                    ' ',
                                    substr(
                                        $contact['email'],
                                        0,
                                        strpos($contact['email'], '@')
                                    )
                                )
            );
        }

        // check for existing contacts
        $existing = $CONTACTS->search('email', $contact['email'], true, false);
        if ($done = $existing->count) {
            $OUTPUT->show_message('contactexists', 'warning');
        }
        else if ($done = $CONTACTS->insert($contact)) {
            $OUTPUT->show_message('addedsuccessfully', 'confirmation');
        }
    }
}

if (!$done) {
    $OUTPUT->show_message('errorsavingcontact', 'warning');
}
$OUTPUT->send();
?>