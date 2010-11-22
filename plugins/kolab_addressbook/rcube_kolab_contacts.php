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
    public $readonly = false;
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
      'address'      => array('limit' => 2, 'subtypes' => array('home','work')),
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
    private $fieldmap = array(
      // kolab       => roundcube
      'full-name'    => 'name',
      'given-name'   => 'firstname',
      'middle-names' => 'middlename',
      'last-name'    => 'surname',
      'prefix'       => 'prefix',
      'suffix'       => 'suffix',
      'nick-name'    => 'nickname',
      'organization' => 'organization',
      'department'   => 'department',
      'job-title'    => 'jobtitle',
      'initials'     => 'initials',
      'birthday'     => 'birthday',
      'anniversary'  => 'anniversary',
      'im-address'   => 'im:aim',
      'web-page'     => 'website',
      'body'         => 'notes',
    );


    public function __construct($imap_folder = null)
    {
        if ($imap_folder)
            $this->imap_folder = $imap_folder;
            
        // extend coltypes configuration 
        $format = rcube_kolab::get_format('contact');
        $this->coltypes['phone']['subtypes'] = $format->_phone_types;
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
    public function get_record_groups($id)
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
     * Create a new contact record
     *
     * @param array Assoziative array with save data
     *  Keys:   Field name with optional section in the form FIELD:SECTION
     *  Values: Field value. Can be either a string or an array of strings for multiple values
     * @param boolean True to check for duplicates first
     * @return mixed The created record ID on success, False on error
     */
    public function insert($save_data, $check=false)
    {
        if (is_object($save_data) && is_a($save_data, rcube_result_set))
            return $this->insert_recset($save_data, $check);

        $insert_id = $existing = false;

        // check for existing records by e-mail comparison
        if ($check) {
            foreach ($this->_get_col_values('email', $save_data, true) as $email) {
                if ($existing = $this->search('email', $email, true, false))
                    break;
            }
        }
        
        $object = $this->_from_rcube_contact($save_data);
        var_dump($object);
        
        // TODO: how to create new Kolab objects?
        
        
        return $insert_id;
    }

    /**
     * Insert new contacts for each row in set
     *
     * @see rcube_kolab_contacts::insert()
     */
    private function insert_recset($result, $check=false)
    {
        $ids = array();
        while ($row = $result->next()) {
            if ($insert = $this->insert($row, $check))
                $ids[] = $insert;
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
    public function update($id, $save_data)
    {
        $updated = false;
        $this->_fetch_contacts();
        if ($this->contacts[$id] && ($uid = $this->id2uid[$id])) {
            $old = $this->contactstorage->getObject($uid);
            $object = array_merge($old, $this->_from_rcube_contact($save_data));
            $object['last-modification-date'] = time();

            $saved = $this->contactstorage->save($object, $uid);
            if (PEAR::isError($saved)) {
                raise_error(array(
                  'code' => 600, 'type' => 'php',
                  'file' => __FILE__, 'line' => __LINE__,
                  'message' => "Error saving contact object to Kolab server:" . $saved->getMessage()),
                true, false);
            }
            else {
                $this->contacts[$id] = $this->_to_rcube_contact($object);
                $updated = true;
            }
        }
        
        return $updated;
    }

    /**
     * Mark one or more contact records as deleted
     *
     * @param array  Record identifiers
     */
    public function delete($ids)
    {

    }

    /**
     * Remove all records from the database
     */
    public function delete_all()
    {
        /* empty for read-only address books */
    }

    
    /**
     * Close connection to source
     * Called on script shutdown
     */
    public function close()
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
          'email' => array(),
          'phone' => array(),
        );
        
        foreach ($this->fieldmap as $kolab => $rcube) {
          if (strlen($record[$kolab]))
            $out[$rcube] = $record[$kolab];
        }
        
        if (isset($record['gender']))
            $out['gender'] = $this->gender_map[$record['gender']];

        foreach ((array)$record['email'] as $i => $email)
            $out['email'][] = $email['smtp-address'];

        foreach ((array)$record['phone'] as $i => $phone)
            $out['phone:'.$phone['type']][] = $phone['number'];

        if (is_array($record['address'])) {
            foreach ($record['address'] as $i => $adr) {
                $key = 'address:' . $adr['type'];
                $out[$key][] = array(
                    'street' => $adr['street'],
                    'locality' => $adr['locality'],
                    'zipcode' => $adr['postal-code'],
                    'region' => $adr['region'],
                    'country' => $adr['country'],
                );
            }
        }

        // remove empty fields
        return array_filter($out);
    }

    private function _from_rcube_contact($contact)
    {
        $object = array();

        foreach (array_flip($this->fieldmap) as $rcube => $kolab) {
            if (isset($contact[$rcube]))
                $object[$kolab] = is_array($contact[$rcube]) ? $contact[$rcube][0] : $contact[$rcube];
        }

        // format dates
        if ($object['birthday'] && ($date = @strtotime($object['birthday'])))
            $object['birthday'] = date('Y-m-d', $date);
        if ($object['anniversary'] && ($date = @strtotime($object['anniversary'])))
            $object['anniversary'] = date('Y-m-d', $date);

        $gendermap = array_flip($this->gender_map);
        if (isset($contact['gender']))
            $object['gender'] = $gendermap[$contact['gender']];

        foreach (($emails = $this->_get_col_values('email', $contact, true)) as $email)
            $object['email'][] = array('smtp-address' => $email, 'display-name' => $object['full-name']);
        $object['emails'] = join(', ', $emails);

        foreach ($this->_get_col_values('phone', $contact) as $type => $values) {
            foreach ((array)$values as $phone)
                $object['phone'][] = array('number' => $phone, 'type' => $type);
        }

        foreach ($this->_get_col_values('address', $contact) as $type => $values) {
            foreach ((array)$values as $adr) {
                $object['address'][] = array(
                    'type' => $type,
                    'street' => $adr['street'],
                    'locality' => $adr['locality'],
                    'postal-code' => $adr['zipcode'],
                    'region' => $adr['region'],
                    'country' => $adr['country'],
                );
            }
        }

        return $object;
    }


    private function _get_col_values($col, $data, $flat = false)
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
