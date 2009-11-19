<?php

/*
 +-----------------------------------------------------------------------+
 | program/include/rcube_imap.php                                        |
 |                                                                       |
 | This file is part of the RoundCube Webmail client                     |
 | Copyright (C) 2005-2009, RoundCube Dev. - Switzerland                 |
 | Licensed under the GNU GPL                                            |
 |                                                                       |
 | PURPOSE:                                                              |
 |   IMAP wrapper that implements the Iloha IMAP Library (IIL)           |
 |   See http://ilohamail.org/ for details                               |
 |                                                                       |
 +-----------------------------------------------------------------------+
 | Author: Thomas Bruederli <roundcube@gmail.com>                        |
 +-----------------------------------------------------------------------+

 $Id$

*/


/*
 * Obtain classes from the Iloha IMAP library
 */
require_once('lib/imap.inc');
require_once('lib/mime.inc');
require_once('lib/tnef_decoder.inc');


/**
 * Interface class for accessing an IMAP server
 *
 * This is a wrapper that implements the Iloha IMAP Library (IIL)
 *
 * @package    Mail
 * @author     Thomas Bruederli <roundcube@gmail.com>
 * @version    1.5
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
  var $sort_field = '';
  var $sort_order = 'DESC';
  var $delimiter = NULL;
  var $threading = false;
  var $caching_enabled = false;
  var $default_charset = 'ISO-8859-1';
  var $struct_charset = NULL;
  var $default_folders = array('INBOX');
  var $default_folders_lc = array('inbox');
  var $fetch_add_headers = '';
  var $cache = array();
  var $cache_keys = array();  
  var $cache_changes = array();
  var $uid_id_map = array();
  var $msg_headers = array();
  var $skip_deleted = false;
  var $search_set = NULL;
  var $search_string = '';
  var $search_charset = '';
  var $search_sort_field = '';
  var $search_threads = false;
  var $debug_level = 1;
  var $error_code = 0;
  var $db_header_fields = array('idx', 'uid', 'subject', 'from', 'to', 'cc', 'date', 'size');
  var $options = array('auth_method' => 'check');
  
  private $host, $user, $pass, $port, $ssl;


  /**
   * Object constructor
   *
   * @param object DB Database connection
   */
  function __construct($db_conn)
    {
    $this->db = $db_conn;
    }


  /**
   * Connect to an IMAP server
   *
   * @param  string   Host to connect
   * @param  string   Username for IMAP account
   * @param  string   Password for IMAP account
   * @param  number   Port to connect to
   * @param  string   SSL schema (either ssl or tls) or null if plain connection
   * @return boolean  TRUE on success, FALSE on failure
   * @access public
   */
  function connect($host, $user, $pass, $port=143, $use_ssl=null)
    {
    global $ICL_SSL, $ICL_PORT, $IMAP_USE_INTERNAL_DATE;
    
    // check for Open-SSL support in PHP build
    if ($use_ssl && extension_loaded('openssl'))
      $ICL_SSL = $use_ssl == 'imaps' ? 'ssl' : $use_ssl;
    else if ($use_ssl) {
      raise_error(array('code' => 403, 'type' => 'imap', 'file' => __FILE__,
                        'message' => 'Open SSL not available;'), TRUE, FALSE);
      $port = 143;
    }

    $ICL_PORT = $port;
    $IMAP_USE_INTERNAL_DATE = false;

    $attempt = 0;
    do {
      $data = rcmail::get_instance()->plugins->exec_hook('imap_connect', array('host' => $host, 'user' => $user, 'attempt' => ++$attempt));
      if (!empty($data['pass']))
        $pass = $data['pass'];

      $this->conn = iil_Connect($data['host'], $data['user'], $pass, $this->options);
    } while(!$this->conn && $data['retry']);

    $this->host = $data['host'];
    $this->user = $data['user'];
    $this->pass = $pass;
    $this->port = $port;
    $this->ssl = $use_ssl;
    
    // print trace messages
    if ($this->conn && ($this->debug_level & 8))
      console($this->conn->message);
    
    // write error log
    else if (!$this->conn && $GLOBALS['iil_error'])
      {
      $this->error_code = $GLOBALS['iil_errornum'];
      raise_error(array('code' => 403,
                       'type' => 'imap',
                       'message' => $GLOBALS['iil_error']), TRUE, FALSE);
      }

    // get server properties
    if ($this->conn)
      {
      if (!empty($this->conn->rootdir))
        {
        $this->set_rootdir($this->conn->rootdir);
        $this->root_ns = preg_replace('/[.\/]$/', '', $this->conn->rootdir);
        }
      if (empty($this->delimiter))
	$this->get_hierarchy_delimiter();
      }

    return $this->conn ? TRUE : FALSE;
    }


  /**
   * Close IMAP connection
   * Usually done on script shutdown
   *
   * @access public
   */
  function close()
    {    
    if ($this->conn)
      iil_Close($this->conn);
    }


  /**
   * Close IMAP connection and re-connect
   * This is used to avoid some strange socket errors when talking to Courier IMAP
   *
   * @access public
   */
  function reconnect()
    {
    $this->close();
    $this->connect($this->host, $this->user, $this->pass, $this->port, $this->ssl);
    
    // issue SELECT command to restore connection status
    if ($this->mailbox)
      iil_C_Select($this->conn, $this->mailbox);
    }

  /**
   * Set options to be used in iil_Connect()
   */
  function set_options($opt)
  {
    $this->options = array_merge($this->options, (array)$opt);
  }

  /**
   * Set a root folder for the IMAP connection.
   *
   * Only folders within this root folder will be displayed
   * and all folder paths will be translated using this folder name
   *
   * @param  string   Root folder
   * @access public
   */
  function set_rootdir($root)
    {
    if (preg_match('/[.\/]$/', $root)) //(substr($root, -1, 1)==='/')
      $root = substr($root, 0, -1);

    $this->root_dir = $root;
    $this->options['rootdir'] = $root;
    
    if (empty($this->delimiter))
      $this->get_hierarchy_delimiter();
    }


  /**
   * Set default message charset
   *
   * This will be used for message decoding if a charset specification is not available
   *
   * @param  string   Charset string
   * @access public
   */
  function set_charset($cs)
    {
    $this->default_charset = $cs;
    }


  /**
   * This list of folders will be listed above all other folders
   *
   * @param  array  Indexed list of folder names
   * @access public
   */
  function set_default_mailboxes($arr)
    {
    if (is_array($arr))
      {
      $this->default_folders = $arr;
      $this->default_folders_lc = array();

      // add inbox if not included
      if (!in_array_nocase('INBOX', $this->default_folders))
        array_unshift($this->default_folders, 'INBOX');

      // create a second list with lower cased names
      foreach ($this->default_folders as $mbox)
        $this->default_folders_lc[] = strtolower($mbox);
      }
    }


  /**
   * Set internal mailbox reference.
   *
   * All operations will be perfomed on this mailbox/folder
   *
   * @param  string  Mailbox/Folder name
   * @access public
   */
  function set_mailbox($new_mbox)
    {
    $mailbox = $this->mod_mailbox($new_mbox);

    if ($this->mailbox == $mailbox)
      return;

    $this->mailbox = $mailbox;

    // clear messagecount cache for this mailbox
    $this->_clear_messagecount($mailbox);
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
   * Save a set of message ids for future message listing methods
   *
   * @param  string  IMAP Search query
   * @param  array   List of message ids or NULL if empty
   * @param  string  Charset of search string
   * @param  string  Sorting field
   */
  function set_search_set($str=null, $msgs=null, $charset=null, $sort_field=null, $threads=false)
    {
    if (is_array($str) && $msgs == null)
      list($str, $msgs, $charset, $sort_field) = $str;
    if ($msgs != null && !is_array($msgs))
      $msgs = explode(',', $msgs);
      
    $this->search_string = $str;
    $this->search_set = $msgs;
    $this->search_charset = $charset;
    $this->search_sort_field = $sort_field;
    $this->search_threads = $threads;
    }


  /**
   * Return the saved search set as hash array
   * @return array Search set
   */
  function get_search_set()
    {
    return array($this->search_string,
	$this->search_set,
	$this->search_charset,
	$this->search_sort_field,
	$this->search_threads,
	);
    }


  /**
   * Returns the currently used mailbox name
   *
   * @return  string Name of the mailbox/folder
   * @access  public
   */
  function get_mailbox_name()
    {
    return $this->conn ? $this->mod_mailbox($this->mailbox, 'out') : '';
    }


  /**
   * Returns the IMAP server's capability
   *
   * @param   string  Capability name
   * @return  mixed   Capability value or TRUE if supported, FALSE if not
   * @access  public
   */
  function get_capability($cap)
    {
    return iil_C_GetCapability($this->conn, strtoupper($cap));
    }


  /**
   * Sets threading flag to the best supported THREAD algorithm
   *
   * @param  boolean  TRUE to enable and FALSE
   * @return string   Algorithm or false if THREAD is not supported
   * @access public
   */
  function set_threading($enable=false)
    {
    $this->threading = false;
    
    if ($enable) {
      if ($this->get_capability('THREAD=REFS'))
        $this->threading = 'REFS';
      else if ($this->get_capability('THREAD=REFERENCES'))
        $this->threading = 'REFERENCES';
      else if ($this->get_capability('THREAD=ORDEREDSUBJECT'))
        $this->threading = 'ORDEREDSUBJECT';
      }
      
    return $this->threading;
    }


  /**
   * Checks the PERMANENTFLAGS capability of the current mailbox
   * and returns true if the given flag is supported by the IMAP server
   *
   * @param   string  Permanentflag name
   * @return  mixed   True if this flag is supported
   * @access  public
   */
  function check_permflag($flag)
    {
    $flag = strtoupper($flag);
    $imap_flag = $GLOBALS['IMAP_FLAGS'][$flag];
    return (in_array_nocase($imap_flag, $this->conn->permanentflags));
    }


  /**
   * Returns the delimiter that is used by the IMAP server for folder separation
   *
   * @return  string  Delimiter string
   * @access  public
   */
  function get_hierarchy_delimiter()
    {
    if ($this->conn && empty($this->delimiter))
      $this->delimiter = iil_C_GetHierarchyDelimiter($this->conn);

    if (empty($this->delimiter))
      $this->delimiter = '/';

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
   * @access  public
   */
  function list_mailboxes($root='', $filter='*')
    {
    $a_out = array();
    $a_mboxes = $this->_list_mailboxes($root, $filter);

    foreach ($a_mboxes as $mbox_row)
      {
      $name = $this->mod_mailbox($mbox_row, 'out');
      if (strlen($name))
        $a_out[] = $name;
      }

    // INBOX should always be available
    if (!in_array_nocase('INBOX', $a_out))
      array_unshift($a_out, 'INBOX');

    // sort mailboxes
    $a_out = $this->_sort_mailbox_list($a_out);

    return $a_out;
    }


  /**
   * Private method for mailbox listing
   *
   * @return  array   List of mailboxes/folders
   * @see     rcube_imap::list_mailboxes()
   * @access  private
   */
  private function _list_mailboxes($root='', $filter='*')
    {
    $a_defaults = $a_out = array();
    
    // get cached folder list    
    $a_mboxes = $this->get_cache('mailboxes');
    if (is_array($a_mboxes))
      return $a_mboxes;

    // Give plugins a chance to provide a list of mailboxes
    $data = rcmail::get_instance()->plugins->exec_hook('list_mailboxes',array('root'=>$root,'filter'=>$filter));
    if (isset($data['folders'])) {
        $a_folders = $data['folders'];
    }
    else{
        // retrieve list of folders from IMAP server
        $a_folders = iil_C_ListSubscribed($this->conn, $this->mod_mailbox($root), $filter);
    }

    
    if (!is_array($a_folders) || !sizeof($a_folders))
      $a_folders = array();

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
   * @return  int      Number of messages
   * @access  public
   */
  function messagecount($mbox_name='', $mode='ALL', $force=FALSE)
    {
    $mailbox = $mbox_name ? $this->mod_mailbox($mbox_name) : $this->mailbox;
    return $this->_messagecount($mailbox, $mode, $force);
    }


  /**
   * Private method for getting nr of messages
   *
   * @access  private
   * @see     rcube_imap::messagecount()
   */
  private function _messagecount($mailbox='', $mode='ALL', $force=FALSE)
    {
    $mode = strtoupper($mode);

    if (empty($mailbox))
      $mailbox = $this->mailbox;

    // count search set
    if ($this->search_string && $mailbox == $this->mailbox && ($mode == 'ALL' || $mode == 'THREADS') && !$force) {
      if ($this->search_threads)
        return $mode == 'ALL' ? count((array)$this->search_set['depth']) : count((array)$this->search_set['tree']);
      else
        return count((array)$this->search_set);
      }
    
    $a_mailbox_cache = $this->get_cache('messagecount');
    
    // return cached value
    if (!$force && is_array($a_mailbox_cache[$mailbox]) && isset($a_mailbox_cache[$mailbox][$mode]))
      return $a_mailbox_cache[$mailbox][$mode];

    if ($mode == 'THREADS')
      $count = $this->_threadcount($mailbox);

    // RECENT count is fetched a bit different
    else if ($mode == 'RECENT')
       $count = iil_C_CheckForRecent($this->conn, $mailbox);

    // use SEARCH for message counting
    else if ($this->skip_deleted)
      {
      $search_str = "ALL UNDELETED";

      // get message count and store in cache
      if ($mode == 'UNSEEN')
        $search_str .= " UNSEEN";

      // get message count using SEARCH
      // not very performant but more precise (using UNDELETED)
      $index = $this->_search_index($mailbox, $search_str);
      $count = is_array($index) ? count($index) : 0;
      }
    else
      {
      if ($mode == 'UNSEEN')
        $count = iil_C_CountUnseen($this->conn, $mailbox);
      else
        $count = iil_C_CountMessages($this->conn, $mailbox);
      }

    if (!is_array($a_mailbox_cache[$mailbox]))
      $a_mailbox_cache[$mailbox] = array();
      
    $a_mailbox_cache[$mailbox][$mode] = (int)$count;

    // write back to cache
    $this->update_cache('messagecount', $a_mailbox_cache);

    return (int)$count;
    }


  /**
   * Private method for getting nr of threads
   *
   * @access  private
   * @see     rcube_imap::messagecount()
   */
  private function _threadcount($mailbox)
    {
    if (!empty($this->cache['__threads']))
      return count($this->cache['__threads']['tree']);
    
    list ($thread_tree, $msg_depth, $has_children) = $this->_fetch_threads($mailbox);

//    $this->update_thread_cache($mailbox, $thread_tree, $msg_depth, $has_children);
    return count($thread_tree);  
    }


  /**
   * Public method for listing headers
   * convert mailbox name with root dir first
   *
   * @param   string   Mailbox/folder name
   * @param   int      Current page to list
   * @param   string   Header field to sort by
   * @param   string   Sort order [ASC|DESC]
   * @param   boolean  Number of slice items to extract from result array
   * @return  array    Indexed array with message header objects
   * @access  public   
   */
  function list_headers($mbox_name='', $page=NULL, $sort_field=NULL, $sort_order=NULL, $slice=0)
    {
    $mailbox = $mbox_name ? $this->mod_mailbox($mbox_name) : $this->mailbox;
    return $this->_list_headers($mailbox, $page, $sort_field, $sort_order, false, $slice);
    }


  /**
   * Private method for listing message headers
   *
   * @access  private
   * @see     rcube_imap::list_headers
   */
  private function _list_headers($mailbox='', $page=NULL, $sort_field=NULL, $sort_order=NULL, $recursive=FALSE, $slice=0)
    {
    if (!strlen($mailbox))
      return array();

    // use saved message set
    if ($this->search_string && $mailbox == $this->mailbox)
      return $this->_list_header_set($mailbox, $page, $sort_field, $sort_order, $slice);

    if ($this->threading)
      return $this->_list_thread_headers($mailbox, $page, $sort_field, $sort_order, $recursive, $slice);

    $this->_set_sort_order($sort_field, $sort_order);

    $page = $page ? $page : $this->list_page;
    $cache_key = $mailbox.'.msg';
    $cache_status = $this->check_cache_status($mailbox, $cache_key);

    // cache is OK, we can get all messages from local cache
    if ($cache_status>0)
      {
      $start_msg = ($page-1) * $this->page_size;
      $a_msg_headers = $this->get_message_cache($cache_key, $start_msg, $start_msg+$this->page_size, $this->sort_field, $this->sort_order);
      $result = array_values($a_msg_headers);
      if ($slice)
        $result = array_slice($result, -$slice, $slice);
      return $result;
      }
    // cache is dirty, sync it
    else if ($this->caching_enabled && $cache_status==-1 && !$recursive)
      {
      $this->sync_header_index($mailbox);
      return $this->_list_headers($mailbox, $page, $this->sort_field, $this->sort_order, TRUE, $slice);
      }

    // retrieve headers from IMAP
    $a_msg_headers = array();

    // use message index sort as default sorting (for better performance)
    if (!$this->sort_field)
      {
        if ($this->skip_deleted) {
          // @TODO: this could be cached
	  if ($msg_index = $this->_search_index($mailbox, 'ALL UNDELETED')) {
            $max = max($msg_index);
            list($begin, $end) = $this->_get_message_range(count($msg_index), $page);
            $msg_index = array_slice($msg_index, $begin, $end-$begin);
	    }
	} else if ($max = iil_C_CountMessages($this->conn, $mailbox)) {
          list($begin, $end) = $this->_get_message_range($max, $page);
	  $msg_index = range($begin+1, $end);
	} else
	  $msg_index = array();

        if ($slice)
          $msg_index = array_slice($msg_index, ($this->sort_order == 'DESC' ? 0 : -$slice), $slice);

        // fetch reqested headers from server
	if ($msg_index)
          $this->_fetch_headers($mailbox, join(",", $msg_index), $a_msg_headers, $cache_key);
      }
    // use SORT command
    else if ($this->get_capability('sort') && ($msg_index = iil_C_Sort($this->conn, $mailbox, $this->sort_field, $this->skip_deleted ? 'UNDELETED' : '')))
      {
      list($begin, $end) = $this->_get_message_range(count($msg_index), $page);
      $max = max($msg_index);
      $msg_index = array_slice($msg_index, $begin, $end-$begin);

      if ($slice)
        $msg_index = array_slice($msg_index, ($this->sort_order == 'DESC' ? 0 : -$slice), $slice);

      // fetch reqested headers from server
      $this->_fetch_headers($mailbox, join(',', $msg_index), $a_msg_headers, $cache_key);
      }
    // fetch specified header for all messages and sort
    else if ($a_index = iil_C_FetchHeaderIndex($this->conn, $mailbox, "1:*", $this->sort_field, $this->skip_deleted))
      {
      asort($a_index); // ASC
      $msg_index = array_keys($a_index);
      $max = max($msg_index);
      list($begin, $end) = $this->_get_message_range(count($msg_index), $page);
      $msg_index = array_slice($msg_index, $begin, $end-$begin);

      if ($slice)
        $msg_index = array_slice($msg_index, ($this->sort_order == 'DESC' ? 0 : -$slice), $slice);

      // fetch reqested headers from server
      $this->_fetch_headers($mailbox, join(",", $msg_index), $a_msg_headers, $cache_key);
      }

    // delete cached messages with a higher index than $max+1
    // Changed $max to $max+1 to fix this bug : #1484295
    $this->clear_message_cache($cache_key, $max + 1);

    // kick child process to sync cache
    // ...

    // return empty array if no messages found
    if (!is_array($a_msg_headers) || empty($a_msg_headers))
      return array();
    
    // use this class for message sorting
    $sorter = new rcube_header_sorter();
    $sorter->set_sequence_numbers($msg_index);
    $sorter->sort_headers($a_msg_headers);

    if ($this->sort_order == 'DESC')
      $a_msg_headers = array_reverse($a_msg_headers);	    

    return array_values($a_msg_headers);
    }


  /**
   * Private method for listing message headers using threads
   *
   * @access  private
   * @see     rcube_imap::list_headers
   */
  private function _list_thread_headers($mailbox, $page=NULL, $sort_field=NULL, $sort_order=NULL, $recursive=FALSE, $slice=0)
    {
    $this->_set_sort_order($sort_field, $sort_order);

    $page = $page ? $page : $this->list_page;
//    $cache_key = $mailbox.'.msg';
//    $cache_status = $this->check_cache_status($mailbox, $cache_key);

    // get all threads (default sort order)
    list ($thread_tree, $msg_depth, $has_children) = $this->_fetch_threads($mailbox);

    if (empty($thread_tree))
      return array();

    $msg_index = $this->_sort_threads($mailbox, $thread_tree);

    return $this->_fetch_thread_headers($mailbox, $thread_tree, $msg_depth, $has_children,
	$msg_index, $page, $lice);
    }


  /**
   * Private method for fetching threads data
   *
   * @param   string   Mailbox/folder name
   * @return  array    Array with thread data
   * @access  private
   */
  private function _fetch_threads($mailbox)
    {
    if (empty($this->cache['__threads'])) {
      // get all threads
      list ($thread_tree, $msg_depth, $has_children) = iil_C_Thread($this->conn,
	$mailbox, $this->threading, $this->skip_deleted ? 'UNDELETED' : '');
    
      // add to internal (fast) cache
      $this->cache['__threads'] = array();
      $this->cache['__threads']['tree'] = $thread_tree;
      $this->cache['__threads']['depth'] = $msg_depth;
      $this->cache['__threads']['has_children'] = $has_children;
      }

    return array(
      $this->cache['__threads']['tree'],
      $this->cache['__threads']['depth'],
      $this->cache['__threads']['has_children'],
      );
    }


  /**
   * Private method for fetching threaded messages headers
   *
   * @access  private
   */
  private function _fetch_thread_headers($mailbox, $thread_tree, $msg_depth, $has_children, $msg_index, $page, $slice=0)
    {
    $cache_key = $mailbox.'.msg';
    // now get IDs for current page
    $max = max($msg_index);
    list($begin, $end) = $this->_get_message_range(count($msg_index), $page);
    $msg_index = array_slice($msg_index, $begin, $end-$begin);

    if ($slice)
      $msg_index = array_slice($msg_index, ($this->sort_order == 'DESC' ? 0 : -$slice), $slice);

    if ($this->sort_order == 'DESC')
      $msg_index = array_reverse($msg_index);

    // flatten threads array
    // @TODO: fetch children only in expanded mode
    $all_ids = array();
    foreach($msg_index as $root) {
      $all_ids[] = $root;
      if (!empty($thread_tree[$root]))
        $all_ids = array_merge($all_ids, array_keys_recursive($thread_tree[$root]));
      }

    // fetch reqested headers from server
    $this->_fetch_headers($mailbox, $all_ids, $a_msg_headers, $cache_key);

    // return empty array if no messages found
    if (!is_array($a_msg_headers) || empty($a_msg_headers))
      return array();
    
    // use this class for message sorting
    $sorter = new rcube_header_sorter();
    $sorter->set_sequence_numbers($all_ids);
    $sorter->sort_headers($a_msg_headers);

    // Set depth, has_children and unread_children fields in headers
    $this->_set_thread_flags($a_msg_headers, $msg_depth, $has_children);

    return array_values($a_msg_headers);
    }


  /**
   * Private method for setting threaded messages flags:
   * depth, has_children and unread_children
   *
   * @param  array   Reference to headers array indexed by message ID
   * @param  array   Array of messages depth indexed by message ID
   * @param  array   Array of messages children flags indexed by message ID
   * @return array   Message headers array indexed by message ID
   * @access private
   */
  private function _set_thread_flags(&$headers, $msg_depth, $msg_children)
    {
    $parents = array();

    foreach ($headers as $idx => $header) {
      $id = $header->id;
      $depth = $msg_depth[$id];
      $parents = array_slice($parents, 0, $depth);

      if (!empty($parents)) {
        $headers[$idx]->parent_uid = end($parents);
        if (!$header->seen)
          $headers[$parents[0]]->unread_children++;
        }
      array_push($parents, $header->uid);

      $headers[$idx]->depth = $depth;
      $headers[$idx]->has_children = $msg_children[$id];
      }
    }


  /**
   * Private method for listing a set of message headers (search results)
   *
   * @param   string   Mailbox/folder name
   * @param   int      Current page to list
   * @param   string   Header field to sort by
   * @param   string   Sort order [ASC|DESC]
   * @param   boolean  Number of slice items to extract from result array
   * @return  array    Indexed array with message header objects
   * @access  private
   * @see     rcube_imap::list_header_set()
   */
  private function _list_header_set($mailbox, $page=NULL, $sort_field=NULL, $sort_order=NULL, $slice=0)
    {
    if (!strlen($mailbox) || empty($this->search_set))
      return array();

    // use saved messages from searching
    if ($this->threading)
      return $this->_list_thread_header_set($mailbox, $page, $sort_field, $sort_order, $slice);

    $msgs = $this->search_set;
    $a_msg_headers = array();
    $page = $page ? $page : $this->list_page;
    $start_msg = ($page-1) * $this->page_size;

    $this->_set_sort_order($sort_field, $sort_order);

    // quickest method (default sorting)
    if (!$this->search_sort_field && !$this->sort_field)
      {
      if ($sort_order == 'DESC')
        $msgs = array_reverse($msgs);

      // get messages uids for one page
      $msgs = array_slice(array_values($msgs), $start_msg, min(count($msgs)-$start_msg, $this->page_size));

      if ($slice)
        $msgs = array_slice($msgs, -$slice, $slice);

      // fetch headers
      $this->_fetch_headers($mailbox, join(',',$msgs), $a_msg_headers, NULL);

      // I didn't found in RFC that FETCH always returns messages sorted by index
      $sorter = new rcube_header_sorter();
      $sorter->set_sequence_numbers($msgs);
      $sorter->sort_headers($a_msg_headers);

      return array_values($a_msg_headers);
      }

    // sorted messages, so we can first slice array and then fetch only wanted headers
    if ($this->get_capability('sort')) // SORT searching result
      {
      // reset search set if sorting field has been changed
      if ($this->sort_field && $this->search_sort_field != $this->sort_field)
        $msgs = $this->search('', $this->search_string, $this->search_charset, $this->sort_field);

      // return empty array if no messages found
      if (empty($msgs))
        return array();

      if ($sort_order == 'DESC')
        $msgs = array_reverse($msgs);

      // get messages uids for one page
      $msgs = array_slice(array_values($msgs), $start_msg, min(count($msgs)-$start_msg, $this->page_size));

      if ($slice)
        $msgs = array_slice($msgs, -$slice, $slice);

      // fetch headers
      $this->_fetch_headers($mailbox, join(',',$msgs), $a_msg_headers, NULL);

      $sorter = new rcube_header_sorter();
      $sorter->set_sequence_numbers($msgs);
      $sorter->sort_headers($a_msg_headers);

      return array_values($a_msg_headers);
      }
    else { // SEARCH result, need sorting
      $cnt = count($msgs);
      // 300: experimantal value for best result
      if (($cnt > 300 && $cnt > $this->page_size) || !$this->sort_field) {
        // use memory less expensive (and quick) method for big result set
	$a_index = $this->message_index('', $this->sort_field, $this->sort_order);
        // get messages uids for one page...
        $msgs = array_slice($a_index, $start_msg, min($cnt-$start_msg, $this->page_size));
        if ($slice)
          $msgs = array_slice($msgs, -$slice, $slice);
	// ...and fetch headers
        $this->_fetch_headers($mailbox, join(',', $msgs), $a_msg_headers, NULL);

        // return empty array if no messages found
        if (!is_array($a_msg_headers) || empty($a_msg_headers))
          return array();

        $sorter = new rcube_header_sorter();
        $sorter->set_sequence_numbers($msgs);
        $sorter->sort_headers($a_msg_headers);

        return array_values($a_msg_headers);
        }
      else {
        // for small result set we can fetch all messages headers
        $this->_fetch_headers($mailbox, join(',', $msgs), $a_msg_headers, NULL);
    
        // return empty array if no messages found
        if (!is_array($a_msg_headers) || empty($a_msg_headers))
          return array();

        // if not already sorted
        $a_msg_headers = iil_SortHeaders($a_msg_headers, $this->sort_field, $this->sort_order);
      
        // only return the requested part of the set
	$a_msg_headers = array_slice(array_values($a_msg_headers), $start_msg, min($cnt-$start_msg, $this->page_size));
        if ($slice)
          $a_msg_headers = array_slice($a_msg_headers, -$slice, $slice);

        return $a_msg_headers;
        }
      }
    }


  /**
   * Private method for listing a set of threaded message headers (search results)
   *
   * @param   string   Mailbox/folder name
   * @param   int      Current page to list
   * @param   string   Header field to sort by
   * @param   string   Sort order [ASC|DESC]
   * @param   boolean  Number of slice items to extract from result array
   * @return  array    Indexed array with message header objects
   * @access  private
   * @see     rcube_imap::list_header_set()
   */
  private function _list_thread_header_set($mailbox, $page=NULL, $sort_field=NULL, $sort_order=NULL, $slice=0)
    {
    $thread_tree = $this->search_set['tree'];
    $msg_depth = $this->search_set['depth'];
    $has_children = $this->search_set['children'];
    $a_msg_headers = array();

    $page = $page ? $page : $this->list_page;
    $start_msg = ($page-1) * $this->page_size;

    $this->_set_sort_order($sort_field, $sort_order);

    $msg_index = $this->_sort_threads($mailbox, $thread_tree, array_keys($msg_depth));

    return $this->_fetch_thread_headers($mailbox, $thread_tree, $msg_depth, $has_children, $msg_index, $page, $slice=0);
    }


  /**
   * Helper function to get first and last index of the requested set
   *
   * @param  int     message count
   * @param  mixed   page number to show, or string 'all'
   * @return array   array with two values: first index, last index
   * @access private
   */
  private function _get_message_range($max, $page)
    {
    $start_msg = ($page-1) * $this->page_size;
    
    if ($page=='all')
      {
      $begin = 0;
      $end = $max;
      }
    else if ($this->sort_order=='DESC')
      {
      $begin = $max - $this->page_size - $start_msg;
      $end =   $max - $start_msg;
      }
    else
      {
      $begin = $start_msg;
      $end   = $start_msg + $this->page_size;
      }

    if ($begin < 0) $begin = 0;
    if ($end < 0) $end = $max;
    if ($end > $max) $end = $max;
    
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
   * @return int     Messages count
   * @access private
   */
  private function _fetch_headers($mailbox, $msgs, &$a_msg_headers, $cache_key)
    {
    // fetch reqested headers from server
    $a_header_index = iil_C_FetchHeaders($this->conn, $mailbox, $msgs, false, false, $this->fetch_add_headers);

    if (!empty($a_header_index))
      {
      // cache is incomplete
      $cache_index = $this->get_message_cache_index($cache_key);

      foreach ($a_header_index as $i => $headers) {
        if ($this->caching_enabled && $cache_index[$headers->id] != $headers->uid) {
	  // prevent index duplicates
	  if ($cache_index[$headers->id]) {
	    $this->remove_message_cache($cache_key, $headers->id, true);
	    unset($cache_index[$headers->id]);
	    }
          // add message to cache
	  $this->add_message_cache($cache_key, $headers->id, $headers, NULL,
	    !in_array($headers->uid, $cache_index));
	  }

        $a_msg_headers[$headers->uid] = $headers;
        }
      }

    return count($a_msg_headers);
    }
    
  
  /**
   * Return sorted array of message IDs (not UIDs)
   *
   * @param string Mailbox to get index from
   * @param string Sort column
   * @param string Sort order [ASC, DESC]
   * @return array Indexed array with message ids
   */
  function message_index($mbox_name='', $sort_field=NULL, $sort_order=NULL)
    {
    $this->_set_sort_order($sort_field, $sort_order);

    $mailbox = $mbox_name ? $this->mod_mailbox($mbox_name) : $this->mailbox;
    $key = "{$mailbox}:{$this->sort_field}:{$this->sort_order}:{$this->search_string}.msgi";

    // we have a saved search result, get index from there
    if (!isset($this->cache[$key]) && $this->search_string && $mailbox == $this->mailbox)
    {
      $this->cache[$key] = array();
      
      // use message index sort as default sorting
      if (!$this->sort_field)
        {
	$msgs = $this->search_set;
	
	if ($this->search_sort_field != 'date')
	  sort($msgs);
	
        if ($this->sort_order == 'DESC')
          $this->cache[$key] = array_reverse($msgs);
        else
          $this->cache[$key] = $msgs;
        }
      // sort with SORT command
      else if ($this->get_capability('sort'))
        {
        if ($this->sort_field && $this->search_sort_field != $this->sort_field)
          $this->search('', $this->search_string, $this->search_charset, $this->sort_field);

        if ($this->sort_order == 'DESC')
          $this->cache[$key] = array_reverse($this->search_set);
        else
          $this->cache[$key] = $this->search_set;
        }
      else
        {
        $a_index = iil_C_FetchHeaderIndex($this->conn, $mailbox, join(',', $this->search_set), $this->sort_field, $this->skip_deleted);

        if ($this->sort_order=="ASC")
          asort($a_index);
        else if ($this->sort_order=="DESC")
          arsort($a_index);

        $this->cache[$key] = array_keys($a_index);
	}
    }

    // have stored it in RAM
    if (isset($this->cache[$key]))
      return $this->cache[$key];

    // check local cache
    $cache_key = $mailbox.'.msg';
    $cache_status = $this->check_cache_status($mailbox, $cache_key);

    // cache is OK
    if ($cache_status>0)
      {
      $a_index = $this->get_message_cache_index($cache_key, TRUE, $this->sort_field, $this->sort_order);
      return array_keys($a_index);
      }

    // use message index sort as default sorting
    if (!$this->sort_field)
      {
      if ($this->skip_deleted) {
        $a_index = $this->_search_index($mailbox, 'ALL');
      } else if ($max = $this->_messagecount($mailbox)) {
        $a_index = range(1, $max);
      }

      if ($this->sort_order == 'DESC')
        $a_index = array_reverse($a_index);

      $this->cache[$key] = $a_index;
      }
    // fetch complete message index
    else if ($this->get_capability('sort') && ($a_index = iil_C_Sort($this->conn, $mailbox, $this->sort_field, $this->skip_deleted ? 'UNDELETED' : '')))
      {
      if ($this->sort_order == 'DESC')
        $a_index = array_reverse($a_index);

      $this->cache[$key] = $a_index;
      }
    else
      {
      $a_index = iil_C_FetchHeaderIndex($this->conn, $mailbox, "1:*", $this->sort_field, $this->skip_deleted);

      if ($this->sort_order=="ASC")
        asort($a_index);
      else if ($this->sort_order=="DESC")
        arsort($a_index);
        
      $this->cache[$key] = array_keys($a_index);
      }

    return $this->cache[$key];
    }


  /**
   * @access private
   */
  function sync_header_index($mailbox)
    {
    $cache_key = $mailbox.'.msg';
    $cache_index = $this->get_message_cache_index($cache_key);

    // fetch complete message index
    $a_message_index = iil_C_FetchHeaderIndex($this->conn, $mailbox, "1:*", 'UID', $this->skip_deleted);
    
    if ($a_message_index === false)
      return false;
        
    foreach ($a_message_index as $id => $uid)
      {
      // message in cache at correct position
      if ($cache_index[$id] == $uid)
        {
        unset($cache_index[$id]);
        continue;
        }
        
      // message in cache but in wrong position
      if (in_array((string)$uid, $cache_index, TRUE))
        {
        unset($cache_index[$id]);
        }
      
      // other message at this position
      if (isset($cache_index[$id]))
        {
	$for_remove[] = $cache_index[$id];
        unset($cache_index[$id]);
        }
        
	$for_update[] = $id;
      }

    // clear messages at wrong positions and those deleted that are still in cache_index      
    if (!empty($for_remove))
      $cache_index = array_merge($cache_index, $for_remove);
    
    if (!empty($cache_index))
      $this->remove_message_cache($cache_key, $cache_index);

    // fetch complete headers and add to cache
    if (!empty($for_update)) {
      if ($headers = iil_C_FetchHeader($this->conn, $mailbox, join(',', $for_update), false, $this->fetch_add_headers))
        foreach ($headers as $header)
          $this->add_message_cache($cache_key, $header->id, $header, NULL,
		in_array($header->uid, (array)$for_remove));
      }
    }


  /**
   * Invoke search request to IMAP server
   *
   * @param  string  mailbox name to search in
   * @param  string  search string
   * @param  string  search string charset
   * @param  string  header field to sort by
   * @return array   search results as list of message ids
   * @access public
   */
  function search($mbox_name='', $str=NULL, $charset=NULL, $sort_field=NULL)
    {
    if (!$str)
      return false;
    
    $mailbox = $mbox_name ? $this->mod_mailbox($mbox_name) : $this->mailbox;

    $results = $this->_search_index($mailbox, $str, $charset, $sort_field);

    // try search with US-ASCII charset (should be supported by server)
    // only if UTF-8 search is not supported
    if (empty($results) && !is_array($results) && !empty($charset) && $charset != 'US-ASCII')
      {
	// convert strings to US_ASCII
        if(preg_match_all('/\{([0-9]+)\}\r\n/', $str, $matches, PREG_OFFSET_CAPTURE))
	  {
	  $last = 0; $res = '';
	  foreach($matches[1] as $m)
	    {
	    $string_offset = $m[1] + strlen($m[0]) + 4; // {}\r\n
	    $string = substr($str, $string_offset - 1, $m[0]);
	    $string = rcube_charset_convert($string, $charset, 'US-ASCII');
	    if (!$string) continue;
	    $res .= sprintf("%s{%d}\r\n%s", substr($str, $last, $m[1] - $last - 1), strlen($string), $string);
	    $last = $m[0] + $string_offset - 1;
	    }
	    if ($last < strlen($str))
	      $res .= substr($str, $last, strlen($str)-$last);
	  }
	else // strings for conversion not found
	  $res = $str;
	  
	$results = $this->search($mbox_name, $res, NULL, $sort_field);
      }

    $this->set_search_set($str, $results, $charset, $sort_field, (bool) $this->threading);

    return $results;
    }


  /**
   * Private search method
   *
   * @return array   search results as list of message ids
   * @access private
   * @see rcube_imap::search()
   */
  private function _search_index($mailbox, $criteria='ALL', $charset=NULL, $sort_field=NULL)
    {
    $orig_criteria = $criteria;

    if ($this->skip_deleted && !preg_match('/UNDELETED/', $criteria))
      $criteria = 'UNDELETED '.$criteria;

    if ($this->threading) {
      list ($thread_tree, $msg_depth, $has_children) = iil_C_Thread($this->conn,
            $mailbox, $this->threading, $criteria, $charset);

      $a_messages = array(
        'tree' 	=> $thread_tree,
	'depth'	=> $msg_depth,
	'children' => $has_children
        );
      }
    else if ($sort_field && $this->get_capability('sort')) {
      $charset = $charset ? $charset : $this->default_charset;
      $a_messages = iil_C_Sort($this->conn, $mailbox, $sort_field, $criteria, FALSE, $charset);
      }
    else {
      if ($orig_criteria == 'ALL') {
        $max = $this->_messagecount($mailbox);
        $a_messages = $max ? range(1, $max) : array();
        }
      else {
        $a_messages = iil_C_Search($this->conn, $mailbox, ($charset ? "CHARSET $charset " : '') . $criteria);
    
        // I didn't found that SEARCH always returns sorted IDs
        if (!$this->sort_field)
          sort($a_messages);
        }
      }
    // update messagecount cache ?
//    $a_mailbox_cache = get_cache('messagecount');
//    $a_mailbox_cache[$mailbox][$criteria] = sizeof($a_messages);
//    $this->update_cache('messagecount', $a_mailbox_cache);
        
    return $a_messages;
    }
    
  
  /**
   * Sort thread
   *
   * @param  array Unsorted thread tree (iil_C_Thread() result)
   * @param  array Message IDs if we know what we need (e.g. search result)
   * @return array Sorted roots IDs
   * @access private
   */
  private function _sort_threads($mailbox, $thread_tree, $ids=NULL)
    {
    // THREAD=ORDEREDSUBJECT: 	sorting by sent date of root message
    // THREAD=REFERENCES: 	sorting by sent date of root message
    // THREAD=REFS: 		sorting by the most recent date in each thread
    // default sorting
    if (!$this->sort_field || ($this->sort_field == 'date' && $this->threading == 'REFS')) {
        return array_keys($thread_tree);
      }
    // here we'll implement REFS sorting, for performance reason
    else { // ($sort_field == 'date' && $this->threading != 'REFS')
      // use SORT command
      if ($this->get_capability('sort')) {
        $a_index = iil_C_Sort($this->conn, $mailbox, $this->sort_field,
	    !empty($ids) ? $ids : ($this->skip_deleted ? 'UNDELETED' : ''));
        }
      else {
        // fetch specified headers for all messages and sort them
        $a_index = iil_C_FetchHeaderIndex($this->conn, $mailbox, !empty($ids) ? $ids : "1:*",
	    $this->sort_field, $this->skip_deleted);
        asort($a_index); // ASC
	$a_index = array_values($a_index);
        }

	return $this->_sort_thread_refs($thread_tree, $a_index);
      }
/*
    // other sorting, we'll sort roots only
    else {
      // use SORT command for root messages sorting
      if ($this->get_capability('sort')) {
        $msg_index = iil_C_Sort($this->conn, $mailbox, $this->sort_field, array_keys($thread_tree));
        }
      else {
        // fetch specified headers for all root messages and sort
        $a_index = iil_C_FetchHeaderIndex($this->conn, $mailbox,
	    array_keys($thread_tree), $this->sort_field, $this->skip_deleted);
        asort($a_index); // ASC
        $msg_index = array_keys($a_index);
        }
      }
*/
    return array();
    }


  /**
   * THREAD=REFS sorting implementation
   *
   * @param  array   Thread tree array (message identifiers as keys)
   * @param  array   Array of sorted message identifiers
   * @return array   Array of sorted roots messages
   * @access private
   */
  private function _sort_thread_refs($tree, $index)
    {
    if (empty($tree))
      return array();
    
    $index = array_combine(array_values($index), $index);

    // assign roots
    foreach ($tree as $idx => $val) {
      $index[$idx] = $idx;
      if (!empty($val)) {
        $idx_arr = array_keys_recursive($tree[$idx]);
        foreach ($idx_arr as $subidx)
          $index[$subidx] = $idx;
        }
      }

    $index = array_values($index);  

    // create sorted array of roots
    $msg_index = array();
    if ($this->sort_order != 'DESC') {
      foreach ($index as $idx)
        if (!isset($msg_index[$idx]))
          $msg_index[$idx] = $idx;
      $msg_index = array_values($msg_index);
      }
    else {
      for ($x=count($index)-1; $x>=0; $x--)
        if (!isset($msg_index[$index[$x]]))
          $msg_index[$index[$x]] = $index[$x];
      $msg_index = array_reverse($msg_index);
      }

    return $msg_index;
    }


  /**
   * Refresh saved search set
   *
   * @return array Current search set
   */
  function refresh_search()
    {
    if (!empty($this->search_string))
      $this->search_set = $this->search('', $this->search_string, $this->search_charset,
    	    $this->search_sort_field, $this->search_threads);
      
    return $this->get_search_set();
    }
  
  
  /**
   * Check if the given message ID is part of the current search set
   *
   * @return boolean True on match or if no search request is stored
   */
  function in_searchset($msgid)
  {
    if (!empty($this->search_string))
      return in_array("$msgid", (array)$this->search_set, true);
    else
      return true;
  }


  /**
   * Return message headers object of a specific message
   *
   * @param int     Message ID
   * @param string  Mailbox to read from 
   * @param boolean True if $id is the message UID
   * @param boolean True if we need also BODYSTRUCTURE in headers
   * @return object Message headers representation
   */
  function get_headers($id, $mbox_name=NULL, $is_uid=TRUE, $bodystr=FALSE)
    {
    $mailbox = $mbox_name ? $this->mod_mailbox($mbox_name) : $this->mailbox;
    $uid = $is_uid ? $id : $this->_id2uid($id);

    // get cached headers
    if ($uid && ($headers = &$this->get_cached_message($mailbox.'.msg', $uid)))
      return $headers;

    $headers = iil_C_FetchHeader($this->conn, $mailbox, $id, $is_uid, $bodystr, $this->fetch_add_headers);

    // write headers cache
    if ($headers)
      {
      if ($headers->uid && $headers->id)
        $this->uid_id_map[$mailbox][$headers->uid] = $headers->id;

      $this->add_message_cache($mailbox.'.msg', $headers->id, $headers, NULL, true);
      }

    return $headers;
    }


  /**
   * Fetch body structure from the IMAP server and build
   * an object structure similar to the one generated by PEAR::Mail_mimeDecode
   *
   * @param int Message UID to fetch
   * @param string Message BODYSTRUCTURE string (optional)
   * @return object rcube_message_part Message part tree or False on failure
   */
  function &get_structure($uid, $structure_str='')
    {
    $cache_key = $this->mailbox.'.msg';
    $headers = &$this->get_cached_message($cache_key, $uid);

    // return cached message structure
    if (is_object($headers) && is_object($headers->structure)) {
      return $headers->structure;
    }

    if (!$structure_str)
      $structure_str = iil_C_FetchStructureString($this->conn, $this->mailbox, $uid, true);
    $structure = iml_GetRawStructureArray($structure_str);
    $struct = false;

    // parse structure and add headers
    if (!empty($structure))
      {
      $headers = $this->get_headers($uid);
      $this->_msg_id = $headers->id;

      // set message charset from message headers
      if ($headers->charset)
        $this->struct_charset = $headers->charset;
      else
        $this->struct_charset = $this->_structure_charset($structure);

      // Here we can recognize malformed BODYSTRUCTURE and 
      // 1. [@TODO] parse the message in other way to create our own message structure
      // 2. or just show the raw message body.
      // Example of structure for malformed MIME message:
      // ("text" "plain" ("charset" "us-ascii") NIL NIL "7bit" 2154 70 NIL NIL NIL)
      if ($headers->ctype && $headers->ctype != 'text/plain'
	  && $structure[0] == 'text' && $structure[1] == 'plain') {
	return false;  
	}

      $struct = &$this->_structure_part($structure);
      $struct->headers = get_object_vars($headers);

      // don't trust given content-type
      if (empty($struct->parts) && !empty($struct->headers['ctype']))
        {
        $struct->mime_id = '1';
        $struct->mimetype = strtolower($struct->headers['ctype']);
        list($struct->ctype_primary, $struct->ctype_secondary) = explode('/', $struct->mimetype);
        }

      // write structure to cache
      if ($this->caching_enabled)
        $this->add_message_cache($cache_key, $this->_msg_id, $headers, $struct);
      }

    return $struct;
    }

  
  /**
   * Build message part object
   *
   * @access private
   */
  function &_structure_part($part, $count=0, $parent='', $raw_headers=null)
    {
    $struct = new rcube_message_part;
    $struct->mime_id = empty($parent) ? (string)$count : "$parent.$count";

    // multipart
    if (is_array($part[0]))
      {
      $struct->ctype_primary = 'multipart';
      
      // find first non-array entry
      for ($i=1; $i<count($part); $i++)
        if (!is_array($part[$i]))
          {
          $struct->ctype_secondary = strtolower($part[$i]);
          break;
          }
          
      $struct->mimetype = 'multipart/'.$struct->ctype_secondary;

      // build parts list for headers pre-fetching
      for ($i=0, $count=0; $i<count($part); $i++)
        if (is_array($part[$i]) && count($part[$i]) > 3)
	  // fetch message headers if message/rfc822 or named part (could contain Content-Location header)
	  if (strtolower($part[$i][0]) == 'message' ||
	    (in_array('name', (array)$part[$i][2]) && (empty($part[$i][3]) || $part[$i][3]=='NIL'))) {
	    $part_headers[] = $struct->mime_id ? $struct->mime_id.'.'.($i+1) : $i+1;
	    }

      // pre-fetch headers of all parts (in one command for better performance)
      if ($part_headers)
        $part_headers = iil_C_FetchMIMEHeaders($this->conn, $this->mailbox, $this->_msg_id, $part_headers);

      $struct->parts = array();
      for ($i=0, $count=0; $i<count($part); $i++)
        if (is_array($part[$i]) && count($part[$i]) > 3) {
          $struct->parts[] = $this->_structure_part($part[$i], ++$count, $struct->mime_id,
		$part_headers[$struct->mime_id ? $struct->mime_id.'.'.($i+1) : $i+1]);
	}

      return $struct;
      }
    
    
    // regular part
    $struct->ctype_primary = strtolower($part[0]);
    $struct->ctype_secondary = strtolower($part[1]);
    $struct->mimetype = $struct->ctype_primary.'/'.$struct->ctype_secondary;

    // read content type parameters
    if (is_array($part[2]))
      {
      $struct->ctype_parameters = array();
      for ($i=0; $i<count($part[2]); $i+=2)
        $struct->ctype_parameters[strtolower($part[2][$i])] = $part[2][$i+1];
        
      if (isset($struct->ctype_parameters['charset']))
        $struct->charset = $struct->ctype_parameters['charset'];
      }
    
    // read content encoding
    if (!empty($part[5]) && $part[5]!='NIL')
      {
      $struct->encoding = strtolower($part[5]);
      $struct->headers['content-transfer-encoding'] = $struct->encoding;
      }
    
    // get part size
    if (!empty($part[6]) && $part[6]!='NIL')
      $struct->size = intval($part[6]);

    // read part disposition
    $di = count($part) - 2;
    if ((is_array($part[$di]) && count($part[$di]) == 2 && is_array($part[$di][1])) ||
        (is_array($part[--$di]) && count($part[$di]) == 2))
      {
      $struct->disposition = strtolower($part[$di][0]);

      if (is_array($part[$di][1]))
        for ($n=0; $n<count($part[$di][1]); $n+=2)
          $struct->d_parameters[strtolower($part[$di][1][$n])] = $part[$di][1][$n+1];
      }
      
    // get child parts
    if (is_array($part[8]) && $di != 8)
      {
      $struct->parts = array();
      for ($i=0, $count=0; $i<count($part[8]); $i++)
        if (is_array($part[8][$i]) && count($part[8][$i]) > 5)
          $struct->parts[] = $this->_structure_part($part[8][$i], ++$count, $struct->mime_id);
      }

    // get part ID
    if (!empty($part[3]) && $part[3]!='NIL')
      {
      $struct->content_id = $part[3];
      $struct->headers['content-id'] = $part[3];
    
      if (empty($struct->disposition))
        $struct->disposition = 'inline';
      }
    
    // fetch message headers if message/rfc822 or named part (could contain Content-Location header)
    if ($struct->ctype_primary == 'message' || ($struct->ctype_parameters['name'] && !$struct->content_id)) {
      if (empty($raw_headers))
        $raw_headers = iil_C_FetchPartHeader($this->conn, $this->mailbox, $this->_msg_id, false, $struct->mime_id);
      $struct->headers = $this->_parse_headers($raw_headers) + $struct->headers;
    }

    if ($struct->ctype_primary=='message') {
      if (is_array($part[8]) && empty($struct->parts))
        $struct->parts[] = $this->_structure_part($part[8], ++$count, $struct->mime_id);
    }

    // normalize filename property
    $this->_set_part_filename($struct, $raw_headers);

    return $struct;
    }
    

  /**
   * Set attachment filename from message part structure 
   *
   * @access private
   * @param  object rcube_message_part Part object
   * @param  string Part's raw headers
   */
  private function _set_part_filename(&$part, $headers=null)
    {
    if (!empty($part->d_parameters['filename']))
      $filename_mime = $part->d_parameters['filename'];
    else if (!empty($part->d_parameters['filename*']))
      $filename_encoded = $part->d_parameters['filename*'];
    else if (!empty($part->ctype_parameters['name*']))
      $filename_encoded = $part->ctype_parameters['name*'];
    // RFC2231 value continuations
    // TODO: this should be rewrited to support RFC2231 4.1 combinations
    else if (!empty($part->d_parameters['filename*0'])) {
      $i = 0;
      while (isset($part->d_parameters['filename*'.$i])) {
        $filename_mime .= $part->d_parameters['filename*'.$i];
        $i++;
      }
      // some servers (eg. dovecot-1.x) have no support for parameter value continuations
      // we must fetch and parse headers "manually"
      if ($i<2) {
	if (!$headers)
          $headers = iil_C_FetchPartHeader($this->conn, $this->mailbox, $this->_msg_id, false, $part->mime_id);
        $filename_mime = '';
        $i = 0;
        while (preg_match('/filename\*'.$i.'\s*=\s*"*([^"\n;]+)[";]*/', $headers, $matches)) {
          $filename_mime .= $matches[1];
          $i++;
        }
      }
    }
    else if (!empty($part->d_parameters['filename*0*'])) {
      $i = 0;
      while (isset($part->d_parameters['filename*'.$i.'*'])) {
        $filename_encoded .= $part->d_parameters['filename*'.$i.'*'];
        $i++;
      }
      if ($i<2) {
	if (!$headers)
          $headers = iil_C_FetchPartHeader($this->conn, $this->mailbox, $this->_msg_id, false, $part->mime_id);
        $filename_encoded = '';
        $i = 0; $matches = array();
        while (preg_match('/filename\*'.$i.'\*\s*=\s*"*([^"\n;]+)[";]*/', $headers, $matches)) {
          $filename_encoded .= $matches[1];
          $i++;
        }
      }
    }
    else if (!empty($part->ctype_parameters['name*0'])) {
      $i = 0;
      while (isset($part->ctype_parameters['name*'.$i])) {
        $filename_mime .= $part->ctype_parameters['name*'.$i];
        $i++;
      }
      if ($i<2) {
	if (!$headers)
          $headers = iil_C_FetchPartHeader($this->conn, $this->mailbox, $this->_msg_id, false, $part->mime_id);
        $filename_mime = '';
        $i = 0; $matches = array();
        while (preg_match('/\s+name\*'.$i.'\s*=\s*"*([^"\n;]+)[";]*/', $headers, $matches)) {
          $filename_mime .= $matches[1];
          $i++;
        }
      }
    }
    else if (!empty($part->ctype_parameters['name*0*'])) {
      $i = 0;
      while (isset($part->ctype_parameters['name*'.$i.'*'])) {
        $filename_encoded .= $part->ctype_parameters['name*'.$i.'*'];
        $i++;
      }
      if ($i<2) {
	if (!$headers)
          $headers = iil_C_FetchPartHeader($this->conn, $this->mailbox, $this->_msg_id, false, $part->mime_id);
        $filename_encoded = '';
        $i = 0; $matches = array();
        while (preg_match('/\s+name\*'.$i.'\*\s*=\s*"*([^"\n;]+)[";]*/', $headers, $matches)) {
          $filename_encoded .= $matches[1];
          $i++;
        }
      }
    }
    // read 'name' after rfc2231 parameters as it may contains truncated filename (from Thunderbird)
    else if (!empty($part->ctype_parameters['name']))
      $filename_mime = $part->ctype_parameters['name'];
    // Content-Disposition
    else if (!empty($part->headers['content-description']))
      $filename_mime = $part->headers['content-description'];
    else
      return;

    // decode filename
    if (!empty($filename_mime)) {
      $part->filename = rcube_imap::decode_mime_string($filename_mime, 
        $part->charset ? $part->charset : $this->struct_charset ? $this->struct_charset :
	    rc_detect_encoding($filename_mime, $this->default_charset));
      } 
    else if (!empty($filename_encoded)) {
      // decode filename according to RFC 2231, Section 4
      if (preg_match("/^([^']*)'[^']*'(.*)$/", $filename_encoded, $fmatches)) {
        $filename_charset = $fmatches[1];
        $filename_encoded = $fmatches[2];
        }
      $part->filename = rcube_charset_convert(urldecode($filename_encoded), $filename_charset);
      }
    }


  /**
   * Get charset name from message structure (first part)
   *
   * @access private
   * @param  array  Message structure
   * @return string Charset name
   */
  function _structure_charset($structure)
    {
      while (is_array($structure)) {
	if (is_array($structure[2]) && $structure[2][0] == 'charset')
	  return $structure[2][1];
	$structure = $structure[0];
	}
    } 


  /**
   * Fetch message body of a specific message from the server
   *
   * @param  int    Message UID
   * @param  string Part number
   * @param  object rcube_message_part Part object created by get_structure()
   * @param  mixed  True to print part, ressource to write part contents in
   * @param  resource File pointer to save the message part
   * @return string Message/part body if not printed
   */
  function &get_message_part($uid, $part=1, $o_part=NULL, $print=NULL, $fp=NULL)
    {
    // get part encoding if not provided
    if (!is_object($o_part))
      {
      $structure_str = iil_C_FetchStructureString($this->conn, $this->mailbox, $uid, true); 
      $structure = iml_GetRawStructureArray($structure_str);
      // error or message not found
      if (empty($structure))
        return false;

      $part_type = iml_GetPartTypeCode($structure, $part);
      $o_part = new rcube_message_part;
      $o_part->ctype_primary = $part_type==0 ? 'text' : ($part_type==2 ? 'message' : 'other');
      $o_part->encoding = strtolower(iml_GetPartEncodingString($structure, $part));
      $o_part->charset = iml_GetPartCharset($structure, $part);
      }
      
    // TODO: Add caching for message parts

    if (!$part) $part = 'TEXT';

    $body = iil_C_HandlePartBody($this->conn, $this->mailbox, $uid, true, $part,
        $o_part->encoding, $print, $fp);

    if ($fp || $print)
      return true;

    // convert charset (if text or message part)
    if ($o_part->ctype_primary=='text' || $o_part->ctype_primary=='message') {
      // assume default if no charset specified
      if (empty($o_part->charset) || strtolower($o_part->charset) == 'us-ascii')
        $o_part->charset = $this->default_charset;

      $body = rcube_charset_convert($body, $o_part->charset);
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
  function &get_body($uid, $part=1)
    {
    $headers = $this->get_headers($uid);
    return rcube_charset_convert($this->get_message_part($uid, $part, NULL),
      $headers->charset ? $headers->charset : $this->default_charset);
    }


  /**
   * Returns the whole message source as string
   *
   * @param int  Message UID
   * @return string Message source string
   */
  function &get_raw_body($uid)
    {
    return iil_C_HandlePartBody($this->conn, $this->mailbox, $uid, true);
    }


  /**
   * Returns the message headers as string
   *
   * @param int  Message UID
   * @return string Message headers string
   */
  function &get_raw_headers($uid)
    {
    return iil_C_FetchPartHeader($this->conn, $this->mailbox, $uid, true);
    }
    

  /**
   * Sends the whole message source to stdout
   *
   * @param int  Message UID
   */ 
  function print_raw_body($uid)
    {
    iil_C_HandlePartBody($this->conn, $this->mailbox, $uid, true, NULL, NULL, true);
    }


  /**
   * Set message flag to one or several messages
   *
   * @param mixed  Message UIDs as array or as comma-separated string
   * @param string Flag to set: SEEN, UNDELETED, DELETED, RECENT, ANSWERED, DRAFT, MDNSENT
   * @param string Folder name
   * @param boolean True to skip message cache clean up
   * @return boolean True on success, False on failure
   */
  function set_flag($uids, $flag, $mbox_name=NULL, $skip_cache=false)
    {
    $mailbox = $mbox_name ? $this->mod_mailbox($mbox_name) : $this->mailbox;

    $flag = strtoupper($flag);
    if (!is_array($uids))
      $uids = explode(',',$uids);
      
    if (strpos($flag, 'UN') === 0)
      $result = iil_C_UnFlag($this->conn, $mailbox, join(',', $uids), substr($flag, 2));
    else
      $result = iil_C_Flag($this->conn, $mailbox, join(',', $uids), $flag);

    // reload message headers if cached
    if ($this->caching_enabled && !$skip_cache) {
      $cache_key = $mailbox.'.msg';
      $this->remove_message_cache($cache_key, $uids);
      }

    // set nr of messages that were flaged
    $count = count($uids);

    // clear message count cache
    if ($result && $flag=='SEEN')
      $this->_set_messagecount($mailbox, 'UNSEEN', $count*(-1));
    else if ($result && $flag=='UNSEEN')
      $this->_set_messagecount($mailbox, 'UNSEEN', $count);
    else if ($result && $flag=='DELETED')
      $this->_set_messagecount($mailbox, 'ALL', $count*(-1));

    return $result;
    }


  /**
   * Remove message flag for one or several messages
   *
   * @param mixed  Message UIDs as array or as comma-separated string
   * @param string Flag to unset: SEEN, DELETED, RECENT, ANSWERED, DRAFT, MDNSENT
   * @param string Folder name
   * @return boolean True on success, False on failure
   * @see set_flag
   */
  function unset_flag($uids, $flag, $mbox_name=NULL)
    {
    return $this->set_flag($uids, 'UN'.$flag, $mbox_name);
    }


  /**
   * Append a mail message (source) to a specific mailbox
   *
   * @param string Target mailbox
   * @param string Message source
   * @return boolean True on success, False on error
   */
  function save_message($mbox_name, &$message)
    {
    $mailbox = $this->mod_mailbox($mbox_name);

    // make sure mailbox exists
    if (($mailbox == 'INBOX') || in_array($mailbox, $this->_list_mailboxes()))
      $saved = iil_C_Append($this->conn, $mailbox, $message);

    if ($saved)
      {
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
  function move_message($uids, $to_mbox, $from_mbox='')
    {
    $fbox = $from_mbox;
    $tbox = $to_mbox;
    $to_mbox = $this->mod_mailbox($to_mbox);
    $from_mbox = $from_mbox ? $this->mod_mailbox($from_mbox) : $this->mailbox;

    // make sure mailbox exists
    if ($to_mbox != 'INBOX' && !in_array($to_mbox, $this->_list_mailboxes()))
      {
      if (in_array($to_mbox_in, $this->default_folders))
        $this->create_mailbox($to_mbox_in, TRUE);
      else
        return FALSE;
      }

    // convert the list of uids to array
    $a_uids = is_string($uids) ? explode(',', $uids) : (is_array($uids) ? $uids : NULL);

    // exit if no message uids are specified
    if (!is_array($a_uids) || empty($a_uids))
      return false;

    // flag messages as read before moving them
    $config = rcmail::get_instance()->config;
    if ($config->get('read_when_deleted') && $tbox == $config->get('trash_mbox')) {
      // don't flush cache (4th argument)
      $this->set_flag($uids, 'SEEN', $fbox, true);
      }

    // move messages
    $iil_move = iil_C_Move($this->conn, join(',', $a_uids), $from_mbox, $to_mbox);
    $moved = !($iil_move === false || $iil_move < 0);
    
    // send expunge command in order to have the moved message
    // really deleted from the source mailbox
    if ($moved) {
      $this->_expunge($from_mbox, FALSE, $a_uids);
      $this->_clear_messagecount($from_mbox);
      $this->_clear_messagecount($to_mbox);
    }
    // moving failed
    else if (rcmail::get_instance()->config->get('delete_always', false)) {
      return iil_C_Delete($this->conn, $from_mbox, join(',', $a_uids));
    }

    // remove message ids from search set
    if ($moved && $this->search_set && $from_mbox == $this->mailbox) {
      foreach ($a_uids as $uid)
        $a_mids[] = $this->_uid2id($uid, $from_mbox);
      $this->search_set = array_diff($this->search_set, $a_mids);
    }

    // update cached message headers
    $cache_key = $from_mbox.'.msg';
    if ($moved && $start_index = $this->get_message_cache_index_min($cache_key, $a_uids)) {
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
  function delete_message($uids, $mbox_name='')
    {
    $mailbox = $mbox_name ? $this->mod_mailbox($mbox_name) : $this->mailbox;

    // convert the list of uids to array
    $a_uids = is_string($uids) ? explode(',', $uids) : (is_array($uids) ? $uids : NULL);
    
    // exit if no message uids are specified
    if (!is_array($a_uids) || empty($a_uids))
      return false;

    $deleted = iil_C_Delete($this->conn, $mailbox, join(',', $a_uids));

    // send expunge command in order to have the deleted message
    // really deleted from the mailbox
    if ($deleted)
      {
      $this->_expunge($mailbox, FALSE, $a_uids);
      $this->_clear_messagecount($mailbox);
      unset($this->uid_id_map[$mailbox]);
      }

    // remove message ids from search set
    if ($deleted && $this->search_set && $mailbox == $this->mailbox) {
      foreach ($a_uids as $uid)
        $a_mids[] = $this->_uid2id($uid, $mailbox);
      $this->search_set = array_diff($this->search_set, $a_mids);
    }

    // remove deleted messages from cache
    $cache_key = $mailbox.'.msg';
    if ($deleted && $start_index = $this->get_message_cache_index_min($cache_key, $a_uids)) {
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
  function clear_mailbox($mbox_name=NULL)
    {
    $mailbox = !empty($mbox_name) ? $this->mod_mailbox($mbox_name) : $this->mailbox;
    $msg_count = $this->_messagecount($mailbox, 'ALL');
    
    if ($msg_count>0)
      {
      $cleared = iil_C_ClearFolder($this->conn, $mailbox);
      
      // make sure the message count cache is cleared as well
      if ($cleared)
        {
        $this->clear_message_cache($mailbox.'.msg');      
        $a_mailbox_cache = $this->get_cache('messagecount');
        unset($a_mailbox_cache[$mailbox]);
        $this->update_cache('messagecount', $a_mailbox_cache);
        }
        
      return $cleared;
      }
    else
      return 0;
    }


  /**
   * Send IMAP expunge command and clear cache
   *
   * @param string Mailbox name
   * @param boolean False if cache should not be cleared
   * @return boolean True on success
   */
  function expunge($mbox_name='', $clear_cache=TRUE)
    {
    $mailbox = $mbox_name ? $this->mod_mailbox($mbox_name) : $this->mailbox;
    return $this->_expunge($mailbox, $clear_cache);
    }


  /**
   * Send IMAP expunge command and clear cache
   *
   * @see rcube_imap::expunge()
   * @param string 	Mailbox name
   * @param boolean 	False if cache should not be cleared
   * @param string 	List of UIDs to remove, separated by comma
   * @return boolean True on success
   * @access private
   */
  private function _expunge($mailbox, $clear_cache=TRUE, $uids=NULL)
    {
    if ($uids && $this->get_capability('UIDPLUS')) 
      $a_uids = is_array($uids) ? join(',', $uids) : $uids;
    else
      $a_uids = NULL;

    $result = iil_C_Expunge($this->conn, $mailbox, $a_uids);

    if ($result>=0 && $clear_cache)
      {
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
  function list_unsubscribed($root='')
    {
    static $sa_unsubscribed;
    
    if (is_array($sa_unsubscribed))
      return $sa_unsubscribed;
      
    // retrieve list of folders from IMAP server
    $a_mboxes = iil_C_ListMailboxes($this->conn, $this->mod_mailbox($root), '*');

    // modify names with root dir
    foreach ($a_mboxes as $mbox_name)
      {
      $name = $this->mod_mailbox($mbox_name, 'out');
      if (strlen($name))
        $a_folders[] = $name;
      }

    // filter folders and sort them
    $sa_unsubscribed = $this->_sort_mailbox_list($a_folders);
    return $sa_unsubscribed;
    }


  /**
   * Get mailbox quota information
   * added by Nuny
   * 
   * @return mixed Quota info or False if not supported
   */
  function get_quota()
    {
    if ($this->get_capability('QUOTA'))
      return iil_C_GetQuota($this->conn);
	
    return FALSE;
    }


  /**
   * Subscribe to a specific mailbox(es)
   *
   * @param array Mailbox name(s)
   * @return boolean True on success
   */ 
  function subscribe($a_mboxes)
    {
    if (!is_array($a_mboxes))
      $a_mboxes = array($a_mboxes);

    // let this common function do the main work
    return $this->_change_subscription($a_mboxes, 'subscribe');
    }


  /**
   * Unsubscribe mailboxes
   *
   * @param array Mailbox name(s)
   * @return boolean True on success
   */
  function unsubscribe($a_mboxes)
    {
    if (!is_array($a_mboxes))
      $a_mboxes = array($a_mboxes);

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
  function create_mailbox($name, $subscribe=FALSE)
    {
    $result = FALSE;
    
    // reduce mailbox name to 100 chars
    $name = substr($name, 0, 100);

    $abs_name = $this->mod_mailbox($name);
    $a_mailbox_cache = $this->get_cache('mailboxes');

    if (strlen($abs_name) && (!is_array($a_mailbox_cache) || !in_array($abs_name, $a_mailbox_cache)))
      $result = iil_C_CreateFolder($this->conn, $abs_name);

    // try to subscribe it
    if ($result && $subscribe)
      $this->subscribe($name);

    return $result ? $name : FALSE;
    }


  /**
   * Set a new name to an existing mailbox
   *
   * @param string Mailbox to rename (as utf-7 string)
   * @param string New mailbox name (as utf-7 string)
   * @return string Name of the renames mailbox, False on error
   */
  function rename_mailbox($mbox_name, $new_name)
    {
    $result = FALSE;

    // encode mailbox name and reduce it to 100 chars
    $name = substr($new_name, 0, 100);

    // make absolute path
    $mailbox = $this->mod_mailbox($mbox_name);
    $abs_name = $this->mod_mailbox($name);
    
    // check if mailbox is subscribed
    $a_subscribed = $this->_list_mailboxes();
    $subscribed = in_array($mailbox, $a_subscribed);
    
    // unsubscribe folder
    if ($subscribed)
      iil_C_UnSubscribe($this->conn, $mailbox);

    if (strlen($abs_name))
      $result = iil_C_RenameFolder($this->conn, $mailbox, $abs_name);

    if ($result)
      {
      $delm = $this->get_hierarchy_delimiter();
      
      // check if mailbox children are subscribed
      foreach ($a_subscribed as $c_subscribed)
        if (preg_match('/^'.preg_quote($mailbox.$delm, '/').'/', $c_subscribed))
          {
          iil_C_UnSubscribe($this->conn, $c_subscribed);
          iil_C_Subscribe($this->conn, preg_replace('/^'.preg_quote($mailbox, '/').'/', $abs_name, $c_subscribed));
          }

      // clear cache
      $this->clear_message_cache($mailbox.'.msg');
      $this->clear_cache('mailboxes');      
      }

    // try to subscribe it
    if ($result && $subscribed)
      iil_C_Subscribe($this->conn, $abs_name);

    return $result ? $name : FALSE;
    }


  /**
   * Remove mailboxes from server
   *
   * @param string Mailbox name(s) string/array
   * @return boolean True on success
   */
  function delete_mailbox($mbox_name)
    {
    $deleted = FALSE;

    if (is_array($mbox_name))
      $a_mboxes = $mbox_name;
    else if (is_string($mbox_name) && strlen($mbox_name))
      $a_mboxes = explode(',', $mbox_name);

    $all_mboxes = iil_C_ListMailboxes($this->conn, $this->mod_mailbox($root), '*');

    if (is_array($a_mboxes))
      foreach ($a_mboxes as $mbox_name)
        {
        $mailbox = $this->mod_mailbox($mbox_name);

        // unsubscribe mailbox before deleting
        iil_C_UnSubscribe($this->conn, $mailbox);

        // send delete command to server
        $result = iil_C_DeleteFolder($this->conn, $mailbox);
        if ($result >= 0) {
          $deleted = TRUE;
          $this->clear_message_cache($mailbox.'.msg');
	  }
	  
        foreach ($all_mboxes as $c_mbox)
          {
          $regex = preg_quote($mailbox . $this->delimiter, '/');
          $regex = '/^' . $regex . '/';
          if (preg_match($regex, $c_mbox))
            {
            iil_C_UnSubscribe($this->conn, $c_mbox);
            $result = iil_C_DeleteFolder($this->conn, $c_mbox);
            if ($result >= 0) {
              $deleted = TRUE;
    	      $this->clear_message_cache($c_mbox.'.msg');
              }
	    }
          }
        }

    // clear mailboxlist cache
    if ($deleted)
      $this->clear_cache('mailboxes');

    return $deleted;
    }


  /**
   * Create all folders specified as default
   */
  function create_default_folders()
    {
    $a_folders = iil_C_ListMailboxes($this->conn, $this->mod_mailbox(''), '*');
    $a_subscribed = iil_C_ListSubscribed($this->conn, $this->mod_mailbox(''), '*');
    
    // create default folders if they do not exist
    foreach ($this->default_folders as $folder)
      {
      $abs_name = $this->mod_mailbox($folder);
      if (!in_array_nocase($abs_name, $a_folders))
        $this->create_mailbox($folder, TRUE);
      else if (!in_array_nocase($abs_name, $a_subscribed))
        $this->subscribe($folder);
      }
    }



  /* --------------------------------
   *   internal caching methods
   * --------------------------------*/

  /**
   * @access private
   */
  function set_caching($set)
    {
    if ($set && is_object($this->db))
      $this->caching_enabled = TRUE;
    else
      $this->caching_enabled = FALSE;
    }

  /**
   * @access private
   */
  function get_cache($key)
    {
    // read cache (if it was not read before)
    if (!count($this->cache) && $this->caching_enabled)
      {
      return $this->_read_cache_record($key);
      }
    
    return $this->cache[$key];
    }

  /**
   * @access private
   */
  function update_cache($key, $data)
    {
    $this->cache[$key] = $data;
    $this->cache_changed = TRUE;
    $this->cache_changes[$key] = TRUE;
    }

  /**
   * @access private
   */
  function write_cache()
    {
    if ($this->caching_enabled && $this->cache_changed)
      {
      foreach ($this->cache as $key => $data)
        {
        if ($this->cache_changes[$key])
          $this->_write_cache_record($key, serialize($data));
        }
      }    
    }

  /**
   * @access private
   */
  function clear_cache($key=NULL)
    {
    if (!$this->caching_enabled)
      return;
    
    if ($key===NULL)
      {
      foreach ($this->cache as $key => $data)
        $this->_clear_cache_record($key);

      $this->cache = array();
      $this->cache_changed = FALSE;
      $this->cache_changes = array();
      }
    else
      {
      $this->_clear_cache_record($key);
      $this->cache_changes[$key] = FALSE;
      unset($this->cache[$key]);
      }
    }

  /**
   * @access private
   */
  private function _read_cache_record($key)
    {
    if ($this->db)
      {
      // get cached data from DB
      $sql_result = $this->db->query(
        "SELECT cache_id, data, cache_key
         FROM ".get_table_name('cache')."
         WHERE  user_id=?
	 AND cache_key LIKE 'IMAP.%'",
        $_SESSION['user_id']);

      while ($sql_arr = $this->db->fetch_assoc($sql_result))
        {
	$sql_key = preg_replace('/^IMAP\./', '', $sql_arr['cache_key']);
        $this->cache_keys[$sql_key] = $sql_arr['cache_id'];
	if (!isset($this->cache[$sql_key]))
	  $this->cache[$sql_key] = $sql_arr['data'] ? unserialize($sql_arr['data']) : FALSE;
        }
      }

    return $this->cache[$key];
    }

  /**
   * @access private
   */
  private function _write_cache_record($key, $data)
    {
    if (!$this->db)
      return FALSE;

    // update existing cache record
    if ($this->cache_keys[$key])
      {
      $this->db->query(
        "UPDATE ".get_table_name('cache')."
         SET    created=". $this->db->now().", data=?
         WHERE  user_id=?
         AND    cache_key=?",
        $data,
        $_SESSION['user_id'],
        'IMAP.'.$key);
      }
    // add new cache record
    else
      {
      $this->db->query(
        "INSERT INTO ".get_table_name('cache')."
         (created, user_id, cache_key, data)
         VALUES (".$this->db->now().", ?, ?, ?)",
        $_SESSION['user_id'],
        'IMAP.'.$key,
        $data);

      // get cache entry ID for this key
      $sql_result = $this->db->query(
        "SELECT cache_id
         FROM ".get_table_name('cache')."
         WHERE  user_id=?
         AND    cache_key=?",
        $_SESSION['user_id'],
        'IMAP.'.$key);
                                     
        if ($sql_arr = $this->db->fetch_assoc($sql_result))
          $this->cache_keys[$key] = $sql_arr['cache_id'];
      }
    }

  /**
   * @access private
   */
  private function _clear_cache_record($key)
    {
    $this->db->query(
      "DELETE FROM ".get_table_name('cache')."
       WHERE  user_id=?
       AND    cache_key=?",
      $_SESSION['user_id'],
      'IMAP.'.$key);
      
    unset($this->cache_keys[$key]);
    }



  /* --------------------------------
   *   message caching methods
   * --------------------------------*/
   

  /**
   * Checks if the cache is up-to-date
   *
   * @param string Mailbox name
   * @param string Internal cache key
   * @return int   Cache status: -3 = off, -2 = incomplete, -1 = dirty
   */
  private function check_cache_status($mailbox, $cache_key)
  {
    if (!$this->caching_enabled)
      return -3;

    $cache_index = $this->get_message_cache_index($cache_key);
    $msg_count = $this->_messagecount($mailbox);
    $cache_count = count($cache_index);

    // empty mailbox
    if (!$msg_count)
      return $cache_count ? -2 : 1;

    // @TODO: We've got one big performance problem in cache status checking method
    // E.g. mailbox contains 1000 messages, in cache table we've got first 100
    // of them. Now if we want to display only that 100 (which we've got)
    // check_cache_status returns 'incomplete' and messages are fetched
    // from IMAP instead of DB.

    if ($cache_count==$msg_count) {
      if ($this->skip_deleted) {
	$h_index = iil_C_FetchHeaderIndex($this->conn, $mailbox, "1:*", 'UID', $this->skip_deleted);

	if (sizeof($h_index) == $cache_count) {
	  $cache_index = array_flip($cache_index);
	  foreach ($h_index as $idx => $uid)
            unset($cache_index[$uid]);

	  if (empty($cache_index))
	    return 1;
	}
	return -2;
      } else {
        // get UID of message with highest index
        $uid = iil_C_ID2UID($this->conn, $mailbox, $msg_count);
        $cache_uid = array_pop($cache_index);
      
        // uids of highest message matches -> cache seems OK
        if ($cache_uid == $uid)
          return 1;
      }
      // cache is dirty
      return -1;
    }
    // if cache count differs less than 10% report as dirty
    else if (abs($msg_count - $cache_count) < $msg_count/10)
      return -1;
    else
      return -2;
  }

  /**
   * @access private
   */
  private function get_message_cache($key, $from, $to, $sort_field, $sort_order)
    {
    $cache_key = "$key:$from:$to:$sort_field:$sort_order";
    
    $config = rcmail::get_instance()->config;

    // use idx sort as default sorting
    if (!$sort_field || !in_array($sort_field, $this->db_header_fields)) {
      $sort_field = 'idx';
      }
    
    if ($this->caching_enabled && !isset($this->cache[$cache_key]))
      {
      $this->cache[$cache_key] = array();
      $sql_result = $this->db->limitquery(
        "SELECT idx, uid, headers
         FROM ".get_table_name('messages')."
         WHERE  user_id=?
         AND    cache_key=?
         ORDER BY ".$this->db->quoteIdentifier($sort_field)." ".strtoupper($sort_order),
        $from,
        $to-$from,
        $_SESSION['user_id'],
        $key);

      while ($sql_arr = $this->db->fetch_assoc($sql_result))
        {
        $uid = $sql_arr['uid'];
        $this->cache[$cache_key][$uid] =  $this->db->decode(unserialize($sql_arr['headers']));

        // featch headers if unserialize failed
        if (empty($this->cache[$cache_key][$uid]))
          $this->cache[$cache_key][$uid] = iil_C_FetchHeader($this->conn, preg_replace('/.msg$/', '', $key), $uid, true, $this->fetch_add_headers);
        }
      }

    return $this->cache[$cache_key];
    }

  /**
   * @access private
   */
  private function &get_cached_message($key, $uid)
    {
    $internal_key = '__single_msg';
    
    if ($this->caching_enabled && !isset($this->cache[$internal_key][$uid]))
      {
      $sql_result = $this->db->query(
        "SELECT idx, headers, structure
         FROM ".get_table_name('messages')."
         WHERE  user_id=?
         AND    cache_key=?
         AND    uid=?",
        $_SESSION['user_id'],
        $key,
        $uid);

      if ($sql_arr = $this->db->fetch_assoc($sql_result))
        {
	$this->uid_id_map[preg_replace('/\.msg$/', '', $key)][$uid] = $sql_arr['idx'];
        $this->cache[$internal_key][$uid] = $this->db->decode(unserialize($sql_arr['headers']));
        if (is_object($this->cache[$internal_key][$uid]) && !empty($sql_arr['structure']))
          $this->cache[$internal_key][$uid]->structure = $this->db->decode(unserialize($sql_arr['structure']));
        }
      }

    return $this->cache[$internal_key][$uid];
    }

  /**
   * @access private
   */  
  private function get_message_cache_index($key, $force=FALSE, $sort_field='idx', $sort_order='ASC')
    {
    static $sa_message_index = array();
    
    // empty key -> empty array
    if (!$this->caching_enabled || empty($key))
      return array();
    
    if (!empty($sa_message_index[$key]) && !$force)
      return $sa_message_index[$key];

    // use idx sort as default
    if (!$sort_field || !in_array($sort_field, $this->db_header_fields))
      $sort_field = 'idx';
    
    $sa_message_index[$key] = array();
    $sql_result = $this->db->query(
      "SELECT idx, uid
       FROM ".get_table_name('messages')."
       WHERE  user_id=?
       AND    cache_key=?
       ORDER BY ".$this->db->quote_identifier($sort_field)." ".$sort_order,
      $_SESSION['user_id'],
      $key);

    while ($sql_arr = $this->db->fetch_assoc($sql_result))
      $sa_message_index[$key][$sql_arr['idx']] = $sql_arr['uid'];
      
    return $sa_message_index[$key];
    }

  /**
   * @access private
   */
  private function add_message_cache($key, $index, $headers, $struct=null, $force=false)
    {
    if (empty($key) || !is_object($headers) || empty($headers->uid))
        return;

    // add to internal (fast) cache
    $this->cache['__single_msg'][$headers->uid] = clone $headers;
    $this->cache['__single_msg'][$headers->uid]->structure = $struct;

    // no further caching
    if (!$this->caching_enabled)
      return;
    
    // check for an existing record (probly headers are cached but structure not)
    if (!$force) {
      $sql_result = $this->db->query(
        "SELECT message_id
         FROM ".get_table_name('messages')."
         WHERE  user_id=?
         AND    cache_key=?
         AND    uid=?",
        $_SESSION['user_id'],
        $key,
        $headers->uid);
      if ($sql_arr = $this->db->fetch_assoc($sql_result))
        $message_id = $sql_arr['message_id'];
      }

    // update cache record
    if ($message_id)
      {
      $this->db->query(
        "UPDATE ".get_table_name('messages')."
         SET   idx=?, headers=?, structure=?
         WHERE message_id=?",
        $index,
        serialize($this->db->encode(clone $headers)),
        is_object($struct) ? serialize($this->db->encode(clone $struct)) : NULL,
        $message_id
        );
      }
    else  // insert new record
      {
      $this->db->query(
        "INSERT INTO ".get_table_name('messages')."
         (user_id, del, cache_key, created, idx, uid, subject, ".$this->db->quoteIdentifier('from').", ".$this->db->quoteIdentifier('to').", cc, date, size, headers, structure)
         VALUES (?, 0, ?, ".$this->db->now().", ?, ?, ?, ?, ?, ?, ".$this->db->fromunixtime($headers->timestamp).", ?, ?, ?)",
        $_SESSION['user_id'],
        $key,
        $index,
        $headers->uid,
        (string)mb_substr($this->db->encode($this->decode_header($headers->subject, TRUE)), 0, 128),
        (string)mb_substr($this->db->encode($this->decode_header($headers->from, TRUE)), 0, 128),
        (string)mb_substr($this->db->encode($this->decode_header($headers->to, TRUE)), 0, 128),
        (string)mb_substr($this->db->encode($this->decode_header($headers->cc, TRUE)), 0, 128),
        (int)$headers->size,
        serialize($this->db->encode(clone $headers)),
        is_object($struct) ? serialize($this->db->encode(clone $struct)) : NULL
        );
      }
    }
    
  /**
   * @access private
   */
  private function remove_message_cache($key, $ids, $idx=false)
    {
    if (!$this->caching_enabled)
      return;
    
    $this->db->query(
      "DELETE FROM ".get_table_name('messages')."
      WHERE user_id=?
      AND cache_key=?
      AND ".($idx ? "idx" : "uid")." IN (".$this->db->array2list($ids, 'integer').")",
      $_SESSION['user_id'],
      $key);
    }

  /**
   * @access private
   */
  private function clear_message_cache($key, $start_index=1)
    {
    if (!$this->caching_enabled)
      return;
    
    $this->db->query(
      "DELETE FROM ".get_table_name('messages')."
       WHERE user_id=?
       AND cache_key=?
       AND idx>=?",
      $_SESSION['user_id'], $key, $start_index);
    }

  /**
   * @access private
   */
  private function get_message_cache_index_min($key, $uids=NULL)
    {
    if (!$this->caching_enabled)
      return;
    
    $sql_result = $this->db->query(
      "SELECT MIN(idx) AS minidx
      FROM ".get_table_name('messages')."
      WHERE  user_id=?
      AND    cache_key=?"
      .(!empty($uids) ? " AND uid IN (".$this->db->array2list($uids, 'integer').")" : ''),
      $_SESSION['user_id'],
      $key);

    if ($sql_arr = $this->db->fetch_assoc($sql_result))
      return $sql_arr['minidx'];
    else
      return 0;  
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
  function decode_address_list($input, $max=null, $decode=true)
    {
    $a = $this->_parse_address_list($input, $decode);
    $out = array();
    // Special chars as defined by RFC 822 need to in quoted string (or escaped).
    $special_chars = '[\(\)\<\>\\\.\[\]@,;:"]';
    
    if (!is_array($a))
      return $out;

    $c = count($a);
    $j = 0;

    foreach ($a as $val)
      {
      $j++;
      $address = $val['address'];
      $name = preg_replace(array('/^[\'"]/', '/[\'"]$/'), '', trim($val['name']));
      if ($name && $address && $name != $address)
        $string = sprintf('%s <%s>', preg_match("/$special_chars/", $name) ? '"'.addcslashes($name, '"').'"' : $name, $address);
      else if ($address)
        $string = $address;
      else if ($name)
        $string = $name;
      
      $out[$j] = array('name' => $name,
                       'mailto' => $address,
                       'string' => $string);
              
      if ($max && $j==$max)
        break;
      }
    
    return $out;
    }
  
  
  /**
   * Decode a Microsoft Outlook TNEF part (winmail.dat)
   *
   * @param object rcube_message_part Message part to decode
   * @param string UID of the message
   * @return array List of rcube_message_parts extracted from windmail.dat
   */
  function tnef_decode(&$part, $uid)
  {
    if (!isset($part->body))
      $part->body = $this->get_message_part($uid, $part->mime_id, $part);

    $pid = 0;
    $tnef_parts = array();
    $tnef_arr = tnef_decode($part->body);
    foreach ($tnef_arr as $winatt) {
      $tpart = new rcube_message_part;
      $tpart->filename = $winatt["name"];
      $tpart->encoding = 'stream';
      $tpart->ctype_primary = $winatt["type0"];
      $tpart->ctype_secondary = $winatt["type1"];
      $tpart->mimetype = strtolower($winatt["type0"] . "/" . $winatt["type1"]);
      $tpart->mime_id = "winmail." . $part->mime_id . ".$pid";
      $tpart->size = $winatt["size"];
      $tpart->body = $winatt['stream'];
      
      $tnef_parts[] = $tpart;
      $pid++;
    }

    return $tnef_parts;
  }


  /**
   * Decode a message header value
   *
   * @param string  Header value
   * @param boolean Remove quotes if necessary
   * @return string Decoded string
   */
  function decode_header($input, $remove_quotes=FALSE)
    {
    $str = rcube_imap::decode_mime_string((string)$input, $this->default_charset);
    if ($str{0}=='"' && $remove_quotes)
      $str = str_replace('"', '', $str);
    
    return $str;
    }


  /**
   * Decode a mime-encoded string to internal charset
   *
   * @param string $input    Header value
   * @param string $fallback Fallback charset if none specified
   *
   * @return string Decoded string
   * @static
   */
  public static function decode_mime_string($input, $fallback=null)
    {
    // Initialize variable
    $out = '';

    // Iterate instead of recursing, this way if there are too many values we don't have stack overflows
    // rfc: all line breaks or other characters not found 
    // in the Base64 Alphabet must be ignored by decoding software
    // delete all blanks between MIME-lines, differently we can 
    // receive unnecessary blanks and broken utf-8 symbols
    $input = preg_replace("/\?=\s+=\?/", '?==?', $input);

    // Check if there is stuff to decode
    if (strpos($input, '=?') !== false) {
      // Loop through the string to decode all occurences of =? ?= into the variable $out 
      while(($pos = strpos($input, '=?')) !== false) {
        // Append everything that is before the text to be decoded
        $out .= substr($input, 0, $pos);

        // Get the location of the text to decode
        $end_cs_pos = strpos($input, "?", $pos+2);
        $end_en_pos = strpos($input, "?", $end_cs_pos+1);
        $end_pos = strpos($input, "?=", $end_en_pos+1);

        // Extract the encoded string
        $encstr = substr($input, $pos+2, ($end_pos-$pos-2));
        // Extract the remaining string
        $input = substr($input, $end_pos+2);

        // Decode the string fragement
        $out .= rcube_imap::_decode_mime_string_part($encstr);
      }

      // Deocde the rest (if any)
      if (strlen($input) != 0)
         $out .= rcube_imap::decode_mime_string($input, $fallback);

       // return the results
      return $out;
    }

    // no encoding information, use fallback
    return rcube_charset_convert($input, 
      !empty($fallback) ? $fallback : rcmail::get_instance()->config->get('default_charset', 'ISO-8859-1'));
    }


  /**
   * Decode a part of a mime-encoded string
   *
   * @access private
   */
  private function _decode_mime_string_part($str)
    {
    $a = explode('?', $str);
    $count = count($a);

    // should be in format "charset?encoding?base64_string"
    if ($count >= 3)
      {
      for ($i=2; $i<$count; $i++)
        $rest.=$a[$i];

      if (($a[1]=="B")||($a[1]=="b"))
        $rest = base64_decode($rest);
      else if (($a[1]=="Q")||($a[1]=="q"))
        {
        $rest = str_replace("_", " ", $rest);
        $rest = quoted_printable_decode($rest);
        }

      return rcube_charset_convert($rest, $a[0]);
      }
    else
      return $str;    // we dont' know what to do with this  
    }


  /**
   * Decode a mime part
   *
   * @param string Input string
   * @param string Part encoding
   * @return string Decoded string
   */
  function mime_decode($input, $encoding='7bit')
    {
    switch (strtolower($encoding))
      {
      case 'quoted-printable':
        return quoted_printable_decode($input);
        break;
      
      case 'base64':
        return base64_decode($input);
        break;

      case 'x-uuencode':
      case 'x-uue':
      case 'uue':
      case 'uuencode':
        return convert_uudecode($input);
        break;
						      
      case '7bit':
      default:
        return $input;
      }
    }


  /**
   * Convert body charset to RCMAIL_CHARSET according to the ctype_parameters
   *
   * @param string Part body to decode
   * @param string Charset to convert from
   * @return string Content converted to internal charset
   */
  function charset_decode($body, $ctype_param)
    {
    if (is_array($ctype_param) && !empty($ctype_param['charset']))
      return rcube_charset_convert($body, $ctype_param['charset']);

    // defaults to what is specified in the class header
    return rcube_charset_convert($body,  $this->default_charset);
    }


  /**
   * Translate UID to message ID
   *
   * @param int    Message UID
   * @param string Mailbox name
   * @return int   Message ID
   */
  function get_id($uid, $mbox_name=NULL) 
    {
      $mailbox = $mbox_name ? $this->mod_mailbox($mbox_name) : $this->mailbox;
      return $this->_uid2id($uid, $mailbox);
    }


  /**
   * Translate message number to UID
   *
   * @param int    Message ID
   * @param string Mailbox name
   * @return int   Message UID
   */
  function get_uid($id,$mbox_name=NULL)
    {
      $mailbox = $mbox_name ? $this->mod_mailbox($mbox_name) : $this->mailbox;
      return $this->_id2uid($id, $mailbox);
    }


  /**
   * Modify folder name for input/output according to root dir and namespace
   *
   * @param string  Folder name
   * @param string  Mode
   * @return string Folder name
   */
  function mod_mailbox($mbox_name, $mode='in')
    {
    if ((!empty($this->root_ns) && $this->root_ns == $mbox_name) || $mbox_name == 'INBOX')
      return $mbox_name;

    if (!empty($this->root_dir) && $mode=='in') 
      $mbox_name = $this->root_dir.$this->delimiter.$mbox_name;
    else if (strlen($this->root_dir) && $mode=='out') 
      $mbox_name = substr($mbox_name, strlen($this->root_dir)+1);

    return $mbox_name;
    }


  /* --------------------------------
   *         private methods
   * --------------------------------*/

  /**
   * Validate the given input and save to local properties
   * @access private
   */
  private function _set_sort_order($sort_field, $sort_order)
  {
    if ($sort_field != null)
      $this->sort_field = asciiwords($sort_field);
    if ($sort_order != null)
      $this->sort_order = strtoupper($sort_order) == 'DESC' ? 'DESC' : 'ASC';
  }

  /**
   * Sort mailboxes first by default folders and then in alphabethical order
   * @access private
   */
  private function _sort_mailbox_list($a_folders)
    {
    $a_out = $a_defaults = $folders = array();

    $delimiter = $this->get_hierarchy_delimiter();

    // find default folders and skip folders starting with '.'
    foreach ($a_folders as $i => $folder)
      {
      if ($folder{0}=='.')
        continue;

      if (($p = array_search(strtolower($folder), $this->default_folders_lc)) !== false && !$a_defaults[$p])
        $a_defaults[$p] = $folder;
      else
        $folders[$folder] = mb_strtolower(rcube_charset_convert($folder, 'UTF7-IMAP'));
      }

    // sort folders and place defaults on the top
    asort($folders, SORT_LOCALE_STRING);
    ksort($a_defaults);
    $folders = array_merge($a_defaults, array_keys($folders));

    // finally we must rebuild the list to move 
    // subfolders of default folders to their place...
    // ...also do this for the rest of folders because
    // asort() is not properly sorting case sensitive names
    while (list($key, $folder) = each($folders)) {
      // set the type of folder name variable (#1485527) 
      $a_out[] = (string) $folder;
      unset($folders[$key]);
      $this->_rsort($folder, $delimiter, $folders, $a_out);	
      }

    return $a_out;
    }


  /**
   * @access private
   */
  private function _rsort($folder, $delimiter, &$list, &$out)
    {
      while (list($key, $name) = each($list)) {
	if (strpos($name, $folder.$delimiter) === 0) {
	  // set the type of folder name variable (#1485527) 
    	  $out[] = (string) $name;
	  unset($list[$key]);
	  $this->_rsort($name, $delimiter, $list, $out);
	  }
        }
      reset($list);	
    }


  /**
   * @access private
   */
  private function _uid2id($uid, $mbox_name=NULL)
    {
    if (!$mbox_name)
      $mbox_name = $this->mailbox;
      
    if (!isset($this->uid_id_map[$mbox_name][$uid]))
      $this->uid_id_map[$mbox_name][$uid] = iil_C_UID2ID($this->conn, $mbox_name, $uid);

    return $this->uid_id_map[$mbox_name][$uid];
    }

  /**
   * @access private
   */
  private function _id2uid($id, $mbox_name=NULL)
    {
    if (!$mbox_name)
      $mbox_name = $this->mailbox;
      
    $index = array_flip((array)$this->uid_id_map[$mbox_name]);
    if (isset($index[$id]))
      $uid = $index[$id];
    else
      {
      $uid = iil_C_ID2UID($this->conn, $mbox_name, $id);
      $this->uid_id_map[$mbox_name][$uid] = $id;
      }
    
    return $uid;
    }


  /**
   * Subscribe/unsubscribe a list of mailboxes and update local cache
   * @access private
   */
  private function _change_subscription($a_mboxes, $mode)
    {
    $updated = FALSE;

    if (is_array($a_mboxes))
      foreach ($a_mboxes as $i => $mbox_name)
        {
        $mailbox = $this->mod_mailbox($mbox_name);
        $a_mboxes[$i] = $mailbox;

        if ($mode=='subscribe')
          $updated = iil_C_Subscribe($this->conn, $mailbox);
        else if ($mode=='unsubscribe')
          $updated = iil_C_UnSubscribe($this->conn, $mailbox);
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


  /**
   * Increde/decrese messagecount for a specific mailbox
   * @access private
   */
  private function _set_messagecount($mbox_name, $mode, $increment)
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


  /**
   * Remove messagecount of a specific mailbox from cache
   * @access private
   */
  private function _clear_messagecount($mbox_name='')
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


  /**
   * Split RFC822 header string into an associative array
   * @access private
   */
  private function _parse_headers($headers)
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


  /**
   * @access private
   */
  private function _parse_address_list($str, $decode=true)
    {
    // remove any newlines and carriage returns before
    $a = rcube_explode_quoted_string('[,;]', preg_replace( "/[\r\n]/", " ", $str));
    $result = array();

    foreach ($a as $key => $val)
      {
      $val = preg_replace("/([\"\w])</", "$1 <", $val);
      $sub_a = rcube_explode_quoted_string(' ', $decode ? $this->decode_header($val) : $val);
      $result[$key]['name'] = '';

      foreach ($sub_a as $k => $v)
        {
	// use angle brackets in regexp to not handle names with @ sign
        if (preg_match('/^<\S+@\S+>$/', $v))
          $result[$key]['address'] = trim($v, '<>');
        else
          $result[$key]['name'] .= (empty($result[$key]['name'])?'':' ').str_replace("\"",'',stripslashes($v));
        }
        
      if (empty($result[$key]['name']))
        $result[$key]['name'] = $result[$key]['address'];        
      elseif (empty($result[$key]['address']))
        $result[$key]['address'] = $result[$key]['name'];
      }
    
    return $result;
    }

}  // end class rcube_imap


/**
 * Class representing a message part
 *
 * @package Mail
 */
class rcube_message_part
{
  var $mime_id = '';
  var $ctype_primary = 'text';
  var $ctype_secondary = 'plain';
  var $mimetype = 'text/plain';
  var $disposition = '';
  var $filename = '';
  var $encoding = '8bit';
  var $charset = '';
  var $size = 0;
  var $headers = array();
  var $d_parameters = array();
  var $ctype_parameters = array();

  function __clone()
  {
    if (isset($this->parts))
      foreach ($this->parts as $idx => $part)
        if (is_object($part))
	  $this->parts[$idx] = clone $part;
  }				
}


/**
 * Class for sorting an array of iilBasicHeader objects in a predetermined order.
 *
 * @package Mail
 * @author Eric Stadtherr
 */
class rcube_header_sorter
{
   var $sequence_numbers = array();
   
   /**
    * Set the predetermined sort order.
    *
    * @param array Numerically indexed array of IMAP message sequence numbers
    */
   function set_sequence_numbers($seqnums)
   {
      $this->sequence_numbers = array_flip($seqnums);
   }
 
   /**
    * Sort the array of header objects
    *
    * @param array Array of iilBasicHeader objects indexed by UID
    */
   function sort_headers(&$headers)
   {
      /*
       * uksort would work if the keys were the sequence number, but unfortunately
       * the keys are the UIDs.  We'll use uasort instead and dereference the value
       * to get the sequence number (in the "id" field).
       * 
       * uksort($headers, array($this, "compare_seqnums")); 
       */
       uasort($headers, array($this, "compare_seqnums"));
   }
 
   /**
    * Sort method called by uasort()
    */
   function compare_seqnums($a, $b)
   {
      // First get the sequence number from the header object (the 'id' field).
      $seqa = $a->id;
      $seqb = $b->id;
      
      // then find each sequence number in my ordered list
      $posa = isset($this->sequence_numbers[$seqa]) ? intval($this->sequence_numbers[$seqa]) : -1;
      $posb = isset($this->sequence_numbers[$seqb]) ? intval($this->sequence_numbers[$seqb]) : -1;
      
      // return the relative position as the comparison value
      return $posa - $posb;
   }
}
