<?php

/*
 +-----------------------------------------------------------------------+
 | program/steps/addressbook/func.inc                                    |
 |                                                                       |
 | This file is part of the RoundCube Webmail client                     |
 | Copyright (C) 2005-2007, RoundCube Dev. - Switzerland                 |
 | Licensed under the GNU GPL                                            |
 |                                                                       |
 | PURPOSE:                                                              |
 |   Provide addressbook functionality and GUI objects                   |
 |                                                                       |
 +-----------------------------------------------------------------------+
 | Author: Thomas Bruederli <roundcube@gmail.com>                        |
 +-----------------------------------------------------------------------+

 $Id$

*/

require_once('include/rcube_contacts.inc');

$CONTACTS = new rcube_contacts($DB, $_SESSION['user_id']);
$CONTACTS->set_pagesize($CONFIG['pagesize']);

// set list properties and session vars
if (!empty($_GET['_page']))
  {
  $CONTACTS->set_page(intval($_GET['_page']));
  $_SESSION['page'] = $_GET['_page'];
  }
else
  $CONTACTS->set_page(isset($_SESSION['page']) ?$_SESSION['page'] : 1);

// set message set for search result
if (!empty($_GET['_search']) && isset($_SESSION['search'][$_GET['_search']]))
  $CONTACTS->set_search_set($_SESSION['search'][$_GET['_search']]);


function rcmail_directory_list($attrib)
{
  global $CONFIG, $OUTPUT;
  
  if (!$attrib['id'])
    $attrib['id'] = 'rcmdirectorylist';

  // allow the following attributes to be added to the <ul> tag
  $out = '<ul' . create_attrib_string($attrib, array('style', 'class', 'id')) . ">\n";
  
  $out .= '<li><a href="#">' . rcube_label('personaladrbook') .  "</a></li>\n";

  $OUTPUT->add_gui_object('directorylist', $attrib['id']);
  
  return $out;
}


// return the message list as HTML table
function rcmail_contacts_list($attrib)
  {
  global $CONTACTS, $OUTPUT;
  
  // count contacts for this user
  $result = $CONTACTS->list_records();
  $rowcount = $result['count'];
  $firstrow = $result['first'];
  
  unset($result['count'], $result['first']);
  
  // add id to message list table if not specified
  if (!strlen($attrib['id']))
    $attrib['id'] = 'rcmAddressList';
  
  // define list of cols to be displayed
  $a_show_cols = array('name', 'email');
  
  // create XHTML table
  $out = rcube_table_output($attrib, $result, $a_show_cols, $CONTACTS->primary_key);
  
  // set client env
  $OUTPUT->add_gui_object('contactslist', $attrib['id']);
  $OUTPUT->set_env('current_page', (int)$CONTACTS->list_page);
  $OUTPUT->set_env('pagecount', ceil($rowcount/$CONTACTS->page_size));
  $OUTPUT->include_script('list.js');
  
  // add some labels to client
  rcube_add_label('deletecontactconfirm');
  
  return $out;
  }


function rcmail_js_contacts_list($result, $obj_name='this')
  {
  $commands = '';
  
  if (empty($result) || $result['count'] == 0)
    return $commands;

  unset($result['count'], $result['first']);
  
  // define list of cols to be displayed
  $a_show_cols = array('name', 'email');
  
  foreach ($result as $i => $row)
    {
    $a_row_cols = array();
    
    // format each col
    foreach ($a_show_cols as $col)
      $a_row_cols[$col] = Q($row[$col]);
    
    $commands .= sprintf("%s.add_contact_row(%s, %s);\n",
                         $obj_name,
                         $row['ID'],
                         array2js($a_row_cols));
    }
    
  return $commands;
  }


// similar function as /steps/settings/identities.inc::rcmail_identity_frame()
function rcmail_contact_frame($attrib)
  {
  global $OUTPUT;

  if (!$attrib['id'])
    $attrib['id'] = 'rcmcontactframe';
    
  $attrib['name'] = $attrib['id'];
  $attrib_str = create_attrib_string($attrib, array('name', 'id', 'class', 'style', 'src', 'width', 'height', 'frameborder'));

  $OUTPUT->set_env('contentframe', $attrib['name']);
  return '<iframe'. $attrib_str . '></iframe>';
  }


function rcmail_rowcount_display($attrib)
  {
  global $OUTPUT;
  
  if (!$attrib['id'])
    $attrib['id'] = 'rcmcountdisplay';

  $OUTPUT->add_gui_object('countdisplay', $attrib['id']);

  // allow the following attributes to be added to the <span> tag
  $attrib_str = create_attrib_string($attrib, array('style', 'class', 'id'));

  $out = '<span' . $attrib_str . '>';
  $out .= rcmail_get_rowcount_text();
  $out .= '</span>';
  return $out;
  }



function rcmail_get_rowcount_text()
  {
  global $CONTACTS;
  
  // read nr of contacts
  $result = $CONTACTS->get_result();
  if (!$result)
    $result = $CONTACTS->count();
  
  if ($result['count'] == 0)
    $out = rcube_label('nocontactsfound');
  else
    $out = rcube_label(array(
      'name' => 'contactsfromto',
      'vars' => array(
        'from'  => $result['first'] + 1,
        'to'    => min($result['count'], $result['first'] + $CONTACTS->page_size),
        'count' => $result['count'])
      ));

  return $out;
  }
  
  
// register UI objects
$OUTPUT->add_handlers(array(
  'directorylist' => 'rcmail_directory_list',
  'addresslist' => 'rcmail_contacts_list',
  'addressframe' => 'rcmail_contact_frame',
  'recordscountdisplay' => 'rcmail_rowcount_display',
  'searchform' => 'rcmail_search_form'
));

?>
