<?php

/*
 +-----------------------------------------------------------------------+
 | program/include/rcube_imap.php                                        |
 |                                                                       |
 | This file is part of the RoundCube Webmail client                     |
 | Copyright (C) 2005-2006, RoundCube Dev. - Switzerland                 |
 | Licensed under the GNU GPL                                            |
 |                                                                       |
 | PURPOSE:                                                              |
 |   IMAP wrapper that implements the Iloha IMAP Library (IIL)           |
 |   See http://ilohamail.org/ for details                               |
 |                                                                       |
 +-----------------------------------------------------------------------+
 | Author: Thomas Bruederli <roundcube@gmail.com>                        |
 +-----------------------------------------------------------------------+

 $Id: rcube_imap.inc 561 2007-05-17 15:18:12Z thomasb $

 */


/**
 * Obtain classes from the Iloha IMAP library
 */
require_once 'lib/imap.inc';
require_once 'lib/mime.inc';


/**
 * Interface class for accessing an IMAP server
 *
 * This is a wrapper that implements the Iloha IMAP Library (IIL)
 *
 * @package    Mail
 * @author     Thomas Bruederli <roundcube@gmail.com>
 * @version    1.40
 * @link       http://ilohamail.org
 */
class rcube_imap
{
    var $db;
    var $conn;
    var $root_ns = '';
    var $root_dir = '';
    var $mailbox = 'INBOX';
    var $list_page = 1;
    var $page_size = 10;
    var $sort_field = 'date';
    var $sort_order = 'DESC';
    var $delimiter = NULL;
    var $caching_enabled = FALSE;
    var $default_folders = array('INBOX');
    var $default_folders_lc = array('inbox');
    var $cache = array();
    var $cache_keys = array();
    var $cache_changes = array();
    var $uid_id_map = array();
    var $msg_headers = array();
    var $capabilities = array();
    var $skip_deleted = FALSE;
    var $search_set = NULL;
    var $search_subject = '';
    var $search_string = '';
    var $search_charset = '';
    var $debug_level = 1;
    var $error_code = 0;

    /**
     * Object constructor
     *
     * @param object DB Database connection
     */
    public function __construct($db_conn) {
        $this->db = $db_conn;
    }

    /**
     * Connect to an IMAP server
     *
     * @param  string   Host to connect
     * @param  string   Username for IMAP account
     * @param  string   Password for IMAP account
     * @param  number   Port to connect to
     * @param  boolean  Use SSL connection
     * @return boolean  TRUE on success, FALSE on failure
     */
    public function connect($host, $user, $pass, $port = 143, $use_ssl = false) {
        global $ICL_SSL, $ICL_PORT, $IMAP_USE_INTERNAL_DATE;

        // check for Open-SSL support in PHP build
        if ($use_ssl && in_array('openssl', get_loaded_extensions())) {
            $ICL_SSL = true;
        } elseif ($use_ssl) {
            rcube_error::raise(
            array(
                    'code' => 403,
                    'type' => 'imap',
                    'file' => __FILE__,
                    'message' => 'OpenSSL not available;'
                    ),
                    true,
                    false
                    );
                    $port = 143;
        }

        $ICL_PORT = $port;
        $IMAP_USE_INTERNAL_DATE = false;

        $this->conn = iil_Connect($host, $user, $pass, array('imap' => 'check'));
        $this->host = $host;
        $this->user = $user;
        $this->pass = $pass;
        $this->port = $port;
        $this->ssl = $use_ssl;

        // print trace mesages
        if ($this->conn && ($this->debug_level & 8)) {
            rcube::console($this->conn->message);
        } else if (!$this->conn && $GLOBALS['iil_error']) {
            // write error log
            $this->error_code = $GLOBALS['iil_errornum'];
            rcube_error::raise(array('code' => 403,
                       'type' => 'imap',
                       'message' => $GLOBALS['iil_error']), true, false);
        }

        // get server properties
        if ($this->conn === false) {
            return false;
        }
        $this->_parse_capability($this->conn->capability);

        if (empty($this->conn->delimiter) === false) {
            $this->delimiter = $this->conn->delimiter;
        }
        if (empty($this->conn->rootdir) === false) {
            $this->set_rootdir($this->conn->rootdir);
            $this->root_ns = ereg_replace('[\.\/]$', '', $this->conn->rootdir);
        }
        return true;
    }

    /**
     * Close IMAP connection
     * Usually done on script shutdown
     */
    public function close() {
        if ($this->conn) {
            iil_Close($this->conn);
        }
    }


    /**
     * Close IMAP connection and re-connect
     * This is used to avoid some strange socket errors when talking to Courier IMAP
     */
    public function reconnect() {
        $this->close();
        $this->connect($this->host, $this->user, $this->pass, $this->port, $this->ssl);
    }

    /**
     * Set a root folder for the IMAP connection.
     *
     * Only folders within this root folder will be displayed
     * and all folder paths will be translated using this folder name
     *
     * @param  string   Root folder
     * @return void
     */
    public function set_rootdir($root) {
        if (ereg('[\.\/]$', $root)) {
            // (substr($root, -1, 1)==='/')
            $root = substr($root, 0, -1);
        }
        $this->root_dir = $root;

        if (empty($this->delimiter)) {
            $this->get_hierarchy_delimiter();
        }
    }

    /**
     * This list of folders will be listed above all other folders
     *
     * @param  array  Indexed list of folder names
     */
    public function set_default_mailboxes($arr) {
        if (is_array($arr)) {
            $this->default_folders = $arr;
            $this->default_folders_lc = array();

            // add inbox if not included
            if (!in_array_nocase('INBOX', $this->default_folders)) {
                array_unshift($this->default_folders, 'INBOX');
            }
            // create a second list with lower cased names
            foreach ($this->default_folders as $mbox) {
                $this->default_folders_lc[] = strtolower($mbox);
            }
        }
    }


    /**
     * Set internal mailbox reference.
     *
     * All operations will be perfomed on this mailbox/folder
     *
     * @param  string  Mailbox/Folder name
     */
    public function set_mailbox($new_mbox) {
        $mailbox = $this->_mod_mailbox($new_mbox);

        if ($this->mailbox == $mailbox) {
            return;
        }
        $this->mailbox = $mailbox;

        // clear messagecount cache for this mailbox
        $this->_clear_messagecount($mailbox);
    }


    /**
     * Set internal list page
     *
     * @param  number  Page number to list
     */
    public function set_page($page) {
        $this->list_page = (int)$page;
    }

    /**
     * Set internal page size
     *
     * @param  number  Number of messages to display on one page
     */
    public function set_pagesize($size) {
        $this->page_size = (int)$size;
    }


    /**
     * Save a set of message ids for future message listing methods
     *
     * @param  array  List of IMAP fields to search in
     * @param  string Search string
     * @param  array  List of message ids or NULL if empty
     */
    private function set_search_set($subject, $str=null, $msgs=null, $charset=null) {
        if (is_array($subject) && $str == null && $msgs == null) {
            list($subject, $str, $msgs, $charset) = $subject;
        }
        if ($msgs != null && !is_array($msgs)) {
            $msgs = split(',', $msgs);
        }
        $this->search_subject = $subject;
        $this->search_string = $str;
        $this->search_set = (array)$msgs;
        $this->search_charset = $charset;
    }

    /**
     * Return the saved search set as hash array
     *
     * @return array Search set
     */
    public function get_search_set() {
        return array($this->search_subject, $this->search_string, $this->search_set, $this->search_charset);
    }

    /**
     * Returns the currently used mailbox name
     *
     * @return  string Name of the mailbox/folder
     */
    public function get_mailbox_name() {
        return $this->conn ? $this->_mod_mailbox($this->mailbox, 'out') : '';
    }

    /**
     * Returns the IMAP server's capability
     *
     * @param   string  Capability name
     * @return  mixed   Capability value or TRUE if supported, FALSE if not
     */
    public function get_capability($cap) {
        return $this->capabilities[strtoupper($cap)];
    }

    /**
     * Returns the delimiter that is used by the IMAP server for folder separation
     *
     * @return  string  Delimiter string
     */
    public function get_hierarchy_delimiter() {
        if ($this->conn && empty($this->delimiter)) {
            $this->delimiter = iil_C_GetHierarchyDelimiter($this->conn);
        }
        if (empty($this->delimiter)) {
            $this->delimiter = '/';
        }
        return $this->delimiter;
    }

    /**
     * Public method for mailbox listing.
     *
     * Converts mailbox name with root dir first
     *
     * @param   string  Optional root folder
     * @param   string  Optional filter for mailbox listing
     * @return  array   List of mailboxes/folders
     */
    public function list_mailboxes($root = '', $filter = '*') {
        $a_out = array();
        $a_mboxes = $this->_list_mailboxes($root, $filter);

        foreach ($a_mboxes as $mbox_row) {
            $name = $this->_mod_mailbox($mbox_row, 'out');
            if (empty($name) === false) {
                $a_out[] = $name;
            } else {
                //rcube::tfk_debug('Filtered out: ' . $mbox_row);
            }
        }

        // INBOX should always be available
        if (!in_array_nocase('INBOX', $a_out)) {
            array_unshift($a_out, 'INBOX');
        }

        // sort mailboxes
        $a_out = $this->_sort_mailbox_list($a_out);

        return $a_out;
    }


    /**
     * Private method for mailbox listing
     *
     * @return  array   List of mailboxes/folders
     * @see     rcube_imap::list_mailboxes()
     */
    private function _list_mailboxes($root = '', $filter = '*') {
        $registry = rcube_registry::get_instance();
        $CONFIG   = $registry->get_all('config');

        $a_defaults = $a_out = array();

        // get cached folder list
        $a_mboxes = $this->get_cache('mailboxes');
        if (is_array($a_mboxes) === true) {
            return $a_mboxes;
        }
        if (isset($CONFIG['display_all_mailboxes']) === false || $CONFIG['display_all_mailboxes'] == false) {
            // retrieve list of folders from IMAP server
            $a_folders = iil_C_ListSubscribed($this->conn, $this->_mod_mailbox($root), $filter);
            if (is_array($a_folders) === false || !sizeof($a_folders)) {
                $a_folders = array();
            }
        } else {
            $a_folders = $this->list_unsubscribed($root);
        }
        // write mailboxlist to cache
        $this->update_cache('mailboxes', $a_folders);

        return $a_folders;
    }

    /**
     * Get message count for a specific mailbox
     *
     * @param   string   Mailbox/folder name
     * @param   string   Mode for count [ALL|UNSEEN|RECENT]
     * @param   boolean  Force reading from server and update cache
     * @return  int   Number of messages
     */
    public function messagecount($mbox_name = '', $mode = 'ALL', $force = false) {
        $mailbox = $mbox_name ? $this->_mod_mailbox($mbox_name) : $this->mailbox;
        return $this->_messagecount($mailbox, $mode, $force);
    }

    /**
     * Private method for getting nr of messages
     *
     * @access  private
     * @see     rcube_imap::messagecount()
     */
    private function _messagecount($mailbox = '', $mode = 'ALL', $force = false) {
        $a_mailbox_cache = false;
        $mode = strtoupper($mode);

        if (empty($mailbox)) {
            $mailbox = $this->mailbox;
        }
        // count search set
        if ($this->search_string && $mailbox == $this->mailbox && $mode == 'ALL') {
            return count((array)$this->search_set);
        }
        $a_mailbox_cache = $this->get_cache('messagecount');

        // return cached value
        if (!$force && is_array($a_mailbox_cache[$mailbox]) && isset($a_mailbox_cache[$mailbox][$mode])) {
            return $a_mailbox_cache[$mailbox][$mode];
        }
        // RECENT count is fetched abit different
        if ($mode == 'RECENT') {
            $count = iil_C_CheckForRecent($this->conn, $mailbox);
        } else if ($this->skip_deleted) {
            // use SEARCH for message counting
            $search_str = 'ALL UNDELETED';

            // get message count and store in cache
            if ($mode == 'UNSEEN') {
                $search_str .= ' UNSEEN';
            }
            // get message count using SEARCH
            // not very performant but more precise (using UNDELETED)
            $count = 0;
            $index = $this->_search_index($mailbox, $search_str);
            if (is_array($index) === true) {
                $str = implode(',', $index);
                if (!empty($str)) {
                    $count = count($index);
                }
            }
        } else {
            if ($mode == 'UNSEEN') {
                $count = iil_C_CountUnseen($this->conn, $mailbox);
            }
            else {
                $count = iil_C_CountMessages($this->conn, $mailbox);
            }
        }

        if (!is_array($a_mailbox_cache[$mailbox])) {
            $a_mailbox_cache[$mailbox] = array();
        }
        $a_mailbox_cache[$mailbox][$mode] = (int)$count;

        // write back to cache
        $this->update_cache('messagecount', $a_mailbox_cache);

        return (int)$count;
    }

    /**
     * Public method for listing headers
     * convert mailbox name with root dir first
     *
     * @param   string   Mailbox/folder name
     * @param   int      Current page to list
     * @param   string   Header field to sort by
     * @param   string   Sort order [ASC|DESC]
     * @return  array    Indexed array with message header objects
     */
    public function list_headers($mbox_name = '', $page = null, $sort_field = null, $sort_order = null) {
        $mailbox = $mbox_name ? $this->_mod_mailbox($mbox_name) : $this->mailbox;
        return $this->_list_headers($mailbox, $page, $sort_field, $sort_order);
    }


    /**
     * Private method for listing message headers
     * @see     rcube_imap::list_headers()
     */
    private function _list_headers($mailbox = '', $page = null, $sort_field = null, $sort_order = null, $recursive = false) {
        if (!strlen($mailbox)) {
            return array();
        }
        // use saved message set
        if ($this->search_string && $mailbox == $this->mailbox) {
            return $this->_list_header_set($mailbox, $this->search_set, $page, $sort_field, $sort_order);
        }

        $this->_set_sort_order($sort_field, $sort_order);

        $max = $this->_messagecount($mailbox);
        $start_msg = ($this->list_page-1) * $this->page_size;

        list($begin, $end) = $this->_get_message_range($max, $page);

        // mailbox is empty
        if ($begin >= $end) {
            return array();
        }

        $headers_sorted = FALSE;
        $cache_key = $mailbox.'.msg';
        $cache_status = $this->check_cache_status($mailbox, $cache_key);

        // cache is OK, we can get all messages from local cache
        if ($cache_status > 0) {
            $a_msg_headers = $this->get_message_cache($cache_key, $start_msg, $start_msg+$this->page_size, $this->sort_field, $this->sort_order);
            $headers_sorted = TRUE;
        } elseif ($this->caching_enabled && $cache_status==-1 && !$recursive) {
            // cache is dirty, sync it
            $this->sync_header_index($mailbox);
            return $this->_list_headers($mailbox, $page, $this->sort_field, $this->sort_order, true);
        } else {
            // retrieve headers from IMAP
            if ($this->get_capability('sort') && ($msg_index = iil_C_Sort($this->conn, $mailbox, $this->sort_field, $this->skip_deleted ? 'UNDELETED' : ''))) {
                $msgs = $msg_index[$begin];
                for ($i=$begin+1; $i < $end; $i++) {
                    $msgs = $msgs.','.$msg_index[$i];
                }
            } else {
                $msgs = sprintf("%d:%d", $begin+1, $end);

                $i = 0;
                for ($msg_seqnum = $begin; $msg_seqnum <= $end; $msg_seqnum++) {
                    $msg_index[$i++] = $msg_seqnum;
                }
            }

            // use this class for message sorting
            $sorter = new rcube_header_sorter();
            $sorter->set_sequence_numbers($msg_index);

            // fetch reuested headers from server
            $a_msg_headers = array();
            $deleted_count = $this->_fetch_headers($mailbox, $msgs, $a_msg_headers, $cache_key);

            // delete cached messages with a higher index than $max+1
            // Changed $max to $max+1 to fix this bug : #1484295
            $this->clear_message_cache($cache_key, $max + 1);

            // kick child process to sync cache
            // ...
        }


        // return empty array if no messages found
        if (!is_array($a_msg_headers) || empty($a_msg_headers)) {
            return array();
        }

        // if not already sorted
        if (!$headers_sorted) {
            $sorter->sort_headers($a_msg_headers);

            if ($this->sort_order == 'DESC') {
                $a_msg_headers = array_reverse($a_msg_headers);
            }
        }
        return array_values($a_msg_headers);
    }

    /**
     * Public method for listing a specific set of headers
     * convert mailbox name with root dir first
     *
     * @param   string   Mailbox/folder name
     * @param   array    List of message ids to list
     * @param   int      Current page to list
     * @param   string   Header field to sort by
     * @param   string   Sort order [ASC|DESC]
     * @return  array    Indexed array with message header objects
     * @access  public
     */
    public function list_header_set($mbox_name = '', $msgs, $page = null, $sort_field = null, $sort_order = null) {
        $mailbox = $mbox_name ? $this->_mod_mailbox($mbox_name) : $this->mailbox;
        return $this->_list_header_set($mailbox, $msgs, $page, $sort_field, $sort_order);
    }

    /**
     * Private method for listing a set of message headers
     *
     * @see     rcube_imap::list_header_set()
     */
    private function _list_header_set($mailbox, $msgs, $page = null, $sort_field = null, $sort_order = null) {
        // also accept a comma-separated list of message ids
        if (is_string($msgs)) {
            $msgs = split(',', $msgs);
        }
        if (!strlen($mailbox) || empty($msgs)) {
            return array();
        }

        $this->_set_sort_order($sort_field, $sort_order);

        $max = count($msgs);
        $start_msg = ($this->list_page-1) * $this->page_size;

        // fetch reuested headers from server
        $a_msg_headers = array();
        $this->_fetch_headers($mailbox, implode(',', $msgs), $a_msg_headers, null);

        // return empty array if no messages found
        if (!is_array($a_msg_headers) || empty($a_msg_headers)) {
            return array();
        }

        // if not already sorted
        $a_msg_headers = iil_SortHeaders($a_msg_headers, $this->sort_field, $this->sort_order);

        // only return the requested part of the set
        return array_slice(array_values($a_msg_headers), $start_msg, min($max-$start_msg, $this->page_size));
    }

    /**
     * Helper function to get first and last index of the requested set
     *
     * @param  int     message count
     * @param  mixed   page number to show, or string 'all'
     * @return array   array with two values: first index, last index
     */
    private function _get_message_range($max, $page) {
        $start_msg = ($this->list_page-1) * $this->page_size;

        if ($page == 'all') {
            $begin = 0;
            $end   = $max;
        } elseif ($this->sort_order == 'DESC') {
            $begin = $max - $this->page_size - $start_msg;
            $end   = $max - $start_msg;
        } else {
            $begin = $start_msg;
            $end   = $start_msg + $this->page_size;
        }

        if ($begin < 0) {
            $begin = 0;
        }
        if ($end < 0) {
            $end = $max;
        }
        if ($end > $max) {
            $end = $max;
        }
        return array($begin, $end);
    }

    /**
     * Fetches message headers
     * Used for loop
     *
     * @param  string  Mailbox name
     * @param  string  Message index to fetch
     * @param  array   Reference to message headers array
     * @param  array   Array with cache index
     * @return int     Number of deleted messages
     */
    private function _fetch_headers($mailbox, $msgs, &$a_msg_headers, $cache_key) {
        // cache is incomplete
        $cache_index = $this->get_message_cache_index($cache_key);

        // fetch reuested headers from server
        $a_header_index = iil_C_FetchHeaders($this->conn, $mailbox, $msgs);
        $deleted_count = 0;

        if (!empty($a_header_index)) {
            foreach ($a_header_index as $i => $headers) {
                if ($headers->deleted && $this->skip_deleted) {
                    // delete from cache
                    if ($cache_index[$headers->id] && $cache_index[$headers->id] == $headers->uid) {
                        $this->remove_message_cache($cache_key, $headers->id);
                    }
                    $deleted_count++;
                    continue;
                }

                // add message to cache
                if ($this->caching_enabled && $cache_index[$headers->id] != $headers->uid) {
                    $this->add_message_cache($cache_key, $headers->id, $headers);
                }
                $a_msg_headers[$headers->uid] = $headers;
            }
        }
        return $deleted_count;
    }


    /**
     * Return sorted array of message UIDs
     *
     * @param string Mailbox to get index from
     * @param string Sort column
     * @param string Sort order [ASC, DESC]
     * @return array Indexed array with message ids
     */
    public function message_index($mbox_name = '', $sort_field = null, $sort_order = null) {
        $this->_set_sort_order($sort_field, $sort_order);

        $mailbox = $mbox_name ? $this->_mod_mailbox($mbox_name) : $this->mailbox;
        $key = $mbox.':'.$this->sort_field.':'.$this->sort_order.'.msgi';

        // have stored it in RAM
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        // check local cache
        $cache_key = $mailbox.'.msg';
        $cache_status = $this->check_cache_status($mailbox, $cache_key);

        // cache is OK
        if ($cache_status > 0) {
            $a_index = $this->get_message_cache_index($cache_key, true, $this->sort_field, $this->sort_order);
            return array_values($a_index);
        }

        // fetch complete message index
        $msg_count = $this->_messagecount($mailbox);
        if ($this->get_capability('sort') && ($a_index = iil_C_Sort($this->conn, $mailbox, $this->sort_field, '', true))) {
            if ($this->sort_order == 'DESC') {
                $a_index = array_reverse($a_index);
            }
            $this->cache[$key] = $a_index;
        } else {
            $a_index = iil_C_FetchHeaderIndex($this->conn, $mailbox, '1:'.$msg_count, $this->sort_field);
            $a_uids = iil_C_FetchUIDs($this->conn, $mailbox);

            if ($this->sort_order == 'ASC') {
                asort($a_index);
            } else if ($this->sort_order == 'DESC') {
                arsort($a_index);
            }

            $i = 0;
            $this->cache[$key] = array();
            foreach ($a_index as $index => $value) {
                $this->cache[$key][$i++] = $a_uids[$index];
            }
        }
        return $this->cache[$key];
    }

    private function sync_header_index($mailbox) {
        $cache_key = $mailbox.'.msg';
        $cache_index = $this->get_message_cache_index($cache_key);
        $msg_count = $this->_messagecount($mailbox);

        // fetch complete message index
        $a_message_index = iil_C_FetchHeaderIndex($this->conn, $mailbox, '1:'.$msg_count, 'UID');

        foreach ($a_message_index as $id => $uid) {
            // message in cache at correct position
            if ($cache_index[$id] == $uid) {
                unset($cache_index[$id]);
                continue;
            }

            // message in cache but in wrong position
            if (in_array((string)$uid, $cache_index, true)) {
                unset($cache_index[$id]);
            }

            // other message at this position
            if (isset($cache_index[$id])) {
                $this->remove_message_cache($cache_key, $id);
                unset($cache_index[$id]);
            }

            // fetch complete headers and add to cache
            $headers = iil_C_FetchHeader($this->conn, $mailbox, $id);
            $this->add_message_cache($cache_key, $headers->id, $headers);
        }

        // those ids that are still in cache_index have been deleted
        if (empty($cache_index) === false) {
            foreach ($cache_index as $id => $uid) {
                $this->remove_message_cache($cache_key, $id);
            }
        }
    }

    /**
     * Invoke search request to IMAP server
     *
     * @param  string  mailbox name to search in
     * @param  string  search criteria (ALL, TO, FROM, SUBJECT, etc)
     * @param  string  search string
     * @return array   search results as list of message ids
     */
    public function search($mbox_name = '', $criteria = 'ALL', $str = null, $charset = null) {
        $mailbox = $mbox_name ? $this->_mod_mailbox($mbox_name) : $this->mailbox;

        // have an array of criterias => execute multiple searches
        if (is_array($criteria) && $str) {
            $results = array();
            foreach ($criteria as $crit) {
                if ($search_result = $this->search($mbox_name, $crit, $str, $charset)) {
                    $results = array_merge($results, $search_result);
                }
            }
            $results = array_unique($results);
            $this->set_search_set($criteria, $str, $results, $charset);
            return $results;
        }
        if ($str && $criteria) {
            $search = (!empty($charset) ? "CHARSET $charset " : '');
            $search.= sprintf("%s {%d}\r\n%s", $criteria, strlen($str), $str);
            $results = $this->_search_index($mailbox, $search);

            // try search with ISO charset (should be supported by server)
            if (empty($results) && !empty($charset) && $charset != 'ISO-8859-1') {
                $results = $this->search($mbox_name, $criteria, rcube::charset_convert($str, $charset, 'ISO-8859-1'), 'ISO-8859-1');
            }
            $this->set_search_set($criteria, $str, $results, $charset);
            return $results;
        }
        return $this->_search_index($mailbox, $criteria);
    }

    /**
     * Private search method
     *
     * @return array search results as list of message ids
     * @see rcube_imap::search()
     */
    private function _search_index($mailbox, $criteria = 'ALL') {
        $a_messages = iil_C_Search($this->conn, $mailbox, $criteria);
        // clean message list (there might be some empty entries)
        if (is_array($a_messages) === false) {
            return $a_messages;
        }
        foreach ($a_messages as $i => $val) {
            if (empty($val)) {
                unset($a_messages[$i]);
            }
        }
        return $a_messages;
    }

    /**
     * Refresh saved search set
     *
     * @return array Current search set
     */
    public function refresh_search() {
        if (!empty($this->search_subject) && !empty($this->search_string)) {
            $this->search_set = $this->search('', $this->search_subject, $this->search_string, $this->search_charset);
        }
        return $this->get_search_set();
    }

    /**
     * Check if the given message ID is part of the current search set
     *
     * @param int message id
     * @return boolean True on match or if no search request is stored
     */
    public function in_searchset($msgid = null) {
        if (!empty($this->search_string)) {
            return in_array($msgid, (array)$this->search_set, true);
        } else {
            return true;
        }
    }

    /**
     * Return message headers object of a specific message
     *
     * @param int     Message ID
     * @param string  Mailbox to read from
     * @param boolean True if $id is the message UID
     * @return object Message headers representation
     * @deprecated
     */
    //TODO used in steps/mail
    public function get_headers($id, $mbox_name = null, $is_uid = true) {
        $mailbox = $mbox_name ? $this->_mod_mailbox($mbox_name) : $this->mailbox;
        $uid = $is_uid ? $id : $this->_id2uid($id);

        // get cached headers
        if ($uid && ($headers = &$this->get_cached_message($mailbox.'.msg', $uid))) {
            return $headers;
        }
        $headers = iil_C_FetchHeader($this->conn, $mailbox, $id, $is_uid);
        //tfk_debug(var_export($headers, true));
        // write headers cache
        if ($headers) {
            if ($headers->uid && $headers->id) {
                $this->uid_id_map[$mailbox][$headers->uid] = $headers->id;
            }
            $this->add_message_cache($mailbox.'.msg', $headers->id, $headers);
        }
        //tfk_debug(var_export($headers));
        return $headers;
    }

    /**
     * Fetch body structure from the IMAP server and build
     * an object structure similar to the one generated by PEAR::Mail_mimeDecode
     *
     * @param Int Message UID to fetch
     * @return object stdClass Message part tree or False on failure
     */
    public function get_structure($uid) {
        $cache_key = $this->mailbox.'.msg';
        $headers = $this->get_cached_message($cache_key, $uid, true);

        // return cached message structure
        if (is_object($headers) && is_object($headers->structure)) {
            return $headers->structure;
        }
        // resolve message sequence number
        if (!($msg_id = $this->_uid2id($uid))) {
            return false;
        }
        $structure_str = iil_C_FetchStructureString($this->conn, $this->mailbox, $msg_id);
        $structure     = iml_GetRawStructureArray($structure_str);
        $struct        = false;

        // parse structure and add headers
        if (empty($structure) === true) {
            return $struct;
        }
        $this->_msg_id = $msg_id;
        $headers = $this->get_headers($msg_id);

        $struct = $this->_structure_part($structure);
        //$struct->headers = get_object_vars($headers);
        $struct->headers = $headers;
        // don't trust given content-type
        if (empty($struct->parts) && !empty($struct->headers->ctype)) {
            $struct->mime_id = '1';
            $struct->mimetype = strtolower($struct->headers->ctype);
            list($struct->ctype_primary, $struct->ctype_secondary) = explode('/', $struct->mimetype);
        }

        // write structure to cache
        if ($this->caching_enabled) {
            $this->add_message_cache($cache_key, $msg_id, $headers, $struct);
        }
        return $struct;
    }

    /**
     * Build message part object
     */
    private function &_structure_part($part, $count = 0, $parent = '') {
        $struct = new rcube_message_part;
        $struct->mime_id = empty($parent) ? (string)$count : "$parent.$count";

        // multipart
        if (is_array($part[0])) {
            $struct->ctype_primary = 'multipart';

            // find first non-array entry
            for ($i=1; count($part); $i++) {
                if (!is_array($part[$i])) {
                    $struct->ctype_secondary = strtolower($part[$i]);
                    break;
                }
            }
            $struct->mimetype = 'multipart/'.$struct->ctype_secondary;

            $struct->parts = array();
            for ($i=0, $count=0; $i<count($part); $i++) {
                if (is_array($part[$i]) && count($part[$i]) > 5) {
                    $struct->parts[] = $this->_structure_part($part[$i], ++$count, $struct->mime_id);
                }
            }
            return $struct;
        }


        // regular part
        $struct->ctype_primary = strtolower($part[0]);
        $struct->ctype_secondary = strtolower($part[1]);
        $struct->mimetype = $struct->ctype_primary.'/'.$struct->ctype_secondary;

        // read content type parameters
        if (is_array($part[2])) {
            $struct->ctype_parameters = array();
            for ($i=0, $size = count($part[2]); $i < $size; $i+=2) {
                $struct->ctype_parameters[strtolower($part[2][$i])] = $part[2][$i+1];
            }
            if (isset($struct->ctype_parameters['charset'])) {
                $struct->charset = $struct->ctype_parameters['charset'];
            }
        }

        // read content encoding
        if (!empty($part[5]) && $part[5] != 'NIL') {
            $struct->encoding = strtolower($part[5]);
            $struct->headers['content-transfer-encoding'] = $struct->encoding;
        }

        // get part size
        if (!empty($part[6]) && $part[6] != 'NIL') {
            $struct->size = intval($part[6]);
        }
        // read part disposition
        $di = count($part) - 2;
        if ((is_array($part[$di]) && count($part[$di]) == 2 && is_array($part[$di][1])) ||
        (is_array($part[--$di]) && count($part[$di]) == 2)
        ) {
            $struct->disposition = strtolower($part[$di][0]);

            if (is_array($part[$di][1])) {
                for ($n=0, $size = count($part[$di][1]); $n < $size; $n+=2) {
                    $struct->d_parameters[strtolower($part[$di][1][$n])] = $part[$di][1][$n+1];
                }
            }
        }

        // get child parts
        if (is_array($part[8]) && $di != 8) {
            $struct->parts = array();
            for ($i=0, $count=0, $size = count($part[8]); $i < $size; $i++) {
                if (is_array($part[8][$i]) && count($part[8][$i]) > 5) {
                    $struct->parts[] = $this->_structure_part($part[8][$i], ++$count, $struct->mime_id);
                }
            }
        }

        // get part ID
        if (!empty($part[3]) && $part[3] != 'NIL') {
            $struct->content_id = $part[3];
            $struct->headers['content-id'] = $part[3];

            if (empty($struct->disposition)) {
                $struct->disposition = 'inline';
            }
        }

        // fetch message headers if message/rfc822
        if ($struct->ctype_primary == 'message') {
            $headers = iil_C_FetchPartBody($this->conn, $this->mailbox, $this->_msg_id, $struct->mime_id.'.HEADER');
            $struct->headers = $this->_parse_headers($headers);

            if (is_array($part[8]) && empty($struct->parts)) {
                $struct->parts[] = $this->_structure_part($part[8], ++$count, $struct->mime_id);
            }
        }

        // normalize filename property
        if (!empty($struct->d_parameters['filename'])) {
            $struct->filename = $this->decode_mime_string($struct->d_parameters['filename']);
        } else if (!empty($struct->ctype_parameters['name'])) {
            $struct->filename = $this->decode_mime_string($struct->ctype_parameters['name']);
        } else if (!empty($struct->headers['content-description'])) {
            $struct->filename = $this->decode_mime_string($struct->headers['content-description']);
        }

        return $struct;
    }

    /**
     * Return a flat array with references to all parts, indexed by part numbers
     *
     * @param object rcube_message_part Message body structure
     * @return Array with part number -> object pairs
     */
    public function get_mime_numbers($structure) {
        $a_parts = array();
        $this->_get_part_numbers($structure, $a_parts);
        return $a_parts;
    }

    /**
     * Helper method for recursive calls
     *
     * @return void
     */
    private function _get_part_numbers($part, $a_parts) {
        if ($part->mime_id) {
            $a_parts[$part->mime_id] = $part;
        }
        if (is_array($part->parts)) {
            for ($i=0, $size = count($part->parts); $i < $size; $i++) {
                $this->_get_part_numbers($part->parts[$i], $a_parts);
            }
        }
    }

    /**
     * Fetch message body of a specific message from the server
     *
     * @param  int    Message UID
     * @param  string Part number
     * @param  object Part object created by get_structure()
     * @param  mixed  True to print part, ressource to write part contents in
     * @return Message/part body if not printed
     */
    function &get_message_part($uid, $part = 1, $o_part = null, $print = null) {
        if (!($msg_id = $this->_uid2id($uid))) {
            return false;
        }

        // get part encoding if not provided
        if (!is_object($o_part)) {
            $structure_str = iil_C_FetchStructureString($this->conn, $this->mailbox, $msg_id);
            $structure = iml_GetRawStructureArray($structure_str);
            $part_type = iml_GetPartTypeCode($structure, $part);

            $o_part = new rcube_message_part;
            $o_part->ctype_primary = $part_type==0 ? 'text' : ($part_type==2 ? 'message' : 'other');
            $o_part->encoding = strtolower(iml_GetPartEncodingString($structure, $part));
            $o_part->charset = iml_GetPartCharset($structure, $part);
        }

        // TODO: Add caching for message parts

        if ($print) {
            $mode = $o_part->encoding == 'base64' ? 3 : ($o_part->encoding == 'quoted-printable' ? 1 : 2);
            $body = iil_C_HandlePartBody($this->conn, $this->mailbox, $msg_id, $part, $mode);
            // we have to decode the part manually before printing
            if ($mode == 1) {
                echo $this->mime_decode($body, $o_part->encoding);
                $body = true;
            }
        } else {
            $body = iil_C_HandlePartBody($this->conn, $this->mailbox, $msg_id, $part, 1);

            // decode part body
            if ($o_part->encoding) {
                $body = $this->mime_decode($body, $o_part->encoding);
            }

            // convert charset (if text or message part)
            if ($o_part->ctype_primary == 'text' || $o_part->ctype_primary == 'message') {
                // assume ISO-8859-1 if no charset specified
                if (empty($o_part->charset)) {
                    $o_part->charset = 'ISO-8859-1';
                }
                $body = rcube::charset_convert($body, $o_part->charset);
            }
        }
        return $body;
    }

    /**
     * Fetch message body of a specific message from the server
     *
     * @param  int    Message UID
     * @return string Message/part body
     * @see    rcube_imap::get_message_part()
     */
    public function get_body($uid, $part=1) {
        return $this->get_message_part($uid, $part);
    }

    /**
     * Returns the whole message source as string
     *
     * @param int  Message UID
     * @return string Message source string
     */
    private function get_raw_body($uid) {
        if (!($msg_id = $this->_uid2id($uid))) {
            return false;
        }
        $body = iil_C_FetchPartHeader($this->conn, $this->mailbox, $msg_id, NULL);
        $body .= iil_C_HandlePartBody($this->conn, $this->mailbox, $msg_id, NULL, 1);

        return $body;
    }

    /**
     * Sends the whole message source to stdout
     *
     * @param int  Message UID
     */
    public function print_raw_body($uid) {
        if (!($msg_id = $this->_uid2id($uid))) {
            return false;
        }
        print iil_C_FetchPartHeader($this->conn, $this->mailbox, $msg_id, NULL);
        flush();
        iil_C_HandlePartBody($this->conn, $this->mailbox, $msg_id, NULL, 2);
    }


    /**
     * Set message flag to one or several messages
     *
     * @param mixed  Message UIDs as array or as comma-separated string
     * @param string Flag to set: SEEN, UNDELETED, DELETED, RECENT, ANSWERED, DRAFT, MDNSENT
     * @return boolean True on success, False on failure
     */
    public function set_flag($uids, $flag) {
        $flag = strtoupper($flag);
        $msg_ids = array();
        if (!is_array($uids)) {
            $uids = explode(',',$uids);
        }
        foreach ($uids as $uid) {
            $msg_ids[$uid] = $this->_uid2id($uid);
        }

        if ($flag == 'UNDELETED') {
            $result = iil_C_Undelete($this->conn, $this->mailbox, implode(',', array_values($msg_ids)));
        } elseif ($flag == 'UNSEEN') {
            $result = iil_C_Unseen($this->conn, $this->mailbox, implode(',', array_values($msg_ids)));
        } else {
            $result = iil_C_Flag($this->conn, $this->mailbox, implode(',', array_values($msg_ids)), $flag);
        }
        //tfk_debug($result);
        // reload message headers if cached
        $cache_key = $this->mailbox.'.msg';
        if ($this->caching_enabled) {
            foreach ($msg_ids as $uid => $id) {
                if ($cached_headers = $this->get_cached_message($cache_key, $uid)) {
                    $this->remove_message_cache($cache_key, $id);
                    //$this->get_headers($uid);
                }
            }

            // close and re-open connection
            // this prevents connection problems with Courier
            $this->reconnect();
        }

        // set nr of messages that were flaged
        $count = count($msg_ids);

        // clear message count cache
        if ($result && $flag == 'SEEN') {
            $this->_set_messagecount($this->mailbox, 'UNSEEN', $count*(-1));
        } elseif ($result && $flag == 'UNSEEN') {
            $this->_set_messagecount($this->mailbox, 'UNSEEN', $count);
        } elseif ($result && $flag == 'DELETED') {
            $this->_set_messagecount($this->mailbox, 'ALL', $count*(-1));
        }
        return $result;
    }

    /**
     * Append a mail message (source) to a specific mailbox
     *
     * @param string Target mailbox
     * @param string Message source
     * @return boolean True on success, False on error
     */
    public function save_message($mbox_name, $message) {
        $mbox_name = stripslashes($mbox_name);
        $mailbox = $this->_mod_mailbox($mbox_name);

        // make sure mailbox exists
        if (in_array($mailbox, $this->_list_mailboxes())) {
            $saved = iil_C_Append($this->conn, $mailbox, $message);
        }
        if ($saved) {
            // increase messagecount of the target mailbox
            $this->_set_messagecount($mailbox, 'ALL', 1);
        }
        return $saved;
    }

    /**
     * Move a message from one mailbox to another
     *
     * @param string List of UIDs to move, separated by comma
     * @param string Target mailbox
     * @param string Source mailbox
     * @return boolean True on success, False on error
     */
    public function move_message($uids, $to_mbox, $from_mbox = '') {
        $to_mbox = stripslashes($to_mbox);
        $from_mbox = stripslashes($from_mbox);
        $to_mbox = $this->_mod_mailbox($to_mbox);
        $from_mbox = $from_mbox ? $this->_mod_mailbox($from_mbox) : $this->mailbox;

        // make sure mailbox exists
        if (!in_array($to_mbox, $this->_list_mailboxes())) {
            if (in_array($to_mbox, $this->default_folders)) {
                $this->create_mailbox($to_mbox, true);
            } else {
                return false;
            }
        }

        // convert the list of uids to array
        $a_uids = is_string($uids) ? explode(',', $uids) : (is_array($uids) ? $uids : null);

        // exit if no message uids are specified
        if (!is_array($a_uids)) {
            return false;
        }

        // convert uids to message ids
        $a_mids = array();
        foreach ($a_uids as $uid) {
            $a_mids[] = $this->_uid2id($uid, $from_mbox);
        }

        $iil_move = iil_C_Move($this->conn, join(',', $a_mids), $from_mbox, $to_mbox);
        $moved = !($iil_move === false || $iil_move < 0);

        //rcube::tfk_debug('Iil_move: ' . $iil_move);
        //rcube::tfk_debug('Moved? (#2): ' . $moved);

        // send expunge command in order to have the moved message
        // really deleted from the source mailbox
        if ($moved) {
            $this->_expunge($from_mbox, false);
            $this->_clear_messagecount($from_mbox);
            $this->_clear_messagecount($to_mbox);
        }

        // remove message ids from search set
        if ($moved && $this->search_set && $from_mbox == $this->mailbox) {
            $this->search_set = array_diff($this->search_set, $a_mids);
        }

        // update cached message headers
        $cache_key = $from_mbox.'.msg';
        if ($moved && ($a_cache_index = $this->get_message_cache_index($cache_key))) {
            $start_index = 100000;
            foreach ($a_uids as $uid) {
                if (($index = array_search($uid, $a_cache_index)) !== false)
                $start_index = min($index, $start_index);
            }

            // clear cache from the lowest index on
            $this->clear_message_cache($cache_key, $start_index);
        }
        return $moved;
    }

    /**
     * Mark messages as deleted and expunge mailbox
     *
     * @param string List of UIDs to move, separated by comma
     * @param string Source mailbox
     * @return boolean True on success, False on error
     */
    public function delete_message($uids, $mbox_name = '') {
        $mbox_name = stripslashes($mbox_name);
        $mailbox = $mbox_name ? $this->_mod_mailbox($mbox_name) : $this->mailbox;

        // convert the list of uids to array
        $a_uids = is_string($uids) ? explode(',', $uids) : (is_array($uids) ? $uids : NULL);

        // exit if no message uids are specified
        if (!is_array($a_uids)) {
            return false;
        }

        // convert uids to message ids
        $a_mids = array();
        foreach ($a_uids as $uid) {
            $a_mids[] = $this->_uid2id($uid, $mailbox);
        }
        $deleted = iil_C_Delete($this->conn, $mailbox, join(',', $a_mids));

        // send expunge command in order to have the deleted message
        // really deleted from the mailbox
        if ($deleted) {
            $this->_expunge($mailbox, false);
            $this->_clear_messagecount($mailbox);
        }

        // remove message ids from search set
        if ($moved && $this->search_set && $mailbox == $this->mailbox) {
            $this->search_set = array_diff($this->search_set, $a_mids);
        }

        // remove deleted messages from cache
        $cache_key = $mailbox.'.msg';
        if ($deleted && ($a_cache_index = $this->get_message_cache_index($cache_key))) {
            $start_index = 100000;
            foreach ($a_uids as $uid) {
                if (($index = array_search($uid, $a_cache_index)) !== false) {
                    $start_index = min($index, $start_index);
                }
            }
            // clear cache from the lowest index on
            $this->clear_message_cache($cache_key, $start_index);
        }
        return $deleted;
    }

    /**
     * Clear all messages in a specific mailbox
     *
     * @param string Mailbox name
     * @return int Above 0 on success
     */
    public function clear_mailbox($mbox_name = null) {
        $mbox_name = stripslashes($mbox_name);
        $mailbox = !empty($mbox_name) ? $this->_mod_mailbox($mbox_name) : $this->mailbox;
        $msg_count = $this->_messagecount($mailbox, 'ALL');

        if ($msg_count > 0) {
            $cleared = iil_C_ClearFolder($this->conn, $mailbox);

            // make sure the message count cache is cleared as well
            if ($cleared) {
                $this->clear_message_cache($mailbox.'.msg');
                $a_mailbox_cache = $this->get_cache('messagecount');
                unset($a_mailbox_cache[$mailbox]);
                $this->update_cache('messagecount', $a_mailbox_cache);
            }
            return $cleared;
        }
        return false;
    }

    /**
     * Send IMAP expunge command and clear cache
     *
     * @param string Mailbox name
     * @param boolean False if cache should not be cleared
     * @return boolean True on success
     */
    public function expunge($mbox_name = '', $clear_cache = true) {
        $mbox_name = stripslashes($mbox_name);
        $mailbox = $mbox_name ? $this->_mod_mailbox($mbox_name) : $this->mailbox;
        return $this->_expunge($mailbox, $clear_cache);
    }

    /**
     * Send IMAP expunge command and clear cache
     *
     * @see rcube_imap::expunge()
     */
    private function _expunge($mailbox, $clear_cache = true) {
        $result = iil_C_Expunge($this->conn, $mailbox);

        if ($result >= 0 && $clear_cache) {
            $this->clear_message_cache($mailbox.'.msg');
            $this->_clear_messagecount($mailbox);
        }
        return $result;
    }

    /* --------------------------------
     *        folder managment
     * --------------------------------*/

    /**
     * Get a list of all folders available on the IMAP server
     *
     * @param string IMAP root dir
     * @return array Indexed array with folder names
     */
    public function list_unsubscribed($root = '') {
        static $sa_unsubscribed;

        if (is_array($sa_unsubscribed)) {
            return $sa_unsubscribed;
        }
        // retrieve list of folders from IMAP server
        $a_mboxes = iil_C_ListMailboxes($this->conn, $this->_mod_mailbox($root), '*');

        // modify names with root dir
        foreach ($a_mboxes as $mbox_name) {
            $name = $this->_mod_mailbox($mbox_name, 'out');
            if (empty($name) === false) {
                $a_folders[] = $name;
            }
        }
        // filter folders and sort them
        $sa_unsubscribed = $this->_sort_mailbox_list($a_folders);
        return $sa_unsubscribed;
    }


    /**
     * Get mailbox quota information
     * @return mixed Quota info or False if not supported
     */
    public function get_quota() {
        if ($this->get_capability('QUOTA')) {
            return iil_C_GetQuota($this->conn);
        }
        return false;
    }

    /**
     * Subscribe to a specific mailbox(es)
     *
     * @param string Mailbox name(s)
     * @return boolean True on success
     */
    public function subscribe($mbox_name) {
        if (is_array($mbox_name)) {
            $a_mboxes = $mbox_name;
        } else if (is_string($mbox_name) && strlen($mbox_name)) {
            $a_mboxes = explode(',', $mbox_name);
        }
        // let this common function do the main work
        return $this->_change_subscription($a_mboxes, 'subscribe');
    }

    /**
     * Unsubscribe mailboxes
     *
     * @param string Mailbox name(s)
     * @return boolean True on success
     */
    public function unsubscribe($mbox_name) {
        if (is_array($mbox_name)) {
            $a_mboxes = $mbox_name;
        } else if (is_string($mbox_name) && strlen($mbox_name)) {
            $a_mboxes = explode(',', $mbox_name);
        }
        // let this common function do the main work
        return $this->_change_subscription($a_mboxes, 'unsubscribe');
    }

    /**
     * Create a new mailbox on the server and register it in local cache
     *
     * @param string  New mailbox name (as utf-7 string)
     * @param boolean True if the new mailbox should be subscribed
     * @param string  Name of the created mailbox, false on error
     */
    public function create_mailbox($name, $subscribe = false) {
        $result = false;

        // replace backslashes
        $name = preg_replace('/[\\\]+/', '-', $name);

        // reduce mailbox name to 100 chars
        $name = substr($name, 0, 100);

        $abs_name = $this->_mod_mailbox($name);
        $a_mailbox_cache = $this->get_cache('mailboxes');

        if (strlen($abs_name) && (!is_array($a_mailbox_cache) || !in_array($abs_name, $a_mailbox_cache))) {
            $result = iil_C_CreateFolder($this->conn, $abs_name);
        }

        // try to subscribe it
        if ($result && $subscribe) {
            $this->subscribe($name);
        }

        return $result ? $name : false;
    }

    /**
     * Set a new name to an existing mailbox
     *
     * @param string Mailbox to rename (as utf-7 string)
     * @param string New mailbox name (as utf-7 string)
     * @return string Name of the renames mailbox, false on error
     */
    public function rename_mailbox($mbox_name, $new_name) {
        $result = false;

        // replace backslashes
        $name = preg_replace('/[\\\]+/', '-', $new_name);

        // encode mailbox name and reduce it to 100 chars
        $name = substr($new_name, 0, 100);

        // make absolute path
        $mailbox = $this->_mod_mailbox($mbox_name);
        $abs_name = $this->_mod_mailbox($name);

        // check if mailbox is subscribed
        $a_subscribed = $this->_list_mailboxes();
        $subscribed = in_array($mailbox, $a_subscribed);

        // unsubscribe folder
        if ($subscribed) {
            iil_C_UnSubscribe($this->conn, $mailbox);
        }

        if (strlen($abs_name)) {
            $result = iil_C_RenameFolder($this->conn, $mailbox, $abs_name);
        }

        if ($result) {
            $delm = $this->get_hierarchy_delimiter();
            // check if mailbox children are subscribed
            foreach ($a_subscribed as $c_subscribed) {
                if (preg_match('/^'.preg_quote($mailbox.$delm, '/').'/', $c_subscribed)) {
                    iil_C_UnSubscribe($this->conn, $c_subscribed);
                    iil_C_Subscribe($this->conn, preg_replace('/^'.preg_quote($mailbox, '/').'/', $abs_name, $c_subscribed));
                }
            }
            // clear cache
            $this->clear_message_cache($mailbox.'.msg');
            $this->clear_cache('mailboxes');
        }

        // try to subscribe it
        if ($result && $subscribed) {
            iil_C_Subscribe($this->conn, $abs_name);
        }
        return $result ? $name : false;
    }


    /**
     * Remove mailboxes from server
     *
     * @param string Mailbox name
     * @return boolean True on success
     */
    public function delete_mailbox($mbox_name) {
        $deleted = false;

        if (is_array($mbox_name)) {
            $a_mboxes = $mbox_name;
        } else if (is_string($mbox_name) && strlen($mbox_name)) {
            $a_mboxes = explode(',', $mbox_name);
        }

        $all_mboxes = iil_C_ListMailboxes($this->conn, $this->_mod_mailbox($root), '*');

        if (is_array($a_mboxes)) {
            foreach ($a_mboxes as $mbox_name) {
                $mailbox = $this->_mod_mailbox($mbox_name);

                // unsubscribe mailbox before deleting
                iil_C_UnSubscribe($this->conn, $mailbox);

                // send delete command to server
                $result = iil_C_DeleteFolder($this->conn, $mailbox);
                if ($result>=0) {
                    $deleted = true;
                }

                foreach ($all_mboxes as $c_mbox) {
                    if (preg_match('/^'.preg_quote($mailbox.$this->delimiter).'/', $c_mbox)) {
                        iil_C_UnSubscribe($this->conn, $c_mbox);
                        $result = iil_C_DeleteFolder($this->conn, $c_mbox);
                        if ($result>=0) {
                            $deleted = true;
                        }
                    }
                }
            }
        }

        // clear mailboxlist cache
        if ($deleted) {
            $this->clear_message_cache($mailbox.'.msg');
            $this->clear_cache('mailboxes');
        }
        return $deleted;
    }

    /**
     * Create all folders specified as default
     */
    public function create_default_folders() {
        $a_folders = iil_C_ListMailboxes($this->conn, $this->_mod_mailbox(''), '*');
        $a_subscribed = iil_C_ListSubscribed($this->conn, $this->_mod_mailbox(''), '*');

        // create default folders if they do not exist
        foreach ($this->default_folders as $folder) {
            $abs_name = $this->_mod_mailbox($folder);
            if (!in_array_nocase($abs_name, $a_folders)) {
                $this->create_mailbox($folder, TRUE);
            } else if (!in_array_nocase($abs_name, $a_subscribed)) {
                $this->subscribe($folder);
            }
        }
    }

    /* --------------------------------
     *   internal caching methods
     * --------------------------------*/

    public function set_caching($set) {
        if ($set && is_object($this->db)) {
            $this->caching_enabled = true;
        } else {
            $this->caching_enabled = false;
        }
    }

    private function get_cache($key) {
        // read cache
        if (!isset($this->cache[$key]) && $this->caching_enabled) {
            $cache_data = $this->_read_cache_record('IMAP.'.$key);
            $this->cache[$key] = strlen($cache_data) ? unserialize($cache_data) : false;
        }
        return $this->cache[$key];
    }


    private function update_cache($key, $data) {
        $this->cache[$key] = $data;
        $this->cache_changed = true;
        $this->cache_changes[$key] = true;
    }

    public function write_cache() {
        if ($this->caching_enabled && $this->cache_changed) {
            foreach ($this->cache as $key => $data) {
                if ($this->cache_changes[$key]) {
                    $this->_write_cache_record('IMAP.'.$key, serialize($data));
                }
            }
        }
    }


    public function clear_cache($key = null) {
        if ($key === null) {
            foreach ($this->cache as $key => $data) {
                $this->_clear_cache_record('IMAP.'.$key);
            }
            $this->cache = array();
            $this->cache_changed = false;
            $this->cache_changes = array();
        } else {
            $this->_clear_cache_record('IMAP.'.$key);
            $this->cache_changes[$key] = false;
            unset($this->cache[$key]);
        }
    }

    private function _read_cache_record($key) {
        if (!$this->db) {
            return false;
        }

        $_query = 'SELECT cache_id, data FROM '.rcube::get_table_name('cache');
        $_query.= ' WHERE user_id = ?';
        $_query.= ' AND cache_key = ?';
        // get cached data from DB
        $sql_result = $this->db->query($_query, $_SESSION['user_id'], $key);

        if ($sql_arr = $this->db->fetch_assoc($sql_result)) {
            $this->cache_keys[$key] = $sql_arr['cache_id'];
            return $sql_arr['data'];
        } else {
            $this->cache_keys[$key] = false;
        }
        return false;
    }

    private function _write_cache_record($key, $data) {
        if (!$this->db) {
            return false;
        }

        // check if we already have a cache entry for this key
        if (!isset($this->cache_keys[$key]) || empty($this->cache_keys[$key])) {
            $this->_read_cache_record($key);
        }

        if ($this->cache_keys[$key]) {
            // update existing cache record
            $_query = 'UPDATE '.rcube::get_table_name('cache');
            $_query.= ' SET created = '.$this->db->now().', data = ?';
            $_query.= ' WHERE user_id = ?';
            $_query.= ' AND cache_key = ?';
            $this->db->query($_query, $data, $_SESSION['user_id'], $key);
        } else {
            // add new cache record
            $_query = 'INSERT INTO '.rcube::get_table_name('cache');
            $_query.= ' (created, user_id, cache_key, data)';
            $_query.= ' VALUES ('.$this->db->now().', ?, ?, ?)';
            $this->db->query($_query, $_SESSION['user_id'], $key, $data);
        }
    }


    private function _clear_cache_record($key) {
        if (!$this->db) {
            return false;
        }
        // clear cache
        $_query = 'DELETE FROM '.rcube::get_table_name('cache');
        $_query.= ' WHERE user_id = ?';
        $_query.= ' AND cache_key = ?';
        $this->db->query($_query, $_SESSION['user_id'], $key);
    }

    /* --------------------------------
     *   message caching methods
     * --------------------------------*/

    /**
     * Checks if the cache is up-to-date
     *
     * @param string Mailbox name
     * @param string Internal cache key
     * @return int -3 = off, -2 = incomplete, -1 = dirty
     */
    private function check_cache_status($mailbox, $cache_key) {
        if (!$this->caching_enabled) {
            return -3;
        }

        $cache_index = $this->get_message_cache_index($cache_key, TRUE);
        $msg_count = $this->_messagecount($mailbox);
        $cache_count = count($cache_index);

        // rcube::console("Cache check: $msg_count !== ".count($cache_index));

        if ($cache_count == $msg_count) {
            // get highest index
            $header = iil_C_FetchHeader($this->conn, $mailbox, $msg_count);
            $cache_uid = array_pop($cache_index);

            // uids of highest message matches -> cache seems OK
            if ($cache_uid == $header->uid) {
                return 1;
            }
            // cache is dirty
            return -1;
        } else if (abs($msg_count - $cache_count) < $msg_count/10) {
            // if cache count differs less than 10% report as dirty
            return -1;
        } else {
            return -2;
        }
    }

    private function get_message_cache($key, $from, $to, $sort_field, $sort_order) {
        $cache_key = $key.':'.$from.':'.$to.':'.$sort_field.':'.$sort_order;
        $db_header_fields = array('idx', 'uid', 'subject', 'from', 'to', 'cc', 'date', 'size');

        if (!in_array($sort_field, $db_header_fields)) {
            $sort_field = 'idx';
        }

        if ($this->caching_enabled && !isset($this->cache[$cache_key]) || empty($this->cache[$cache_key])) {
            $this->cache[$cache_key] = array();
            $_query = 'SELECT idx, uid, headers FROM '.rcube::get_table_name('messages');
            $_query.= ' WHERE user_id = ?';
            $_query.= ' AND cache_key = ?';
            $_query.= ' ORDER BY '.$this->db->quoteIdentifier($sort_field).' '.strtoupper($sort_order);
            $sql_result = $this->db->limitquery($_query, $from, $to-$from, $_SESSION['user_id'], $key);

            while ($sql_arr = $this->db->fetch_assoc($sql_result)) {
                $uid = $sql_arr['uid'];
                $this->cache[$cache_key][$uid] = unserialize($sql_arr['headers']);
                // featch headers if unserialize failed
                if (empty($this->cache[$cache_key][$uid])) {
                    $this->cache[$cache_key][$uid] = iil_C_FetchHeader($this->conn, preg_replace('/.msg$/', '', $key), $uid, true);
                }
            }
        }
        return $this->cache[$cache_key];
    }

    private function get_cached_message($key, $uid, $struct = false) {
        $internal_key = '__single_msg';

        if ($this->caching_enabled && (!isset($this->cache[$internal_key][$uid]) || ($struct && empty($this->cache[$internal_key][$uid]->structure)))) {
            $sql_select = 'idx, uid, headers' . ($struct ? ', structure' : '');

            $_query = 'SELECT '.$sql_select.' FROM '.rcube::get_table_name('messages');
            $_query.= ' WHERE user_id = ?';
            $_query.= ' AND cache_key = ?';
            $_query.= ' AND uid = ?';
            $sql_result = $this->db->query($_query, $_SESSION['user_id'], $key, $uid);

            if ($sql_arr = $this->db->fetch_assoc($sql_result)) {
                $this->cache[$internal_key][$uid] = unserialize($sql_arr['headers']);
                if (is_object($this->cache[$internal_key][$uid]) && !empty($sql_arr['structure'])) {
                    $this->cache[$internal_key][$uid]->structure = unserialize($sql_arr['structure']);
                }
            }
        }
        return $this->cache[$internal_key][$uid];
    }

    private function get_message_cache_index($key, $force = false, $sort_col = 'idx', $sort_order = 'ASC') {
        static $sa_message_index = array();

        // empty key -> empty array
        if (!$this->caching_enabled || empty($key)) {
            return array();
        }

        if (!empty($sa_message_index[$key]) && !$force) {
            return $sa_message_index[$key];
        }

        $sa_message_index[$key] = array();
        // query
        $_query = 'SELECT idx, uid FROM '.rcube::get_table_name('messages');
        $_query.= ' WHERE user_id = ?';
        $_query.= ' AND cache_key = ?';
        $_query.= ' ORDER BY '.$this->db->quoteIdentifier($sort_col).' '.$sort_order;
        $sql_result = $this->db->query($_query, $_SESSION['user_id'], $key);

        while ($sql_arr = $this->db->fetch_assoc($sql_result)) {
            $sa_message_index[$key][$sql_arr['idx']] = $sql_arr['uid'];
        }
        return $sa_message_index[$key];
    }

    /**
     * add_message_cache
     * @todo docs
     */
    private function add_message_cache($key, $index, $headers, $struct = null) {
        if (empty($key) || !is_object($headers) || empty($headers->uid)) {
            return;
        }
        // add to internal (fast) cache
        $this->cache['__single_msg'][$headers->uid] = $headers;
        $this->cache['__single_msg'][$headers->uid]->structure = $struct;
        // no further caching
        if (!$this->caching_enabled) {
            return;
        }

        // check for an existing record (probly headers are cached but structure not)
        $_query = 'SELECT message_id FROM '.rcube::get_table_name('messages');
        $_query.= ' WHERE user_id = ?';
        $_query.= ' AND cache_key = ?';
        $_query.= ' AND uid = ?';
        $_query.= ' AND del <> 1';

        $sql_result = $this->db->query($_query, $_SESSION['user_id'], $key, $headers->uid);

        //rcube::tfk_debug(var_export($headers, true));

        // update cache record
        if ($sql_arr = $this->db->fetch_assoc($sql_result)) {
            $_query = 'UPDATE ' . rcube::get_table_name('messages');
            $_query.= ' SET idx = ?, headers = ?, structure = ?';
            $_query.= ' WHERE message_id = ?';
            $this->db->query($_query, $index, serialize($headers), (is_object($struct) ? serialize($struct) : null), $sql_arr['message_id']);
            return;
        }

        if (!isset($headers->timestamp) || empty($headers->timestamp)) {
            rcube::tfk_debug('NO TIMESTAMP: ' . var_export($headers, true));
            $headers->timestamp = mktime();
        }

        // insert new record
        $_query = 'INSERT INTO ' . rcube::get_table_name('messages');
        $_query.= ' (user_id, del, cache_key, created, idx, uid, subject,';
        $_query.= ' ' . $this->db->quoteIdentifier('from') . ',';
        $_query.= ' ' . $this->db->quoteIdentifier('to').',';
        $_query.= ' cc, date, size, headers, structure)';
        $_query.= ' VALUES (?, 0, ?,';
        $_query.= ' ' . $this->db->now() . ', ?, ?, ?, ?, ?, ?,';
        $_query.= ' ' . $this->db->fromunixtime($headers->timestamp) . ',';
        $_query.= ' ?, ?, ?)';
        $this->db->query($_query, $_SESSION['user_id'], $key, $index, $headers->uid,
            (string)substr($this->decode_header($headers->subject, true), 0, 128),
            (string)substr($this->decode_header($headers->from, true), 0, 128),
            (string)substr($this->decode_header($headers->to, true), 0, 128),
            (string)substr($this->decode_header($headers->cc, true), 0, 128),
            (int)$headers->size,
            serialize($headers),
            (is_object($struct) ? serialize($struct) : null));
    }

    private function remove_message_cache($key, $index) {
        $_query = 'DELETE FROM '.rcube::get_table_name('messages');
        $_query.= ' WHERE user_id = ?';
        $_query.= ' AND cache_key = ?';
        $_query.= ' AND idx = ?';
        $this->db->query($_query, $_SESSION['user_id'], $key, $index);
    }


    private function clear_message_cache($key, $start_index = 1) {
        $_query = 'DELETE FROM '.rcube::get_table_name('messages');
        $_query.= ' WHERE user_id = ?';
        $_query.= ' AND cache_key = ?';
        $_query.= ' AND idx = ?';
        $this->db->query($_query, $_SESSION['user_id'], $key, $start_index);
    }

    /* --------------------------------
     *   encoding/decoding methods
     * --------------------------------*/

    /**
     * Split an address list into a structured array list
     *
     * @param string  Input string
     * @param int     List only this number of addresses
     * @param boolean Decode address strings
     * @return array  Indexed list of addresses
     */
    public function decode_address_list($input, $max = null, $decode = true) {
        $a = $this->_parse_address_list($input, $decode);
        $out = array();

        if (!is_array($a)) {
            return $out;
        }

        $c = count($a);
        $j = 0;

        foreach ($a as $val) {
            $j++;
            $address = $val['address'];
            $name    = preg_replace(array('/^[\'"]/', '/[\'"]$/'), '', trim($val['name']));

            if ($name && $address && $name != $address) {
                $string = sprintf('%s <%s>', strpos($name, ',')!==FALSE ? '"'.$name.'"' : $name, $address);
            } else if ($address) {
                $string = $address;
            } else if ($name) {
                $string = $name;
            }

            $out[$j] = array('name' => $name, 'mailto' => $address, 'string' => $string);

            if ($max && $j==$max) {
                break;
            }
        }
        return $out;
    }


    function decode_header($input, $remove_quotes=FALSE)
    {
        $str = $this->decode_mime_string((string)$input);
        if ($str{0}=='"' && $remove_quotes) {
            $str = str_replace('"', '', $str);
        }
        return $str;
    }


    /**
     * Decode a mime-encoded string to internal charset
     *
     * @access static
     */
    static function decode_mime_string($input, $fallback=null)
    {
        $out = '';

        $pos = strpos($input, '=?');
        if ($pos !== false) {
            $out = substr($input, 0, $pos);

            $end_cs_pos = strpos($input, "?", $pos+2);
            $end_en_pos = strpos($input, "?", $end_cs_pos+1);
            $end_pos = strpos($input, "?=", $end_en_pos+1);

            $encstr = substr($input, $pos+2, ($end_pos-$pos-2));
            $rest = substr($input, $end_pos+2);

            $out .= rcube_imap::_decode_mime_string_part($encstr);
            $out .= rcube_imap::decode_mime_string($rest, $fallback);

            return $out;
        }

        // no encoding information, use fallback
        return rcube::charset_convert($input, !empty($fallback) ? $fallback : 'ISO-8859-1');
    }


    /**
     * Decode a part of a mime-encoded string
     *
     * @access static
     */
    static function _decode_mime_string_part($str)
    {
        $a = explode('?', $str);
        $count = count($a);

        // should be in format "charset?encoding?base64_string"
        if ($count >= 3) {
            for ($i=2; $i<$count; $i++) {
                $rest.=$a[$i];
            }

            if (($a[1]=="B")||($a[1]=="b")) {
                $rest = base64_decode($rest);
            }
            else if (($a[1]=="Q")||($a[1]=="q")) {
                $rest = str_replace("_", " ", $rest);
                $rest = quoted_printable_decode($rest);
            }

            return rcube::charset_convert($rest, $a[0]);
        }
        return $str;    // we dont' know what to do with this
    }

    function mime_decode($input, $encoding='7bit')
    {
        switch (strtolower($encoding)) {
            case '7bit':
                return $input;
                break;

            case 'quoted-printable':
                return quoted_printable_decode($input);
                break;

            case 'base64':
                return base64_decode($input);
                break;

            default:
                return $input;
        }
    }

    function mime_encode($input, $encoding='7bit')
    {
        switch ($encoding) {
            case 'quoted-printable':
                return quoted_printable_encode($input);
                break;

            case 'base64':
                return base64_encode($input);
                break;

            default:
                return $input;
        }
    }


    // convert body chars according to the ctype_parameters
    function charset_decode($body, $ctype_param)
    {
        if (is_array($ctype_param) && !empty($ctype_param['charset'])) {
            return rcube::charset_convert($body, $ctype_param['charset']);
        }

        // defaults to what is specified in the class header
        return rcube::charset_convert($body,  'ISO-8859-1');
    }


    /* --------------------------------
     *         private methods
     * --------------------------------*/


    /**
     * _mod_mailbox
     *
     * @access private
     * @param  string  $mbox_name
     * @param  string  $mode
     * @return string  $mbox_name
     */
    private function _mod_mailbox($mbox_name, $mode='in')
    {
        if ((!empty($this->root_ns) && $this->root_ns == $mbox_name) || $mbox_name == 'INBOX') {
            return $mbox_name;
        }

        if (!empty($this->root_dir) && $mode=='in') {
            $mbox_name = $this->root_dir.$this->delimiter.$mbox_name;
        }
        else if (strlen($this->root_dir) && $mode=='out') {
            $mbox_name = substr($mbox_name, strlen($this->root_dir)+1);
        }
        return $mbox_name;
    }

    /**
     * sort mailboxes first by default folders and then in alphabethical order
     */
    function _sort_mailbox_list($a_folders)
    {
        $a_out = $a_defaults = array();

        // find default folders and skip folders starting with '.'
        foreach($a_folders as $i => $folder) {
            if ($folder{0}=='.') {
                continue;
            }

            if (($p = array_search(strtolower($folder), $this->default_folders_lc))!==FALSE) {
                $a_defaults[$p] = $folder;
            }
            else {
                $a_out[] = $folder;
            }
        }

        sort($a_out);
        ksort($a_defaults);

        return array_merge($a_defaults, $a_out);
    }

    function get_id($uid, $mbox_name=NULL)
    {
        $mailbox = $mbox_name ? $this->_mod_mailbox($mbox_name) : $this->mailbox;
        return $this->_uid2id($uid, $mailbox);
    }

    function get_uid($id,$mbox_name=NULL)
    {
        $mailbox = $mbox_name ? $this->_mod_mailbox($mbox_name) : $this->mailbox;
        return $this->_id2uid($id, $mailbox);
    }

    function _uid2id($uid, $mbox_name=NULL)
    {
        if (!$mbox_name) {
            $mbox_name = $this->mailbox;
        }

        if (!isset($this->uid_id_map[$mbox_name][$uid])) {
            $this->uid_id_map[$mbox_name][$uid] = iil_C_UID2ID($this->conn, $mbox_name, $uid);
        }
        return $this->uid_id_map[$mbox_name][$uid];
    }

    function _id2uid($id, $mbox_name=NULL)
    {
        if (!$mbox_name) {
            $mbox_name = $this->mailbox;
        }
        return iil_C_ID2UID($this->conn, $mbox_name, $id);
    }


    // parse string or array of server capabilities and put them in internal array
    function _parse_capability($caps)
    {
        if (!is_array($caps))
        $cap_arr = explode(' ', $caps);
        else
        $cap_arr = $caps;

        foreach ($cap_arr as $cap)
        {
            if ($cap=='CAPABILITY')
            continue;

            if (strpos($cap, '=')>0)
            {
                list($key, $value) = explode('=', $cap);
                if (!is_array($this->capabilities[$key]))
                $this->capabilities[$key] = array();

                $this->capabilities[$key][] = $value;
            }
            else
            $this->capabilities[$cap] = TRUE;
        }
    }


    // subscribe/unsubscribe a list of mailboxes and update local cache
    function _change_subscription($a_mboxes, $mode)
    {
        $updated = FALSE;

        if (is_array($a_mboxes))
        foreach ($a_mboxes as $i => $mbox_name)
        {
            $mailbox = $this->_mod_mailbox($mbox_name);
            $a_mboxes[$i] = $mailbox;

            if ($mode=='subscribe')
            $result = iil_C_Subscribe($this->conn, $mailbox);
            else if ($mode=='unsubscribe')
            $result = iil_C_UnSubscribe($this->conn, $mailbox);

            if ($result>=0)
            $updated = TRUE;
        }

        // get cached mailbox list
        if ($updated)
        {
            $a_mailbox_cache = $this->get_cache('mailboxes');
            if (!is_array($a_mailbox_cache))
            return $updated;

            // modify cached list
            if ($mode=='subscribe')
            $a_mailbox_cache = array_merge($a_mailbox_cache, $a_mboxes);
            else if ($mode=='unsubscribe')
            $a_mailbox_cache = array_diff($a_mailbox_cache, $a_mboxes);

            // write mailboxlist to cache
            $this->update_cache('mailboxes', $this->_sort_mailbox_list($a_mailbox_cache));
        }

        return $updated;
    }


    // increde/decrese messagecount for a specific mailbox
    function _set_messagecount($mbox_name, $mode, $increment)
    {
        $a_mailbox_cache = FALSE;
        $mailbox = $mbox_name ? $mbox_name : $this->mailbox;
        $mode = strtoupper($mode);

        $a_mailbox_cache = $this->get_cache('messagecount');

        if (!is_array($a_mailbox_cache[$mailbox]) || !isset($a_mailbox_cache[$mailbox][$mode]) || !is_numeric($increment))
        return FALSE;

        // add incremental value to messagecount
        $a_mailbox_cache[$mailbox][$mode] += $increment;

        // there's something wrong, delete from cache
        if ($a_mailbox_cache[$mailbox][$mode] < 0)
        unset($a_mailbox_cache[$mailbox][$mode]);

        // write back to cache
        $this->update_cache('messagecount', $a_mailbox_cache);

        return TRUE;
    }


    // remove messagecount of a specific mailbox from cache
    function _clear_messagecount($mbox_name='')
    {
        $a_mailbox_cache = FALSE;
        $mailbox = $mbox_name ? $mbox_name : $this->mailbox;

        $a_mailbox_cache = $this->get_cache('messagecount');

        if (is_array($a_mailbox_cache[$mailbox]))
        {
            unset($a_mailbox_cache[$mailbox]);
            $this->update_cache('messagecount', $a_mailbox_cache);
        }
    }


    // split RFC822 header string into an associative array
    function _parse_headers($headers)
    {
        $a_headers = array();
        $lines = explode("\n", $headers);
        $c = count($lines);
        for ($i=0; $i<$c; $i++)
        {
            if ($p = strpos($lines[$i], ': '))
            {
                $field = strtolower(substr($lines[$i], 0, $p));
                $value = trim(substr($lines[$i], $p+1));
                if (!empty($value))
                $a_headers[$field] = $value;
            }
        }

        return $a_headers;
    }


    function _parse_address_list($str, $decode=true)
    {
        // remove any newlines and carriage returns before
        $a = $this->_explode_quoted_string('[,;]', preg_replace( "/[\r\n]/", " ", $str));
        $result = array();

        foreach ($a as $key => $val)
        {
            $val = preg_replace("/([\"\w])</", "$1 <", $val);
            $sub_a = $this->_explode_quoted_string(' ', $decode ? $this->decode_header($val) : $val);
            $result[$key]['name'] = '';

            foreach ($sub_a as $k => $v)
            {
                if (strpos($v, '@') > 0)
                $result[$key]['address'] = str_replace('<', '', str_replace('>', '', $v));
                else
                $result[$key]['name'] .= (empty($result[$key]['name'])?'':' ').str_replace("\"",'',stripslashes($v));
            }

            if (empty($result[$key]['name']))
            $result[$key]['name'] = $result[$key]['address'];
        }

        return $result;
    }


    function _explode_quoted_string($delimiter, $string)
    {
        $result = array();
        $strlen = strlen($string);
        for ($q=$p=$i=0; $i < $strlen; $i++)
        {
            if ($string{$i} == "\"" && $string{$i-1} != "\\")
            $q = $q ? false : true;
            else if (!$q && preg_match("/$delimiter/", $string{$i}))
            {
                $result[] = substr($string, $p, $i - $p);
                $p = $i + 1;
            }
        }

        $result[] = substr($string, $p);
        return $result;
    }
}


/**
 * Add quoted-printable encoding to a given string
 *
 * @param string  $input      string to encode
 * @param int     $line_max   add new line after this number of characters
 * @param boolena $space_conf true if spaces should be converted into =20
 * @return encoded string
 */
function quoted_printable_encode($input, $line_max=76, $space_conv=false)
{
    $hex = array('0','1','2','3','4','5','6','7','8','9','A','B','C','D','E','F');
    $lines = preg_split("/(?:\r\n|\r|\n)/", $input);
    $eol = "\r\n";
    $escape = "=";
    $output = "";

    while( list(, $line) = each($lines)) {
        //$line = rtrim($line); // remove trailing white space -> no =20\r\n necessary
        $linlen = strlen($line);
        $newline = "";
        for($i = 0; $i < $linlen; $i++) {
            $c = substr( $line, $i, 1 );
            $dec = ord( $c );
            if ( ( $i == 0 ) && ( $dec == 46 ) ) {
                // convert first point in the line into =2E
                $c = "=2E";
            }
            if ( $dec == 32 )
            {
                if ( $i == ( $linlen - 1 ) ) // convert space at eol only
                {
                    $c = "=20";
                }
                else if ( $space_conv )
                {
                    $c = "=20";
                }
            }
            else if ( ($dec == 61) || ($dec < 32 ) || ($dec > 126) )  // always encode "\t", which is *not* required
            {
                $h2 = floor($dec/16);
                $h1 = floor($dec%16);
                $c = $escape.$hex["$h2"].$hex["$h1"];
            }

            if ( (strlen($newline) + strlen($c)) >= $line_max )  // CRLF is not counted
            {
                $output .= $newline.$escape.$eol; // soft line break; " =\r\n" is okay
                $newline = "";
                // check if newline first character will be point or not
                if ( $dec == 46 ) {
                    $c = "=2E";
                }
            }
            $newline .= $c;
        } // end of for
        $output .= $newline.$eol;
    } // end of while

    return trim($output);
}
