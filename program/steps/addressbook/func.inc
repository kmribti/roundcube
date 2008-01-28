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

 $Id: func.inc 578 2007-05-18 13:06:01Z thomasb $

*/


$registry = rcube_registry::get_instance();
$CONFIG   = $registry->get_all('config');
$OUTPUT   = $registry->get('OUTPUT', 'core');

/**
 * @todo Create a class file from local functions
 * @todo Add documentation to this file.
 */

//rcube::tfk_debug("ABOOK PLUGIN? " . $CONFIG['addressbook_plugin']);

// instantiate a contacts object according to the given source
if (
    ($source = rcube::get_input_value('_source', rcube::INPUT_GPC))
    && isset($CONFIG['ldap_public'][$source])
) {
    $CONTACTS = new rcube_ldap($CONFIG['ldap_public'][$source]);
} else {
    /**
     * @todo resolve conditional include (maybe autoload)
     */
    if (isset($CONFIG['addressbook_plugin']) && !empty($CONFIG['addressbook_plugin'])) {
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
}
$CONTACTS->set_pagesize($CONFIG['pagesize']);
$registry->set('CONTACTS', $CONTACTS, 'core');

// set list properties and session vars
if (!empty($_GET['_page'])) {
    $CONTACTS->set_page(($_SESSION['page'] = intval($_GET['_page'])));
} else {
    $CONTACTS->set_page(isset($_SESSION['page']) ?$_SESSION['page'] : 1);
}
$CONTACTS = $registry->set('CONTACTS', $CONTACTS, 'core');

// set message set for search result
if (!empty($_REQUEST['_search']) && isset($_SESSION['search'][$_REQUEST['_search']])) {
    $CONTACTS->set_search_set($_SESSION['search'][$_REQUEST['_search']]);
}

// set data source env
$OUTPUT->set_env('source', $source ? $source : '0');
$OUTPUT->set_env('readonly', $CONTACTS->readonly, false);

// add list of address sources to client env
$js_list = array("0" => array('id' => 0, 'readonly' => false));
foreach ((array)$CONFIG['ldap_public'] as $id => $prop) {
    $js_list[$id] = array('id' => $id, 'readonly' => !$prop['writeable']);
}
$OUTPUT->set_env('address_sources', $js_list);

function rcmail_directory_list($attrib)
{
    $registry = rcube_registry::get_instance();
    $CONFIG   = $registry->get_all('config');
    $OUTPUT   = $registry->get('OUTPUT', 'core');

    if (!$attrib['id']) {
        $attrib['id'] = 'rcmdirectorylist';
    }

    $local_id = '0';
    $current = rcube::get_input_value('_source', rcube::INPUT_GPC);

    $line_templ = '<li id="%s" class="%s"><a href="%s"';
    $line_templ.= ' onclick="return %s.command(\'list\',\'%s\',this)"';
    $line_templ.= ' onmouseover="return %s.focus_folder(\'%s\')"';
    $line_templ.= ' onmouseout="return %s.unfocus_folder(\'%s\')"';
    $line_templ.= ' onmouseup="return %s.folder_mouse_up(\'%s\')">%s';
    $line_templ.= "</a></li>\n";

    // allow the following attributes to be added to the <ul> tag
    $out = '<ul' . rcube::create_attrib_string($attrib, array('style', 'class', 'id')) . ">\n";

    //rcube::tfk_debug($out);

    $out.= sprintf(
                $line_templ,
                'rcmli'.$local_id,
                !$current ? 'selected' : '',
                Q(rcube::url('list', array('_source' => 0))),
                JS_OBJECT_NAME,
                $local_id,
                JS_OBJECT_NAME,
                $local_id,
                JS_OBJECT_NAME,
                $local_id,
                JS_OBJECT_NAME,
                $local_id,
                rcube::gettext('personaladrbook')
    );

    foreach ((array)$CONFIG['ldap_public'] as $id => $prop) {
        $js_id = JQ($id);
        $dom_id = preg_replace('/[^a-z0-9\-_]/i', '', $id);
        $out .= sprintf(
                    $line_templ,
                    'rcmli'.$dom_id,
                    $current == $id ? 'selected' : '',
                    Q(rcube::url('list', array('_source' => $id))),
                    JS_OBJECT_NAME,
                    $js_id,
                    JS_OBJECT_NAME,
                    $js_id,
                    JS_OBJECT_NAME,
                    $js_id,
                    JS_OBJECT_NAME,
                    $js_id,
                    !empty($prop['name']) ? Q($prop['name']) : Q($id)
        );
    }

    $out .= '</ul>';

    $OUTPUT->add_gui_object('folderlist', $attrib['id']);

    return $out;
}

/**
 * return the message list as HTML table
 *
 * @access public
 * @param  array $attrib
 * @return string
 * @uses   rcube_registry::get_instance()
 * @uses   rcube::table_output()
 * @uses   rcube_template::add_label()
 */
function rcube_contacts_list($attrib)
{
    $registry = rcube_registry::get_instance();
    $CONTACTS = $registry->get('CONTACTS', 'core');
    $OUTPUT   = $registry->get('OUTPUT', 'core');

    // count contacts for this user
    $result = $CONTACTS->list_records();

    // add id to message list table if not specified
    if (!strlen($attrib['id'])) {
        $attrib['id'] = 'rcmAddressList';
    }
    // define list of cols to be displayed
    $a_show_cols = array('name');

    // create XHTML table
    $out = rcube::table_output(
                $attrib,
                $result->records,
                $a_show_cols,
                $CONTACTS->primary_key
    );

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
    $registry = rcube_registry::get_instance();
    $OUTPUT   = $registry->get('OUTPUT', 'core');

    if (empty($result) || $result->count == 0) {
        return;
    }
    // define list of cols to be displayed
    $a_show_cols = array('name');

    while ($row = $result->next()) {
        $a_row_cols = array();

        // format each col
        foreach ($a_show_cols as $col) {
            $a_row_cols[$col] = $row[$col];
        }
        $OUTPUT->command($prefix.'add_contact_row', $row['ID'], $a_row_cols);
    }
}


/**
 * rcmail_contract_from
 *
 * @access public
 * @param  array $attrib
 * @return string
 * @see    rcmail_identity_frame()
 */
function rcmail_contact_frame($attrib)
{
    $registry = rcube_registry::get_instance();
    $OUTPUT   = $registry->get('OUTPUT', 'core');

    if (!$attrib['id']) {
        $attrib['id'] = 'rcmcontactframe';
    }
    $attrib['name'] = $attrib['id'];
    $attrib_str = rcube::create_attrib_string(
                        $attrib,
                        array(
                            'name',
                            'id',
                            'class',
                            'style',
                            'src',
                            'width',
                            'height',
                            'frameborder'
                        )
    );

    $OUTPUT->set_env('contentframe', $attrib['name']);
    $OUTPUT->set_env('blankpage', $attrib['src'] ? $OUTPUT->abs_url($attrib['src']) : 'program/blank.gif');
    return '<iframe'. $attrib_str . '></iframe>';
  }


function rcmail_rowcount_display($attrib)
{
    $registry = rcube_registry::get_instance();
    $OUTPUT   = $registry->get('OUTPUT', 'core');

    if (!$attrib['id']) {
        $attrib['id'] = 'rcmcountdisplay';
    }
    $OUTPUT->add_gui_object('countdisplay', $attrib['id']);

    // allow the following attributes to be added to the <span> tag
    $attrib_str = rcube::create_attrib_string($attrib, array('style', 'class', 'id'));

    $out = '<span' . $attrib_str . '>';
    $out .= rcmail_get_rowcount_text();
    $out .= '</span>';
    return $out;
}



function rcmail_get_rowcount_text()
{
    $registry = rcube_registry::get_instance();
    $CONTACTS = $registry->get('CONTACTS', 'core');

    // read nr of contacts
    $result = $CONTACTS->get_result();
    if (!$result) {
        $result = $CONTACTS->count();
    }
    if ($result->count == 0) {
        $out = rcube::gettext('nocontactsfound');
    }
    else {
        $out = rcube::gettext(array(
                    'name' => 'contactsfromto',
                    'vars' => array(
                                'from'  => $result->first + 1,
                                'to'    => min($result->count, $result->first + $CONTACTS->page_size),
                                'count' => $result->count)
                    )
        );
    }
    return $out;
}


// register UI objects
$OUTPUT->add_handlers(
    array(
        'directorylist'       => 'rcmail_directory_list',
        'addresslist'         => 'rcube_contacts_list',
        'addressframe'        => 'rcmail_contact_frame',
        'recordscountdisplay' => 'rcmail_rowcount_display',
        'searchform'          => array($OUTPUT, 'search_form'),
    )
);
?>