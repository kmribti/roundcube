<?php

/**
 * Folders Access Control Lists Management
 *
 * @version 0.1
 * @author Aleksander Machniak <alec@alec.pl>
 *
 *
 * Copyright (C) 2011, Kolab Systems AG
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 */

class acl extends rcube_plugin
{
    public $task = 'settings';

    private $rc;
    private $supported = null;
    private $mbox;

    /**
     * Plugin initialization
     */
    function init()
    {
        $this->rc = rcmail::get_instance();

        $this->require_plugin('jqueryui');

        // Register hooks
        $this->add_hook('folder_form', array($this, 'folder_form'));
        // Plugin actions
        $this->register_action('plugin.acl', array($this, 'acl_actions')); 
    }

    /**
     * Handler for plugin actions (AJAX)
     */
    function acl_actions()
    {
        $action = trim(get_input_value('_act', RCUBE_INPUT_GPC));

        // Connect to IMAP
        $this->rc->imap_init();
        $this->rc->imap_connect();

        // Load localization and configuration
        $this->add_texts('localization/');
        $this->load_config();

        if ($action == 'save') {
            $this->action_save();
        }
        else if ($action == 'delete') {
            $this->action_delete();
        }
        else if ($action == 'list') {
            $this->action_list();
        }

        // Only AJAX actions
        $this->rc->output->send();
    }

    /**
     * Handler for 'folder_form' hook
     *
     * @param array $args Hook arguments array (form data)
     *
     * @return array Hook arguments array
     */
    function folder_form($args)
    {
        // Edited folder name (empty in create-folder mode)
        $mbox_imap = $args['options']['name'];
        if (!strlen($mbox_imap)) {
            return $args;
        }
/*
        // Do nothing on protected folders (?)
        if ($args['options']['protected']) {
            return $args;
        }
*/
        // Do nothing if no ACL support
        if (!$this->rc->imap->get_capability('ACL')) {
            return $args;
        }

        // Get MYRIGHTS
        if (!($myrights = $args['options']['rights'])) {
            return $args;
        }

        // Load localization and include scripts
        $this->load_config();
        $this->add_texts('localization/', array('deleteconfirm', 'norights',
            'nouser', 'deleting', 'saving'));
        $this->include_script('acl.js');
        $this->rc->output->include_script('list.js');
        $this->include_stylesheet($this->local_skin_path().'/acl.css');

        // Display folder rights to 'Info' fieldset
        $args['form']['props']['fieldsets']['info']['content']['myrights'] = array(
            'label' => Q($this->gettext('myrights')),
            'value' => $this->acl2text($myrights)
        );

        // Return if not folder admin
        if (!in_array('a', $myrights)) {
            return $args;
        }

        // The 'Sharing' tab
        $this->mbox = $mbox_imap;
        $this->rc->output->set_env('mailbox', $mbox_imap);
        $this->rc->output->add_handlers(array(
            'acltable'  => array($this, 'templ_table'),
            'aclrights' => array($this, 'templ_rights'),
        ));

        $args['form']['sharing'] = array(
            'name'    => Q($this->gettext('sharing')),
            'content' => $this->rc->output->parse('acl.table', false, false),
        );

        return $args;
    }

    /**
     * Creates ACL rights table
     *
     * @param array $attrib Template object attributes
     *
     * @return string HTML Content
     */
    function templ_table($attrib)
    {
        if (empty($attrib['id']))
            $attrib['id'] = 'acl-table';

        $out = $this->list_rights($attrib);

        $this->rc->output->add_gui_object('acltable', $attrib['id']);

        return $out;
    }

    /**
     * Creates ACL rights form
     *
     * @param array $attrib Template object attributes
     *
     * @return string HTML Content
     */
    function templ_rights($attrib)
    {
        // Get supported rights
        $supported = $this->rights_supported();

        // depending on server capability either use 'te' or 'd' for deleting msgs
        $deleteright = implode(array_intersect(str_split('ted'), $supported));

        $out = '';
        $ul  = '';
        $input = new html_checkbox();

        // Advanced rights
        $attrib['id'] = 'advancedrights';
        foreach ($supported as $val) {
            $id = "acl$val";
            $ul .= html::tag('li', null,
                $input->show('', array(
                    'name' => "acl[$val]", 'value' => $val, 'id' => $id))
                . html::label(array('for' => $id, 'title' => $this->gettext('longacl'.$val)),
                    $this->gettext('acl'.$val)));
        }

        $out = html::tag('ul', $attrib, $ul, html::$common_attrib);

        // Simple rights
        $ul = '';
        $attrib['id'] = 'simplerights';
        $items = array(
            'read' => 'lrs',
            'write' => 'wi',
            'delete' => $deleteright,
            'full' => implode($supported),
        );

        foreach ($items as $key => $val) {
            $id = "acl$key";
            $ul .= html::tag('li', null,
                $input->show('', array(
                    'name' => "acl[$val]", 'value' => $val, 'id' => $id))
                . html::label(array('for' => $id, 'title' => $this->gettext('longacl'.$key)),
                    $this->gettext('acl'.$key)));
        }
        
        $out .= "\n" . html::tag('ul', $attrib, $ul, html::$common_attrib);

        $this->rc->output->set_env('acl_items', $items);

        return $out;
    }

    /**
     * Creates ACL rights table
     *
     * @param array $attrib Template object attributes
     *
     * @return string HTML Content
     */
    private function list_rights($attrib=array())
    {
        // Get ACL for the folder
        $acl = $this->rc->imap->get_acl($this->mbox);

        if (!is_array($acl)) {
            $acl = array();
        }

        // Sort the list by username
        uksort($acl, 'strnatcasecmp');

        // Get supported rights and build column names
        $supported = $this->rights_supported();

        // depending on server capability either use 'te' or 'd' for deleting msgs
        $deleteright = implode(array_intersect(str_split('ted'), $supported));

        // Use advanced or simple (grouped) rights
        $advanced = $this->rc->config->get('acl_advanced_mode');

        if ($advanced) {
            $items = array();
            foreach ($supported as $sup) {
                $items[$sup] = $sup;
            }
        }
        else {
            $items = array(
                'read' => 'lrs',
                'write' => 'wi',
                'delete' => $deleteright,
                'full' => implode($supported),
            );
        }

        // Create the table
        $attrib['noheader'] = true;
        $table = new html_table($attrib);

        // Create table header
        $table->add_header('user', $this->gettext('username'));
        foreach (array_keys($items) as $key) {
            $table->add_header('acl'.$key, $this->gettext('shortacl'.$key));
        }

        $i = 1;
        $js_table = array();
        foreach ($acl as $user => $rights) {
            if ($this->rc->imap->conn->user == $user) {
                continue;
            }

            // filter out virtual rights (c or d) the server may return
            $userrights = array_intersect($rights, $supported);
            $userid = html_identifier($user);

            $table->add_row(array('id' => 'rcmrow'.$userid));
            $table->add('user', Q($user));

            foreach ($items as $key => $right) {
                $in = $this->acl_compare($userrights, $right);
                $table->add('acl'.$key . ' ' . ($in ? 'enabled' : 'disabled'), '');
            }

            $js_table[$userid] = implode($userrights);
        }

        $this->rc->output->set_env('acl', $js_table);
        $this->rc->output->set_env('acl_advanced', $advanced);

        $out = $table->show();

        return $out;
    }

    /**
     * Handler for ACL update/create action
     */
    private function action_save()
    {
        $mbox  = trim(get_input_value('_mbox', RCUBE_INPUT_GPC, true, 'UTF7-IMAP'));
        $user  = trim(get_input_value('_user', RCUBE_INPUT_GPC));
        $acl   = trim(get_input_value('_acl', RCUBE_INPUT_GPC));
        $oldid = trim(get_input_value('_old', RCUBE_INPUT_GPC));

        $acl = array_intersect(str_split($acl), $this->rights_supported());

        if (!strpos($user, '@') && ($realm = $this->rc->config->get('acl_username_realm'))) {
            $user .= '@' . rcube_idn_to_ascii(preg_replace('/^@/', '', $realm));
        }

        if ($acl && $user && strlen($mbox)
            && $this->rc->imap->set_acl($mbox, $user, $acl)
        ) {
            $ret = array('id' => html_identifier($user),
                 'username' => $user, 'acl' => implode($acl), 'old' => $oldid);
            $this->rc->output->command('acl_update', $ret);
            $this->rc->output->show_message($oldid ? 'acl.updatesuccess' : 'acl.createsuccess', 'confirmation');
        }
        else {
            $this->rc->output->show_message($oldid ? 'acl.updateerror' : 'acl.createerror', 'error');
        }
    }

    /**
     * Handler for ACL delete action
     */
    private function action_delete()
    {
        $mbox = trim(get_input_value('_mbox', RCUBE_INPUT_GPC, true, 'UTF7-IMAP'));
        $user = trim(get_input_value('_user', RCUBE_INPUT_GPC));

        $user = explode(',', $user);

        foreach ($user as $u) {
            if ($this->rc->imap->delete_acl($mbox, $u)) {
                $this->rc->output->command('acl_remove_row', html_identifier($u));
            }
            else {
                $error = true;
            }
        }

        if (!$error) {
            $this->rc->output->show_message('acl.deletesuccess', 'confirmation');
        }
        else {
            $this->rc->output->show_message('acl.deleteerror', 'error');
        }
    }

    /**
     * Handler for ACL list update action (with display mode change)
     */
    private function action_list()
    {
        if (in_array('acl_advanced_mode', (array)$this->rc->config->get('dont_override'))) {
            return;
        }

        $this->mbox = trim(get_input_value('_mbox', RCUBE_INPUT_GPC, true, 'UTF7-IMAP'));
        $advanced   = trim(get_input_value('_mode', RCUBE_INPUT_GPC));
        $advanced   = $advanced == 'advanced' ? true : false;

        // Save state in user preferences
        $this->rc->user->save_prefs(array('acl_advanced_mode' => $advanced));
        
        $out = $this->list_rights();

        $out = preg_replace(array('/^<table[^>]+>/', '/<\/table>$/'), '', $out);

        $this->rc->output->command('acl_list_update', $out);
    }

    /**
     * Creates <UL> list with descriptive access rights
     *
     * @param array $rights MYRIGHTS result
     *
     * @return string HTML content
     */        
    function acl2text($rights)
    {
        if (empty($rights)) {
            return '';
        }

        $supported = $this->rights_supported();
        $list      = array();
        $attrib    = array(
            'name' => 'rcmyrights',
            'style' => 'padding: 0 15px;',
        );

        foreach ($supported as $right) {
            if (in_array($right, $rights)) {
                $list[] = html::tag('li', null, Q($this->gettext('acl' . $right)));
            }
        }

        if (count($list) == count($supported))
            return Q($this->gettext('aclfull'));

        return html::tag('ul', $attrib, implode("\n", $list));
    }

    /**
     * Compares two ACLs (according to supported rights)
     *
     * @param array $acl1 ACL rights array (or string)
     * @param array $acl2 ACL rights array (or string)
     *
     * @param boolean Comparision result
     */
    function acl_compare($acl1, $acl2)
    {
        if (!is_array($acl1)) $acl1 = str_split($acl1);
        if (!is_array($acl2)) $acl2 = str_split($acl2);

        $rights = $this->rights_supported();

        $acl1 = array_intersect($acl1, $rights);
        $acl2 = array_intersect($acl2, $rights);
        $res  = array_intersect($acl1, $acl2);

        return count($res) == count($acl2);
    }

    /**
     * Get list of supported access rights (according to RIGHTS capability)
     *
     * @return array List of supported access rights abbreviations
     */
    function rights_supported()
    {
        if ($this->supported !== null) {
            return $this->supported;
        }

        $capa = $this->rc->imap->get_capability('RIGHTS');

        if (is_array($capa)) {
            $rights = strtolower($capa[0]);
        }
        else {
            $rights = 'cd';
        }

        return $this->supported = str_split('lrswi' . $rights . 'pa');
    }
}