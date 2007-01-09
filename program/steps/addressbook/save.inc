<?php

/*
 +-----------------------------------------------------------------------+
 | program/steps/addressbook/save.inc                                    |
 |                                                                       |
 | This file is part of the RoundCube Webmail client                     |
 | Copyright (C) 2005, RoundCube Dev. - Switzerland                      |
 | Licensed under the GNU GPL                                            |
 |                                                                       |
 | PURPOSE:                                                              |
 |   Save a contact entry or to add a new one                            |
 |                                                                       |
 +-----------------------------------------------------------------------+
 | Author: Thomas Bruederli <roundcube@gmail.com>                        |
 +-----------------------------------------------------------------------+

 $Id$

*/

// cannot edit record
if ($CONTACTS->readonly)
  {
  show_message('contactreadonly', 'error');
  rcmail_overwrite_action(empty($_POST['_cid']) ? 'add' : 'show');
  return;
  }

// check input
if ((!get_input_value('_name', RCUBE_INPUT_POST) || !get_input_value('_email', RCUBE_INPUT_POST)) && $_framed)
  {
  show_message('formincomplete', 'warning');
  rcmail_overwrite_action(empty($_POST['_cid']) ? 'add' : 'show');
  return;
  }


// setup some vars we need
$a_save_cols = array('name', 'firstname', 'surname', 'email');
$a_record = array();
$cid = get_input_value('_cid', RCUBE_INPUT_POST);

// read POST values into hash array
foreach ($a_save_cols as $col)
  {
  $fname = '_'.$col;
  if (isset($_POST[$fname]))
    $a_record[$col] = get_input_value($fname, RCUBE_INPUT_POST);
  }

// update an existing contact
if (!empty($cid))
  {
  if ($CONTACTS->update($cid, $a_record))
    {
    if ($_framed)
      {
      // define list of cols to be displayed
      $a_js_cols = array();
      $record = $CONTACTS->get_record($cid);

      foreach (array('name', 'email') as $col)
        $a_js_cols[] = (string)$record[$col];

      // update the changed col in list
      $OUTPUT->add_script(sprintf("if(parent.%s)parent.%s.update_contact_row('%d', %s);",
                          $JS_OBJECT_NAME,
                          $JS_OBJECT_NAME,
                          $cid,
                          array2js($a_js_cols)));
      }
      
    // show confirmation
    show_message('successfullysaved', 'confirmation');    
    rcmail_overwrite_action('show');
    }
  else
    {
    // show error message
    show_message('errorsaving', 'error');
    rcmail_overwrite_action('show');
    }
  }

// insert a new contact
else
  {
  // check for existing contacts
  $existing = $CONTACTS->search('email', $a_record['email'], false);
  
  // show warning message
  if ($existing['count'])
    {
    show_message('contactexists', 'warning');
    rcmail_overwrite_action('add');
    return;
    }

  // insert record and send response
  if ($insert_id = $CONTACTS->insert($a_record))
    {
    if ($_framed)
      {
      // add contact row or jump to the page where it should appear
      $CONTACTS->reset();
      $result = $CONTACTS->search($CONTACTS->primary_key, $insert_id);
      
      $commands = "if(parent.$JS_OBJECT_NAME){\n";
      $commands .= "parent." . rcmail_js_contacts_list($result, $JS_OBJECT_NAME);

      $commands .= sprintf("parent.%s.contact_list.select('%s');\n",
                           $JS_OBJECT_NAME,
                           $insert_id);

      // update record count display
      $CONTACTS->reset();
      $commands .= sprintf("parent.%s.set_rowcount('%s');\n",
                           $JS_OBJECT_NAME,
                           rcmail_get_rowcount_text());

      $commands .= '}';
      $OUTPUT->add_script($commands);
      }

    // show confirmation
    show_message('successfullysaved', 'confirmation');
    rcmail_overwrite_action('show');
    $_GET['_cid'] = $insert_id;
    }
  else
    {
    // show error message
    show_message('errorsaving', 'error');
    rcmail_overwrite_action('add');
    }
  }

?>
