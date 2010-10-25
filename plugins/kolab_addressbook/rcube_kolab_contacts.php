<?php


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

    private static $instance;
    
    private $_gid;
    private $_imap;
    private $_kolab;
    private $_folder;
    private $_data;
    private $_groups;
    private $_uid2index;
    private $filter;
    private $result;
    private $imap_folder = 'INBOX/Contacts';
    
    
    /**
     * Singleton getter
     */
    public static function singleton()
    {
        if (!self::$instance)
            self::$instance = new rcube_kolab_contacts;
        return self::$instance;
    }


    public function __construct()
    {
        // setup Kolab backend
        rcube_kolab::setup();
        
        // fetch objects from Cotnacts folder
        $this->_kolab = Kolab_List::singleton();
        $this->_folder = $this->_kolab->getFolder($this->imap_folder);
        $this->_storage = $this->_folder->getData();
        $this->_objects = $this->_storage->getObjects();
        
        // dump objects to log/console
        console($this->_objects);

        // TEMPORARY SOLUTION: use Roundcube's IMAP connection to fetch data
        $rcmail = rcmail::get_instance();
        $rcmail->imap_connect();
        $this->_imap = $rcmail->imap;

        $folders = $this->_imap->list_unsubscribed();
        
        if (in_array($this->imap_folder, $folders)) {
          $this->_imap->set_pagesize(9999);
          $this->_imap->set_mailbox($this->imap_folder);
          $this->ready = true;
        }
    }


    /**
     * Setter for the current group
     */
    function set_group($gid)
    {
        $this->_gid = $gid;
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
        $this->_fetch_data();
        $groups = array();
        foreach ($this->_groups as $group)
            $groups[] = array('ID' => $group['ID'], 'name' => $group['last-name']);
        return $groups;
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
        if ($this->_gid) {
            $data = $this->_fetch_data();
            $this->result = $this->count();
            
            foreach ((array)$this->_groups[$this->_gid]['member'] as $member) {
                $this->result->add($data->records[$this->_uid2index[$member['uid']]]);
            }
        }
        else
            $this->result = $this->_fetch_data();
        
        return $this->result;
    }
    
    
    /**
     * Simply fetch all records and store them in a result_set object
     */
    private function _fetch_data()
    {
        if ($this->_data)
            return $this->_data;
        
        $this->_data = new rcube_result_set(0, ($this->list_page-1) * $this->page_size);
        $this->_groups = $this->_uid2index = array();

        $xml_contact = Horde_Kolab_Format_XML::factory('contact');
        $xml_list = Horde_Kolab_Format_XML::factory('distributionlist');
      
        $index = 0;
        $headers = $this->_imap->list_headers();
        foreach ($headers as $header) {
            if ($type = $header->others['x-kolab-type']) {
                if ($type == 'application/x-vnd.kolab.contact')
                    $loader = $xml_contact;
                else if ($type == 'application/x-vnd.kolab.distribution-list')
                    $loader = $xml_list;
                else
                    continue;

                $record = $loader->load($this->_imap->get_message_part($header->uid, '2'));
                if (PEAR::isError($record)) {
                    raise_error(array(
                      'code' => 600, 'type' => 'php',
                      'file' => __FILE__, 'line' => __LINE__,
                      'message' => "Failed to load XML data from IMAP record:" . $record->getMessage()),
                    true, false);
                }
                else if ($type == 'application/x-vnd.kolab.contact') {
                    $this->_data->add(array(
                      'ID' => md5($record['uid']),
                      'name' => $record['full-name'],
                      'firstname' => $record['given-name'],
                      'surname' => $record['last-name'],
                      'email' => $record['emails'],
                    ));
                    $this->_data->count++;
                    $this->_uid2index[$record['uid']] = $index++;
                }
                else if ($type == 'application/x-vnd.kolab.distribution-list') {
                    $record['ID'] = md5($record['uid']);
                    foreach ($record['member'] as $i => $member)
                        $record['member'][$i]['ID'] = md5($member['uid']);
                    $this->_groups[$record['ID']] = $record;
                }
            }
        }

        return $this->_data;
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
        $this->_fetch_data();
        $count = $this->_gid ? count($this->_groups[$this->_gid]['member']) : $this->_data->count;
        return new rcube_result_set($count, ($this->list_page-1) * $this->page_size);
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
        $list = $this->_fetch_data();
        $rec = $list->first();
        do {
            if ($rec['ID'] == $id)
                break;
        }
        while ($rec = $list->next());

        $this->result = new rcube_result_set(1);
        $this->result->add($rec);

        return $assoc ? $rec : $this->result;
    }


    /**
     * Get group assignments of a specific contact record
     *
     * @param mixed Record identifier
     * @return array List of assigned groups as ID=>Name pairs
     */
    function get_record_groups($id)
    {
        $out = array();
        
        foreach ($this->_groups as $gid => $group) {
            foreach ($group['member'] as $member) {
                if ($member['ID'] == $id)
                    $out[$gid] = $group['last-name'];
            }
        }
        
        return $out;
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
