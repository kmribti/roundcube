<?php

/*
 +-----------------------------------------------------------------------+
 | program/include/rcube_contacts.inc                                    |
 |                                                                       |
 | This file is part of the RoundCube Webmail client                     |
 | Copyright (C) 2006-2007, RoundCube Dev. - Switzerland                 |
 | Licensed under the GNU GPL                                            |
 |                                                                       |
 | PURPOSE:                                                              |
 |   Interface to the local address book database                        |
 |                                                                       |
 +-----------------------------------------------------------------------+
 | Author: Thomas Bruederli <roundcube@gmail.com>                        |
 +-----------------------------------------------------------------------+

 $Id: rcube_contacts.inc 328 2006-08-30 17:41:21Z thomasb $

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
require_once dirname(__FILE__) . '/rcube/result_set.php';

/**
 * rcube_contacts
 *
 * @final
 */
class rcube_contacts_macbay
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
                //$this->xmlrpc_client = new Zend_XmlRpc_Client('http://10.0.1.112:8080/sync/XMLRPCServer');
            }
            catch(Zend_Exception $e) {
                rc_main::tfk_debug(var_export($e, true));
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
            //rc_main::tfk_debug(var_export($e, true));
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
    }


    /**
     * Count number of available contacts in database
     *
     * @return Result array with values for 'count' and 'first'
     */
    public function count()
    {
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
    function get_record($id, $assoc=false)
    {
    }


    /**
     * Create a new contact record
     *
     * @param  array Assoziative array with save data
     * @return The created record ID on success, False on error
     */
    public function insert($save_data, $check=false)
    {
        /*
        if (is_object($save_data) && is_a($save_data, rcube_result_set)) {
            return $this->insert_recset($save_data, $check);
        }
        $insert_id = $existing = false;

        if ($check) {
            $existing = $this->search('email', $save_data['email'], false);
        }
        $a_insert_cols = $a_insert_values = array();
        foreach ($this->table_cols as $col) {
            if (isset($save_data[$col])) {
                $a_insert_cols[] = $this->db->quoteIdentifier($col);
                $a_insert_values[] = $this->db->quote($save_data[$col]);
            }
        }

        if (!$existing->count && !empty($a_insert_cols)) {
            $_query = "INSERT INTO " . $this->db_name;
            $_query.= " (user_id, changed, del, " . join(', ', $a_insert_cols).")";
            $_query.= " VALUES (?, " . $this->db->now() . ", 0, ";
            $_query.= join(', ', $a_insert_values).")";
            $this->db->query($_query, $this->user_id);

            $insert_id = $this->db->insert_id(
                                rc_main::get_sequence_name('contacts')
            );
        }

        return $insert_id;
        */
    }


    /**
     * Insert new contacts for each row in set
     */
    function insert_recset($result, $check=false)
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
     * @param mixed Record identifier
     * @param array Assoziative array with save data
     * @return True on success, False on error
     */
    function update($id, $save_cols)
    {
    }


    /**
     * Mark one or more contact records as deleted
     *
     * @param array  Record identifiers
     */
    function delete($ids)
    {
    }
}
?>