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
rcube::imap_init(TRUE);

$registry = rcube_registry::get_instance();
$OUTPUT   = $registry->get('OUTPUT', 'core');
$IMAP     = $registry->get('IMAP', 'core');

//rcube::tfk_debug('DEBUG: ' . $_action);
//rcube::tfk_debug('MAILBOX: ' . $_POST['_mboxes']);
//rcube::tfk_debug('AJAX?: ' . $OUTPUT->ajax_call);

// subscribe to one or more mailboxes
if ($_action=='subscribe') {
    if ($mboxes = rcube::get_input_value('_mboxes', rcube::INPUT_POST)) {
        $status = $IMAP->subscribe(array($mboxes));
        //rcube::tfk_debug('Status: ' . $status);
    }
    if ($OUTPUT->ajax_call) {
        $OUTPUT->remote_response('// subscribed');
    }
}

// unsubscribe one or more mailboxes
else if ($_action=='unsubscribe') {
    if ($mboxes = rcube::get_input_value('_mboxes', rcube::INPUT_POST)) {
        $status = $IMAP->unsubscribe(array($mboxes));
        //rcube::tfk_debug('Status: ' . $status);
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
                        rcube::get_input_value('_name', rcube::INPUT_POST, FALSE, 'UTF-7')
                    ),
                    TRUE
        );
    }
    if ($create && $OUTPUT->ajax_call) {
        $OUTPUT->command(
            'add_folder_row',
            $create,
            rcube::charset_convert($create, 'UTF-7')
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

        $oldname         = rcube::get_input_value('_folder_oldname', rcube::INPUT_POST);
        $_folder_newname = trim(rcube::get_input_value('_folder_newname', rcube::INPUT_POST, FALSE, 'UTF-7'));

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
                    rcube::charset_convert($rename, 'UTF-7')
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
    if ($mboxes = rcube::get_input_value('_mboxes', rcube::INPUT_POST)) {
        $deleted = $IMAP->delete_mailbox(array($mboxes));
    }

    if ($OUTPUT->ajax_call && $deleted) {
        $OUTPUT->command('remove_folder_row', rcube::get_input_value('_mboxes', rcube::INPUT_POST));
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
 * @uses   rcube_registry::get_instance()
 * @uses   rcube::gettext()
 * @uses   get_form_tags()
 */
function rcube_subscription_form($attrib)
{
    $registry = rcube_registry::get_instance();
    $IMAP     = $registry->get('IMAP', 'core');
    $CONFIG   = $registry->get_all('config');
    $OUTPUT   = $registry->get('OUTPUT', 'core');

    list($form_start, $form_end) = get_form_tags($attrib, 'folders');
    unset($attrib['form']);
  
  
    if (!$attrib['id']) {
        $attrib['id'] = 'rcmSubscriptionlist';
    }

    // allow the following attributes to be added to the <table> tag
    $attrib_str = rcube::create_attrib_string(
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
                    rcube::gettext('foldername'),
                    rcube::gettext('subscribed')
    );
    $out .= "\n</tr></thead>\n<tbody>\n";


    // get folders from server
    $IMAP->clear_cache('mailboxes');

    $a_unsubscribed = $IMAP->list_unsubscribed();
    $a_subscribed   = $IMAP->list_mailboxes();
    $a_js_folders   = array();
 
    $checkbox_subscribe = new html_checkbox(
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
                        rcube::gettext('delete')
        );
    }
    else {
        $del_button = rcube::gettext('delete');
    }

    if (!empty($attrib['renameicon'])) {
        $edit_button = sprintf(
                        '<img src="%s%s" alt="%s" border="0" />',
                        $CONFIG['skin_path'],
                        $attrib['renameicon'],
                        rcube::gettext('rename')
        );
    }
    else {
        $del_button = rcube::gettext('rename');
    }

    // create list of available folders
    foreach ($a_unsubscribed as $i => $folder) {
        $subscribed  = in_array($folder, $a_subscribed);
        $protected   = ($CONFIG['protect_default_folders'] == TRUE && in_array($folder,$CONFIG['default_imap_folders']));
        $zebra_class = $i%2 ? 'even' : 'odd';
        $folder_js   = JQ($folder);
        $folder_html = '';
        if (
            $CONFIG['protect_default_folders']
            && in_array($folder, $CONFIG['default_imap_folders'])
        ) {
            $folder_html.= rcube::gettext(strtolower($folder));
        }
        else {
            $folder_html.= rcube::charset_convert($folder, 'UTF-7');
        }
        if (strlen($folder_html) > 40) {
            $folder_html = substr($folder_html, 0, 27) . '...';
        }
        if (!$protected) {
            $a_js_folders['rcmrow'.($i+1)] = array($folder, rcube::charset_convert($folder, 'UTF-7'));
        }
        $out .= sprintf(
                    '<tr id="rcmrow%d" class="%s"><td>%s</td>',
                    $i+1,
                    $zebra_class,
                    Q($folder_html)
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
                        rcube::gettext('renamefolder'),
                        $edit_button,
                        JS_OBJECT_NAME,
                        $folder_js,
                        rcube::gettext('deletefolder'),
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

    $input = new html_inputfield(array('name' => '_folder_name'));
    $out .= $input->show();
  
    if (get_boolean($attrib['button'])) {
        $button = new input_field(
                        array(
                            'type'    => 'button',
                            'value'   => rcube::gettext('create'),
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
 * @uses   rcube_registry::get_instance()
 */
function rcube_rename_folder_form($attrib)
{
    $registry = rcube_registry::get_instance();
    $CONFIG   = $registry->get_all('config');
    $IMAP     = $registry->get('IMAP', 'core');

    list($form_start, $form_end) = get_form_tags($attrib, 'rename-folder');
    unset($attrib['form']);

    // return the complete edit form as table
    $out = "$form_start\n";

    $a_unsubscribed = $IMAP->list_unsubscribed();
    $select_folder = new html_select(array('name' => '_folder_oldname', 'id' => 'rcmfd_oldfolder'));

    foreach ($a_unsubscribed as $i => $folder) {
        if ($CONFIG['protect_default_folders'] == TRUE && in_array($folder,$CONFIG['default_imap_folders'])) {
            continue;
        }
        $select_folder->add($folder);
    }

    $out .= $select_folder->show();

    $out .= " to ";
    $inputtwo = new html_inputfield(array('name' => '_folder_newname'));
    $out .= $inputtwo->show();

    if (get_boolean($attrib['button'])) {
        $button = new input_field(array('type' => 'button',
                                    'value' => rcube::gettext('rename'),
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
$OUTPUT->add_label('deletefolderconfirm');

$OUTPUT->send('managefolders');
?>
