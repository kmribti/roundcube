<?php

/*
 +-----------------------------------------------------------------------+
 | program/steps/addressbook/func.inc                                    |
 |                                                                       |
 | This file is part of the Roundcube Webmail client                     |
 | Copyright (C) 2005-2007, Roundcube Dev. - Switzerland                 |
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

// add list of address sources to client env
$js_list = $RCMAIL->get_address_sources();

// select source
$source = get_input_value('_source', RCUBE_INPUT_GPC);

// if source is not set use first directory
if (empty($source))
    $source = $js_list[key($js_list)]['id'];

// instantiate a contacts object according to the given source
$CONTACTS = $RCMAIL->get_address_book($source);

$CONTACTS->set_pagesize($CONFIG['pagesize']);

// set list properties and session vars
if (!empty($_GET['_page']))
    $CONTACTS->set_page(($_SESSION['page'] = intval($_GET['_page'])));
else
    $CONTACTS->set_page(isset($_SESSION['page']) ?$_SESSION['page'] : 1);
  
if (!empty($_REQUEST['_gid']))
    $CONTACTS->set_group(get_input_value('_gid', RCUBE_INPUT_GPC));

// set message set for search result
if (!empty($_REQUEST['_search']) && isset($_SESSION['search'][$_REQUEST['_search']]))
    $CONTACTS->set_search_set($_SESSION['search'][$_REQUEST['_search']]);

// set data source env
$OUTPUT->set_env('source', $source ? $source : '0');
$OUTPUT->set_env('readonly', $CONTACTS->readonly, false);
if (!$OUTPUT->ajax_call) {
    $OUTPUT->set_env('address_sources', $js_list);
    $OUTPUT->set_pagetitle(rcube_label('addressbook'));
}


// TODO: let the $CONTACT object define the list of possible coltypes
$CONTACT_COLTYPES = array(
  'name'         => array('type' => 'text', 'size' => 40, 'limit' => 1, 'label' => rcube_label('name')),
  'firstname'    => array('type' => 'text', 'size' => 19, 'limit' => 1, 'label' => rcube_label('firstname')),
  'surname'      => array('type' => 'text', 'size' => 19, 'limit' => 1, 'label' => rcube_label('surname')),
  'middlename'   => array('type' => 'text', 'size' => 19, 'limit' => 1, 'label' => rcube_label('middlename')),
  'nickname'     => array('type' => 'text', 'size' => 40, 'limit' => 1, 'label' => rcube_label('nickname')),
  'jobtitle'     => array('type' => 'text', 'size' => 40, 'limit' => 1, 'label' => rcube_label('jobtitle')),
  'organization' => array('type' => 'text', 'size' => 19, 'limit' => 1, 'label' => rcube_label('organization')),
  'department'   => array('type' => 'text', 'size' => 19, 'limit' => 1, 'label' => rcube_label('department')),
  'email'        => array('type' => 'text', 'size' => 40, 'label' => rcube_label('email'), 'subtypes' => array('home','work','other')),
  'phone'        => array('type' => 'text', 'size' => 40, 'label' => rcube_label('phone'), 'subtypes' => array('home','home2','work','work2','mobile','main','homefax','workfax','car','pager','assistant','other')),
  'address'      => array('type' => 'composite', 'label' => rcube_label('address'), 'subtypes' => array('home','work','other'), 'childs' => array('street','locality','zipcode','region','country')),
    'street'     => array('type' => 'text', 'size' => 40, 'label' => rcube_label('street'), 'composite' => true),
    'locality'   => array('type' => 'text', 'size' => 28, 'label' => rcube_label('locality'), 'composite' => true),
    'zipcode'    => array('type' => 'text', 'size' => 8, 'label' => rcube_label('zipcode'), 'composite' => true),
    'region'     => array('type' => 'text', 'size' => 12, 'label' => rcube_label('region'), 'composite' => true),
    'country'    => array('type' => 'text', 'size' => 40, 'label' => rcube_label('country'), 'composite' => true),
  'birthday'     => array('type' => 'date', 'size' => 12, 'label' => rcube_label('birthday'), 'limit' => 1),
  'website'      => array('type' => 'text', 'size' => 40, 'label' => rcube_label('website'), 'subtypes' => array('home','work','blog','other')),
  'im'           => array('type' => 'text', 'size' => 40, 'label' => rcube_label('instantmessenger'), 'subtypes' => array('aim','icq','msn','yahoo','jabber','other')),
  'notes'        => array('type' => 'textarea', 'size' => 40, 'rows' => 15, 'label' => rcube_label('notes'), 'limit' => 1),
);



function rcmail_directory_list($attrib)
{
    global $RCMAIL, $OUTPUT;

    if (!$attrib['id'])
        $attrib['id'] = 'rcmdirectorylist';

    $out = '';
    $local_id = '0';
    $jsdata = array();
    $current = get_input_value('_source', RCUBE_INPUT_GPC);
    $line_templ = html::tag('li', array(
        'id' => 'rcmli%s', 'class' => 'addressbook %s'),
        html::a(array('href' => '%s',
            'onclick' => "return ".JS_OBJECT_NAME.".command('list','%s',this)"), '%s'));

    if (!$current && strtolower($RCMAIL->config->get('address_book_type', 'sql')) != 'ldap') {
        $current = '0';
    }
    else if (!$current) {
        // DB address book not used, see if a source is set, if not use the
        // first LDAP directory.
        $current = key((array)$RCMAIL->config->get('ldap_public', array()));
    }

    foreach ((array)$OUTPUT->env['address_sources'] as $j => $source) {
        $id = $source['id'] ? $source['id'] : $j;
        $js_id = JQ($id);
        $dom_id = preg_replace('/[^a-z0-9\-_]/i', '', $id);
        $out .= sprintf($line_templ, $dom_id, ($current == $id ? 'selected' : ''),
            Q(rcmail_url(null, array('_source' => $id))),
            $js_id, (!empty($source['name']) ? Q($source['name']) : Q($id)));
        $groupdata = rcmail_contact_groups(array('out' => $out, 'jsdata' => $jsdata, 'source' => $id));
        $jsdata = $groupdata['jsdata'];
        $out = $groupdata['out'];
    }

    $OUTPUT->set_env('contactgroups', $jsdata); 
    $OUTPUT->add_gui_object('folderlist', $attrib['id']);

    return html::tag('ul', $attrib, $out, html::$common_attrib);
}


function rcmail_contact_groups($args)
{
    global $RCMAIL;

    $groups = $RCMAIL->get_address_book($args['source'])->list_groups();

    if (!empty($groups)) {
        $line_templ = html::tag('li', array(
            'id' => 'rcmliG%s%s', 'class' => 'contactgroup'),
            html::a(array('href' => '#',
                'onclick' => "return ".JS_OBJECT_NAME.".command('listgroup',{'source':'%s','id':'%s'},this)"), '%s'));

        $jsdata = array();
        foreach ($groups as $group) {
            $args['out'] .= sprintf($line_templ, $args['source'], $group['ID'], $args['source'], $group['ID'], Q($group['name']));
            $args['jsdata']['G'.$args['source'].$group['ID']] = array(
                'source' => $args['source'], 'id' => $group['ID'],
                'name' => $group['name'], 'type' => 'group');
        }
    }

    return $args;
}


// return the message list as HTML table
function rcmail_contacts_list($attrib)
{
    global $CONTACTS, $OUTPUT;

    // count contacts for this user
    $result = $CONTACTS->list_records();

    // add id to message list table if not specified
    if (!strlen($attrib['id']))
        $attrib['id'] = 'rcmAddressList';

    // define list of cols to be displayed
    $a_show_cols = array('name');

    // create XHTML table
    $out = rcube_table_output($attrib, $result->records, $a_show_cols, $CONTACTS->primary_key);

    // set client env
    $OUTPUT->add_gui_object('contactslist', $attrib['id']);
    $OUTPUT->set_env('current_page', (int)$CONTACTS->list_page);
    $OUTPUT->set_env('pagecount', ceil($result->count/$CONTACTS->page_size));
    $OUTPUT->include_script('list.js');

    // add some labels to client
    $OUTPUT->add_label('deletecontactconfirm');

    return $out;
}


function rcmail_js_contacts_list($result, $prefix='')
{
    global $OUTPUT;

    if (empty($result) || $result->count == 0)
        return;

    // define list of cols to be displayed
    $a_show_cols = array('name');
  
    while ($row = $result->next()) {
        $a_row_cols = array();
    
        // format each col
        foreach ($a_show_cols as $col)
            $a_row_cols[$col] = Q($row[$col]);

        $OUTPUT->command($prefix.'add_contact_row', $row['ID'], $a_row_cols);
    }
}


// similar function as /steps/settings/identities.inc::rcmail_identity_frame()
function rcmail_contact_frame($attrib)
{
    global $OUTPUT;

    if (!$attrib['id'])
        $attrib['id'] = 'rcmcontactframe';
    
    $attrib['name'] = $attrib['id'];

    $OUTPUT->set_env('contentframe', $attrib['name']);
    $OUTPUT->set_env('blankpage', $attrib['src'] ? $OUTPUT->abs_url($attrib['src']) : 'program/blank.gif');

    return html::iframe($attrib);
}


function rcmail_rowcount_display($attrib)
{
    global $OUTPUT;

    if (!$attrib['id'])
        $attrib['id'] = 'rcmcountdisplay';

    $OUTPUT->add_gui_object('countdisplay', $attrib['id']);

    return html::span($attrib, rcmail_get_rowcount_text());
}


function rcmail_get_rowcount_text()
{
    global $CONTACTS;
  
    // read nr of contacts
    $result = $CONTACTS->get_result();
    if (!$result) {
        $result = $CONTACTS->count();
    }

    if ($result->count == 0)
        $out = rcube_label('nocontactsfound');
    else
        $out = rcube_label(array(
            'name'  => 'contactsfromto',
            'vars'  => array(
            'from'  => $result->first + 1,
            'to'    => min($result->count, $result->first + $CONTACTS->page_size),
            'count' => $result->count)
        ));

    return $out;
}


function rcmail_contact_form($form, $record, $attrib = null)
{
    global $RCMAIL;

    // Allow plugins to modify contact form content
    $plugin = $RCMAIL->plugins->exec_hook('contact_form', array(
        'form' => $form, 'record' => $record));

    $form = $plugin['form'];
    $record = $plugin['record'];
    $formdata = array();
    $out = '';
    
    // get default coltypes
    $coltypes = $GLOBALS['CONTACT_COLTYPES'];
    
    foreach ($coltypes as $col => $prop) {
        if ($prop['subtypes']) {
            $select_subtype = new html_select(array('name' => '_subtype_'.$col.'[]', 'class' => 'contactselectsubtype'));
            $select_subtype->add($prop['subtypes']);
            $coltypes[$col]['subtypes_select'] = $select_subtype->show();
        }
    }

    foreach ($form as $section => $fieldset) {
        // skip empty sections
        if (empty($fieldset['content']))
            continue;

        $select_add = new html_select(array('class' => 'addfieldmenu', 'rel' => $section));
        $select_add->add(rcube_label('addfield'), '');

        // render head section with name fields (not a regular list of rows)
        if ($section == 'head') {
            $content = '';
            
            $names_arr = array($record['firstname'], $record['middlename'], $record['surname']);
            if ($record['name'] == join(' ', array_filter($names_arr)))
              unset($record['name']);

            // group fields
            $field_blocks = array(
                'names'    => array('firstname','middlename','surname'),
                'addnames' => array('name','nickname'),
                'jobnames' => array('organization','department','jobtitle'),
            );
            foreach ($field_blocks as $blockname => $colnames) {
                $fields = '';
                foreach ($colnames as $col) {
                    if ($RCMAIL->action == 'show') {
                        if (!empty($record[$col]))
                            $fields .= html::span('namefield', Q($record[$col])) . " ";
                    }
                    else {
                        $colprop = (array)$fieldset['content'][$col] + (array)$coltypes[$col];
                        $colprop['id'] = 'ff_'.$col;
                        if (empty($record[$col]) && !$colprop['visible']) {
                            $colprop['style'] = 'display:none';
                            $select_add->add($colprop['label'], $col);
                        }
                        $fields .= rcmail_get_edit_field($col, $record[$col], $colprop, $colprop['type']);
                    }
                }
                $content .= html::div($blockname, $fields);
            }
            
            if ($RCMAIL->action != 'show')
                $content .= html::p('addfield', $select_add->show(null));

            $out .= html::tag('fieldset', $attrib, (!empty($fieldset['name']) ? html::tag('legend', null, Q($fieldset['name'])) : '') . $content) ."\n";
            continue;
        }

        $content = '';
        if (is_array($fieldset['content'])) {
            foreach ($fieldset['content'] as $col => $colprop) {
              #  $colprop['id'] = 'rcmfd_'.$col;

                list($field, $subtype) = explode(':', $col);
                if (!$subtype)
                  $subtype = 'home';

                $fullkey = $col.':'.$subtype;
                $label = isset($colprop['label']) ? $colprop['label'] : rcube_label($col);
                
                if ($coltypes[$field])
                    $colprop += $coltypes[$field];

                if ($RCMAIL->action != 'show' && is_array($colprop['subtypes'])) {
                    $select_subtype = new html_select(array('name' => '_subtype_'.$col.'[]', 'class' => 'contactselectsubtype'));
                    $select_subtype->add($colprop['subtypes']);
                }
                else
                    $select_subtype = null;

                if (!empty($colprop['value'])) {
                    $values = (array)$colprop['value'];
                }
                else {
                    if (is_array($colprop['subtypes'])) {
                        $values = $subtypes = array();
                        foreach ($colprop['subtypes'] as $i => $st) {
                            $newval = false;
                            if ($record[$field.':'.$st]) {
                                $subtypes[count($values)] = $st;
                                $newval = $record[$field.':'.$st];
                            }
                            else if ($i == 0 && $record[$field]) {
                                $subtypes[count($values)] = $st;
                                $newval = $record[$field];
                            }
                            if ($newval !== false) {
                                if (is_array($newval) && isset($newval[0]))
                                    $values = array_merge($values, $newval);
                                else
                                    $values[] = $newval;
                            }
                        }
                    }
                    else {
                        $values = $record[$fullkey] ?: $record[$field];
                        $subtypes = null;
                    }
                }

                if (empty($values) && $colprop['visible'])
                    $values[] = '';

                $rows = '';
                foreach ((array)$values as $i => $val) {
                    if ($subtypes[$i])
                        $subtype = $subtypes[$i];

                    // render composite field
                    if ($colprop['type'] == 'composite') {
                        $composite = '';
                        foreach ($colprop['childs'] as $j => $childcol) {
                            $childvalue = $val[$childcol] ? $val[$childcol] : $val[$j];

                            if ($RCMAIL->action != 'show') {
                                $cp = $coltypes[$childcol];
                                if ($colprop['subtypes']) $cp['array'] = true;
                                $composite .= rcmail_get_edit_field($childcol, $childvalue, $cp, $cp['type']) . " ";
                            }
                            else
                                $composite .= html::span('data ' . $childcol, Q($childvalue)) . " ";
                        }

                        $val = $composite;
                    }
                    else if ($RCMAIL->action != 'show') {
                        $coltypes[$field] = array('field' => $field, 'label' => $label) + (array)$colprop;
                        $formdata[$field][] = array('subtype' => $subtype, 'value' => $val);

                        if ($colprop['subtypes'])
                            $colprop['array'] = true;
                        $val = rcmail_get_edit_field($col, $val, $colprop, $colprop['type']);
                        $coltypes[$field]['count']++;
                    }
                    else if (!$colprop['value'])
                        $val = Q($val);

                    if ($colprop['subtypes'])
                        $label = $subtype;

                    if ($label) {
                        $rows .= html::div('row',
                            html::div('contactfieldlabel label', $select_subtype ? $select_subtype->show($subtype) : Q($label)) .
                            html::div('contactfieldcontent '.$colprop['type'], $val));
                    }
                    else
                        $rows .= html::div('row', html::div('contactfield', $val));
                }
                
                // add option to the add-field menu
                if (!$colprop['limit'] || $coltypes[$field]['count'] < $colprop['limit']) {
                    $select_add->add($colprop['label'], $col);
                    $select_add->_count++;
                }
                
                $content .= html::div('contactfieldgroup contactcontroller' . $col, $rows);
            }

            if ($RCMAIL->action != 'show' && $select_add->_count)
                $content .= html::p('addfield', $select_add->show(null));

            $content = html::div(array('id' => 'contactsection' . $section), $content);
        }
        else {
            $content = $fieldset['content'];
        }

        $out .= html::tag('fieldset', null, html::tag('legend', null, Q($fieldset['name'])) . $content) ."\n";
    }

    if ($RCMAIL->action != 'show') {
      $RCMAIL->output->set_env('contactdata', $formdata);
      $RCMAIL->output->set_env('coltypes', $coltypes);
    }

    return $out;
}



// register UI objects
$OUTPUT->add_handlers(array(
    'directorylist' => 'rcmail_directory_list',
//  'groupslist' => 'rcmail_contact_groups',
    'addresslist' => 'rcmail_contacts_list',
    'addressframe' => 'rcmail_contact_frame',
    'recordscountdisplay' => 'rcmail_rowcount_display',
    'searchform' => array($OUTPUT, 'search_form')
));
