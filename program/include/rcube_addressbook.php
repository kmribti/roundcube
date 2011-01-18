<?php

/*
 +-----------------------------------------------------------------------+
 | program/include/rcube_addressbook.php                                 |
 |                                                                       |
 | This file is part of the Roundcube Webmail client                     |
 | Copyright (C) 2006-2011, The Roundcube Dev Team                       |
 | Licensed under the GNU GPL                                            |
 |                                                                       |
 | PURPOSE:                                                              |
 |   Interface to the local address book database                        |
 |                                                                       |
 +-----------------------------------------------------------------------+
 | Author: Thomas Bruederli <roundcube@gmail.com>                        |
 +-----------------------------------------------------------------------+

 $Id$

*/


/**
 * Abstract skeleton of an address book/repository
 *
 * @package Addressbook
 */
abstract class rcube_addressbook
{
    /** constants for error reporting **/
    const ERROR_READ_ONLY = 1;
    const ERROR_NO_CONNECTION = 2;
    const ERROR_INCOMPLETE = 3;
    const ERROR_SAVING = 4;
    
    /** public properties (mandatory) */
    public $primary_key;
    public $groups = false;
    public $readonly = true;
    public $ready = false;
    public $list_page = 1;
    public $page_size = 10;
    public $coltypes = array('name' => array('limit'=>1), 'firstname' => array('limit'=>1), 'surname' => array('limit'=>1), 'email' => array('limit'=>1));
    
    protected $error;

    /**
     * Save a search string for future listings
     *
     * @param mixed Search params to use in listing method, obtained by get_search_set()
     */
    abstract function set_search_set($filter);

    /**
     * Getter for saved search properties
     *
     * @return mixed Search properties used by this class
     */
    abstract function get_search_set();

    /**
     * Reset saved results and search parameters
     */
    abstract function reset();

    /**
     * Refresh saved search set after data has changed
     *
     * @return mixed New search set
     */
    function refresh_search()
    {
        return $this->get_search_set();
    }

    /**
     * List the current set of contact records
     *
     * @param  array  List of cols to show
     * @param  int    Only return this number of records, use negative values for tail
     * @return array  Indexed list of contact records, each a hash array
     */
    abstract function list_records($cols=null, $subset=0);

    /**
     * Search records
     *
     * @param array   List of fields to search in
     * @param string  Search value
     * @param boolean True if results are requested, False if count only
     * @param boolean True to skip the count query (select only)
     * @param array   List of fields that cannot be empty
     * @return object rcube_result_set List of contact records and 'count' value
     */
    abstract function search($fields, $value, $strict=false, $select=true, $nocount=false, $required=array());

    /**
     * Count number of available contacts in database
     *
     * @return rcube_result_set Result set with values for 'count' and 'first'
     */
    abstract function count();

    /**
     * Return the last result set
     *
     * @return rcube_result_set Current result set or NULL if nothing selected yet
     */
    abstract function get_result();

    /**
     * Get a specific contact record
     *
     * @param mixed record identifier(s)
     * @param boolean True to return record as associative array, otherwise a result set is returned
     *
     * @return mixed Result object with all record fields or False if not found
     */
    abstract function get_record($id, $assoc=false);

    /**
     * Returns the last error occured (e.g. when updating/inserting failed)
     *
     * @return array Hash array with the following fields: type, message
     */
    function get_error()
    {
      return $this->error;
    }
    
    /**
     * Setter for errors for internal use
     *
     * @param int Error type (one of this class' error constants)
     * @param string Error message (name of a text label)
     */
    protected function set_error($type, $message)
    {
      $this->error = array('type' => $type, 'message' => $message);
    }

    /**
     * Close connection to source
     * Called on script shutdown
     */
    function close() { }

    /**
     * Set internal list page
     *
     * @param  number  Page number to list
     * @access public
     */
    function set_page($page)
    {
        $this->list_page = (int)$page;
    }

    /**
     * Set internal page size
     *
     * @param  number  Number of messages to display on one page
     * @access public
     */
    function set_pagesize($size)
    {
        $this->page_size = (int)$size;
    }

    /**
     * Create a new contact record
     *
     * @param array Assoziative array with save data
     *  Keys:   Field name with optional section in the form FIELD:SECTION
     *  Values: Field value. Can be either a string or an array of strings for multiple values
     * @param boolean True to check for duplicates first
     * @return mixed The created record ID on success, False on error
     */
    function insert($save_data, $check=false)
    {
        /* empty for read-only address books */
    }

    /**
     * Create new contact records for every item in the record set
     *
     * @param object rcube_result_set Recordset to insert
     * @param boolean True to check for duplicates first
     * @return array List of created record IDs
     */
    function insertMultiple($recset, $check=false)
    {
        $ids = array();
        if (is_object($recset) && is_a($recset, rcube_result_set)) {
            while ($row = $recset->next()) {
                if ($insert = $this->insert($row, $check))
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
     *  Keys:   Field name with optional section in the form FIELD:SECTION
     *  Values: Field value. Can be either a string or an array of strings for multiple values
     * @return boolean True on success, False on error
     */
    function update($id, $save_cols)
    {
        /* empty for read-only address books */
    }

    /**
     * Mark one or more contact records as deleted
     *
     * @param array  Record identifiers
     */
    function delete($ids)
    {
        /* empty for read-only address books */
    }

    /**
     * Remove all records from the database
     */
    function delete_all()
    {
        /* empty for read-only address books */
    }

    /**
     * Setter for the current group
     * (empty, has to be re-implemented by extending class)
     */
    function set_group($gid) { }

    /**
     * List all active contact groups of this source
     *
     * @param string  Optional search string to match group name
     * @return array  Indexed list of contact groups, each a hash array
     */
    function list_groups($search = null)
    {
        /* empty for address books don't supporting groups */
        return array();
    }

    /**
     * Create a contact group with the given name
     *
     * @param string The group name
     * @return mixed False on error, array with record props in success
     */
    function create_group($name)
    {
        /* empty for address books don't supporting groups */
        return false;
    }

    /**
     * Delete the given group and all linked group members
     *
     * @param string Group identifier
     * @return boolean True on success, false if no data was changed
     */
    function delete_group($gid)
    {
        /* empty for address books don't supporting groups */
        return false;
    }

    /**
     * Rename a specific contact group
     *
     * @param string Group identifier
     * @param string New name to set for this group
     * @return boolean New name on success, false if no data was changed
     */
    function rename_group($gid, $newname)
    {
        /* empty for address books don't supporting groups */
        return false;
    }

    /**
     * Add the given contact records the a certain group
     *
     * @param string  Group identifier
     * @param array   List of contact identifiers to be added
     * @return int    Number of contacts added
     */
    function add_to_group($group_id, $ids)
    {
        /* empty for address books don't supporting groups */
        return 0;
    }

    /**
     * Remove the given contact records from a certain group
     *
     * @param string  Group identifier
     * @param array   List of contact identifiers to be removed
     * @return int    Number of deleted group members
     */
    function remove_from_group($group_id, $ids)
    {
        /* empty for address books don't supporting groups */
        return 0;
    }

    /**
     * Get group assignments of a specific contact record
     *
     * @param mixed Record identifier
     *
     * @return array List of assigned groups as ID=>Name pairs
     * @since 0.5-beta
     */
    function get_record_groups($id)
    {
        /* empty for address books don't supporting groups */
        return array();
    }


    /**
     * Utility function to return all values of a certain data column
     * either as flat list or grouped by subtype
     *
     * @param string Col name
     * @param array  Record data array as used for saving
     * @param boolean True to return one array with all values, False for hash array with values grouped by type
     * @return array List of column values
     */
    function get_col_values($col, $data, $flat = false)
    {
        $out = array();
        foreach ($data as $c => $values) {
            if (strpos($c, $col) === 0) {
                if ($flat) {
                    $out = array_merge($out, (array)$values);
                }
                else {
                    list($f, $type) = explode(':', $c);
                    $out[$type] = array_merge((array)$out[$type], (array)$values);
                }
            }
        }
      
        return $out;
    }
    
}

