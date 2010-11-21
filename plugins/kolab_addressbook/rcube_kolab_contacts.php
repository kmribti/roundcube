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
    public $coltypes = array(
      'name'         => array('limit' => 1),
      'firstname'    => array('limit' => 1),
      'surname'      => array('limit' => 1),
      'middlename'   => array('limit' => 1),
      'prefix'       => array('limit' => 1),
      'suffix'       => array('limit' => 1),
      'nickname'     => array('limit' => 1),
      'jobtitle'     => array('limit' => 1),
      'organization' => array('limit' => 1),
      'department'   => array('limit' => 1),
      'gender'       => array('limit' => 1),
      'birthday'     => array('limit' => 1),
      'email'        => array('subtypes' => null),
      'phone'        => array(),
      'im'           => array('limit' => 1),
      'website'      => array('limit' => 1, 'subtypes' => null),
      'address'      => array(),
      'notes'        => array(),
      // define additional coltypes
      'initials'     => array('type' => 'text', 'size' => 6, 'limit' => 1),
      'anniversary'  => array('type' => 'date', 'size' => 12, 'limit' => 1),
      // TODO: define more Kolab-specific fields such as: office-location, profession, manager-name, assistant, spouse-name, children, language, latitude, longitude, pgp-publickey, free-busy-url
    );

    private $gid;
    private $imap;
    private $kolab;
    private $folder;
    private $contactstorage;
    private $liststorage;
    private $contacts;
    private $distlists;
    private $id2uid;
    private $filter;
    private $result;
    private $imap_folder = 'INBOX/Contacts';
    private $gender_map = array(0 => 'male', 1 => 'female');


    public function __construct($imap_folder = null)
    {
        if ($imap_folder)
            $this->imap_folder = $imap_folder;
            
        // extend coltypes configuration 
        $format = rcube_kolab::get_format('contact');
        $this->coltypes['phone']['subtypes'] = $format->_phone_types;
        $this->coltypes['address']['subtypes'] = $format->_address_types;
        $this->coltypes['anniversary']['label'] = rcube_label('anniversary');
        
        // fetch objects from the given IMAP folder
        $this->contactstorage = rcube_kolab::get_storage($this->imap_folder);
        $this->liststorage = rcube_kolab::get_storage($this->imap_folder, 'distributionlist');

        $this->ready = !PEAR::isError($this->contactstorage) && !PEAR::isError($this->liststorage);
    }


    /**
     * Getter for the address book name to be displayed
     *
     * @return string Name of this address book
     */
    public function get_name()
    {
        return strtr(preg_replace('!^(INBOX|user)/!i', '', $this->imap_folder), '/', ':');
    }


    /**
     * Setter for the current group
     */
    public function set_group($gid)
    {
        $this->gid = $gid;
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
        $this->_fetch_groups();
        $groups = array();
        foreach ((array)$this->distlists as $group)
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
        $this->result = $this->count();
        
        // list member of the selected group
        if ($this->gid) {
            foreach ((array)$this->distlists[$this->gid]['member'] as $member) {
                $this->result->add($this->contacts[$member['ID']]);
            }
        }
        else {
            $i = $j = 0;
            foreach ($this->contacts as $id => $contact) {
                if ($i++ < $this->result->first)
                    continue;
                $this->result->add($contact);
                if (++$j == $this->page_size)
                    break;
            }
        }
        
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
        // TODO: currently not implemented, just list all records
        return $this->list_records();
    }


    /**
     * Count number of available contacts in database
     *
     * @return rcube_result_set Result set with values for 'count' and 'first'
     */
    public function count()
    {
        $this->_fetch_contacts();
        $count = $this->gid ? count($this->distlists[$this->gid]['member']) : count($this->contacts);
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
        $this->_fetch_contacts();
        if ($this->contacts[$id]) {
            $this->result = new rcube_result_set(1);
            $this->result->add($this->contacts[$id]);
            return $assoc ? $this->contacts[$id] : $this->result;
        }

        return false;
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
        $this->_fetch_groups();
        
        foreach ($this->distlists as $gid => $group) {
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
        rcube_kolab::shutdown();
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


    /**
     * Simply fetch all records and store them in private member vars
     */
    private function _fetch_contacts()
    {
        if (!isset($this->contacts)) {
            // read contacts
            $this->contacts = $this->id2uid = array();
            foreach ((array)$this->contactstorage->getObjects() as $record) {
                $contact = $this->_to_rcube_contact($record);
                $id = $contact['ID'];
                $this->contacts[$id] = $contact;
                $this->id2uid[$id] = $record['uid'];
            }

            // TODO: sort data arrays according to desired list sorting
        }
    }
    
    
    /**
     * Read distribution-lists AKA groups from server
     */
    private function _fetch_groups()
    {
        if (!isset($this->distlists)) {
            $this->distlists = array();
            foreach ((array)$this->liststorage->getObjects() as $record) {
                // FIXME: folders without any distribution-list objects return contacts instead ?!
                if ($record['__type'] != 'Group')
                    continue;
                $record['ID'] = md5($record['uid']);
                foreach ($record['member'] as $i => $member)
                    $record['member'][$i]['ID'] = md5($member['uid']);
                $this->distlists[$record['ID']] = $record;
            }
        }
    }
    
    
    /**
     * Map fields from internal Kolab_Format to Roundcube contact format
     */
    private function _to_rcube_contact($record)
    {
        $out = array(
          'ID' => md5($record['uid']),
          'name' => $record['full-name'],
          'firstname' => $record['given-name'],
          'middlename' => $record['middle-names'],
          'surname' => $record['last-name'],
          'prefix' => $record['prefix'],
          'suffix' => $record['suffix'],
          'nickname' => $record['nick-name'],
          'organization' => $record['organization'],
          'department' => $record['department'],
          'jobtitle' => $record['job-title'],
          'initials' => $record['initials'],
          'birthday' => $record['birthday'],
          'anniversary' => $record['anniversary'],
          'email' => array(),
          'phone' => array(),
          'notes' => $record['body'],
        );
        
        if (isset($record['gender']))
            $out['gender'] = $this->gender_map[$record['gender']];

        foreach ((array)$record['email'] as $i => $email)
            $out['email'][] = $email['smtp-address'];

        foreach ((array)$record['phone'] as $i => $phone)
            $out['phone:'.$phone['type']][] = $phone['number'];
            
        if ($record['im-address'])
            $out['im:aim'] = array($record['im-address']);
        if ($record['web-page'])
            $out['website'] = array($record['web-page']);

        if ($record['addr-home-type']) {
            $key = 'address:' . $record['addr-home-type'];
            $out[$key][] = array(
                'street' => $record['addr-home-street'],
                'locality' => $record['addr-home-locality'],
                'zipcode' => $record['addr-home-postal-code'],
                'region' => $record['addr-home-region'],
                'country' => $record['addr-home-country'],
            );
        }

        // remove empty fields
        return array_filter($out);
    }

    private function _from_rcube_contact($contact)
    {
        // TBD.
    }

}
