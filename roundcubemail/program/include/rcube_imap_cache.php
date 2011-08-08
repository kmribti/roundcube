<?php

/*
 +-----------------------------------------------------------------------+
 | program/include/rcube_imap_cache.php                                  |
 |                                                                       |
 | This file is part of the Roundcube Webmail client                     |
 | Copyright (C) 2005-2011, The Roundcube Dev Team                       |
 | Licensed under the GNU GPL                                            |
 |                                                                       |
 | PURPOSE:                                                              |
 |   Caching of IMAP folder contents (messages and index)                |
 |                                                                       |
 +-----------------------------------------------------------------------+
 | Author: Thomas Bruederli <roundcube@gmail.com>                        |
 | Author: Aleksander Machniak <alec@alec.pl>                            |
 +-----------------------------------------------------------------------+

 $Id$

*/


/**
 * Interface class for accessing Roundcube messages cache
 *
 * @package    Cache
 * @author     Thomas Bruederli <roundcube@gmail.com>
 * @author     Aleksander Machniak <alec@alec.pl>
 * @version    1.0
 */
class rcube_imap_cache
{
    /**
     * Instance of rcube_imap
     *
     * @var rcube_imap
     */
    public $imap;

    /**
     * Instance of rcube_mdb2
     *
     * @var rcube_mdb2
     */
    private $db;

    /**
     * User ID
     *
     * @var int
     */
    private $userid;

    /**
     * Internal (in-memory) cache
     *
     * @var array
     */
    private $icache = array();

    private $skip_deleted = false;

    /**
     * Object constructor.
     */
    function __construct($db, $imap, $userid, $skip_deleted)
    {
        $this->db           = $db;
        $this->imap         = $imap;
        $this->userid       = (int)$userid;
        $this->skip_deleted = $skip_deleted;
    }


    public function close()
    {
    }


    /**
     * Return (sorted) messages index.
     * If index doesn't exist or is invalid, will be updated.
     *
     * @param string  $mailbox     Folder name
     * @param string  $sort_field  Sorting column
     * @param string  $sort_order  Sorting order (ASC|DESC)
     *
     * @return array Messages index
     */
    function get_index($mailbox, $sort_field = null, $sort_order = null)
    {
        if (empty($this->icache[$mailbox]))
            $this->icache[$mailbox] = array();

        $sort_order = strtoupper($sort_order) == 'ASC' ? 'ASC' : 'DESC';

        // Seek in internal cache
        if (array_key_exists('index', $this->icache[$mailbox])
            && ($sort_field == 'ANY' || $this->icache[$mailbox]['index']['sort_field'] == $sort_field)
        ) {
            if ($this->icache[$mailbox]['index']['sort_order'] == $sort_order)
                return $this->icache[$mailbox]['index']['result'];
            else
                return array_reverse($this->icache[$mailbox]['index']['result'], true);
        }

        // Get index from DB
        $index = $this->get_index_row($mailbox, $sort_field);

        // Get mailbox data (UIDVALIDITY, counters, etc.) for status check
        $mbox_data = $this->imap->mailbox_data($mailbox);
        $data      = null;

        // @TODO: Think about skipping validation checks.
        // If we could check only every 10 minutes, we would be able to skip
        // expensive checks, mailbox selection or even IMAP connection, this would require
        // additional logic to force cache invalidation in some cases
        // and many rcube_imap changes to connect when needed

        // Entry exist, check cache status
        if (!empty($index)) {
            $exists = true;
            if ($sort_field == 'ANY') {
                $sort_field = $index['sort_field'];
            }

            // Check UIDVALIDITY
            if ($index['validity'] != $mbox_data['UIDVALIDITY']) {
                // the whole cache (all folders) is invalid
                $this->clear();
                $index = null;
                $exists = false;
            }
            // Folder is empty but cache isn't
            else if (!$mbox_data['EXISTS'] && !empty($index['seq'])) {
                $this->clear($mailbox);
                $index = null; // cache invalid
                $exists = false;
            }
            // Checks for skip_deleted=true
            else if (!empty($this->skip_deleted)) {
                // stored index was created with skip_deleted disabled
                if (empty($index['deleted'])) {
                    $index = null; // cache invalid
                }
                // compare counts if available
                else if ($mbox_data['COUNT_UNDELETED'] != null
                    && $mbox_data['COUNT_UNDELETED'] != count($index['uid'])) {
                    $index = null; // cache invalid
                }
                // compare UID sets
                else if ($mbox_data['ALL_UNDELETED'] != null) {
                    $uids_new = rcube_imap_generic::uncompressMessageSet($mbox_data['ALL_UNDELETED']);
                    $uids_old = $index['uid'];

                    if (count($uids_new) != count($uids_old)) {
                            $index = null; // cache invalid
                    }
                    else {
                        sort($uids_new, SORT_NUMERIC);
                        sort($uids_old, SORT_NUMERIC);

                        if ($uids_old != $uids_new)
                            $index = null; // cache invalid
                    }
                }
                else {
                    // get all undeleted messages excluding cached UIDs
                    $ids = $this->imap->search_once($mailbox, 'ALL UNDELETED NOT UID '.
                        rcube_imap_generic::compressMessageSet($index['uid']));

                    if (!empty($ids)) {
                        $index = null; // cache invalid
                    }
                }
            }
            else {
                // stored index was created with skip_deleted enabled
                if (!empty($index['deleted'])) {
                    $index = null; // cache invalid
                }
                // check messages number...
                else if ($mbox_data['EXISTS'] != max($index['seq'])
                    // ... and max UID
                    || max($index['uid']) != $this->imap->id2uid($mbox_data['EXISTS'], $mailbox, true)
                ) {
                    $index = null;
                }
            }

            if (!empty($index)) {
                // build index, assign sequence IDs to unique IDs
                $data = array_combine($index['seq'], $index['uid']);
                // revert the order if needed
                if ($index['sort_order'] != $sort_order)
                    $data = array_reverse($data, true);
            }
        }
        else if ($sort_field == 'ANY') {
            $sort_field = '';
        }

        // Index not found or not valid, get index from IMAP server
        if ($data === null) {
            $data = array();
            if ($mbox_data['EXISTS']) {
                // fetch sorted sequence numbers
                $data_seq = $this->imap->message_index_direct($mailbox, $sort_field, $sort_order);
                // fetch UIDs
                if (!empty($data_seq)) {
                    // Seek in internal cache
                    if (array_key_exists('index', $this->icache[$mailbox]))
                        $data_uid = $this->icache[$mailbox]['index']['result'];
                    else
                        $data_uid = $this->imap->conn->fetchUIDs($mailbox, $data_seq);

                    // build index
                    if (!empty($data_uid)) {
                        foreach ($data_seq as $seq)
                            if ($uid = $data_uid[$seq])
                                $data[$seq] = $uid;
                    }
                }
            }

            $in_data = implode(':', array(
                implode(',', array_keys($data)),
                implode(',', array_values($data)),
                $sort_order,
                (int) $this->skip_deleted,
                (int) $mbox_data['UIDVALIDITY'],
            ));

            if ($exists)
                $sql_result = $this->db->query(
                    "UPDATE ".get_table_name('cache_index')
                    ." SET data = ?, changed = ".$this->db->now()
                    ." WHERE user_id = ?"
                        ." AND mailbox = ?"
                        ." AND sort_field = ?",
                    $in_data, $this->userid, $mailbox, (string)$sort_field);
            else
                $sql_result = $this->db->query(
                    "INSERT INTO ".get_table_name('cache_index')
                    ." (user_id, mailbox, sort_field, threaded, data, changed)"
                    ." VALUES (?, ?, ?, 0, ?, ".$this->db->now().")",
                    $this->userid, $mailbox, (string)$sort_field, $in_data);
        }

        $this->icache[$mailbox]['index'] = array(
            'result'     => $data,
            'sort_field' => $sort_field,
            'sort_order' => $sort_order,
        );

        return $this->icache[$mailbox]['index']['result'];
    }


    /**
     * Returns list of messages (headers). See rcube_imap::fetch_headers().
     *
     * @param string $mailbox  Folder name
     * @param array  $msgs     Message sequence numbers
     * @param bool   $is_uid   True if $msgs contains message UIDs
     *
     * @return array The list of messages (rcube_mail_header) indexed by UID
     */
    function get_messages($mailbox, $msgs = array(), $is_uid = true)
    {
        if (empty($msgs)) {
            return array();
        }

        // Convert IDs to UIDs
        // @TODO: it would be nice if we could work with UIDs only
        // then, e.g. when fetching search result, index would be not needed
        if (!$is_uid) {
            $index = $this->get_index($mailbox);
            foreach ($msgs as $idx => $msgid)
                if ($uid = $index[$msgid])
                    $msgs[$idx] = $uid;
        }

        // Fetch messages from cache
        $sql_result = $this->db->query(
            "SELECT uid, data"
            ." FROM ".get_table_name('cache_messages')
            ." WHERE user_id = ?"
                ." AND mailbox = ?"
                ." AND uid IN (".$this->db->array2list($msgs, 'integer').")",
            $this->userid, $mailbox);

        $msgs   = array_flip($msgs);
        $result = array();

        while ($sql_arr = $this->db->fetch_assoc($sql_result)) {
            $uid          = intval($sql_arr['uid']);
            $result[$uid] = $this->db->decode(unserialize($sql_arr['data']));
//@TODO: update message ID according to index data?

            if (!empty($result[$uid])) {
                unset($msgs[$uid]);
            }
        }

        // Fetch not found messages from IMAP server
        if (!empty($msgs)) {
            $messages = $this->imap->fetch_headers($mailbox, array_keys($msgs), true, true);

            // Insert to DB and add to result list
            if (!empty($messages)) {
                foreach ($messages as $msg) {
                    $this->add_message($mailbox, $msg, !array_key_exists($msg->uid, $result));
                    $result[$msg->uid] = $msg;
                }
            }
        }

        return $result;
    }


    /**
     * Returns message data.
     *
     * @param string $mailbox  Folder name
     * @param int    $uid      Message UID
     *
     * @return rcube_mail_header Message data
     */
    function get_message($mailbox, $uid)
    {
        $sql_result = $this->db->query(
            "SELECT data FROM ".get_table_name('cache_messages')
            ." WHERE user_id = ?"
                ." AND mailbox = ?"
                ." AND uid = ?",
                $this->userid, $mailbox, $uid);

        if ($sql_arr = $this->db->fetch_assoc($sql_result)) {
            $message = $this->db->decode(unserialize($sql_arr['data']));
            $found   = true;

//@TODO: update message ID according to index data?
        }

        // Get the message from IMAP server
        if (empty($message)) {
            $message = $this->imap->get_headers($uid, $mailbox, true);
            // update cache
            $this->add_message($mailbox, $message, !$found);
        }

        return $message;
    }


    /**
     * Saves the message in cache.
     *
     * @param string            $mailbox  Folder name
     * @param rcube_mail_header $message  Message data
     * @param bool              $force    Skips message in-cache existance check
     */
    function add_message($mailbox, $message, $force = false)
    {
        if (!is_object($message) || empty($message->uid))
            return;

        $msg = serialize($this->db->encode(clone $message));

        // update cache record (even if it exists, the update
        // here will work as select, assume row exist if affected_rows=0)
        if (!$force) {
            $res = $this->db->query(
                "UPDATE ".get_table_name('cache_messages')
                ." SET data = ?, changed = ".$this->db->now()
                ." WHERE user_id = ?"
                    ." AND mailbox = ?"
                    ." AND uid = ?",
                $msg, $this->userid, $mailbox, $message->uid);

            if ($this->db->affected_rows())
                return;
        }

        // insert new record
        $this->db->query(
            "INSERT INTO ".get_table_name('cache_messages')
            ." (user_id, mailbox, uid, changed, data)"
            ." VALUES (?, ?, ?, ".$this->db->now().", ?)",
            $this->userid, $mailbox, $message->uid, $msg);
    }


    /**
     * Clears messages/index cache
     *
     * @param string $mailbox  Folder name
     * @param array  $uids     Message UIDs
     */
    function clear($mailbox = null, $uids = array())
    {
        $this->db->query(
            "DELETE FROM ".get_table_name('cache_index')
            ." WHERE user_id = ?"
                .(strlen($mailbox) ? " AND mailbox = ".$this->db->quote($mailbox) : ""),
            $this->userid);

        if (!strlen($mailbox)) {
            unset($this->icache[$mailbox]);
            $this->db->query(
                "DELETE FROM ".get_table_name('cache_messages')
                ." WHERE user_id = ?",
                $this->userid);
        }
        else {
            $this->icache = array();
            $this->db->query(
                "DELETE FROM ".get_table_name('cache_messages')
                ." WHERE user_id = ?"
                    ." AND mailbox = ".$this->db->quote($mailbox)
                    .(!empty($uids) ? " AND uid IN (".$this->db->array2list($uids, 'integer').")" : ""),
                $this->userid);
        }
    }


    /**
     * @param string $mailbox Folder name
     * @param int    $id      Message (sequence) ID
     *
     * @return int Message UID
     */
    function id2uid($mailbox, $id)
    {
        $index = $this->get_index($mailbox, 'ANY');

        return $index[$id];
    }


    /**
     * @param string $mailbox Folder name
     * @param int    $uid     Message UID
     *
     * @return int Message (sequence) ID
     */
    function uid2id($mailbox, $uid)
    {
        $index = $this->get_index($mailbox, 'ANY');

        return array_search($uid, $index);
    }


    /**
     * Fetches index/thread data from database
     */
    private function get_index_row($mailbox, $sort_field, $threaded = false)
    {
        // Get index from DB
        // There's a special case when we want most recent index
        if ($sort_field == 'ANY')
            $sql_result = $this->db->limitquery(
                "SELECT data, sort_field"
                ." FROM ".get_table_name('cache_index')
                ." WHERE user_id = ?"
                    ." AND mailbox = ?"
                    ." AND threaded = ?"
                ." ORDER BY changed DESC",
                0, 1, $this->userid, $mailbox, (int)$threaded);
        else
            $sql_result = $this->db->query(
                "SELECT data, sort_field"
                ." FROM ".get_table_name('cache_index')
                ." WHERE user_id = ?"
                    ." AND mailbox = ?"
                    ." AND threaded = ?"
                    ." AND sort_field = ?",
                $this->userid, $mailbox, (int)$threaded, (string)$sort_field);

        if ($sql_arr = $this->db->fetch_assoc($sql_result)) {
            if (!$threaded) {
                $data = explode(':', $sql_arr['data']);
                return array(
                    'seq'        => explode(',', $data[0]),
                    'uid'        => explode(',', $data[1]),
                    'sort_field' => $sql_arr['sort_field'],
                    'sort_order' => $data[2],
                    'deleted'    => $data[3],
                    'validity'   => $data[4],
                );
            }
            else {
            // @TODO: threads
            }
        }

        return null;
    }

}
