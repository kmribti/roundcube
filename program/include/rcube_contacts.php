<?php

/*
 +-----------------------------------------------------------------------+
 | program/include/rcube_contacts.php                                    |
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
 * Model class for the local address book database
 *
 * @package Addressbook
 */
class rcube_contacts
{
    protected $db = null;
    protected $db_name = '';
    protected $user_id = 0;
    protected $filter = null;
    protected $result = null;
    protected $search_fields;
    protected $search_string;
    protected $table_cols = array('name', 'email', 'firstname', 'surname');

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
    public function __construct($dbconn, $user)
    {
        $this->db      = $dbconn;
        $this->db_name = rcube::get_table_name('contacts');
        $this->user_id = $user;
        $this->ready   = $this->db && !$this->db->is_error();
    }

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
     * Save a search string for future listings
     *
     * @param  string SQL params to use in listing method
     */
    function set_search_set($filter)
    {
        $this->filter = $filter;
    }


    /**
     * Getter for saved search properties
     *
     * @return mixed Search properties used by this class
     */
    function get_search_set()
    {
        return $this->filter;
    }


    /**
     * Reset all saved results and search parameters
     */
    function reset()
    {
        $this->result = null;
        $this->filter = null;
        $this->search_fields = null;
        $this->search_string = null;
    }

	/**
	 * Close connection to source
	 * Called on script shutdown
	 */
    function close() {}

    /**
     * List the current set of contact records
     *
     * @param  array  List of cols to show
     * @param  int    Only return this number of records, use negative values for tail
     * @return array  Indexed list of contact records, each a hash array
     */
    function list_records($cols=null, $subset=0)
    {
        // count contacts for this user
        $this->result = $this->count();
        $sql_result = NULL;

        // get contacts from DB
        if ($this->result->count) {
            $start_row = $subset < 0 ? $this->result->first + $this->page_size + $subset : $this->result->first;
            $length    = $subset != 0 ? abs($subset) : $this->page_size;

            $_query = "SELECT * FROM " . $this->db_name;
            $_query.= " WHERE del<>1";
            $_query.= " AND user_id=?";
            $_query.= ($this->filter ? " AND (".$this->filter.")" : "");
            $_query.= " ORDER BY name";

            $sql_result = $this->db->limitquery(
                                $_query,
                                $start_row,
                                $length,
                                $this->user_id
            );
        }

        while ($sql_result && ($sql_arr = $this->db->fetch_assoc($sql_result))) {
            $sql_arr['ID'] = $sql_arr[$this->primary_key];
            // make sure we have a name to display
            if (empty($sql_arr['name'])) {
                $sql_arr['name'] = $sql_arr['email'];
            }
            $this->result->add($sql_arr);
        }
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
    function search($fields, $value, $strict = false, $select = true)
    {
        if (!is_array($fields)) {
            $fields = array($fields);
        }
        $add_where = array();
        foreach ($fields as $col) {
            if ($col == 'ID' || $col == $this->primary_key) {
                $ids         = !is_array($value) ? split(',', $value) : $value;
                $add_where[] = $this->primary_key." IN (".join(',', $ids).")";
            }
            elseif ($strict) {
                $add_where[] = $this->db->quoteIdentifier($col)."=".$this->db->quote($value);
            }
            else {
                $_where = $this->db->quoteIdentifier($col);
                $_where.= " LIKE " . $this->db->quote(strlen($value)>2 ? "%$value%" : "$value%");
                $add_where[] = $_where;
            }
        }

        if (!empty($add_where)) {
            $this->set_search_set(join(' OR ', $add_where));
            if ($select) {
                $this->list_records();
            }
            else {
                $this->result = $this->count();
            }
        }
        return $this->result;
    }


    /**
     * Count number of available contacts in database
     *
     * @return Result array with values for 'count' and 'first'
     */
    function count()
    {
        $_query = "SELECT COUNT(contact_id) AS rows";
        $_query.= " FROM " . $this->db_name;
        $_query.= " WHERE del<>1";
        $_query.= " AND user_id=?";
        $_query.= ($this->filter ? " AND (".$this->filter.")" : "");

        // count contacts for this user
        $sql_result = $this->db->query($_query, $this->user_id);
        $sql_arr    = $this->db->fetch_assoc($sql_result);
        return new rcube_result_set(
                        $sql_arr['rows'],
                        ($this->list_page-1) * $this->page_size
        );
    }


    /**
     * Return the last result set
     *
     * @return Result array or NULL if nothing selected yet
     */
    function get_result($as_res=true)
    {
        return $this->result;
    }


    /**
     * Get a specific contact record
     *
     * @param mixed record identifier(s)
     * @return Result object with all record fields or False if not found
     */
    function get_record($id, $assoc=false)
    {
        // return cached result
        if (
            $this->result
            && ($first = $this->result->first())
            && $first[$this->primary_key] == $id
        ) {
            return $assoc ? $first : $this->result;
        }

        $_query = "SELECT * FROM ".$this->db_name;
        $_query.= " WHERE contact_id=?";
        $_query.= " AND user_id=?";
        $_query.= " AND del<>1";

        $this->db->query($_query, $id, $this->user_id);

        if ($sql_arr = $this->db->fetch_assoc()) {
            $sql_arr['ID'] = $sql_arr[$this->primary_key];
            $this->result = new rcube_result_set(1);
            $this->result->add($sql_arr);
        }
        return $assoc && $sql_arr ? $sql_arr : $this->result;
    }


  /**
   * Create a new contact record
   *
   * @param array Assoziative array with save data
   * @return The created record ID on success, False on error
   */
    function insert($save_data, $check=false)
    {
        if (is_object($save_data) && is_a($save_data, rcube_result_set)) {
            return $this->insert_recset($save_data, $check);
        }
        $insert_id = $existing = false;

        if ($check) {
            $existing = $this->search('email', $save_data['email'], true, false);
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
                                rcube::get_sequence_name('contacts')
            );
        }

        return $insert_id;
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
        $updated = false;
        $write_sql = array();
        foreach ($this->table_cols as $col) {
            if (isset($save_cols[$col])) {
                $write_sql[] = sprintf("%s=%s", $this->db->quoteIdentifier($col), $this->db->quote($save_cols[$col]));
            }
        }
        if (!empty($write_sql)) {
            $_query= "UPDATE " . $this->db_name;
            $_query.= " SET changed=" . $this->db->now() . ",";
            $_query.= " " . join(', ', $write_sql);
            $_query.= " WHERE contact_id=?";
            $_query.= " AND user_id=?";
            $_query.= " AND del<>1";
            $this->db->query($_query, $id, $this->user_id);

            $updated = $this->db->affected_rows();
        }
        return $updated;
    }


    /**
     * Mark one or more contact records as deleted
     *
     * @param array  Record identifiers
     */
    function delete($ids)
    {
        if (is_array($ids)) {
            $ids = join(',', $ids);
        }
        $_query =
        $this->db->query($_query, $this->user_id);

        return $this->db->affected_rows();
    }
}
?>