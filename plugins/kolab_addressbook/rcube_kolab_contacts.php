<?php

require_once(dirname(__FILE__) . '/lib/rcube_kolab.php');


/**
 * Backend class for a custom address book
 *
 * This part of the Roundcube+Kolab integration and connects the
 * rcube_addressbook interface with the rcube_kolab wrapper for Kolab_Storage
 *
 * @author Thomas Bruederli
 * @see rcube_addressbook
 */
class rcube_kolab_contacts extends rcube_addressbook
{
    public $primary_key = 'ID';
    public $readonly = true;
    public $groups = true;

    private $filter;
    private $result;


    public function __construct()
    {
        // setup Kolab backend
        rcube_kolab::setup();
        
        // $this->share = Kolab_Storage::getShare();
        
        $this->ready = true;
    }


    /**
     * Save a search string for future listings
     *
     * @param mixed Search params to use in listing method, obtained by get_search_set()
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
     * Reset saved results and search parameters
     */
    public function reset()
    {
        $this->result = null;
        $this->filter = null;
    }


    /**
     * List all active contact groups of this source
     *
     * @return array  Indexed list of contact groups, each a hash array
     */
    function list_groups($search = null)
    {
        return array(
          #array('ID' => 'testgroup1', 'name' => "Testgroup"),
          #array('ID' => 'testgroup2', 'name' => "Sample Group"),
        );
    }

    /**
     * List the current set of contact records
     *
     * @param  array  List of cols to show
     * @param  int    Only return this number of records, use negative values for tail
     * @return array  Indexed list of contact records, each a hash array
     */
    public function list_records($cols=null, $subset=0)
    {
        $this->result = $this->count();
        
        // Just return a sample contact record for now
        $this->result->add(array('ID' => '111', 'name' => "Kolab Contact", 'firstname' => "Kolab", 'surname' => "Contact", 'email' => "example@kolab.org"));

        return $this->result;
    }


    /**
     * Search records
     *
     * @param array   List of fields to search in
     * @param string  Search value
     * @param boolean True if results are requested, False if count only
     * @return Indexed list of contact records and 'count' value
     */
    public function search($fields, $value, $strict=false, $select=true)
    {
        // no search implemented, just list all records
        return $this->list_records();
    }


    /**
     * Count number of available contacts in database
     *
     * @return rcube_result_set Result set with values for 'count' and 'first'
     */
    public function count()
    {
        return new rcube_result_set(1, ($this->list_page-1) * $this->page_size);
    }


    /**
     * Return the last result set
     *
     * @return rcube_result_set Current result set or NULL if nothing selected yet
     */
    public function get_result()
    {
        return $this->result;
    }

    /**
     * Get a specific contact record
     *
     * @param mixed record identifier(s)
     * @param boolean True to return record as associative array, otherwise a result set is returned
     * @return mixed Result object with all record fields or False if not found
     */
    public function get_record($id, $assoc=false)
    {
        $this->list_records();
        $first = $this->result->first();
        $sql_arr = $first['ID'] == $id ? $first : null;
    
        return $assoc && $sql_arr ? $sql_arr : $this->result;
    }


    /**
     * Close connection to source
     * Called on script shutdown
     */
    function close()
    {
        
    }


    function create_group($name)
    {
        return false;
    }

    function delete_group($gid)
    {
        return false;
    }

    function rename_group($gid, $newname)
    {
      return $newname;
    }

    function add_to_group($group_id, $ids)
    {
        return false;
    }

    function remove_from_group($group_id, $ids)
    {
         return false;
    }
  
}
