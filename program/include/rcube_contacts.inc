<?php

/*
 +-----------------------------------------------------------------------+
 | program/include/rcube_contacts.inc                                    |
 |                                                                       |
 | This file is part of the RoundCube Webmail client                     |
 | Copyright (C) 2006, RoundCube Dev. - Switzerland                      |
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

class rcube_contacts
{
  var $db = null;
  var $db_name = '';
  var $user_id = 0;
  var $filter = '1';
  var $result = null;
  
  /** public properties */
  var $primary_key = 'contact_id';
  var $readonly = false;
  var $list_page = 1;
  var $page_size = 10;
  
  
  /**
   * Object constructor
   *
   * @param object  Instance of the rcube_db class
   * @param integer User-ID
   */
  function __construct($dbconn, $user)
  {
    $this->db = $dbconn;
    $this->db_name = get_table_name('contacts');
    $this->user_id = $user;
  }

  /**
   * PHP 4 object constructor
   *
   * @see  rcube_contacts::__construct
   */
  function rcube_contacts($dbconn, $user)
  {
    $this->__construct($dbconn, $user);
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
   * Reset all saved results and search parameters
   */
  function reset()
  {
    $this->result = NULL;
    $this->filter = '1';
  }
  
  
  /**
   * List the current set of contact records
   *
   * @param  array  List of cols to show
   * @return array  Indexed list of contact records, each a hash array
   */
  function list_records($cols=null, $subset=0)
  {
    // count contacts for this user
    $this->result = $this->count();
    $sql_result = NULL;

    // get contacts from DB
    if ($this->result['count'])
      {
      $start_row = $subset < 0 ? $this->result['first'] + $this->page_size + $subset : $this->result['first'];
      $length = $subset != 0 ? abs($subset) : $this->page_size;
      
      $sql_result = $this->db->limitquery(
        "SELECT * FROM ".$this->db_name."
         WHERE  del<>1
         AND    user_id=?
         AND    (".$this->filter.")
         ORDER BY name",
        $start_row,
        $length,
        $this->user_id);
      }

    while ($sql_result && ($sql_arr = $this->db->fetch_assoc($sql_result)))
    {
      $sql_arr['ID'] = $sql_arr[$this->primary_key];
      $this->result[] = $sql_arr;
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
  function search($fields, $value, $select=true)
  {
    if (!is_array($fields))
      $fields = array($fields);
    
    $add_where = array();
    foreach ($fields as $col)
      $add_where[] = $this->db->quoteIdentifier($col)."=".$this->db->quote($value);
    
    if (!empty($add_where))
      {
      $this->filter = join(' OR ', $add_where);
      if ($select)
        $this->list_records();
      else
        $this->result = $this->count();
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
    // count contacts for this user
    $sql_result = $this->db->query(
      "SELECT COUNT(contact_id) AS rows
       FROM ".$this->db_name."
       WHERE  del<>1
       AND    user_id=?
       AND    (".$this->filter.")",
      $this->user_id);

    $sql_arr = $this->db->fetch_assoc($sql_result);
    return array('count' => (int)$sql_arr['rows'], 'first' => ($this->list_page-1) * $this->page_size);
  }


  /**
   * Return the last result set
   *
   * @return Result array or NULL if nothing selected yet
   */
  function get_result()
  {
    return $this->result;
  }
  
  
  /**
   * Get a specific contact record
   *
   * @param mixed record identifier
   * @return Hash array with all record fields or False if not found
   */
  function get_record($id)
  {
    // return cached result
    if ($this->result && $this->result[$this->primary_key] == $id)
      return $this->result;
      
    $this->db->query(
      "SELECT * FROM ".$this->db_name."
       WHERE  contact_id=?
       AND    user_id=?
       AND    del<>1",
      $id,
      $this->user_id);

    if ($this->result = $this->db->fetch_assoc())
        $this->result['ID'] = $this->result[$this->primary_key];

    return $this->result;
  }
  
  
  /**
   * Create a new contact record
   *
   * @param array Assoziative array with save data
   * @return The create record ID on success, False on error
   */
  function insert($save_cols)
  {
    $insert_id = false;
    $a_insert_cols = $a_insert_values = array();
    foreach ($save_cols as $col => $value)
      {
      $a_insert_cols[] = $this->db->quoteIdentifier($col);
      $a_insert_values[] = $this->db->quote($value);
      }
    
    if (!empty($a_insert_cols))
      {
      $this->db->query(
        "INSERT INTO ".$this->db_name."
         (user_id, changed, del, ".join(', ', $a_insert_cols).")
         VALUES (?, ".$this->db->now().", 0, ".join(', ', $a_insert_values).")",
        $this->user_id);
      
      $insert_id = $this->db->insert_id(get_sequence_name('contacts'));
      }
    
    return $insert_id;
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
    foreach ($save_cols as $col => $value)
      $write_sql[] = sprintf("%s=%s", $this->db->quoteIdentifier($col), $this->db->quote($value));

    if (!empty($write_sql))
      {
      $this->db->query(
        "UPDATE ".$this->db_name."
         SET    changed=".$this->db->now().", ".join(', ', $write_sql)."
         WHERE  contact_id=?
         AND    user_id=?
         AND    del<>1",
        $id,
        $this->user_id);

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
    if (is_array($ids))
      $ids = join(',', $ids);

    $this->db->query(
      "UPDATE ".$this->db_name."
       SET    del=1
       WHERE  user_id=?
       AND    contact_id IN (".$ids.")",
      $this->user_id);

    return $this->db->affected_rows();
  }

}

?>