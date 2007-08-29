<?php

/*
 +-----------------------------------------------------------------------+
 | program/steps/settings/manage_folders.inc                             |
 |                                                                       |
 | This file is part of the RoundCube Webmail client                     |
 | Copyright (C) 2005-2007, RoundCube Dev. - Switzerland                 |
 | Licensed under the GNU GPL                                            |
 |                                                                       |
 | PURPOSE:                                                              |
 |   Provide functionality to create/delete/rename folders               |
 |                                                                       |
 +-----------------------------------------------------------------------+
 | Author: Thomas Bruederli <roundcube@gmail.com>                        |
 +-----------------------------------------------------------------------+

 $Id: manage_folders.inc 573 2007-05-18 11:29:25Z thomasb $

*/

// init IMAP connection
rc_main::rcmail_imap_init(TRUE);

$registry = rc_registry::getInstance();
$OUTPUT   = $registry->get('OUTPUT', 'core');
$IMAP     = $registry->get('IMAP', 'core');

//rc_main::tfk_debug('DEBUG: ' . $_action);
//rc_main::tfk_debug('MAILBOX: ' . $_POST['_mboxes']);
//rc_main::tfk_debug('AJAX?: ' . $OUTPUT->ajax_call);

// subscribe to one or more mailboxes
if ($_action=='subscribe') {
    if ($mboxes = rc_main::get_input_value('_mboxes', RCUBE_INPUT_POST)) {
        $status = $IMAP->subscribe(array($mboxes));
        //rc_main::tfk_debug('Status: ' . $status);
    }
    if ($OUTPUT->ajax_call) {
        $OUTPUT->remote_response('// subscribed');
    }
}

// unsubscribe one or more mailboxes
else if ($_action=='unsubscribe') {
    if ($mboxes = rc_main::get_input_value('_mboxes', RCUBE_INPUT_POST)) {
        $status = $IMAP->unsubscribe(array($mboxes));
        //rc_main::tfk_debug('Status: ' . $status);
    }
    if ($OUTPUT->ajax_call) {
        $OUTPUT->remote_response('// unsubscribed');
    }
}

// create a new mailbox
else if ($_action=='create-folder') {
    if (!empty($_POST['_name'])) {
        $create = $IMAP->create_mailbox(
                    trim(
                        rc_main::get_input_value('_name', RCUBE_INPUT_POST, FALSE, 'UTF-7')
                    ),
                    TRUE
        );
    }
    if ($create && $OUTPUT->ajax_call) {
        $OUTPUT->command(
            'add_folder_row',
            $create,
            rc_main::rcube_charset_convert($create, 'UTF-7')
        );
        $OUTPUT->send();
    }
    else if (!$create && $OUTPUT->ajax_call) {
        $OUTPUT->show_message('errorsaving', 'error');
        $OUTPUT->remote_response();
    }
    else if (!$create) {
        $OUTPUT->show_message('errorsaving', 'error');
    }
}

// rename a mailbox
else if ($_action=='rename-folder') {
    if (!empty($_POST['_folder_oldname']) && !empty($_POST['_folder_newname'])) {

        $oldname         = rc_main::get_input_value('_folder_oldname', RCUBE_INPUT_POST);
        $_folder_newname = trim(rc_main::get_input_value('_folder_newname', RCUBE_INPUT_POST, FALSE, 'UTF-7'));

        $rename = $IMAP->rename_mailbox(
                        $oldname,
                        $_folder_newname
        );
    }
    if ($rename && $OUTPUT->ajax_call) {
        $OUTPUT->command(
                    'replace_folder_row',
                    $oldname,
                    $rename,
                    rc_main::rcube_charset_convert($rename, 'UTF-7')
        );
        $OUTPUT->command('reset_folder_rename');
        $OUTPUT->send();
    }
    elseif (!$rename && $OUTPUT->ajax_call) {
        $OUTPUT->command('reset_folder_rename');
        $OUTPUT->show_message('errorsaving', 'error');
        $OUTPUT->send();
    }
    elseif (!$rename) {
        $OUTPUT->show_message('errorsaving', 'error');
    }
}

// delete an existing IMAP mailbox
elseif ($_action=='delete-folder') {
    if ($mboxes = rc_main::get_input_value('_mboxes', RCUBE_INPUT_POST)) {
        $deleted = $IMAP->delete_mailbox(array($mboxes));
    }

    if ($OUTPUT->ajax_call && $deleted) {
        $OUTPUT->command('remove_folder_row', rc_main::get_input_value('_mboxes', RCUBE_INPUT_POST));
        $OUTPUT->show_message('folderdeleted', 'confirmation');
        $OUTPUT->send();
    }
    elseif ($OUTPUT->ajax_call) {
        $OUTPUT->show_message('errorsaving', 'error');
        $OUTPUT->send();
    }
}

/**
 * build table with all folders listed by server
 *
 * @access static
 * @param  array $attrib
 * @return string
 * @uses   rc_registry::getInstance()
 * @uses   rcube_label()
 * @uses   get_form_tags()
 */
function rcube_subscription_form($attrib)
{
    $registry = rc_registry::getInstance();
    $IMAP     = $registry->get('IMAP', 'core');
    $CONFIG   = $registry->get('CONFIG', 'core');
    $OUTPUT   = $registry->get('OUTPUT', 'core');

    list($form_start, $form_end) = get_form_tags($attrib, 'folders');
    unset($attrib['form']);
  
  
    if (!$attrib['id']) {
        $attrib['id'] = 'rcmSubscriptionlist';
    }

    // allow the following attributes to be added to the <table> tag
    $attrib_str = rc_main::create_attrib_string(
                    $attrib,
                    array(
                        'style',
                        'class',
                        'id',
                        'cellpadding',
                        'cellspacing',
                        'border',
                        'summary'
                    )
    );

    $out = "$form_start\n<table" . $attrib_str . ">\n";


    // add table header
    $out .= "<thead><tr>\n";
    $out .= sprintf(
                    '<td class="name">%s</td><td class="subscribed">%s</td>'.
                    '<td class="rename">&nbsp;</td><td class="delete">&nbsp;</td>',
                    rcube_label('foldername'),
                    rcube_label('subscribed')
    );
    $out .= "\n</tr></thead>\n<tbody>\n";


    // get folders from server
    $IMAP->clear_cache('mailboxes');

    $a_unsubscribed = $IMAP->list_unsubscribed();
    $a_subscribed   = $IMAP->list_mailboxes();
    $a_js_folders   = array();
 
    $checkbox_subscribe = new checkbox(
                            array(
                                'name' => '_subscribed[]',
                                'onclick' => JS_OBJECT_NAME.".command(this.checked?'subscribe':'unsubscribe',this.value)"
                            )
    );
    if (!empty($attrib['deleteicon'])) {
        $del_button = sprintf(
                        '<img src="%s%s" alt="%s" border="0" />',
                        $CONFIG['skin_path'],
                        $attrib['deleteicon'],
                        rcube_label('delete')
        );
    }
    else {
        $del_button = rcube_label('delete');
    }

    if (!empty($attrib['renameicon'])) {
        $edit_button = sprintf(
                        '<img src="%s%s" alt="%s" border="0" />',
                        $CONFIG['skin_path'],
                        $attrib['renameicon'],
                        rcube_label('rename')
        );
    }
    else {
        $del_button = rcube_label('rename');
    }

    // create list of available folders
    foreach ($a_unsubscribed as $i => $folder) {
        $subscribed  = in_array($folder, $a_subscribed);
        $protected   = ($CONFIG['protect_default_folders'] == TRUE && in_array($folder,$CONFIG['default_imap_folders']));
        $zebra_class = $i%2 ? 'even' : 'odd';
        $folder_js   = rc_main::JQ($folder);
        $folder_html = $CONFIG['protect_default_folders'] && in_array($folder, $CONFIG['default_imap_folders']) ? rcube_label(strtolower($folder)) : rc_main::rcube_charset_convert($folder, 'UTF-7');
    
        if (!$protected) {
            $a_js_folders['rcmrow'.($i+1)] = array($folder, rc_main::rcube_charset_convert($folder, 'UTF-7'));
        }
        $out .= sprintf(
                    '<tr id="rcmrow%d" class="%s"><td>%s</td>',
                    $i+1,
                    $zebra_class,
                    rc_main::Q($folder_html)
        );
                    
        if ($protected) {
            $out .= '<td>&nbsp;'.($subscribed ? '&#x2022;' : '-').'</td>';
        }
        else {
            $out.= '<td>';
            $out.= $checkbox_subscribe->show($subscribed?$folder:'', array('value' => $folder));
            $out.= '</td>';
        }

        // add rename and delete buttons
        if (!$protected) {
            $out .= sprintf(
                        '<td><a href="#rename" onclick="%s.command(\'rename-folder\',\'%s\')" title="%s">%s</a>'.
                        '<td><a href="#delete" onclick="%s.command(\'delete-folder\',\'%s\')" title="%s">%s</a></td>',
                        JS_OBJECT_NAME,
                        $folder_js,
                        rcube_label('renamefolder'),
                        $edit_button,
                        JS_OBJECT_NAME,
                        $folder_js,
                        rcube_label('deletefolder'),
                        $del_button
            );
        }
        else {
            $out .= '<td></td><td></td>';
        }
        $out .= "</tr>\n";
    }

    $out .= "</tbody>\n</table>";
    $out .= "\n$form_end";

    $OUTPUT->add_gui_object('subscriptionlist', $attrib['id']);
    $OUTPUT->set_env('subscriptionrows', $a_js_folders);

    return $out;  
}


/**
 * @access public
 * @param  array  $attrib
 * @return string
 */
function rcube_create_folder_form($attrib)
{
    list($form_start, $form_end) = get_form_tags($attrib, 'create-folder');
    unset($attrib['form']);

    // return the complete edit form as table
    $out = "$form_start\n";

    $input = new textfield(array('name' => '_folder_name'));
    $out .= $input->show();
  
    if (get_boolean($attrib['button'])) {
        $button = new input_field(
                        array(
                            'type'    => 'button',
                            'value'   => rcube_label('create'),
                            'onclick' => JS_OBJECT_NAME.".command('create-folder',this.form)"
                        )
        );
        $out .= $button->show();
    }

    $out .= "\n$form_end";

    return $out;
}

/**
 * @access static
 * @param  array $attrib
 * @return string
 * @uses   rc_registry::getInstance()
 */
function rcube_rename_folder_form($attrib)
{
    $registry = rc_registry::getInstance();
    $CONFIG   = $registry->get('CONFIG', 'core');
    $IMAP     = $registry->get('IMAP', 'core');

    list($form_start, $form_end) = get_form_tags($attrib, 'rename-folder');
    unset($attrib['form']);

    // return the complete edit form as table
    $out = "$form_start\n";

    $a_unsubscribed = $IMAP->list_unsubscribed();
    $select_folder = new select(array('name' => '_folder_oldname', 'id' => 'rcmfd_oldfolder'));

    foreach ($a_unsubscribed as $i => $folder) {
        if ($CONFIG['protect_default_folders'] == TRUE && in_array($folder,$CONFIG['default_imap_folders'])) {
            continue;
        }
        $select_folder->add($folder);
    }

    $out .= $select_folder->show();

    $out .= " to ";
    $inputtwo = new textfield(array('name' => '_folder_newname'));
    $out .= $inputtwo->show();

    if (get_boolean($attrib['button'])) {
        $button = new input_field(array('type' => 'button',
                                    'value' => rcube_label('rename'),
                                    'onclick' => JS_OBJECT_NAME.".command('rename-folder',this.form)"));
        $out .= $button->show();
    }

    $out .= "\n$form_end";

    return $out;
}


// register UI objects
$OUTPUT->add_handlers(array(
    'foldersubscription' => 'rcube_subscription_form',
    'createfolder'       => 'rcube_create_folder_form',
    'renamefolder'       => 'rcube_rename_folder_form'
));

// add some labels to client
rc_main::rcube_add_label('deletefolderconfirm');

$OUTPUT->send('managefolders');
?>
