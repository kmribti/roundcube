<?php

require_once(dirname(__FILE__) . '/rcube_kolab_contacts.php');

/**
 * Kolab address book
 * 
 * Sample plugin to add a new address book source with data from Kolab storage
 *
 * This is work-in-progress for the Roundcube+Kolab integration.
 * The library part is to be moved into a separate PEAR package or plugin
 * that this and other Kolab-related plugins will depend on.
 *
 * @author Thomas Bruederli <roundcube@gmail.com>
 * 
 */
class kolab_addressbook extends rcube_plugin
{
    private $folders;
    private $sources;

    /**
     * Required startup method of a Roundcube plugin
     */
    public function init()
    {
        // load local config
        $this->load_config();
        
        $this->add_hook('addressbooks_list', array($this, 'address_sources'));
        $this->add_hook('addressbook_get', array($this, 'get_address_book'));

        // extend include path to load bundled Horde classes
        $include_path = $this->home . '/lib' . PATH_SEPARATOR . ini_get('include_path');
        set_include_path($include_path);

        // extend list of address sources to be used for autocompletion
        $rcmail = rcmail::get_instance();
        if ($rcmail->action == 'autocomplete' || $rcmail->action == 'group-expand') {
            $sources = (array) $rcmail->config->get('autocomplete_addressbooks', array());
            foreach ($this->_list_sources() as $abook_id => $abook) {
                if (!in_array($abook_id, $sources))
                    $sources[] = $abook_id;
            }
            $rcmail->config->set('autocomplete_addressbooks', $sources);
        }
    }

    /**
     * Handler for the addressbooks_list hook.
     *
     * This will add all instances of available Kolab-based address books
     * to the list of address sources of Roundcube.
     *
     * @param array Hash array with hook parameters
     * @return array Hash array with modified hook parameters
     */
    public function address_sources($p)
    {
        foreach ($this->_list_sources() as $abook_id => $abook) {
            // register this address source
            $p['sources'][$abook_id] = array(
                'id' => $abook_id,
                'name' => $abook->get_name(),
                'readonly' => $abook->readonly,
                'groups' => $abook->groups,
            );
        }

        return $p;
    }


    /**
     * Getter for the rcube_addressbook instance
     */
    public function get_address_book($p)
    {
        if ($this->sources[$p['id']]) {
            $p['instance'] = $this->sources[$p['id']];
        }
        
        return $p;
    }
    
    
    private function _list_sources()
    {
        // already read sources
        if (isset($this->sources))
            return $this->sources;

        // get all folders that have "contact" type
        $this->folders = rcube_kolab::get_folders('contact');
        $this->sources = array();

        if (PEAR::isError($this->folders)) {
            raise_error(array(
              'code' => 600, 'type' => 'php',
              'file' => __FILE__, 'line' => __LINE__,
              'message' => "Failed to list contact folders from Kolab server:" . $this->folders->getMessage()),
            true, false);
        }
        else {
            foreach ($this->folders as $c_folder) {
                // create instance of rcube_contacts
                $abook_id = strtolower(asciiwords(strtr($c_folder->name, '/.', '--')));
                $abook = new rcube_kolab_contacts($c_folder->name);
                $this->sources[$abook_id] = $abook;
            }
        }
        
        return $this->sources;
    }

}
