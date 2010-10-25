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
    private $abook_id = 'kolab';
    private $abook;
 
    /**
     * Required startup method of a Roundcube plugin
     */
    public function init()
    {
        // load local config
        $this->load_config();
        
        $this->add_hook('addressbooks_list', array($this, 'address_sources'));
        $this->add_hook('addressbook_get', array($this, 'get_address_book'));
        $this->add_hook('imap_init', array($this, 'imap_init'));

        // use this address book for autocompletion queries
        $config = rcmail::get_instance()->config;
        $sources = (array) $config->get('autocomplete_addressbooks', array('sql'));
        if (!in_array($this->abook_id, $sources)) {
            $sources[] = $this->abook_id;
            $config->set('autocomplete_addressbooks', $sources);
        }
        
        // extend include path to load bundled Horde classes
        $include_path = $this->home . '/lib' . PATH_SEPARATOR . ini_get('include_path');
        set_include_path($include_path);
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
        // get single instance (for now)
        $abook = rcube_kolab_contacts::singleton();
        
        // maybe here we'll add more than one item
        $p['sources'][$this->abook_id] = array(
            'id' => $this->abook_id,
            'name' => 'Kolab',
            'readonly' => $abook->readonly,
            'groups' => $abook->groups,
        );
        return $p;
    }
 
 
    /**
     * Getter for the rcube_addressbook instance
     */
    public function get_address_book($p)
    {
        if ($p['id'] === $this->abook_id) {
            $p['instance'] = rcube_kolab_contacts::singleton();
        }
        
        return $p;
    }
 
 
    /**
     * Make sure the X-Kolab-Type headers are also fetched when listing messages
     */
    function imap_init($p)
    {
        $p['fetch_headers'] = strtoupper('X-Kolab-Type');
        return $p;
    }
}
