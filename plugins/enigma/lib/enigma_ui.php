<?php
/*
 +-------------------------------------------------------------------------+
 | User Interface for the Enigma Plugin                                    |
 |                                                                         |
 | This program is free software; you can redistribute it and/or modify    |
 | it under the terms of the GNU General Public License version 2          |
 | as published by the Free Software Foundation.                           |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 |                                                                         |
 | You should have received a copy of the GNU General Public License along |
 | with this program; if not, write to the Free Software Foundation, Inc., |
 | 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.             |
 |                                                                         |
 +-------------------------------------------------------------------------+
 | Author: Aleksander Machniak <alec@alec.pl>                              |
 +-------------------------------------------------------------------------+
*/

class enigma_ui
{
    private $rc;
    private $enigma;
    private $home;
    private $css_added;
    private $listsize;
    private $data;


    function __construct($enigma_plugin, $home='')
    {
        $this->enigma = $enigma_plugin;
        $this->rc = $enigma_plugin->rc;
        // we cannot use $enigma_plugin->home here
        $this->home = $home;
    }

    /**
     * UI initialization and requests handlers.
     *
     * @param string Preferences section
     */
    function init($section='')
    {
        $this->enigma->include_script('enigma.js');

        // Enigma actions
        if ($this->rc->action == 'plugin.enigma') {
            $action = get_input_value('_a', RCUBE_INPUT_GET);

            switch ($action) {
                case 'keyedit':
                    $this->key_edit();
                    break;
                case 'keyimport':
                    $this->key_import();
                    break;
                case 'keysearch':
                    $this->key_search();
                    break;
                default:
                    $this->key_info();
            }
        }
        // Preferences UI
        else { // if ($this->rc->action == 'edit-prefs') {
            if ($section == 'enigmacerts') {
                $this->rc->output->add_handlers(array(
                    'keyslist' => array($this, 'certs_list'),
                    'keyframe' => array($this, 'cert_frame'),
                    'countdisplay' => array($this, 'certs_rowcount'),
                    'searchform' => array($this->rc->output, 'search_form'),
                ));
                $this->rc->output->set_pagetitle($this->enigma->gettext('enigmacerts'));
                $this->rc->output->send('enigma.certs'); 
            }
            else {
                $this->rc->output->add_handlers(array(
                    'keyslist' => array($this, 'keys_list'),
                    'keyframe' => array($this, 'key_frame'),
                    'countdisplay' => array($this, 'keys_rowcount'),
                    'searchform' => array($this->rc->output, 'search_form'),
                ));
                $this->rc->output->set_pagetitle($this->enigma->gettext('enigmakeys'));
                $this->rc->output->send('enigma.keys'); 
            }
        }
    }

   /**
     * Adds CSS style file to the page header.
     */
    function add_css()
    {
        if ($this->css_loaded)
            return;

        $skin = $this->rc->config->get('skin');
        if (!file_exists($this->home . "/skins/$skin/enigma.css"))
            $skin = 'default';

        $this->enigma->include_stylesheet("skins/$skin/enigma.css");
        $this->css_added = true;
    }

    /**
     * Template object for key info/edit frame.
     *
     * @param array Object attributes
     *
     * @return string HTML output
     */
    function key_frame($attrib)
    {
        if (!$attrib['id']) {
            $attrib['id'] = 'rcmkeysframe';
        }
    
        $attrib['name'] = $attrib['id'];

        $this->rc->output->set_env('contentframe', $attrib['name']);
        $this->rc->output->set_env('blankpage', $attrib['src'] ? 
            $this->rc->output->abs_url($attrib['src']) : 'program/blank.gif');

        return html::tag('iframe', $attrib);
    }

    /**
     * Template object for list of keys.
     *
     * @param array Object attributes
     *
     * @return string HTML content
     */
    function keys_list($attrib)
    {
        $this->enigma->load_engine();

        // add id to message list table if not specified
        if (!strlen($attrib['id'])) {
            $attrib['id'] = 'rcmenigmakeyslist';
        }

        // define list of cols to be displayed
        $a_show_cols = array('name');
        $result = array();

        // Get the list
        $list = $this->enigma->engine->list_keys();

        if (is_array($list)) {
            // Sort the list by key (user) name
            usort($list, array('enigma_key', 'cmp'));

            foreach($list as $idx => $key) {
                $result[] = array('name' => $key->name, 'id' => $key->id);
                unset($list[$idx]);
            }
        }

        // create XHTML table
        $out = rcube_table_output($attrib, $result, $a_show_cols, 'id');

        if ($list && ($list instanceof enigma_error))
            $this->rc->output->show_message('enigma.keylisterror', 'error');
        else if (empty($result))
            $this->rc->output->show_message('enigma.nokeysfound', 'notice');
        else
            $this->listsize = count($result);

        // set client env
        $this->rc->output->add_gui_object('keyslist', $attrib['id']);
        $this->rc->output->include_script('list.js');
  
        // add some labels to client
        $this->rc->output->add_label('enigma.keyconfirmdelete');
  
        return $out;
    }


    /**
     * Template object for list records counter.
     *
     * @param array Object attributes
     *
     * @return string HTML output
     */
    function keys_rowcount($attrib)
    {
        if (!$attrib['id'])
            $attrib['id'] = 'rcmcountdisplay';

        $this->rc->output->add_gui_object('countdisplay', $attrib['id']);

        return html::span($attrib, $this->get_rowcount_text());
    }

    /**
     * Returns text representation of list records counter
     */
    private function get_rowcount_text()
    {
        $page_size = $this->rc->config->get('pagesize', 100);
        $count = $this->listsize;
        $first = 0;

        if (!$count)
            $out = $this->enigma->gettext('nokeysfound');
        else
            $out = $this->enigma->gettext(array(
                'name' => 'keysfromto',
                'vars' => array(
                    'from'  => $first + 1,
                    'to'    => min($count, $page_size),
                    'count' => $count)
        ));

        return $out;
    }

    /**
     * Key information page handler
     */
    private function key_info()
    {
        $id = get_input_value('_id', RCUBE_INPUT_GET);

        $this->enigma->load_engine();
        $res = $this->enigma->engine->get_key($id);

        if ($res instanceof enigma_key)
            $this->data = $res;
        else { // error
            $this->rc->output->show_message('enigma.keyopenerror', 'error');
            $this->rc->output->command('parent.enigma_loadframe');
            $this->rc->output->send('iframe');
        }

        $this->rc->output->add_handlers(array(
            'keyname' => array($this, 'key_name'),
            'keydata' => array($this, 'key_data'),
        ));

        $this->rc->output->set_pagetitle($this->enigma->gettext('keyinfo'));
        $this->rc->output->send('enigma.keyinfo');
    }

    /**
     * Template object for key name
     */
    function key_name($attrib)
    {
        return Q($this->data->name);
    }

    /**
     * Template object for key information page content
     */
    function key_data($attrib)
    {
        $out = '';
        $table = new html_table(array('cols' => 2)); 

        // Key user ID
        $table->add('title', $this->enigma->gettext('keyuserid'));
        $table->add(null, Q($this->data->name));
        // Key ID
        $table->add('title', $this->enigma->gettext('keyid'));
        $table->add(null, $this->data->subkeys[0]->get_short_id());
        // Key type
        $keytype = $this->data->get_type();
        if ($keytype == enigma_key::TYPE_KEYPAIR)
            $type = $this->enigma->gettext('typekeypair');
        else if ($keytype == enigma_key::TYPE_PUBLIC)
            $type = $this->enigma->gettext('typepublickey');
        $table->add('title', $this->enigma->gettext('keytype'));
        $table->add(null, $type);
        // Key fingerprint
        $table->add('title', $this->enigma->gettext('fingerprint'));
        $table->add(null, $this->data->subkeys[0]->get_fingerprint());

        $out .= html::tag('fieldset', null,
            html::tag('legend', null,
                $this->enigma->gettext('basicinfo')) . $table->show($attrib));

        // Subkeys
        $table = new html_table(array('cols' => 6)); 
        // Columns: Type, ID, Algorithm, Size, Created, Expires

        $out .= html::tag('fieldset', null,
            html::tag('legend', null, 
                $this->enigma->gettext('subkeys')) . $table->show($attrib));

        // Additional user IDs
        $table = new html_table(array('cols' => 2));
        // Columns: User ID, Validity

        $out .= html::tag('fieldset', null,
            html::tag('legend', null, 
                $this->enigma->gettext('userids')) . $table->show($attrib));

        return $out;
    }

    /**
     * Key import page handler
     */
    private function key_import()
    {
        // Import process
        if ($_FILES['_file']['tmp_name'] && is_uploaded_file($_FILES['_file']['tmp_name'])) {
            $this->enigma->load_engine();
            $result = $this->enigma->engine->import_key($_FILES['_file']['tmp_name'], true);

            if (is_array($result)) {
                $this->rc->output->show_message('enigma.keysimportsuccess', 'confirmation',
                    array('new' => $result['imported'], 'old' => $result['unchanged']));

                if ($result['imported']) {
                    // @TODO: reload list if any keys has been added
                }
                $this->rc->output->command('parent.enigma_loadframe');
                $this->rc->output->send('iframe');
            }
            else
                $this->rc->output->show_message('enigma.keysimportfailed', 'error');
        }
        else if ($err = $_FILES['_file']['error']) {
            if ($err == UPLOAD_ERR_INI_SIZE || $err == UPLOAD_ERR_FORM_SIZE) {
                $this->rc->output->show_message('filesizeerror', 'error',
                    array('size' => show_bytes(parse_bytes(ini_get('upload_max_filesize')))));
            } else {
                $this->rc->output->show_message('fileuploaderror', 'error');
            }
        }

        $this->rc->output->add_handlers(array(
            'importform' => array($this, 'key_import_form'),
        ));

        $this->rc->output->set_pagetitle($this->enigma->gettext('keyimport'));
        $this->rc->output->send('enigma.keyimport');
    }

    /**
     * Template object for key import (upload) form
     */
    function key_import_form($attrib)
    {
        $attrib += array('id' => 'rcmKeyImportForm');
  
        $upload = new html_inputfield(array('type' => 'file', 'name' => '_file',
            'id' => 'rcmimportfile', 'size' => 30));

        $form = html::p(null,
            Q($this->enigma->gettext('keyimporttext'), 'show')
            . html::br() . html::br() . $upload->show()
        );
  
        $this->rc->output->add_label('selectimportfile', 'importwait');
        $this->rc->output->add_gui_object('importform', $attrib['id']);

        $out = $this->rc->output->form_tag(array(
            'action' => $this->rc->url(array('action' => 'plugin.enigma', 'a' => 'keyimport')),
            'method' => 'post',
            'enctype' => 'multipart/form-data') + $attrib,
            $form);

        return $out;
    }


}
