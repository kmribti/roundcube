<?php

/*
 +-----------------------------------------------------------------------+
 | program/steps/mail/attachments.inc                                    |
 |                                                                       |
 | This file is part of the RoundCube Webmail client                     |
 | Copyright (C) 2005-2008, RoundCube Dev. - Switzerland                 |
 | Licensed under the GNU GPL                                            |
 |                                                                       |
 | PURPOSE:                                                              |
 |   Upload, remove, display attachments in compose form                 |
 |                                                                       |
 +-----------------------------------------------------------------------+
 | Author: Thomas Bruederli <roundcube@gmail.com>                        |
 +-----------------------------------------------------------------------+

 $Id: compose.inc 2081 2008-11-23 12:38:44Z thomasb $

*/


if (!$_SESSION['compose']) {
  die("Invalid session var!");
}


// remove an attachment
if ($RCMAIL->action=='remove-attachment')
{
  $plugin = rcmail::get_instance()->plugins->exec_hook('remove_attachment',array('id'=>substr($_POST['_file'],7)));
  if ($plugin['status'])
  {
    $id = $plugin['id'];
    if (is_array($_SESSION['compose']['attachments'][$id]))
    {
      unset($_SESSION['compose']['attachments'][$id]);
      $OUTPUT->command('remove_from_attachment_list', "rcmfile$id");
      $OUTPUT->send();
    }
  }
  exit;
}

if ($RCMAIL->action=='display-attachment')
{
  $plugin = rcmail::get_instance()->plugins->exec_hook('display_attachment',array('id'=>substr($_GET['_file'],7)));
  if ($plugin['status'])
  {
    $id = $plugin['id'];
    $apath = $_SESSION['compose']['attachments'][$id]['path'];
    header('Content-Type: ' . $_SESSION['compose']['attachments'][$id]['mimetype']);
    header('Content-Length: ' . filesize($apath));
    readfile($apath);
    // plugins that don't use disk storage will want this temp file removed after use
    if($plugin['erase_after_send']){
        unlink($apath);
    }
  }
  exit;
}

// attachment upload action

// use common temp dir for file uploads
$temp_dir = unslashify($CONFIG['temp_dir']);

if (!is_array($_SESSION['compose']['attachments'])) {
  $_SESSION['compose']['attachments'] = array();
}

// clear all stored output properties (like scripts and env vars)
$OUTPUT->reset();

if (is_array($_FILES['_attachments']['tmp_name'])) {
  foreach ($_FILES['_attachments']['tmp_name'] as $i => $filepath) {
    $plugin = rcmail::get_instance()->plugins->exec_hook('upload_attachment',array('filepath'=>$filepath,'index'=>$i));
    if ($plugin['status']) {
      $id = $plugin['id'];
      if (is_file($icon = $CONFIG['skin_path'] . '/images/icons/remove-attachment.png')) {
        $button = html::img(array(
          'src' => $icon,
          'alt' => rcube_label('delete'),
          'style' => "padding-right:2px;vertical-align:middle",
        ));
      }
      else {
        $button = Q(rcube_label('delete'));
      }

      $content = html::a(array(
        'href' => "#delete",
        'onclick' => sprintf("return %s.command('remove-attachment','rcmfile%s', this)", JS_OBJECT_NAME, $id),
        'title' => rcube_label('delete'),
      ), $button);
      
      $content .= Q($_FILES['_attachments']['name'][$i]);
      
      $OUTPUT->command('add2attachment_list', "rcmfile$id", $content);
    }
    else {  // upload failed
      $err = $_FILES['_attachments']['error'][$i];
      if ($err == UPLOAD_ERR_INI_SIZE || $err == UPLOAD_ERR_FORM_SIZE) {
        $msg = rcube_label(array('name' => 'filesizeerror', 'vars' => array('size' => show_bytes(parse_bytes(ini_get('upload_max_filesize'))))));
      }
      else {
        $msg = rcube_label('fileuploaderror');
      }
    
      $OUTPUT->command('display_message', $msg, 'error');
    }
  }
}
else if ($_SERVER['REQUEST_METHOD'] == 'POST') {
  $OUTPUT->command('display_message', rcube_label('fileuploaderror'), 'error');
}

// send html page with JS calls as response
$OUTPUT->command('show_attachment_form', false);
$OUTPUT->command('auto_save_start', false);
$OUTPUT->send('iframe');

?>
