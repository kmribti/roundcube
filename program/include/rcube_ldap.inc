<?php

/*
 +-----------------------------------------------------------------------+
 | program/include/rcube_ldap.inc                                        |
 |                                                                       |
 | This file is part of the RoundCube Webmail client                     |
 | Copyright (C) 2006-2007, RoundCube Dev. - Switzerland                 |
 | Licensed under the GNU GPL                                            |
 |                                                                       |
 | PURPOSE:                                                              |
 |   Interface to an LDAP address directory                              |
 |                                                                       |
 +-----------------------------------------------------------------------+
 | Author: Thomas Bruederli <roundcube@gmail.com>                        |
 +-----------------------------------------------------------------------+

 $Id$

*/

class rcube_ldap
{
  var $conn;
  var $prop = array();
  
  var $filter = '1';
  var $result = null;
  var $search_fields;
  var $search_string;
  
  /** public properties */
  var $primary_key = 'contact_id';
  var $readonly = true;
  var $list_page = 1;
  var $page_size = 10;
  
  
  /**
   * Object constructor
   *
   * @param array LDAP connection properties
   * @param integer User-ID
   */
  function __construct($p)
  {
    $this->prop = $p;
    $this->connect();
  }

  /**
   * PHP 4 object constructor
   *
   * @see  rcube_ldap::__construct
   */
  function rcube_contacts($p)
  {
    $this->__construct($p);
  }
  

  /**
   * Establish a connection to the LDAP server
   */
  function connect()
  {
    if (!function_exists('ldap_connect'))
      raise_error(array('type' => "ldap",
                        'message' => "No ldap support in this installation of PHP"),
                  true);

    if (is_resource($this->conn))
      return true;
    
    if (!is_array($this->prop['hosts']))
      $this->prop['hosts'] = array($this->prop['hosts']);

    foreach ($this->prop['hosts'] as $host)
    {
      if ($lc = @ldap_connect($host, $this->prop['port']))
      {
        @ldap_set_option($lc, LDAP_OPT_PROTOCOL_VERSION, $this->prop['port']);
        $this->prop['host'] = $host;
        $this->conn = $lc;
        return true;
      }
    }
    
    if (!is_resource($this->conn))
      raise_error(array('type' => "ldap",
                        'message' => "Could not connect to any LDAP server, tried $host:{$this->prop[port]} last"),
                  true);
  }


  /**
   * Close connection to LDAP server
   */
  function close()
  {
    if ($this->conn)
      @ldap_unbind($this->conn);
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
   * @param string ??
   */
  function set_search_set($filter)
  {
    // TODO
  }
  
  
  /**
   * Getter for saved search properties
   *
   * @return mixed Search properties used by this class
   */
  function get_search_set()
  {
    // TODO
  }


  /**
   * Reset all saved results and search parameters
   */
  function reset()
  {
    // TODO
    $this->result = null;
    $this->filter = '1';
    $this->search_fields = null;
    $this->search_string = null;
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

    // TODO
    
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
    // TODO
    
    $this->set_search_set(join(' OR ', $add_where));
    if ($select)
      $this->list_records();
    else
      $this->result = $this->count();
   
    return $this->result; 
  }


  /**
   * Count number of available contacts in database
   *
   * @return Result array with values for 'count' and 'first'
   */
  function count()
  {
    // TODO
    $count = 0;
    return array('count' => $count, 'first' => ($this->list_page-1) * $this->page_size);
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
    // TODO

    //if ($this->result = $this->db->fetch_assoc())
    //    $this->result['ID'] = $this->result[$this->primary_key];

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
    // TODO
    return false;
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
    // TODO    
    return false;
  }
  
  
  /**
   * Mark one or more contact records as deleted
   *
   * @param array  Record identifiers
   */
  function delete($ids)
  {
    // TODO
    return false;
  }

}

?>