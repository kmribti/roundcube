<?php

/*
 +-----------------------------------------------------------------------+
 | program/steps/settings/identities.inc                                 |
 |                                                                       |
 | This file is part of the RoundCube Webmail client                     |
 | Copyright (C) 2005-2007, RoundCube Dev. - Switzerland                 |
 | Licensed under the GNU GPL                                            |
 |                                                                       |
 | PURPOSE:                                                              |
 |   Manage identities of a user account                                 |
 |                                                                       |
 +-----------------------------------------------------------------------+
 | Author: Thomas Bruederli <roundcube@gmail.com>                        |
 +-----------------------------------------------------------------------+

 $Id: identities.inc 543 2007-04-28 18:07:12Z thomasb $

*/
$registry = rcube_registry::get_instance();
$DB       = $registry->get('DB', 'core');
$OUTPUT   = $registry->get('OUTPUT', 'core');

if ($USER_DATA = $DB->fetch_assoc($sql_result)) {
    rcube::tfk_debug(var_export($USER_DATA, true));
    $OUTPUT->set_pagetitle(
                sprintf(
                    '%s (%s@%s)',
                    rcube::gettext('identities'),
                    $USER_DATA['username'],
                    $USER_DATA['mail_host']
                )
    );
}
$OUTPUT->include_script('list.js');

// similar function as /steps/addressbook/func.inc::rcmail_contact_frame()
function rcmail_identity_frame($attrib)
{
    $registry = rcube_registry::get_instance();
    $OUTPUT   = $registry->get('OUTPUT', 'core');

    if (!$attrib['id']) {
        $attrib['id'] = 'rcmIdentityFrame';
    }
    $attrib['name'] = $attrib['id'];

    $OUTPUT->set_env('contentframe', $attrib['name']);

    $attrib_str = rcube::create_attrib_string(
                        $attrib,
                        array(
                            'name', 'id', 'class', 'style',
                            'src', 'width', 'height', 'frameborder'
                        )
    );
    $out = '<iframe'. $attrib_str . '></iframe>';

    return $out;
}

$OUTPUT->add_handler('identityframe', 'rcmail_identity_frame');

rcube::parse_template('identities');
?>
