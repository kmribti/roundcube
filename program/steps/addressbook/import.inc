<?php

/*
 +-----------------------------------------------------------------------+
 | program/steps/addressbook/import.inc                                  |
 |                                                                       |
 | This file is part of the Roundcube Webmail client                     |
 | Copyright (C) 2008-2009, The Roundcube Dev Team                       |
 |                                                                       |
 | Licensed under the GNU General Public License version 3 or            |
 | any later version with exceptions for skins & plugins.                |
 | See the README file for a full license statement.                     |
 |                                                                       |
 | PURPOSE:                                                              |
 |   Import contacts from a vCard or CSV file                            |
 |                                                                       |
 +-----------------------------------------------------------------------+
 | Author: Thomas Bruederli <roundcube@gmail.com>                        |
 | Author: Aleksander Machniak <machniak@kolabsys.com>                   |
 +-----------------------------------------------------------------------+

 $Id$

*/

/**
 * Handler function to display the import/upload form
 */
function rcmail_import_form($attrib)
{
  global $RCMAIL, $OUTPUT;
  $target = get_input_value('_target', RCUBE_INPUT_GPC);

  $attrib += array('id' => "rcmImportForm");

  $writable_books = $RCMAIL->get_address_sources(true);

  $upload = new html_inputfield(array('type' => 'file', 'name' => '_file', 'id' => 'rcmimportfile', 'size' => 40));
  $form = html::p(null, html::label('rcmimportfile', rcube_label('importfromfile')) . $upload->show());

  // addressbook selector
  if (count($writable_books) > 1) {
    $select = new html_select(array('name' => '_target', 'id' => 'rcmimporttarget'));

    foreach ($writable_books as $book)
        $select->add($book['name'], $book['id']);

    $form .= html::p(null, html::label('rcmimporttarget', rcube_label('importtarget'))
        . $select->show($target));
  }
  else {
    $abook = new html_hiddenfield(array('name' => '_target', 'value' => key($writable_books)));
    $form .= $abook->show();
  }

  $check_replace = new html_checkbox(array('name' => '_replace', 'value' => 1, 'id' => 'rcmimportreplace'));
  $form .= html::p(null, $check_replace->show(get_input_value('_replace', RCUBE_INPUT_GPC)) .
    html::label('rcmimportreplace', rcube_label('importreplace')));

  $OUTPUT->set_env('writable_source', !empty($writable_books));
  $OUTPUT->add_label('selectimportfile','importwait');
  $OUTPUT->add_gui_object('importform', $attrib['id']);

  $out = html::p(null, Q(rcube_label('importtext'), 'show'));

  $out .= $OUTPUT->form_tag(array(
      'action' => $RCMAIL->url('import'),
      'method' => 'post',
      'enctype' => 'multipart/form-data') + $attrib,
    $form);

  return $out;
}


/**
 * Render the confirmation page for the import process
 */
function rcmail_import_confirm($attrib)
{
  global $IMPORT_STATS;

  $vars = get_object_vars($IMPORT_STATS);
  $vars['names'] = $vars['skipped_names'] = '';

  $content = html::p(null, rcube_label(array(
      'name' => 'importconfirm',
      'nr' => $IMORT_STATS->inserted,
      'vars' => $vars,
    )) . ($IMPORT_STATS->names ? ':' : '.'));

  if ($IMPORT_STATS->names)
    $content .= html::p('em', join(', ', array_map('Q', $IMPORT_STATS->names)));

  if ($IMPORT_STATS->skipped) {
      $content .= html::p(null, rcube_label(array(
          'name' => 'importconfirmskipped',
          'nr' => $IMORT_STATS->skipped,
          'vars' => $vars,
        )) . ':');
      $content .= html::p('em', join(', ', array_map('Q', $IMPORT_STATS->skipped_names)));
  }

  return html::div($attrib, $content);
}


/**
 * Create navigation buttons for the current import step
 */
function rcmail_import_buttons($attrib)
{
  global $IMPORT_STATS, $OUTPUT;
  $target = get_input_value('_target', RCUBE_INPUT_GPC);

  $attrib += array('type' => 'input');
  unset($attrib['name']);

  if (is_object($IMPORT_STATS)) {
    $attrib['class'] = trim($attrib['class'] . ' mainaction');
    $out = $OUTPUT->button(array('command' => 'list', 'prop' => $target, 'label' => 'done') + $attrib);
  }
  else {
    $out = $OUTPUT->button(array('command' => 'list', 'label' => 'cancel') + $attrib);
    $out .= '&nbsp;';
    $attrib['class'] = trim($attrib['class'] . ' mainaction');
    $out .= $OUTPUT->button(array('command' => 'import', 'label' => 'import') + $attrib);
  }

  return $out;
}


/** The import process **/

$importstep = 'rcmail_import_form';

if ($_FILES['_file']['tmp_name'] && is_uploaded_file($_FILES['_file']['tmp_name'])) {
  $replace = (bool)get_input_value('_replace', RCUBE_INPUT_GPC);
  $target = get_input_value('_target', RCUBE_INPUT_GPC);
  $CONTACTS = $RCMAIL->get_address_book($target, true);

  // let rcube_vcard do the hard work :-)
  $vcard_o = new rcube_vcard();
  $vcard_o->extend_fieldmap($CONTACTS->vcard_map);

  $vcards = $vcard_o->import(file_get_contents($_FILES['_file']['tmp_name']));

  // no vcards detected
  if (!count($vcards)) {
    $OUTPUT->show_message('importerror', 'error');
  }
  else if ($CONTACTS->readonly) {
    $OUTPUT->show_message('addresswriterror', 'error');
  }
  else {
    $IMPORT_STATS = new stdClass;
    $IMPORT_STATS->names = array();
    $IMPORT_STATS->skipped_names = array();
    $IMPORT_STATS->count = count($vcards);
    $IMPORT_STATS->inserted = $IMPORT_STATS->skipped = $IMPORT_STATS->nomail = $IMPORT_STATS->errors = 0;

    if ($replace)
      $CONTACTS->delete_all();

    foreach ($vcards as $vcard) {
      $email = $vcard->email[0];

      // skip entries without an e-mail address
      if (empty($email)) {
        $IMPORT_STATS->nomail++;
        continue;
      }

      // We're using UTF8 internally
      $email = rcube_idn_to_utf8($email);

      if (!$replace && $email) {
        // compare e-mail address
        $existing = $CONTACTS->search('email', $email, 1, false);
        if (!$existing->count && $vcard->displayname) {  // compare display name
          $existing = $CONTACTS->search('name', $vcard->displayname, 1, false);
        }
        if ($existing->count) {
          $IMPORT_STATS->skipped++;
          $IMPORT_STATS->skipped_names[] = $vcard->displayname ? $vcard->displayname : $email;
          continue;
        }
      }

      $a_record = $vcard->get_assoc();
      $a_record['vcard'] = $vcard->export();

      $plugin = $RCMAIL->plugins->exec_hook('contact_create', array('record' => $a_record, 'source' => null));
      $a_record = $plugin['record'];

      // insert record and send response
      if (!$plugin['abort'])
        $success = $CONTACTS->insert($a_record);
      else
        $success = $plugin['result'];

      if ($success) {
        $IMPORT_STATS->inserted++;
        $IMPORT_STATS->names[] = $vcard->displayname ? $vcard->displayname : $email;
      } else {
        $IMPORT_STATS->errors++;
      }
    }

    $importstep = 'rcmail_import_confirm';
  }
}
else if ($err = $_FILES['_file']['error']) {
  if ($err == UPLOAD_ERR_INI_SIZE || $err == UPLOAD_ERR_FORM_SIZE) {
    $OUTPUT->show_message('filesizeerror', 'error', array('size' => show_bytes(parse_bytes(ini_get('upload_max_filesize')))));
  } else {
    $OUTPUT->show_message('fileuploaderror', 'error');
  }
}


$OUTPUT->set_pagetitle(rcube_label('importcontacts'));

$OUTPUT->add_handlers(array(
  'importstep' => $importstep,
  'importnav' => 'rcmail_import_buttons',
));

// render page
$OUTPUT->send('importcontacts');
