<?php
/*
 +-----------------------------------------------------------------------+
 | program/include/rcube.php                                             |
 |                                                                       |
 | This file is part of the RoundCube Webmail client                     |
 | Copyright (C) 2005-2007, RoundCube Dev, - Switzerland                 |
 | Licensed under the GNU GPL                                            |
 |                                                                       |
 | PURPOSE:                                                              |
 |   Provide basic functions for the webmail package                     |
 |                                                                       |
 +-----------------------------------------------------------------------+
 | Author: Thomas Bruederli <roundcube@gmail.com>                        |
 |         Till Klampaeckel <till@php.net>                               |
 +-----------------------------------------------------------------------+

 $Id: main.inc 567 2007-05-17 18:41:24Z thomasb $

*/


require_once 'lib/des.inc';
require_once 'lib/utf7.inc';
require_once 'lib/utf8.class.php';

/**
 * rcube
 *
 * @author   Thomas Bruederli <roundcube@gmail.com>
 * @author   Till Klampaeckel <till@php.net>
 * @license  GPL
 * @category main
 * @package  roundcube
 * @todo     Docs, docs, docs!
 * @todo     Maybe unit tests.
 * @since    0.1-rc1
 */
class rcube {
    const INPUT_GET  = 0x0101;
    const INPUT_POST = 0x0102;
    const INPUT_GPC  = 0x0103;
    
    
    /**
     * register session and connect to server
     *
     * @todo   Remove this include from here. This is not good for bytecode caching.
     * @todo   Maybe implement autoload
     * @param  string $task
     * @return void
     */
    static function startup($task = 'mail') {
        $registry = rcube_registry::get_instance();
        $registry->set('task', $task, 'core');

        // load configuration
        $CONFIG = self::load_config();

        // set session garbage collecting time according to session_lifetime
        if (!empty($CONFIG['session_lifetime'])) {
            ini_set('session.gc_maxlifetime', ($CONFIG['session_lifetime']) * 120);
        }

        $DB = new rcube_db($CONFIG['db_dsnw'], $CONFIG['db_dsnr'], $CONFIG['db_persistent']);
        $DB->sqlite_initials = INSTALL_PATH . 'SQL/sqlite.initial.sql';
        $DB->db_connect('w');

        $registry->set('DB', $DB, 'core');

        // use database for storing session data
        include_once 'include/session.inc';

        // init session
        session_start();
        $registry->set('sess_id', session_id(), 'core');

        // create session and set session vars
        if (isset($_SESSION['auth_time']) !== true) {
            $_SESSION['user_lang'] = rcube::language_prop($CONFIG['locale_string']);
            $_SESSION['auth_time'] = time();
            $_SESSION['temp']      = true;
        }

        // set session vars global
        $user_lang = rcube::language_prop($_SESSION['user_lang']);
        $registry->set('user_lang', $user_lang, 'core');

        // overwrite config with user preferences
        if (is_array($_SESSION['user_prefs'])) {
            foreach ($_SESSION['user_prefs'] as $key => $prop)
                $registry->set($key, $prop, 'config');
            
            $CONFIG = array_merge($CONFIG, $_SESSION['user_prefs']);
        }
        $registry->set('CONFIG', $CONFIG, 'core');

        // reset some session parameters when changing task
        if ($_SESSION['task'] != $task) {
            unset($_SESSION['page']);
        }
        // set current task to session
        $_SESSION['task'] = $task;

        // create IMAP object
        if ($task == 'mail') {
            self::imap_init();
        }

        // set localization
        if ($CONFIG['locale_string']) {
            setlocale(LC_ALL, $CONFIG['locale_string']);
        }
        else if ($user_lang) {
            setlocale(LC_ALL, $user_lang);
        }
        
        register_shutdown_function(array('rcube', 'shutdown'));
    }


    /**
     * Load roundcube configuration array
     *
     * @return array Named configuration parameters
     */
    static function load_config()
    {
        // load config file (throw php error if fails)
        include_once INSTALL_PATH . 'config/main.inc.php';
        $conf = is_array($rcmail_config) ? $rcmail_config : array();

        // load host-specific configuration
        $conf = self::load_host_config($conf);

        $conf['skin_path'] = $conf['skin_path'] ? unslashify($conf['skin_path']) : 'skins/default';

        // load db conf
        include_once INSTALL_PATH . 'config/db.inc.php';
        $conf = array_merge($conf, $rcmail_config);

        if (empty($conf['log_dir'])) {
            $conf['log_dir'] = INSTALL_PATH . 'logs';
        }
        else {
            $conf['log_dir'] = unslashify($conf['log_dir']);
        }
        // set PHP error logging according to config
        if ($conf['debug_level'] & 1) {
            ini_set('log_errors', 1);
            ini_set('error_log', $conf['log_dir'].'/errors');
        }
        if ($conf['debug_level'] & 4) {
            ini_set('display_errors', 1);
        }
        else {
            ini_set('display_errors', 0);
        }
        
        // copy all config parameters to registry
        $registry = rcube_registry::get_instance();
        foreach ($conf as $key => $prop)
            $registry->set($key, $prop, 'config');
        
        return $conf;
    }


    /**
     * Load a host-specific config file if configured
     * This will merge the host specific configuration with the given one
     *
     * @param array Global configuration parameters
     */
    static function load_host_config($config)
    {
        $fname = null;

        if (is_array($config['include_host_config'])) {
            $fname = $config['include_host_config'][$_SERVER['HTTP_HOST']];
        }
        else if (!empty($config['include_host_config'])) {
            $fname = preg_replace('/[^a-z0-9\.\-_]/i', '', $_SERVER['HTTP_HOST']) . '.inc.php';
        }
        if ($fname && is_file(INSTALL_PATH . 'config/' . $fname)) {
            include(INSTALL_PATH . 'config/' . $fname);
            $config = array_merge($config, $rcmail_config);
        }
        
        return $config;
    }


    /**
     * Create unique authorization hash
     *
     * @param string Session ID
     * @param int Timestamp
     * @return string The generated auth hash
     */
    static function auth_hash($sess_id, $ts)
    {
        $registry = rcube_registry::get_instance();
        $CONFIG   = $registry->get_all('config');

        $auth_string = sprintf(
                            'rcmail*sess%sR%s*Chk:%s;%s',
                            $sess_id,
                            $ts,
                            $CONFIG['ip_check'] ? $_SERVER['REMOTE_ADDR'] : '***.***.***.***',
                            $_SERVER['HTTP_USER_AGENT']
        );

        if (function_exists('sha1')) {
            return sha1($auth_string);
        }
        return md5($auth_string);
    }


    /**
     * Check the auth hash sent by the client against the local session credentials
     *
     * @return boolean True if valid, False if not
     */
    static function authenticate_session()
    {
        $registry       = rcube_registry::get_instance();
        $CONFIG         = $registry->get_all('config');
        $SESS_CLIENT_IP = $registry->get('SESS_CLIENT_IP', 'core');
        $SESS_CHANGED   = $registry->get('SESS_CHANGED', 'core');

        // advanced session authentication
        if ($CONFIG['double_auth']) {
            $now = time();
            $valid = ($_COOKIE['sessauth'] == self::auth_hash(session_id(), $_SESSION['auth_time']) ||
                  $_COOKIE['sessauth'] == self::auth_hash(session_id(), $_SESSION['last_auth']));

            // renew auth cookie every 5 minutes (only for GET requests)
            if (!$valid || ($_SERVER['REQUEST_METHOD']!='POST' && $now-$_SESSION['auth_time'] > 300)) {
                $_SESSION['last_auth'] = $_SESSION['auth_time'];
                $_SESSION['auth_time'] = $now;
                setcookie('sessauth', self::auth_hash(session_id(), $now));
            }
        }
        else {
            $valid = $CONFIG['ip_check'] ? $_SERVER['REMOTE_ADDR'] == $SESS_CLIENT_IP : true;
        }
        // check session filetime
        if (!empty($CONFIG['session_lifetime']) && isset($SESS_CHANGED) && $SESS_CHANGED + $CONFIG['session_lifetime']*60 < time()) {
            $valid = false;
        }
        return $valid;
    }

    /**
     * create IMAP object and connects to server if param
     * is set to true.
     *
     * @param  bool True if connection should be established
     * @return void
     * @uses   rcube_registry::get_instance()
     */
    static function imap_init($connect=FALSE)
    {
        $registry = rcube_registry::get_instance();
        $CONFIG   = $registry->get_all('config');
        $DB       = $registry->get('DB', 'core');
        $OUTPUT   = $registry->get('OUTPUT', 'core');

        $IMAP = new rcube_imap($DB);

        $IMAP->debug_level  = $CONFIG['debug_level'];
        $IMAP->skip_deleted = $CONFIG['skip_deleted'];

        // connect with stored session data
        if ($connect) {
            $conn = $IMAP->connect(
                $_SESSION['imap_host'],
                $_SESSION['username'],
                self::decrypt_passwd($_SESSION['password']),
                $_SESSION['imap_port'],
                $_SESSION['imap_ssl']
            );
            $registry->set('IMAP', $IMAP, 'core');
            if ($conn === false) {
                $OUTPUT->show_message('imaperror', 'error');
            }
            self::set_imap_prop();
        }

        // enable caching of imap data
        if ($CONFIG['enable_caching'] === true) {
            $IMAP->set_caching(true);
        }
        // set pagesize from config
        if (isset($CONFIG['pagesize'])) {
            $IMAP->set_pagesize($CONFIG['pagesize']);
        }
        
        $registry->set('IMAP', $IMAP, 'core');
    }

    /**
     * Set root dir and last stored mailbox
     * This must be done AFTER connecting to the server!
     */
    static function set_imap_prop()
    {
        $registry         = rcube_registry::get_instance();
        $IMAP             = $registry->get('IMAP', 'core');
        $imap_root        = $registry->get('imap_root', 'config');
        $default_folders  = $registry->get('default_imap_folders', 'config');

        // set root dir from config
        if (!empty($imap_root)) {
            $IMAP->set_rootdir($imap_root);
        }
        if (is_array($default_folders)) {
            $IMAP->set_default_mailboxes($default_folders);
        }
        if (!empty($_SESSION['mbox'])) {
            $IMAP->set_mailbox($_SESSION['mbox']);
        }
        if (isset($_SESSION['page'])) {
            $IMAP->set_page($_SESSION['page']);
        }
    }


    /**
     * Do these things on script shutdown
     */
    static function shutdown()
    {
        $registry = rcube_registry::get_instance();
        $IMAP     = $registry->get('IMAP', 'core');

        if (is_object($IMAP)) {
            $IMAP->close();
            $IMAP->write_cache();
        }

        // before closing the database connection, write session data
        session_write_close();
    }

    /**
     * Destroy session data and remove cookie
     */
    static function kill_session()
    {
        // save user preferences
        $a_user_prefs = $_SESSION['user_prefs'];
        if (!is_array($a_user_prefs)) {
            $a_user_prefs = array();
        }
        if (
            (
                isset($_SESSION['sort_col'])
                && $_SESSION['sort_col'] != $a_user_prefs['message_sort_col']
            )
            ||
            (
                isset($_SESSION['sort_order'])
                && $_SESSION['sort_order'] != $a_user_prefs['message_sort_order']
            )
        ) {
            $a_user_prefs['message_sort_col'] = $_SESSION['sort_col'];
            $a_user_prefs['message_sort_order'] = $_SESSION['sort_order'];
            self::save_user_prefs($a_user_prefs);
        }

        $_SESSION = array(
                        'user_lang' => $GLOBALS['user_lang'],
                        'auth_time' => time(),
                        'temp' => true
        );
        setcookie('sessauth', '-del-', time()-60);
        session_destroy();
    }


    /**
     * return correct name for a specific database table
     *
     * @param  string Table name
     * @return string Translated table name
     * @uses   rcube_registry::get_instance()
     */
    static function get_table_name($table)
    {
        $registry = rcube_registry::get_instance();
        $CONFIG   = $registry->get_all('config');

        // return table name if configured
        $config_key = 'db_table_' . $table;

        if (strlen($CONFIG[$config_key])) {
            return $CONFIG[$config_key];
        }
        return $table;
    }


    /**
     * Return correct name for a specific database sequence
     * (used for Postres only)
     *
     * @param string Secuence name
     * @return string Translated sequence name
     */
    static function get_sequence_name($sequence)
    {
        $registry = rcube_registry::get_instance();

        if ($seq = $registry->get('db_sequence_' . $sequence, 'config')) {
            return $seq;
        }
        
        // return table name if not configured
        return $table;
    }


    /**
     * Init output object for GUI and add common scripts.
     * This will instantiate a rcube_template object and set
     * environment vars according to the current session and configuration
     */
    static function load_gui()
    {
        $registry  = rcube_registry::get_instance();
        $config    = $registry->get_all('config');

        // init output page
        $OUTPUT = new rcube_template();

        if (is_array($config['javascript_config'])) {
            foreach ($config['javascript_config'] as $js_config_var) {
                $OUTPUT->set_env($js_config_var, $config[$js_config_var]);
            }
        }

        if (!empty($GLOBALS['_framed'])) {
            $OUTPUT->set_env('framed', true);
        }

        // add some basic label to client
        $OUTPUT->add_label('loading', 'movingmessage');
        
        $registry->set('OUTPUT', $OUTPUT, 'core');
        
        // set locale setting
        self::set_locale($registry->get('user_lang', 'core'));
    }
    
    
    /**
     * Create an output object for JSON responses
     * and register it to the global registry
     */
    static function init_json()
    {
        $registry  = rcube_registry::get_instance();
        
        $OUTPUT = new rcube_json_output();
        $registry->set('OUTPUT', $OUTPUT, 'core');
        
        // set locale setting
        self::set_locale($registry->get('user_lang', 'core'));
    }


    // set localization charset based on the given language
    static function set_locale($lang)
    {
        $registry        = rcube_registry::get_instance();
        $charset         = $registry->get('charset', 'config', RCMAIL_CHARSET);
        $OUTPUT          = $registry->get('OUTPUT', 'core');
        $MBSTRING        = $registry->get('MBSTRING', 'core');
        $mbstring_loaded = $registry->get('mbstring_loaded', 'core');
        
        // settings for mbstring module (by Tadashi Jokagi)
        if (is_null($mbstring_loaded)) {
            $MBSTRING = $mbstring_loaded = extension_loaded("mbstring");
        }
        else {
            $MBSTRING = $mbstring_loaded = FALSE;
        }

        if ($MBSTRING) {
            mb_internal_encoding($charset);
        }
        
        $registry->set('MBSTRING', $MBSTRING, 'core');
        $registry->set('mbstring_loaded', $mbstring_loaded, 'core');
        $registry->set('OUTPUT_CHARSET', $charset, 'core');

        $OUTPUT->set_charset($charset);
    }


    /**
     * auto-select IMAP host based on the posted login information
     *
     * @link   ./index.php
     * @todo   Remove reference to $_POST superglobal.
     */
    static function autoselect_host()
    {
        $registry = rcube_registry::get_instance();
        $default_host = $registry->get('default_host', 'config');

        $host = '';
        if (isset($_POST['_host']) && empty($_POST['_host']) === false) {
            $host .= rcube::get_input_value('_host', rcube::INPUT_POST);
        }
        else {
            $host .= $default_host;
        }

        if (is_array($host)) {
            list($user, $domain) = explode('@', rcube::get_input_value('_user', rcube::INPUT_POST));
            if (!empty($domain)) {
                foreach ($host as $imap_host => $mail_domains) {
                    if (is_array($mail_domains) && in_array($domain, $mail_domains)) {
                        $host = $imap_host;
                        break;
                    }
                }
            }

            // take the first entry if $host is still an array
            if (is_array($host)) {
                $host = array_shift($host);
            }
        }

        return $host;
    }

    /**
     * Perfom login to the IMAP server and to the webmail service.
     * This will also create a new user entry if auto_create_user is configured.
     *
     * @param string IMAP user name
     * @param string IMAP password
     * @param string IMAP host
     * @return boolean True on success, False on failure
     */
    static function login($user, $pass, $host=null)
    {
        $user_id = null;

        $registry  = rcube_registry::get_instance();
        $CONFIG    = $registry->get_all('config');
        $IMAP      = $registry->get('IMAP', 'core');
        $DB        = $registry->get('DB', 'core');
        $user_lang = $registry->get('user_lang', 'core');

        if (is_null($host) === true) {
            $host = $CONFIG['default_host'];
        }
        // Validate that selected host is in the list of configured hosts
        if (is_array($CONFIG['default_host'])) {
            $allowed = false;
            foreach ($CONFIG['default_host'] as $key => $host_allowed) {
                if (!is_numeric($key)) {
                    $host_allowed = $key;
                }
                if ($host == $host_allowed) {
                    $allowed = true;
                    break;
                }
            }
            if (!$allowed) {
                return false;
            }
        }
        else if (!empty($CONFIG['default_host']) && $host != $CONFIG['default_host']) {
            return false;
        }

        // parse $host URL
        $a_host = parse_url($host);
        if ($a_host['host']) {
            $host = $a_host['host'];
            $imap_ssl = (isset($a_host['scheme']) && in_array($a_host['scheme'], array('ssl','imaps','tls'))) ? true : false;
            $imap_port = isset($a_host['port']) ? $a_host['port'] : ($imap_ssl ? 993 : $CONFIG['default_port']);
        }
        else {
            $imap_port = $CONFIG['default_port'];
        }

        /**
         * Modify username with domain if required
         * Inspired by Marco <P0L0_notspam_binware.org>
         */
        // Check if we need to add domain
        //tfk_debug('User #1: ' . $user);
        //tfk_debug('Host: ' . $CONFIG['username_domain']);
        if (!empty($CONFIG['username_domain']) && !strstr($user, '@')) {
            if (is_array($CONFIG['username_domain']) && isset($CONFIG['username_domain'][$host])) {
                $user .= '@' . $CONFIG['username_domain'][$host];
            }
            else if (is_string($CONFIG['username_domain'])) {
                $user .= '@' . $CONFIG['username_domain'];
            }
        }
        //tfk_debug('User #2: ' . $user);

        // try to resolve email address from virtuser table
        if (!empty($CONFIG['virtuser_file']) && strstr($user, '@')) {
            $user = rcube::email2user($user);
        }

        // query if user already registered
        $_query = "SELECT user_id, username, language, preferences";
        $_query.= " FROM " . self::get_table_name('users');
        $_query.= " WHERE mail_host=?";
        $_query.= " AND (username=? OR alias=?)";

        $sql_result = $DB->query(
                            $_query,
                            $host,
                            $user,
                            $user
        );
        if ($DB->db_error === true) {
            return FALSE;
        }
        // user already registered -> overwrite username
        if ($sql_arr = $DB->fetch_assoc($sql_result)) {
            $user_id = $sql_arr['user_id'];
            $user    = $sql_arr['username'];
        }

        // exit if IMAP login failed
        if (!($imap_login  = $IMAP->connect($host, $user, $pass, $imap_port, $imap_ssl))) {
            return FALSE;
        }
        // user already registered
        if ($user_id && !empty($sql_arr)) {
            // get user prefs
            if (strlen($sql_arr['preferences'])) {
                $user_prefs = unserialize($sql_arr['preferences']);
                $_SESSION['user_prefs'] = $user_prefs;
                array_merge($CONFIG, $user_prefs);
            }


            // set user specific language
            if (strlen($sql_arr['language'])) {
                $user_lang = $_SESSION['user_lang'] = $sql_arr['language'];
            }
            // update user's record
            $_query = "UPDATE " . self::get_table_name('users');
            $_query.= " SET last_login=" . $DB->now();
            $_query.= " WHERE user_id=?";
            $DB->query($_query, $user_id);
        }
        // create new system user
        else if ($CONFIG['auto_create_user']) {
            $user_id = self::create_user($user, $host);
        }

        //tfk_debug('User id: ' . $user_id);

        if (empty($user_id) === true) {
            return false;
        }
        $_SESSION['user_id']    = $user_id;
        $_SESSION['imap_host']  = $host;
        $_SESSION['imap_port']  = $imap_port;
        $_SESSION['imap_ssl']   = $imap_ssl;
        $_SESSION['username']   = $user;
        $_SESSION['user_lang']  = $user_lang;
        $_SESSION['password']   = self::encrypt_passwd($pass);
        $_SESSION['login_time'] = mktime();

        /**
         * If multi-SMTP servers, use correct one
         * Otherwise, use the general SMTP server
         * or use phpMail function
         *
         * @author Brett Patterson <brett@bpatterson.net>
         * @author Till Klampaeckel <till@php.net>
         */
        if(is_array($CONFIG['smtp_server']) === true) {
            if (isset($CONFIG['smtp_server'][$host]) === true) {
                $_SESSION['smtp_server'] = $CONFIG['smtp_server'][$host];
            }
            else {
                $_SESSION['smtp_server'] = 'phpMail';
            }
        }
        else {
            if (empty($CONFIG['smtp_server']) === false) {
                $_SESSION['smtp_server'] = $CONFIG['smtp_server'];
            }
            else {
                $_SESSION['smtp_server'] = 'phpMail';
            }
        }

        // force reloading complete list of subscribed mailboxes
        self::set_imap_prop();
        $IMAP->clear_cache('mailboxes');
        $IMAP->create_default_folders();

        return TRUE;
    }


    /**
     * Create new entry in users and identities table
     *
     * @param string User name
     * @param string IMAP host
     * @return mixed New user ID or False on failure
     */
    static function create_user($user, $host)
    {
        $registry = rcube_registry::get_instance();
        $DB       = $registry->get('DB', 'core');
        $CONFIG   = $registry->get_all('config');
        $IMAP     = $registry->get('IMAP', 'core');

        $user_email = '';

        // try to resolve user in virtusertable
        if (!empty($CONFIG['virtuser_file']) && strstr($user, '@') === FALSE) {
            $user_email = self::user2email($user);
        }
        else { // failover
            $user_email = $user;
        }
        $_query = "INSERT INTO " . self::get_table_name('users');
        $_query.= " (created, last_login, username, mail_host, alias, language)";
        $_query.= " VALUES (" . $DB->now() . ", " . $DB->now() . ", %s, %s, %s, %s)";

        $_query = sprintf(
                    $_query,
                    $DB->quote(strip_newlines($user)),
                    $DB->quote(strip_newlines($host)),
                    $DB->quote(strip_newlines($user_email)),
                    $DB->quote($_SESSION['user_lang'])
        );
        rcube::tfk_debug($_query);


        $DB->query($_query);

        if ($user_id = $DB->insert_id(self::get_sequence_name('users'))) {
            $mail_domain = self::mail_domain($host);

            if ($user_email=='') {
                $user_email = strstr($user, '@') ? $user : sprintf('%s@%s', $user, $mail_domain);
            }
            $user_name = $user!=$user_email ? $user : '';

            // try to resolve the e-mail address from the virtuser table
            if (
                !empty($CONFIG['virtuser_query'])
                && ($sql_result = $DB->query(preg_replace('/%u/', $user, $CONFIG['virtuser_query'])))
                && ($DB->num_rows()>0)
            ) {
                while ($sql_arr = $DB->fetch_array($sql_result)) {
                    $_query = "INSERT INTO " . self::get_table_name('identities');
                    $_query.= " (user_id, del, standard, name, email)";
                    $_query.= " VALUES (?, 0, 1, ?, ?)";
                    $DB->query(
                            $_query,
                            $user_id,
                            strip_newlines($user_name),
                            preg_replace('/^@/', $user . '@', $sql_arr[0])
                    );
                }
            }
            else {
                // also create new identity records
                $_query = "INSERT INTO " . self::get_table_name('identities');
                $_query.= " (user_id, del, standard, name, email)";
                $_query.= " VALUES (?, 0, 1, ?, ?)";
                $DB->query(
                        $_query,
                        $user_id,
                        strip_newlines($user_name),
                        strip_newlines($user_email)
                );
            }

            // get existing mailboxes
            $a_mailboxes = $IMAP->list_mailboxes();
        }
        else {
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
    function get_virtualfile()
    {
        $registry = rcube_registry::get_instance();
        $registry->get_all('config');
        if (empty($CONFIG['virtuser_file']) || !is_file($CONFIG['virtuser_file'])) {
            return FALSE;
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
    static function find_in_virtual($pattern)
    {
        $result  = array();
        $virtual = self::get_virtualfile();
        if ($virtual==FALSE) {
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
    static function email2user($email)
    {
        $user = $email;
        $r = self::find_in_virtual("^$email");

        for ($i=0; $i<count($r); $i++) {
            $data = $r[$i];
            $arr = preg_split('/\s+/', $data);
            if (count($arr)>0) {
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
    static function user2email($user)
    {
        $email = "";
        $r = self::find_in_virtual("$user$");

        for ($i=0; $i<count($r); $i++) {
            $data=$r[$i];
            $arr = preg_split('/\s+/', $data);
            if (count($arr)>0) {
                $email = trim($arr[0]);
                break;
            }
        }
        return $email;
    }


    /**
     * Write the given user prefs to the user's record
     *
     * @param mixed User prefs to save
     * @return boolean True on success, False on failure
     */
    static function save_user_prefs($a_user_prefs)
    {
        $registry       = rcube_registry::get_instance();
        $DB             = $registry->get('DB', 'core');
        $CONFIG         = $registry->get_all('config');
        $user_lang = $registry->get('user_lang', 'core');

        $_query = "UPDATE " . self::get_table_name('users');
        $_query.= " SET preferences=?,";
        $_query.= " language=?";
        $_query.= " WHERE user_id=?";
        $DB->query(
            $_query,
            serialize($a_user_prefs),
            $user_lang,
            $_SESSION['user_id']
        );

        if ($DB->affected_rows()) {
            $_SESSION['user_prefs'] = $a_user_prefs;
            foreach ($a_user_prefs as $key => $value)
                $registry->set($key, $value, 'config');
            return true;
        }

        return false;
    }


    /**
     * Overwrite action variable
     *
     * @param string New action value
     */
    static function override_action($action)
    {
        $registry = rcube_registry::get_instance();
        $OUTPUT   = $registry->get('OUTPUT', 'core');
        $GLOBALS['_action'] = $action;
        $OUTPUT->set_env('action', $action);
    }


    /**
     * Compose an URL for a specific action
     *
     * @param string  Request action
     * @param array   More URL parameters
     * @param string  Request task (omit if the same)
     * @return The application URL
     */
    static function url($action, $p=array(), $task=null)
    {
        $registry   = rcube_registry::get_instance();
        $COMM_PATH  = $registry->get('COMM_PATH', 'core');
        $MAIN_TASKS = $registry->get('MAIN_TASKS', 'core');

        $qstring = '';
        $base = $COMM_PATH;

        if ($task && in_array($task, $MAIN_TASKS)) {
            $base = ereg_replace('_task=[a-z]+', '_task='.$task, $COMM_PATH);
        }
        if (is_array($p)) {
            foreach ($p as $key => $val) {
                $qstring .= '&'.urlencode($key).'='.urlencode($val);
            }
        }
        return $base . ($action ? '&_action='.$action : '') . $qstring;
    }


    /**
     * Encrypt IMAP password using DES encryption
     *
     * @param string Password to encrypt
     * @return string Encryprted string
     */
    static function encrypt_passwd($pass)
    {
        $cypher = des(self::get_des_key(), $pass, 1, 0, NULL);
        return base64_encode($cypher);
    }

    /**
     * Decrypt IMAP password using DES encryption
     *
     * @param string Encrypted password
     * @return string Plain password
     */
    static function decrypt_passwd($cypher)
    {
        $pass = des(self::get_des_key(), base64_decode($cypher), 0, 0, NULL);
        return preg_replace('/\x00/', '', $pass);
    }


    /**
     * Return a 24 byte key for the DES encryption
     *
     * @return string DES encryption key
     */
    static function get_des_key()
    {
        $registry = rcube_registry::get_instance();
        $CONFIG   = $registry->get_all('config');
        $key = !empty($CONFIG['des_key']) ? $CONFIG['des_key'] : 'rcmail?24BitPwDkeyF**ECB';
        $len = strlen($key);

        // make sure the key is exactly 24 chars long
        if ($len<24) {
            $key .= str_repeat('_', 24-$len);
        }
        else if ($len>24) {
            substr($key, 0, 24);
        }
        return $key;
    }


    /**
     * Garbage collector function for temp files.
     * Remove temp files older than two days
     */
    function temp_gc()
    {
        $registry = rcube_registry::get_instance();
        $CONFIG   = $registry->get_all('config');
        $tmp      = unslashify($CONFIG['temp_dir']);
        $expire   = mktime() - 172800;  // expire in 48 hours

        if (($dir = opendir($tmp)) === false) {
            return false;
        }
        while (($fname = readdir($dir)) !== false) {
            if ($fname{0} == '.')
                continue;

            if (filemtime($tmp.'/'.$fname) < $expire)
                @unlink($tmp.'/'.$fname);
        }
        closedir($dir);
    }


    /**
     * Garbage collector for cache entries.
     * Remove all expired message cache records
     */
    static function message_cache_gc()
    {
        $registry = rcube_registry::get_instance();
        $DB       = $registry->get('DB', 'core');
        $CONFIG   = $registry->get_all('config');

        // no cache lifetime configured
        if (empty($CONFIG['message_cache_lifetime'])) {
            return;
        }
        // get target timestamp
        $ts = get_offset_time($CONFIG['message_cache_lifetime'], -1);

        $_query = "DELETE FROM " . self::get_table_name('messages');
        $_query.= " WHERE created < " . $DB->fromunixtime($ts);
        $DB->query($_query);
    }


    /**
     * Check if a specific template exists
     *
     * @param string Template name
     * @return bool True if template exists
     */
    static function template_exists($name)
    {
        $registry = rcube_registry::get_instance();
        $CONFIG   = $registry->get_all('config');
        $skin_path = $CONFIG['skin_path'];

        // check template file
        return is_file("$skin_path/templates/$name.html");
    }


    /**
     * Wrapper for rcube_template::parse()
     *
     * @deprecated
     */
    static function parse_template($name='main', $exit=true)
    {
        $registry = rcube_registry::get_instance();
        $OUTPUT   = $registry->get('OUTPUT', 'core');
        $OUTPUT->send($name, $exit);
    }

    /**
     * Create a HTML table based on the given data
     *
     * @param  array  Named table attributes
     * @param  mixed  Table row data. Either a two-dimensional array or a valid SQL result set
     * @param  array  List of cols to show
     * @param  string Name of the identifier col
     * @return string HTML table code
     * @uses   rcube_registry::get_instance()
     * @uses   Q()
     */
    static function table_output($attrib, $table_data, $a_show_cols, $id_col)
    {
        $registry = rcube_registry::get_instance();
        $DB       = $registry->get('DB', 'core');
        $OUTPUT   = null;

        // allow the following attributes to be added to the <table> tag
        $attrib_str = rcube::create_attrib_string(
                        $attrib,
                        array(
                            'style',
                            'class',
                            'id',
                            'cellpadding',
                            'cellspacing',
                            'border',
                            'summary'
                        )
        );
        $table = '<table' . $attrib_str . ">\n";

        // add table title
        $table .= "<thead><tr>\n";

        foreach ($a_show_cols as $col) {
            $table .= '<td class="'.$col.'">' . Q(rcube::gettext($col)) . "</td>\n";
        }
        $table .= "</tr></thead>\n<tbody>\n";

        $c = 0;
        if (!is_array($table_data)) {
            while ($table_data && ($sql_arr = $DB->fetch_assoc($table_data))) {
                $zebra_class = $c%2 ? 'even' : 'odd';

                $table .= sprintf(
                            '<tr id="rcmrow%d" class="contact '.$zebra_class.'">'."\n",
                            $sql_arr[$id_col]
                );

                // format each col
                foreach ($a_show_cols as $col) {
                    $cont = Q($sql_arr[$col]);
                    $table .= '<td class="'.$col.'">' . $cont . "</td>\n";
                }

                $table .= "</tr>\n";
                $c++;
            }
        }
        else {
            foreach ($table_data as $row_data) {
                $zebra_class = $c%2 ? 'even' : 'odd';

                $table .= sprintf(
                            '<tr id="rcmrow%d" class="contact '.$zebra_class.'">'."\n",
                            $row_data[$id_col]
                );

                // format each col
                foreach ($a_show_cols as $col) {
                    $cont = $row_data[$col];
                    if (strstr($cont, '<roundcube')) {
                        if (is_null($OUTPUT) === true) {
                            $OUTPUT = $registry->get('OUTPUT','core');
                        }
                        // parse tags/conditions
                        $cont = $OUTPUT->just_parse($cont);
                        //var_dump($cont); exit;
                    }
                    else {
                        $cont = Q($row_data[$col]);
                    }
                    $table .= '<td class="' . $col . '">' . $cont . "</td>\n";
                }

                $table .= "</tr>\n";
                $c++;
            }
        }

        // complete message table
        $table .= "</tbody></table>\n";

        return $table;
    }


    /**
     * Create an edit field for inclusion on a form
     * 
     * @param string col field name
     * @param string value field value
     * @param array attrib HTML element attributes for field
     * @param string type HTML element type (default 'text')
     * @return string HTML field definition
     */
    static function get_edit_field($col, $value, $attrib, $type='text')
    {
        $fname = '_'.$col;
        $attrib['name'] = $fname;

        if ($type=='checkbox') {
            $attrib['value'] = '1';
            $input = new html_checkbox($attrib);
        }
        else if ($type=='textarea') {
            $attrib['cols'] = $attrib['size'];
            $input = new html_textarea($attrib);
        }
        else {
            $input = new html_inputfield($attrib);
        }
        // use value from post
        if (!empty($_POST[$fname])) {
            $value = $_POST[$fname];
        }
        $out = $input->show($value);

        return $out;
    }


    /**
     * Return the mail domain configured for the given host
     *
     * @param string IMAP host
     * @return string Resolved SMTP host
     */
    static function mail_domain($host)
    {
        $registry = rcube_registry::get_instance();
        $CONFIG   = $registry->get_all('config');

        $domain = $host;
        if (is_array($CONFIG['mail_domain'])) {
            if (isset($CONFIG['mail_domain'][$host])) {
                $domain = $CONFIG['mail_domain'][$host];
            }
        }
        else if (!empty($CONFIG['mail_domain'])) {
            $domain = $CONFIG['mail_domain'];
        }
        return $domain;
    }


    /**
     * Convert the given date to a human readable form
     * This uses the date formatting properties from config
     *
     * @param mixed Date representation (string or timestamp)
     * @param string Date format to use
     * @return string Formatted date string
     */
    static function format_date($date, $format=NULL)
    {
        $registry       = rcube_registry::get_instance();
        $CONFIG         = $registry->get_all('config');
        $user_lang = $registry->get('user_lang', 'core');

        $ts = NULL;

        if (is_numeric($date)) {
            $ts = $date;
        }
        else if (!empty($date)) {
            $ts = @strtotime($date);
        }
        if (empty($ts)) {
            return '';
        }
        // get user's timezone
        $tz = $CONFIG['timezone'];
        if ($CONFIG['dst_active']) {
            $tz++;
        }
        // convert time to user's timezone
        $timestamp = $ts - date('Z', $ts) + ($tz * 3600);

        // get current timestamp in user's timezone
        $now      = time();  // local time
        $now     -= (int)date('Z'); // make GMT time
        $now     += ($tz * 3600); // user's time
        $now_date = getdate($now);

        $today_limit = mktime(
                        0, 0, 0,
                        $now_date['mon'],
                        $now_date['mday'],
                        $now_date['year']
        );
        $week_limit  = mktime(
                        0, 0, 0,
                        $now_date['mon'],
                        $now_date['mday']-6,
                        $now_date['year']
        );

        // define date format depending on current time
        if (
            $CONFIG['prettydate']
            && !$format
            && $timestamp > $today_limit
            && $timestamp < $now
        ) {
            return sprintf(
                    '%s %s',
                    rcube::gettext('today'),
                    date(
                        $CONFIG['date_today'] ? $CONFIG['date_today'] : 'H:i',
                        $timestamp
                    )
            );
        }
        elseif (
            $CONFIG['prettydate']
            && !$format
            && $timestamp > $week_limit
            && $timestamp < $now
        ) {
            $format = $CONFIG['date_short'] ? $CONFIG['date_short'] : 'D H:i';
        }
        elseif (!$format) {
            $format = $CONFIG['date_long'] ? $CONFIG['date_long'] : 'd.m.Y H:i';
        }

        // parse format string manually in order to provide localized weekday and month names
        // an alternative would be to convert the date() format string to fit with strftime()
        $out = '';
        for($i=0; $i<strlen($format); $i++) {
            if ($format{$i}=='\\') {  // skip escape chars
                continue;
            }
            // write char "as-is"
            if ($format{$i}==' ' || $format{$i-1}=='\\') {
                $out .= $format{$i};
            // weekday (short)
            }
            elseif ($format{$i}=='D') {
                $out .= rcube::gettext(strtolower(date('D', $timestamp)));
            // weekday long
            }
            elseif ($format{$i}=='l') {
                $out .= rcube::gettext(strtolower(date('l', $timestamp)));
            // month name (short)
            }
            elseif ($format{$i}=='M') {
                $out .= rcube::gettext(strtolower(date('M', $timestamp)));
            // month name (long)
            }
            elseif ($format{$i}=='F') {
                $out .= rcube::gettext(strtolower(date('F', $timestamp)));
            }
            else {
                $out .= date($format{$i}, $timestamp);
            }
        }
        return $out;
    }


    /**
     * Compose a valid representaion of name and e-mail address
     *
     * @param string E-mail address
     * @param string Person name
     * @return string Formatted string
     */
    static function format_email_recipient($email, $name='')
    {
        if ($name && $name != $email) {
            return sprintf('%s <%s>', strpos($name, ",") ? '"'.$name.'"' : $name, $email);
        }
        else {
            return $email;
        }
    }
    
    
    
    /**
     * Check the given string and returns language properties
     *
     * @param string Language code
     * @param string Peropert name
     * @return string Property value
     */
    static function language_prop($lang, $prop='lang')
    {
        $registry               = rcube_registry::get_instance();
        $rcube_languages        = $registry->get('languages', 'core');
        $rcube_language_aliases = $registry->get('language_aliases', 'core');
        $rcube_charsets         = $registry->get('language_charsets', 'core');

        if (empty($rcube_languages)) {
            $status = @include(INSTALL_PATH . 'program/localization/index.inc');
            if ($status === false) {
                self::tfk_debug("Couldn't include localization/index.inc");
            }
        }
        // check if we have an alias for that language
        if (!isset($rcube_languages[$lang]) && isset($rcube_language_aliases[$lang])) {
            $lang = $rcube_language_aliases[$lang];
        }
        // try the first two chars
        if (!isset($rcube_languages[$lang]) && strlen($lang)>2) {
            $registry->set('rcube_languages', $rcube_languages, 'core');
            $registry->set('rcube_language_aliases', $rcube_language_aliases, 'core');
            $registry->set('rcube_charsets', $rcube_charsets, 'core');

            $lang = substr($lang, 0, 2);
            $lang = rcube::language_prop($lang);
        }

        if (!isset($rcube_languages[$lang])) {
            $lang = 'en_US';
        }
        // language has special charset configured
        if (isset($rcube_charsets[$lang])) {
            $charset = $rcube_charsets[$lang];
        }
        else {
            $charset = 'UTF-8';
        }

        // write back to registry
        $registry->set('languages', $rcube_languages, 'core');
        $registry->set('language_aliases', $rcube_language_aliases, 'core');
        $registry->set('language_charsets', $rcube_charsets, 'core');

        if ($prop=='charset') {
            return $charset;
        }

        return $lang;
    }
    
    
    /**
     * Read directory program/localization and return a list of available languages
     *
     * @return array List of available localizations
     */
    static function list_languages()
    {
        $registry      = rcube_registry::get_instance();
        $localizations = $registry->get('localizations', 'core');

        if (empty($localizations)) {
            $localizations = array();
            @include(INSTALL_PATH . 'program/localization/index.inc');

            if ($dh = @opendir(INSTALL_PATH . 'program/localization')) {
                while (($name = readdir($dh)) !== false) {
                    if ($name{0}=='.' || !is_dir(INSTALL_PATH . 'program/localization/' . $name))
                        continue;

                    if ($label = $rcube_languages[$name])
                        $localizations[$name] = $label ? $label : $name;
                }
                closedir($dh);
            }
            $registry->set('localizations', $localizations, 'core');
        }
        return $localizations;
    }
    
    
    /**
     * Get localized text in the desired language
     *
     * @param mixed Named parameters array or label name
     * @return string Localized text
     */
    static function gettext($attrib)
    {
        static $sa_text_data, $s_language, $utf8_decode;
        
        $registry = rcube_registry::get_instance();
        $lang     = $registry->get('user_lang', 'core');

        // extract attributes
        if (is_string($attrib)) {
            $attrib = array('name' => $attrib);
        }
        $nr = is_numeric($attrib['nr']) ? $attrib['nr'] : 1;
        $vars = isset($attrib['vars']) ? $attrib['vars'] : '';

        $command_name = !empty($attrib['command']) ? $attrib['command'] : NULL;
        $alias = $attrib['name'] ? $attrib['name'] : ($command_name && $command_label_map[$command_name] ? $command_label_map[$command_name] : '');


        // load localized texts
        if (!$sa_text_data || $s_language != $lang) {
            $sa_text_data = array();

            // get english labels (these should be complete)
            include(INSTALL_PATH . 'program/localization/en_US/labels.inc');
            include(INSTALL_PATH . 'program/localization/en_US/messages.inc');

            if (is_array($labels)) {
                $sa_text_data = $labels;
            }
            if (is_array($messages)) {
                $sa_text_data = array_merge($sa_text_data, $messages);
            }
            // include user language files
            if (
                !empty($lang)
                && $lang != 'en'
                && is_dir(INSTALL_PATH . 'program/localization/'.$lang)
            ) {
                include_once(INSTALL_PATH . 'program/localization/' . $lang . '/labels.inc');
                include_once(INSTALL_PATH . 'program/localization/' . $lang . '/messages.inc');

                if (is_array($labels)) {
                    $sa_text_data = array_merge($sa_text_data, $labels);
                }
                if (is_array($messages)) {
                    $sa_text_data = array_merge($sa_text_data, $messages);
                }
            }
            $s_language = $lang;
        }

        // text does not exist
        if (!($text_item = $sa_text_data[$alias])) {
            /*
            rcube_error::raise(
                array(
                    'code' => 500,
                    'type' => 'php',
                    'line' => __LINE__,
                    'file' => __FILE__,
                    'message' => "Missing localized text for '$alias' in '$lang'"
                ),
                TRUE,
                FALSE
            );
            */
            /**
             * We got as far - so let's see if $_SESSION contains what we are
             * looking for.
             *
             * @author Till Klampaeckel <till@php.net>
             */
            if (substr($alias, 0, 3) == 'RC_') {

                $_sess_ident = substr($alias, 3);

                if (isset($_SESSION[$_sess_ident]) === true) {
                    return $_SESSION[$_sess_ident];
                }
            }
            return "[$alias]";
        }

        // make text item array
        $a_text_item = is_array($text_item) ? $text_item : array('single' => $text_item);

        // decide which text to use
        if ($nr==1) {
            $text = $a_text_item['single'];
        }
        elseif ($nr>0) {
            $text = $a_text_item['multiple'];
        }
        elseif ($nr==0) {
            if ($a_text_item['none']) {
                $text = $a_text_item['none'];
            }
            elseif ($a_text_item['single']) {
                $text = $a_text_item['single'];
            }
            elseif ($a_text_item['multiple']) {
                $text = $a_text_item['multiple'];
            }
        }

        // default text is single
        if ($text=='')
            $text = $a_text_item['single'];

        // replace vars in text
        if (is_array($attrib['vars'])) {
            foreach ($attrib['vars'] as $var_key=>$var_value) {
                $a_replace_vars[substr($var_key, 0, 1)=='$' ? substr($var_key, 1) : $var_key] = $var_value;
            }
        }

        if ($a_replace_vars) {
            $text = preg_replace('/\${?([_a-z]{1}[_a-z0-9]*)}?/ei', '$a_replace_vars["\1"]', $text);
        }

        // format output
        if (($attrib['uppercase'] && strtolower($attrib['uppercase']=='first')) || $attrib['ucfirst']) {
            return ucfirst($text);
        }
        elseif ($attrib['uppercase']) {
            return strtoupper($text);
        }
        elseif ($attrib['lowercase']) {
            return strtolower($text);
        }
        return $text;
    }
    
    
    /**
     * Read input value and convert it for internal use
     * Performs stripslashes() and charset conversion if necessary
     *
     * @param  string   Field name to read
     * @param  int      Source to get value from (GPC)
     * @param  boolean  Allow HTML tags in field value
     * @param  string   Charset to convert into
     * @return string   Field value or NULL if not available
     */
    static function get_input_value($fname, $source, $allow_html=false, $charset=null)
    {
        try {
            $registry = rcube_registry::get_instance();
            $output   = $registry->get('OUTPUT', 'core');
        }
        catch (rcube_registry_exception $e) {
            $output = NULL;
        }

        $value = NULL;

        if ($source == rcube::INPUT_GET && isset($_GET[$fname])) {
            $value = $_GET[$fname];
        }
        else if ($source == rcube::INPUT_POST && isset($_POST[$fname])) {
            $value = $_POST[$fname];
        }
        else if ($source == rcube::INPUT_GPC) {
            if (isset($_POST[$fname])) {
                $value = $_POST[$fname];
            }
            else if (isset($_GET[$fname])) {
                $value = $_GET[$fname];
            }
            else if (isset($_COOKIE[$fname])) {
                $value = $_COOKIE[$fname];
            }
        }

        // strip slashes if magic_quotes enabled
        if ((bool)get_magic_quotes_gpc())
            $value = stripslashes($value);

        // remove HTML tags if not allowed
        if (!$allow_html) {
            $value = strip_tags($value);
        }
        // convert to internal charset
        if (is_object($output)) {
            return self::charset_convert($value, $output->get_charset(), $charset);
        }
        return $value;
    }
    
    
    /**
     * Read a specific HTTP request header
     *
     * @param  string $name Header name
     * @return mixed  Header value or null if not available
     */
    static function get_request_header($name)
    {
        if (function_exists('getallheaders')) {
            $hdrs = getallheaders();
            $hdrs = array_change_key_case($hdrs, CASE_UPPER);
            $key  = strtoupper($name);
        }
        else {
            $key  = 'HTTP_' . strtoupper(strtr($name, '-', '_'));
            $hdrs = array_change_key_case($_SERVER, CASE_UPPER);
        }
        if (isset($hdrs[$key])) {
            return $hdrs[$key];
        }
        return null;
    }
    
    
    /**
     * Convert a string from one charset to another.
     * Uses mbstring and iconv functions if possible
     *
     * @param  string Input string
     * @param  string Suspected charset of the input string
     * @param  string Target charset to convert to; defaults to RCMAIL_CHARSET
     * @return Converted string
     */
    static function charset_convert($str, $from, $to=NULL)
    {
        $registry = rcube_registry::get_instance();
        $MBSTRING = $registry->get('MBSTRING', 'core');

        $from = strtoupper($from);
        $to = $to==NULL ? strtoupper(RCMAIL_CHARSET) : strtoupper($to);

        if ($from==$to || $str=='' || empty($from)) {
            return $str;
        }
        // convert charset using mbstring module
        if ($MBSTRING) {
            $to = $to=="UTF-7" ? "UTF7-IMAP" : $to;
            $from = $from=="UTF-7" ? "UTF7-IMAP": $from;

            // return if convert succeeded
            if (($out = @mb_convert_encoding($str, $to, $from)) != '') {
                return $out;
            }
        }

        // convert charset using iconv module
        if (function_exists('iconv') && $from!='UTF-7' && $to!='UTF-7')
            return iconv($from, $to, $str);

        $conv = new utf8();

        // convert string to UTF-8
        if ($from=='UTF-7') {
            $str = utf7_to_utf8($str);
        }
        else if (($from=='ISO-8859-1') && function_exists('utf8_encode')) {
            $str = utf8_encode($str);
        }
        else if ($from!='UTF-8') {
            $conv->loadCharset($from);
            $str = $conv->strToUtf8($str);
        }

        // encode string for output
        if ($to=='UTF-7') {
            return utf8_to_utf7($str);
        }
        else if ($to=='ISO-8859-1' && function_exists('utf8_decode')) {
            return utf8_decode($str);
        }
        else if ($to!='UTF-8') {
            $conv->loadCharset($to);
            return $conv->utf8ToStr($str);
        }

        // return UTF-8 string
        return $str;
    }


    /**
     * Replacing specials characters to a specific encoding type
     *
     * @param  string  Input string
     * @param  string  Encoding type: text|html|xml|js|url
     * @param  string  Replace mode for tags: show|replace|remove
     * @param  boolean Convert newlines
     * @return The quoted string
     */
    static function rep_specialchars_output($str, $enctype='', $mode='', $newlines=TRUE)
    {
        static $html_encode_arr, $js_rep_table, $xml_rep_table;
        
        $out_charset = rcube_registry::get_instance()->get('OUTPUT_CHARSET', 'core');

        if (!$enctype) {
            $enctype = $registry->get('OUTPUT_TYPE', 'core');
        }

        // encode for plaintext
        if ($enctype == 'text') {
            return str_replace(
                "\r\n",
                "\n",
                $mode=='remove' ? strip_tags($str) : $str
            );
        }
        
        // encode for HTML output
        if ($enctype == 'html') {
            if (empty($html_encode_arr)) {
                $html_encode_arr = get_html_translation_table(HTML_SPECIALCHARS);
                unset($html_encode_arr['?']);
            }

            $ltpos = strpos($str, '<');
            $encode_arr = $html_encode_arr;

            // don't replace quotes and html tags
            if (
                ($mode == 'show' || $mode == '')
                && $ltpos!==false
                && strpos($str, '>', $ltpos)!==false
            ) {
                unset($encode_arr['"']);
                unset($encode_arr['<']);
                unset($encode_arr['>']);
                unset($encode_arr['&']);
            }
            else if ($mode == 'remove') {
                $str = strip_tags($str);
            }
            // avoid douple quotation of &
            $out = preg_replace(
                '/&amp;([a-z]{2,5}|#[0-9]{2,4});/',
                '&\\1;',
                strtr($str, $encode_arr)
            );
            return $newlines ? nl2br($out) : $out;
        }

        if ($enctype == 'url') {
            return rawurlencode($str);
        }
        
        // if the replace tables for XML and JS are not yet defined
        if (empty($js_rep_table)) {
            $js_rep_table = $xml_rep_table = array();
            $xml_rep_table['&'] = '&amp;';

            for ($c=160; $c<256; $c++)  // can be increased to support more charsets
            {
                $xml_rep_table[Chr($c)] = "&#$c;";

                if ($out_charset == 'ISO-8859-1') {
                    $js_rep_table[Chr($c)] = sprintf("\\u%04x", $c);
                }
            }
            $xml_rep_table['"'] = '&quot;';
        }

        // encode for XML
        if ($enctype == 'xml') {
            return strtr($str, $xml_rep_table);
        }
        
        // encode for javascript use
        if ($enctype == 'js') {
            if ($out_charset != 'UTF-8') {
                $str = self::charset_convert($str, RCMAIL_CHARSET, $out_charset);
            }
            return preg_replace(
                array("/\r?\n/", "/\r/"),
                array('\n', '\n'),
                addslashes(strtr($str, $js_rep_table))
            );
        }
        
        // no encoding given -> return original string
        return $str;
    }
    
    
    /**
     * Compose a valid attribute string for HTML tags
     *
     * @param array Named tag attributes
     * @param array List of allowed attributes
     * @return string HTML formatted attribute string
     * @uses html::attrib_string
     * @deprecated
     */
    static function create_attrib_string($attrib, $allowed=array('id', 'class', 'style'))
    {
        return html::attrib_string($attrib, $allowed);
    }


    /**
     * Convert a HTML attribute string attributes to an associative array (name => value)
     *
     * @param string Input string
     * @return array Key-value pairs of parsed attributes
     */
    function parse_attrib_string($str)
    {
        $attrib = array();
        preg_match_all(
            '/\s*([-_a-z]+)=(["\'])([^"]+)\2/Ui',
            stripslashes($str),
            $regs,
            PREG_SET_ORDER
        );

        // convert attributes to an associative array (name => value)
        if ($regs) {
            foreach ($regs as $attr) {
                $attrib[strtolower($attr[1])] = $attr[3];
            }
        }
        return $attrib;
    }
    
    
    /****** debugging functions ********/


    /**
     * tfk_debug
     *
     * @param  string $str
     * @return void
     */
    static function tfk_debug($str)
    {
        $str = "\n\n" . @date('Y-m-d H:i:s') . "\n" . $str;
        $fp = @fopen(dirname(__FILE__) . '/../../logs/debug.tfk', 'a');
        if ($fp !== false) {
            @fwrite($fp, $str);
            @fclose($fp);
        }
        else {
            die('Could not open logs/debug.tfk.');
        }
    }
    

    /**
     * Print or write debug messages
     *
     * @param mixed Debug message or data
     */
    function console($msg)
    {
        $registry = rcube_registry::get_instance();
        $CONFIG   = $registry->get_all('config');
        if (!is_string($msg)) {
            $msg = var_export($msg, true);
        }
        if (!($CONFIG['debug_level'] & 4)) {
            write_log('console', $msg);
        }
        elseif ($GLOBALS['REMOTE_REQUEST']) {
            echo "/*\n $msg \n*/\n";
        }
        else {
            echo '<div style="background:#eee; border:1px solid #ccc; ';
            echo 'margin-bottom:3px; padding:6px"><pre>';
            echo $msg;
            echo "</pre></div>\n";
        }
    }


    /**
     * Append a line to a logfile in the logs directory.
     * Date will be added automatically to the line.
     *
     * @param string Name of logfile
     * @param string Line to append
     */
    function write_log($name, $line)
    {
        $registry = rcube_registry::get_instance();
        $log_dir  = $registry->get('log_dir', 'config');

        if (!is_string($line)) {
            $line = var_export($line, true);
        }
        $log_entry = sprintf(
            "[%s]: %s\n",
            date("d-M-Y H:i:s O", mktime()),
            $line
        );

        if (empty($log_dir)) {
            $log_dir = INSTALL_PATH . 'logs';
        }
        // try to open specific log file for writing
        if ($fp = @fopen($log_dir . '/' . $name, 'a')) {
            fwrite($fp, $log_entry);
            fclose($fp);
        }
    }


    static function timer()
    {
        list($usec, $sec) = explode(" ", microtime());
        return ((float)$usec + (float)$sec);
    }

    static function print_time($timer, $label='Timer')
    {
        static $print_count = 0;

        $print_count++;
        $now = rcube_timer();
        $diff = $now-$timer;

        if (empty($label)) {
            $label = 'Timer '.$print_count;
        }
        rcube::console(sprintf("%s: %0.4f sec", $label, $diff));
    }
    
    
}

