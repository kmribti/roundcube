<?php

/*
 +-----------------------------------------------------------------------+
 | program/include/rcube_user.inc                                        |
 |                                                                       |
 | This file is part of the RoundCube Webmail client                     |
 | Copyright (C) 2006-2008, RoundCube Dev. - Switzerland                 |
 | Licensed under the GNU GPL                                            |
 |                                                                       |
 | PURPOSE:                                                              |
 |   This class represents a system user linked and provides access      |
 |   to the related database records.                                    |
 |                                                                       |
 +-----------------------------------------------------------------------+
 | Author: Thomas Bruederli <roundcube@gmail.com>                        |
 +-----------------------------------------------------------------------+

 $Id: rcube_user.inc 933 2007-11-29 14:17:32Z thomasb $

*/

/**
 * Class representing a system user
 *
 * @package    core
 * @author     Thomas Bruederli <roundcube@gmail.com>
 */
class rcube_user {
    public $ID = null;
    public $data = null;

    /**
     * Object constructor
     *
     * @param object DB Database connection
     */
    public function __construct($id = null, $sql_arr = null) {

        if (!empty($id) && empty($sql_arr)) {
            $registry  = rcube_registry::get_instance();
            $DB        = $registry->get('DB', 'core');
            
            $sql_result = $DB->query('SELECT * FROM '.rcube::get_table_name('users').' WHERE  user_id = ?', $id);
            $sql_arr = $DB->fetch_assoc($sql_result);
        }

        if (!empty($sql_arr)) {
            $this->ID = $sql_arr['user_id'];
            $this->data = $sql_arr;
        }
    }

    /**
     * Build a user name string (as e-mail address)
     *
     * @return string Full user name
     */
    public static function get_username() {
        return self::$data['username'] ? self::$data['username'] . (!strpos(self::$data['username'], '@') ? '@'.self::$data['mail_host'] : '') : false;
    }

    /**
     * Get the preferences saved for this user
     *
     * @return array Hash array with prefs
     */
    public static function get_prefs() {
        if (self::$ID && self::$data['preferences']) {
            return unserialize(self::$data['preferences']);
        } else {
            return array();
        }
    }


    /**
     * Write the given user prefs to the user's record
     *
     * @param mixed User prefs to save
     * @return boolean True on success, False on failure
     */
    public static function save_prefs($a_user_prefs) {
        $registry  = rcube_registry::get_instance();
        $DB        = $registry->get('DB', 'core');
        $CONFIG    = $registry->get_all('config');
        $user_lang = $registry->get('user_lang', 'core');

        $_query = 'UPDATE '.rcube::get_table_name('users');
        $_query.= ' SET preferences=?,';
        $_query.= ' language=?';
        $_query.= ' WHERE user_id=?';
        $DB->query($_query, serialize($a_user_prefs), $user_lang, $_SESSION['user_id']);

        if ($DB->affected_rows()) {
            $_SESSION['user_prefs'] = $a_user_prefs;
            foreach ($a_user_prefs as $key => $value) {
                $registry->set($key, $value, 'config');
            }
            return true;
        }
        return false;
    }

    /**
     * Get default identity of this user
     *
     * @param int  Identity ID. If empty, the default identity is returned
     * @return array Hash array with all cols of the
     */
    public static function get_identity($identity_id = null) {
        $registry  = rcube_registry::get_instance();
        $DB        = $registry->get('DB', 'core');

        $sql_result = self::list_identities($identity_id ? sprintf('AND identity_id=%d', $identity_id) : '');
        return $DB->fetch_assoc($sql_result);
    }

    /**
     * Return a list of all identities linked with this user
     *
     * @return array List of identities
     */
    public static function list_identities($sql_add = '') {
        $registry  = rcube_registry::get_instance();
        $DB        = $registry->get('DB', 'core');

        $_query = 'SELECT * FROM '.rcube::get_table_name('identities');
        $_query.= ' WHERE del <> 1';
        $_query.= ' AND user_id=?';
        $_query.= (!empty($sql_add) ? ' '.$sql_add : '');
        $_query.= ' ORDER BY '.$DB->quoteIdentifier('standard').' DESC, name ASC';
        // get contacts from DB
        return $DB->query($_query, self::$ID);
    }

    /**
     * Update a specific identity record
     *
     * @param int    Identity ID
     * @param array  Hash array with col->value pairs to save
     * @return boolean True if saved successfully, false if nothing changed
     */
    public static function update_identity($identity_id = null, $data = array()) {
        if (empty(self::$ID) || empty($identity_id) || empty($data) || !is_array($data)) {
            return false;
        }

        $registry  = rcube_registry::get_instance();
        $DB        = $registry->get('DB', 'core');

        $write_sql = array();

        foreach ((array)$data as $col => $value) {
            $write_sql[] = sprintf("%s=%s", $DB->quoteIdentifier($col), $DB->quote($value));
        }

        $_query = 'UPDATE '.rcube::get_table_name('identities');
        $_query.= ' SET '.implode(', ', $write_sql);
        $_query.= ' WHERE identity_id=?';
        $_query.= ' AND user_id=?';
        $_query.= ' AND del <> 1';
        $DB->query($_query, $identity_id, self::$ID);
        return $DB->affected_rows();
    }


    /**
     * Create a new identity record linked with this user
     *
     * @param array  Hash array with col->value pairs to save
     * @return int  The inserted identity ID or false on error
     */
    public static function insert_identity($data = array()) {
        if (!self::$ID || empty($data) || !is_array($data)) {
            return false;
        }

        $registry  = rcube_registry::get_instance();
        $DB        = $registry->get('DB', 'core');

        $insert_cols = $insert_values = array();
        foreach ((array)$data as $col => $value) {
            $insert_cols[] = $DB->quoteIdentifier($col);
            $insert_values[] = $DB->quote($value);
        }
        $_query = 'INSERT INTO '.rcube::get_table_name('identities');
        $_query.= ' (user_id, '.implode(', ', $insert_cols).')';
        $_query.= ' VALUES (?, '.implode(', ', $insert_values).')';

        $DB->query($_query, self::$ID);
        return $DB->insert_id(rcube::get_sequence_name('identities'));
    }

    /**
     * Mark the given identity as deleted
     *
     * @param int  Identity ID
     * @return boolean True if deleted successfully, false if nothing changed
     */
    public static function delete_identity($identity_id = null) {
        if (!self::$ID || empty($identity_id)) {
            return false;
        }

        $registry  = rcube_registry::get_instance();
        $DB        = $registry->get('DB', 'core');

        $_query = 'UPDATE '.rcube::get_table_name('identities');
        $_query.= ' SET del = 1';
        $_query.= ' WHERE user_id = ?';
        $_query.= ' AND identity_id = ?';

        $DB->query($_query, self::$ID, $identity_id);
        return $DB->affected_rows();
    }

    /**
     * Make this identity the default one for this user
     *
     * @param int The identity ID
     */
    public static function set_default($identity_id = null) {

        if (!empty(self::$ID) && !empty($identity_id)) {
            $registry  = rcube_registry::get_instance();
            $DB        = $registry->get('DB', 'core');

            $_query = 'UPDATE '.rcube::get_table_name('identities');
            $_query.= ' SET '.$DB->quoteIdentifier('standard').'="0"';
            $_query.= ' WHERE user_id = ?';
            $_query.= ' AND identity_id <> ?';
            $_query.= ' AND del <> 1';
            $DB->query($_query, self::$ID, $identity_id);
        }
    }


    /**
     * Update user's last_login timestamp
     */
    public static function touch() {
        if (!empty(self::$ID)) {
            $registry  = rcube_registry::get_instance();
            $DB        = $registry->get('DB', 'core');

            $_query = 'UPDATE '.rcube::get_table_name('users');
            $_query.= ' SET last_login = '.$DB->now();
            $_query.= ' WHERE user_id = ?';
            
            $DB->query($_query, self::$ID);
        }
    }
    /**
     * Clear the saved object state
     */
    public static function reset() {
        self::$ID = null;
        self::$data = null;
    }

    /**
     * Find a user record matching the given name and host
     *
     * @param string IMAP user name
     * @param string IMAP host name
     * @return object rcube_user New user instance
     * @static
     */
    public static function query($user = null, $host = null) {
        $registry  = rcube_registry::get_instance();
        $DB        = $registry->get('DB', 'core');

        // query if user already registered
        $_query = 'SELECT * FROM '.rcube::get_table_name('users');
        $_query.= ' WHERE mail_host = ?';
        $_query.= ' AND (username = ? OR alias = ?)';
        $sql_result = $DB->query($_query, $host, $user, $user);

        // user already registered -> overwrite username
        if ($sql_arr = $DB->fetch_assoc($sql_result)) {
            return new rcube_user($sql_arr['user_id'], $sql_arr);
        } else {
            return false;
        }
    }

    /**
     * Create a new user record and return a rcube_user instance
     *
     * @param string IMAP user name
     * @param string IMAP host
     * @return object rcube_user New user instance
     * @static
     */
    public static function create($user, $host) {
        $registry = rcube_registry::get_instance();
        $DB       = $registry->get('DB', 'core');
        $CONFIG   = $registry->get_all('config');
        $IMAP     = $registry->get('IMAP', 'core');

        $user_email = '';

        // try to resolve user in virtusertable
        if (!empty($CONFIG['virtuser_file']) && strstr($user, '@') === FALSE) {
            $user_email = self::user2email($user);
        } else { // failover
            $user_email = $user;
        }

        $_query = 'INSERT INTO '.rcube::get_table_name('users');
        $_query.= ' (created, last_login, username, mail_host, alias, language)';
        $_query.= ' VALUES ('.$DB->now().', '.$DB->now().', %s, %s, %s, %s)';

        $_query = sprintf(
        $_query,
        $DB->quote(strip_newlines($user)),
        $DB->quote(strip_newlines($host)),
        $DB->quote(strip_newlines($user_email)),
        $DB->quote($_SESSION['user_lang'])
        );
        rcube::tfk_debug($_query);
        // query
        $DB->query($_query);

        if ($user_id = $DB->insert_id(rcube::get_sequence_name('users'))) {
            $mail_domain = rcube::mail_domain($host);

            if ($user_email=='') {
                $user_email = strstr($user, '@') ? $user : sprintf('%s@%s', $user, $mail_domain);
            }
            $user_name = ($user != $user_email) ? $user : '';

            // try to resolve the e-mail address from the virtuser table
            // TODO there was $DB->escapeSimple($user) in trunk, don't know what to use instead
            if (
            !empty($CONFIG['virtuser_query'])
            && ($sql_result = $DB->query(preg_replace('/%u/', $user, $CONFIG['virtuser_query'])))
            && ($DB->num_rows() > 0)
            ) {
                while ($sql_arr = $DB->fetch_array($sql_result)) {
                    $_query = 'INSERT INTO '.rcube::get_table_name('identities');
                    $_query.= ' (user_id, del, standard, name, email)';
                    $_query.= ' VALUES (?, 0, 1, ?, ?)';
                    $DB->query(
                    $_query,
                    $user_id,
                    strip_newlines($user_name),
                    preg_replace('/^@/', $user . '@', $sql_arr[0])
                    );
                }
            } else {
                // also create new identity records
                $_query = 'INSERT INTO '.rcube::get_table_name('identities');
                $_query.= ' (user_id, del, standard, name, email)';
                $_query.= ' VALUES (?, 0, 1, ?, ?)';
                $DB->query(
                $_query,
                $user_id,
                strip_newlines($user_name),
                strip_newlines($user_email)
                );
            }

            // get existing mailboxes
            $a_mailboxes = $IMAP->list_mailboxes();
        } else {
            rcube_error::raise(
            array(
                    'code' => 500,
                    'type' => 'php',
                    'line' => __LINE__,
                    'file' => __FILE__,
                    'message' => "Failed to create new user"
                    ),
                    TRUE,
                    FALSE
                    );
        }
        return $user_id;
    }

    /**
     * Load virtuser table in array
     *
     * @return array Virtuser table entries
     */
    public static function get_virtualfile() {
        $registry = rcube_registry::get_instance();
        $CONFIG   = $registry->get_all('config');

        if (empty($CONFIG['virtuser_file']) || !is_file($CONFIG['virtuser_file'])) {
            return false;
        }
        // read file
        $a_lines = file($CONFIG['virtuser_file']);
        return $a_lines;
    }

    /**
     * Find matches of the given pattern in virtuser table
     *
     * @param string Regular expression to search for
     * @return array Matching entries
     */
    public static function find_in_virtual($pattern) {
        $result  = array();
        $virtual = self::get_virtualfile();
        if ($virtual == false) {
            return $result;
        }
        // check each line for matches
        foreach ($virtual as $line) {
            $line = trim($line);
            if (empty($line) || $line{0}=='#') {
                continue;
            }
            if (eregi($pattern, $line)) {
                $result[] = $line;
            }
        }
        return $result;
    }

    /**
     * Resolve username using a virtuser table
     *
     * @param string E-mail address to resolve
     * @return string Resolved IMAP username
     */
    public static function email2user($email = null) {
        $user = $email;
        $r = self::find_in_virtual("^$email");

        for ($i=0, $size = count($r); $i < $size; $i++) {
            $data = $r[$i];
            $arr = preg_split('/\s+/', $data);
            if (count($arr) > 0) {
                $user = trim($arr[count($arr)-1]);
                break;
            }
        }
        return $user;
    }

    /**
     * Resolve e-mail address from virtuser table
     *
     * @param string User name
     * @return string Resolved e-mail address
     */
    public static function user2email($user) {
        $email = '';
        $r = self::find_in_virtual("$user$");

        for ($i=0, $size = count($r); $i < $size; $i++) {
            $data=$r[$i];
            $arr = preg_split('/\s+/', $data);
            if (count($arr) > 0) {
                $email = trim($arr[0]);
                break;
            }
        }
        return $email;
    }
}

?>