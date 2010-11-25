<?php

/*
 +-----------------------------------------------------------------------+
 | program/include/rcube_contacts.php                                    |
 |                                                                       |
 | This file is part of the Roundcube Webmail client                     |
 | Copyright (C) 2006-2010, Roundcube Dev. - Switzerland                 |
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
 * Model class for the local address book database
 *
 * @package Addressbook
 */
class rcube_contacts extends rcube_addressbook
{
    // protected for backward compat. with some plugins
    protected $db_name = 'contacts';
    protected $db_groups = 'contactgroups';
    protected $db_groupmembers = 'contactgroupmembers';

    /**
     * Store database connection.
     *
     * @var rcube_mdb2
     */
    private $db = null;
    private $user_id = 0;
    private $filter = null;
    private $result = null;
    private $search_fields;
    private $search_string;
    private $cache;
    private $table_cols = array('name', 'email', 'firstname', 'surname', 'vcard');

    // public properties
    public $primary_key = 'contact_id';
    public $readonly = false;
    public $groups = true;
    public $list_page = 1;
    public $page_size = 10;
    public $group_id = 0;
    public $ready = false;
    public $coltypes = array('name', 'firstname', 'surname', 'middlename', 'prefix', 'suffix', 'nickname',
      'jobtitle', 'organization', 'department', 'gender', 'maidenname', 'email', 'phone', 'address',
      'birthday', 'website', 'im', 'notes');


    /**
     * Object constructor
     *
     * @param object  Instance of the rcube_db class
     * @param integer User-ID
     */
    function __construct($dbconn, $user)
    {
        $this->db = $dbconn;
        $this->user_id = $user;
        $this->ready = $this->db && !$this->db->is_error();
    }


    /**
     * Save a search string for future listings
     *
     * @param  string SQL params to use in listing method
     */
    function set_search_set($filter)
    {
        $this->filter = $filter;
        $this->cache = null;
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
     * Setter for the current group
     * (empty, has to be re-implemented by extending class)
     */
    function set_group($gid)
    {
        $this->group_id = $gid;
        $this->cache = null;
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
        $this->cache = null;
    }


    /**
     * List all active contact groups of this source
     *
     * @param string  Search string to match group name
     * @return array  Indexed list of contact groups, each a hash array
     */
    function list_groups($search = null)
    {
        $results = array();

        if (!$this->groups)
            return $results;

        $sql_filter = $search ? " AND " . $this->db->ilike('name', '%'.$search.'%') : '';

        $sql_result = $this->db->query(
            "SELECT * FROM ".get_table_name($this->db_groups).
            " WHERE del<>1".
            " AND user_id=?".
            $sql_filter.
            " ORDER BY name",
            $this->user_id);

        while ($sql_result && ($sql_arr = $this->db->fetch_assoc($sql_result))) {
            $sql_arr['ID'] = $sql_arr['contactgroup_id'];
            $results[]     = $sql_arr;
        }

        return $results;
    }


    /**
     * List the current set of contact records
     *
     * @param  array   List of cols to show
     * @param  int     Only return this number of records, use negative values for tail
     * @param  boolean True to skip the count query (select only)
     * @return array  Indexed list of contact records, each a hash array
     */
    function list_records($cols=null, $subset=0, $nocount=false)
    {
        if ($nocount || $this->list_page <= 1) {
            // create dummy result, we don't need a count now
            $this->result = new rcube_result_set();
        } else {
            // count all records
            $this->result = $this->count();
        }

        $start_row = $subset < 0 ? $this->result->first + $this->page_size + $subset : $this->result->first;
        $length = $subset != 0 ? abs($subset) : $this->page_size;

        if ($this->group_id)
            $join = " LEFT JOIN ".get_table_name($this->db_groupmembers)." AS m".
                " ON (m.contact_id = c.".$this->primary_key.")";

        $sql_result = $this->db->limitquery(
            "SELECT * FROM ".get_table_name($this->db_name)." AS c" .
            $join .
            " WHERE c.del<>1" .
                " AND c.user_id=?" .
                ($this->group_id ? " AND m.contactgroup_id=?" : "").
                ($this->filter ? " AND (".$this->filter.")" : "") .
            " ORDER BY c.name",
            $start_row,
            $length,
            $this->user_id,
            $this->group_id);

        while ($sql_result && ($sql_arr = $this->db->fetch_assoc($sql_result))) {
            $sql_arr['ID'] = $sql_arr[$this->primary_key];
            $sql_arr['email'] = preg_split('/,\s*/', $sql_arr['email']);
            // make sure we have a name to display
            if (empty($sql_arr['name']))
                $sql_arr['name'] = $sql_arr['email'][0];
            $this->result->add($sql_arr);
        }

        $cnt = count($this->result->records);

        // update counter
        if ($nocount)
            $this->result->count = $cnt;
        else if ($this->list_page <= 1) {
            if ($cnt < $this->page_size && $subset == 0)
                $this->result->count = $cnt;
            else if (isset($this->cache['count']))
                $this->result->count = $this->cache['count'];
            else
                $this->result->count = $this->_count();
        }

        return $this->result;
    }


    /**
     * Search contacts
     *
     * @param array   List of fields to search in
     * @param string  Search value
     * @param boolean True for strict (=), False for partial (LIKE) matching
     * @param boolean True if results are requested, False if count only
     * @param boolean True to skip the count query (select only)
     * @param array   List of fields that cannot be empty
     * @return object rcube_result_set Contact records and 'count' value
     */
    function search($fields, $value, $strict=false, $select=true, $nocount=false, $required=array())
    {
        if (!is_array($fields))
            $fields = array($fields);
        if (!is_array($required) && !empty($required))
            $required = array($required);

        $where = $and_where = array();

        foreach ($fields as $col) {
            if ($col == 'ID' || $col == $this->primary_key) {
                $ids     = !is_array($value) ? explode(',', $value) : $value;
                $ids     = $this->db->array2list($ids, 'integer');
                $where[] = 'c.' . $this->primary_key.' IN ('.$ids.')';
            }
            else if ($strict)
                $where[] = $this->db->quoteIdentifier($col).' = '.$this->db->quote($value);
            else
                $where[] = $this->db->ilike($col, '%'.$value.'%');
        }

        foreach ($required as $col) {
            $and_where[] = $this->db->quoteIdentifier($col).' <> '.$this->db->quote('');
        }

        if (!empty($where))
            $where = join(' OR ', $where);

        if (!empty($and_where))
            $where = ($where ? "($where) AND " : '') . join(' AND ', $and_where);

        if (!empty($where)) {
            $this->set_search_set($where);
            if ($select)
                $this->list_records(null, 0, $nocount);
            else
                $this->result = $this->count();
        }

        return $this->result; 
    }


    /**
     * Count number of available contacts in database
     *
     * @return rcube_result_set Result object
     */
    function count()
    {
        $count = isset($this->cache['count']) ? $this->cache['count'] : $this->_count();

        return new rcube_result_set($count, ($this->list_page-1) * $this->page_size);
    }


    /**
     * Count number of available contacts in database
     *
     * @return int Contacts count
     */
    private function _count()
    {
        if ($this->group_id)
            $join = " LEFT JOIN ".get_table_name($this->db_groupmembers)." AS m".
                " ON (m.contact_id=c.".$this->primary_key.")";

        // count contacts for this user
        $sql_result = $this->db->query(
            "SELECT COUNT(c.contact_id) AS rows".
            " FROM ".get_table_name($this->db_name)." AS c".
                $join.
            " WHERE c.del<>1".
            " AND c.user_id=?".
            ($this->group_id ? " AND m.contactgroup_id=?" : "").
            ($this->filter ? " AND (".$this->filter.")" : ""),
            $this->user_id,
            $this->group_id
        );

        $sql_arr = $this->db->fetch_assoc($sql_result);

        $this->cache['count'] = (int) $sql_arr['rows'];

        return $this->cache['count'];
    }


    /**
     * Return the last result set
     *
     * @return mixed Result array or NULL if nothing selected yet
     */
    function get_result()
    {
        return $this->result;
    }


    /**
     * Get a specific contact record
     *
     * @param mixed record identifier(s)
     * @return mixed Result object with all record fields or False if not found
     */
    function get_record($id, $assoc=false)
    {
        // return cached result
        if ($this->result && ($first = $this->result->first()) && $first[$this->primary_key] == $id)
            return $assoc ? $first : $this->result;

        $this->db->query(
            "SELECT * FROM ".get_table_name($this->db_name).
            " WHERE contact_id=?".
                " AND user_id=?".
                " AND del<>1",
            $id,
            $this->user_id
        );

        if ($sql_arr = $this->db->fetch_assoc()) {
            $record = $this->convert_db_data($sql_arr);
            $this->result = new rcube_result_set(1);
            $this->result->add($record);
        }

        return $assoc && $record ? $record : $this->result;
    }


    /**
     * Get group assignments of a specific contact record
     *
     * @param mixed Record identifier
     * @return array List of assigned groups as ID=>Name pairs
     */
    function get_record_groups($id)
    {
      $results = array();

      if (!$this->groups)
          return $results;

      $sql_result = $this->db->query(
        "SELECT cgm.contactgroup_id, cg.name FROM " . get_table_name($this->db_groupmembers) . " AS cgm" .
        " LEFT JOIN " . get_table_name($this->db_groups) . " AS cg ON (cgm.contactgroup_id = cg.contactgroup_id AND cg.del<>1)" .
        " WHERE cgm.contact_id=?",
        $id
      );
      while ($sql_result && ($sql_arr = $this->db->fetch_assoc($sql_result))) {
        $results[$sql_arr['contactgroup_id']] = $sql_arr['name'];
      }

      return $results;
    }


    /**
     * Create a new contact record
     *
     * @param array Associative array with save data
     * @return integer|boolean The created record ID on success, False on error
     */
    function insert($save_data, $check=false)
    {
        if (!is_array($save_data))
            return false;

        $insert_id = $existing = false;

        if ($check) {
            foreach ($save_data as $col => $values) {
                if (strpos($col, 'email') === 0) {
                    foreach ((array)$values as $email) {
                        if ($existing = $this->search('email', $email, true, false))
                            break 2;
                    }
                }
            }
        }

        $save_data = $this->convert_save_data($save_data);
        $a_insert_cols = $a_insert_values = array();

        foreach ($save_data as $col => $value) {
            $a_insert_cols[]   = $this->db->quoteIdentifier($col);
            $a_insert_values[] = $this->db->quote($value);
        }

        if (!$existing->count && !empty($a_insert_cols)) {
            $this->db->query(
                "INSERT INTO ".get_table_name($this->db_name).
                " (user_id, changed, del, ".join(', ', $a_insert_cols).")".
                " VALUES (".intval($this->user_id).", ".$this->db->now().", 0, ".join(', ', $a_insert_values).")"
            );

            $insert_id = $this->db->insert_id($this->db_name);
        }

        // also add the newly created contact to the active group
        if ($insert_id && $this->group_id)
            $this->add_to_group($this->group_id, $insert_id);

        $this->cache = null;

        return $insert_id;
    }


    /**
     * Update a specific contact record
     *
     * @param mixed Record identifier
     * @param array Assoziative array with save data
     * @return boolean True on success, False on error
     */
    function update($id, $save_cols)
    {
        $updated = false;
        $write_sql = array();
        $record = $this->get_record($id, true);
        $save_cols = $this->convert_save_data($save_cols, $record);

        foreach ($save_cols as $col => $value) {
            $write_sql[] = sprintf("%s=%s", $this->db->quoteIdentifier($col), $this->db->quote($value));
        }

        if (!empty($write_sql)) {
            $this->db->query(
                "UPDATE ".get_table_name($this->db_name).
                " SET changed=".$this->db->now().", ".join(', ', $write_sql).
                " WHERE contact_id=?".
                    " AND user_id=?".
                    " AND del<>1",
                $id,
                $this->user_id
            );

            $updated = $this->db->affected_rows();
            $this->result = null;  // clear current result (from get_record())
        }

        return $updated;
    }
    
    
    private function convert_db_data($sql_arr)
    {
        $record = array();
        $record['ID'] = $sql_arr[$this->primary_key];
        
        if ($sql_arr['vcard']) {
          unset($save_arr['email']);
          $vcard = new rcube_vcard($sql_arr['vcard']);
          $record += $vcard->get_assoc() + $sql_arr;
        }
        else
          $record += $sql_arr;
        
        return $record;
    }


    private function convert_save_data($save_data, $record = array())
    {
        $out = array();

        // copy values into vcard object
        $vcard = new rcube_vcard($record['vcard']);
        $vcard->reset();
        foreach ($save_data as $key => $values) {
            list($field, $section) = explode(':', $key);
            foreach ((array)$values as $value) {
                if (is_array($value) || strlen($value))
                    $vcard->set($field, $value, $section);
            }
        }
        $out['vcard'] = $vcard->export();

        foreach ($this->table_cols as $col) {
            $key = $col;
            if (!isset($save_data[$key]))
                $key .= ':home';
            if (isset($save_data[$key]))
                $out[$col] = is_array($save_data[$key]) ? join(',', $save_data[$key]) : $save_data[$key];
        }

        // save all e-mails in database column
        $out['email'] = join(", ", $vcard->email);

        return $out;
    }


    /**
     * Mark one or more contact records as deleted
     *
     * @param array  Record identifiers
     */
    function delete($ids)
    {
        if (!is_array($ids))
            $ids = explode(',', $ids);

        $ids = $this->db->array2list($ids, 'integer');

        // flag record as deleted
        $this->db->query(
            "UPDATE ".get_table_name($this->db_name).
            " SET del=1, changed=".$this->db->now().
            " WHERE user_id=?".
                " AND contact_id IN ($ids)",
            $this->user_id
        );

        $this->cache = null;

        return $this->db->affected_rows();
    }


    /**
     * Remove all records from the database
     */
    function delete_all()
    {
        $this->db->query("DELETE FROM ".get_table_name($this->db_name)." WHERE user_id = ?", $this->user_id);
        $this->cache = null;
        return $this->db->affected_rows();
    }


    /**
     * Create a contact group with the given name
     *
     * @param string The group name
     * @return mixed False on error, array with record props in success
     */
    function create_group($name)
    {
        $result = false;

        // make sure we have a unique name
        $name = $this->unique_groupname($name);

        $this->db->query(
            "INSERT INTO ".get_table_name($this->db_groups).
            " (user_id, changed, name)".
            " VALUES (".intval($this->user_id).", ".$this->db->now().", ".$this->db->quote($name).")"
        );

        if ($insert_id = $this->db->insert_id($this->db_groups))
            $result = array('id' => $insert_id, 'name' => $name);

        return $result;
    }


    /**
     * Delete the given group (and all linked group members)
     *
     * @param string Group identifier
     * @return boolean True on success, false if no data was changed
     */
    function delete_group($gid)
    {
        // flag group record as deleted
        $sql_result = $this->db->query(
            "UPDATE ".get_table_name($this->db_groups).
            " SET del=1, changed=".$this->db->now().
            " WHERE contactgroup_id=?",
            $gid
        );

        $this->cache = null;

        return $this->db->affected_rows();
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
        // make sure we have a unique name
        $name = $this->unique_groupname($newname);

        $sql_result = $this->db->query(
            "UPDATE ".get_table_name($this->db_groups).
            " SET name=?, changed=".$this->db->now().
            " WHERE contactgroup_id=?",
            $name, $gid
        );

        return $this->db->affected_rows() ? $name : false;
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
        if (!is_array($ids))
            $ids = explode(',', $ids);

        $added = 0;
        $exists = array();

        // get existing assignments ...
        $sql_result = $this->db->query(
            "SELECT contact_id FROM ".get_table_name($this->db_groupmembers).
            " WHERE contactgroup_id=?".
                " AND contact_id IN (".$this->db->array2list($ids, 'integer').")",
            $group_id
        );
        while ($sql_result && ($sql_arr = $this->db->fetch_assoc($sql_result))) {
            $exists[] = $sql_arr['contact_id'];
        }
        // ... and remove them from the list
        $ids = array_diff($ids, $exists);

        foreach ($ids as $contact_id) {
            $this->db->query(
                "INSERT INTO ".get_table_name($this->db_groupmembers).
                " (contactgroup_id, contact_id, created)".
                " VALUES (?, ?, ".$this->db->now().")",
                $group_id,
                $contact_id
            );

            if (!$this->db->db_error)
                $added++;
        }

        return $added;
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
        if (!is_array($ids))
            $ids = explode(',', $ids);

        $ids = $this->db->array2list($ids, 'integer');

        $sql_result = $this->db->query(
            "DELETE FROM ".get_table_name($this->db_groupmembers).
            " WHERE contactgroup_id=?".
                " AND contact_id IN ($ids)",
            $group_id
        );

        return $this->db->affected_rows();
    }


    /**
     * Check for existing groups with the same name
     *
     * @param string Name to check
     * @return string A group name which is unique for the current use
     */
    private function unique_groupname($name)
    {
        $checkname = $name;
        $num = 2; $hit = false;

        do {
            $sql_result = $this->db->query(
                "SELECT 1 FROM ".get_table_name($this->db_groups).
                " WHERE del<>1".
                    " AND user_id=?".
                    " AND name=?",
                $this->user_id,
                $checkname);

            // append number to make name unique
            if ($hit = $this->db->num_rows($sql_result))
                $checkname = $name . ' ' . $num++;
        } while ($hit > 0);

        return $checkname;
    }

}
