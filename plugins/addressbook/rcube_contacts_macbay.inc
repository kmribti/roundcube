<?php
/*
 +-----------------------------------------------------------------------+
 | plugins/addressbook/rcube_contacts_macbay.inc                         |
 |                                                                       |
 | This is a plugin example for an addressbook plugin. It heavily relies |
 | on Zend Framework componsents (http://framework.zend.com) in your     |
 | include_path and will only work with Macbay (http://www.macbay.de).   |
 |                                                                       |
 | PURPOSE:                                                              |
 |   This is a plugin for the macbay.de addressbook service.             |
 |                                                                       |
 +-----------------------------------------------------------------------+
 | Author: Till Klampaeckel <till@php.net>                               |
 +-----------------------------------------------------------------------+

 $Id: $

*/

/**
 * Zend_XmlRpc_Client
 * @ignore
 */
require_once 'Zend/XmlRpc/Client.php';

/**
 * Include rcube_result_set
 * @ignore
 */
require_once 'include/rcube/result_set.php';

/**
 * rcube_contacts_macbay
 *
 * @final
 * @author Till Klampaeckel <till@php.net>
 * @since  0.1-rc1
 */
final class rcube_contacts_macbay
{
    protected $db = null;
    protected $db_name = '';
    protected $user_id = 0;
    protected $filter = null;
    protected $result = null;
    protected $search_fields;
    protected $search_string;
    protected $table_cols = array('name', 'email', 'firstname', 'surname');

    protected $username;
    protected $password;
    protected $xmlrpc_client;

    /** public properties */
    public $primary_key = 'contact_id';
    public $readonly = false;
    public $list_page = 1;
    public $page_size = 10;
    public $ready = false;

    /**
     * Object constructor
     *
     * @access public
     * @param  object  Instance of the rcube_db class
     * @param  integer User-ID
     */
    public function __construct($username, $password, $xmlrpc_client = null)
    {
        $this->username = $username;
        $this->password = $password;

        if (is_null($xmlrpc_client) === FALSE) {
            $this->xmlrpc_client = $xmlrpc_client;
        }
        else {
            try {
                $this->xmlrpc_client = new Zend_XmlRpc_Client('https://www.macbay.de/sync2/XMLRPCServer');
            }
            catch(Zend_Exception $e) {
                rc_main::tfk_debug('Addressbook Exception: ' . var_export($e, true));
            }
        }
    }

    /**
     * Set internal list page
     *
     * @param  number  Page number to list
     * @access public
     */
    public function set_page($page)
    {
        $this->list_page = (int)$page;
    }


    /**
     * Set internal page size
     *
     * @param  number  Number of messages to display on one page
     * @access public
     */
    public function set_pagesize($size)
    {
        $this->page_size = (int)$size;
    }


    /**
     * Save a search string for future listings
     *
     * @param  string SQL params to use in listing method
     */
    public function set_search_set($filter)
    {
        $this->filter = $filter;
    }


    /**
     * Getter for saved search properties
     *
     * @return mixed Search properties used by this class
     */
    public function get_search_set()
    {
        return $this->filter;
    }


    /**
     * Reset all saved results and search parameters
     */
    public function reset()
    {
        $this->result = null;
        $this->filter = null;
        $this->search_fields = null;
        $this->search_string = null;
    }


    /**
     * Retrieves the contacts from XML-RPC and formats them accordingly.
     *
     * @param  array  List of cols to show
     * @return array  Indexed list of contact records, each a hash array
     * @uses   self::$username
     * @uses   self::$password
     * @uses   rc_main::decrypt_passwd()
     */
    public function list_records($cols=null, $subset=0)
    {
        $this->result = new rcube_result_set(0, 0);

        $this->primary_key = 'record_key';

        $params = array();
        array_push($params, $this->username);
        array_push($params, rc_main::decrypt_passwd($this->password));
        array_push($params, 'sort2');

        //rc_main::tfk_debug(var_export($params, true));

        try {
            $resp   = $this->xmlrpc_client->call('Addressbook.listContacts', $params);
            if (isset($resp['error'])) {
		rc_main::tfk_debug('Adressbook Exception: '.var_export($resp,true));
                throw new Zend_Exception($resp['error'], $resp['resultcode']);
            }
            //rc_main::tfk_debug(var_export($resp, true));
            if (isset($resp['result']) === false) {
                throw new Zend_Exception('Unknown response.');
            }
            $resp = $resp['result'];
            $z = 0;
            foreach ($resp AS $foo=>$_contact) {

                //rc_main::tfk_debug("CONTACT: " . var_export($_contact, true));

                if (isset($_contact['Email']['values']) === false) {
                    continue;
                }
                for ($y=0; $y<count($_contact['Email']['values']); $y++) {
                    $_data = $_contact['Email']['values'][$y];

                    if (empty($_data['value'])) {
                        continue;
                    }
                    $sql_array          = array();
                    $sql_array['ID']    = $z;
                    $sql_array['email'] = $_data['value'];
                    $sql_array['name']  = @$_contact['First'];
                    $sql_array['name'] .= ((isset($_contact['Middle']))?' ' . trim($_contact['Middle']):'');
                    $sql_array['name'] .= ((isset($_contact['Last']))?' ' . trim($_contact['Last']):'');
                    $sql_array['name']  = trim($sql_array['name']);

                    if (empty($sql_array['name'])) {
                        $sql_array['name'] = @$_contact['Organization'];
                        $sql_array['name'] = trim($sql_array['name']);
                    }
                    if (empty($sql_array['name'])) {
                        $sql_array['name'] = $sql_array['email'];
                    }
                    $this->result->add($sql_array);
                    $z++;
                }
            }
            $this->result->count = $z;
            $this->result->first = 0;
        }
        catch(Zend_Exception $e) {
            rc_main::tfk_debug('Addressbook Exception: ' . var_export($e, true));
            //$resp = array();
        }
        //rc_main::tfk_debug(var_export($this->result, true));
        return $this->result;
    }


    /**
     * Search contacts
     *
     * @param array   List of fields to search in
     * @param string  Search value
     * @param boolean True if results are requested, False if count only
     * @return Indexed list of contact records and 'count' value
     */
    public function search($fields, $value, $select=true)
    {
        return false;
    }


    /**
     * Count number of available contacts in database
     *
     * @return Result array with values for 'count' and 'first'
     */
    public function count()
    {
        if (isset($this->result->count)) {
            return $this->result->count;
        }
        return false;
    }


    /**
     * Return the last result set
     *
     * @return Result array or NULL if nothing selected yet
     */
    public function get_result($as_res=true)
    {
        return $this->result;
    }


    /**
     * Get a specific contact record
     *
     * @param  mixed record identifier(s)
     * @return Result object with all record fields or False if not found
     */
    public function get_record($id, $assoc=false)
    {
    }


    /**
     * Create a new contact record
     *
     * @access public
     * @param  array Assoziative array with save data
     * @param  boolean $check
     * @return The created record ID on success, False on error
     */
    public function insert($save_data, $check=false)
    {
        //rc_main::tfk_debug(var_export($save_data, true));

        $params = array();
        array_push($params, $this->username);
        array_push($params, rc_main::decrypt_passwd($this->password));

        $Email = array(
                    'label' => '_$!<Other>!$_',
                    'value' => $save_data['email']
        );

        $first = '';
        $last  = '';
        if (strstr($save_data['name'], ' ')) {
            $name = explode(' ', $save_data['name']);
            if (empty($name) || count($name) == 1) {
                $first.= '';
                $last .= $save_data['name'];
            }
            else {
                if (count($name) == 2) {
                    $first.= $name[0];
                    $last .= $name[1];
                }
                else {
                    $first.= $name[0];
                    for ($x=1; $x<count($name); $x++) {
                        $last .= $name[$x] . ' ';
                    }
                }
            }
        }
        else {
            $first.= '';
            $last .= $save_data['name'];
        }
        $contact_data = array();
        $contact_data['First'] = trim($first);
        $contact_data['Last']  = trim($last);
        $contact_data['Email'] = array();
        array_push($contact_data['Email'], $Email);

        array_push($params, $contact_data);

        //rc_main::tfk_debug(var_export($params, true));
        try {
            $resp = $this->xmlrpc_client->call(
                            'Addressbook.createContact',
                            $params
            );
        }
        catch (Zend_Exception $e) {
            rc_main::tfk_debug('Addressbook Exception: ' . var_export($e, true));
            return false;
        }
        if (isset($resp['result'])) {
            return true;
        }
        rc_main::tfk_debug(var_export($resp, true));
        return false;
    }


    /**
     * Insert new contacts for each row in set
     */
    public function insert_recset($result, $check=false)
    {
        $ids = array();
        while ($row = $result->next()) {
            if ($insert = $this->insert($row, $check)) {
                $ids[] = $insert;
            }
        }
        return $ids;
    }


    /**
     * Update a specific contact record
     *
     * @todo   Implement.
     * @param  mixed Record identifier
     * @param  array Assoziative array with save data
     * @return True on success, False on error
     */
    public function update($id, $save_cols)
    {
    }


    /**
     * Mark one or more contact records as deleted
     *
     * @todo  Implement.
     * @param array  Record identifiers
     */
    public function delete($ids)
    {

    }
}
?>
