<?php
/*
 +-----------------------------------------------------------------------+
 | program/include/main.inc                                              |
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
 +-----------------------------------------------------------------------+

 $Id: main.inc 567 2007-05-17 18:41:24Z thomasb $

*/

require_once 'lib/des.inc';
require_once 'lib/utf7.inc';
require_once 'lib/utf8.class.php';
require_once 'include/rcmail_template.inc';


// define constannts for input reading
define('RCUBE_INPUT_GET',  0x0101);
define('RCUBE_INPUT_POST', 0x0102);
define('RCUBE_INPUT_GPC',  0x0103);

class rc_main
{

    private function __construct()
    {

    }
    /**
     * tfk_debug
     *
     * @param string $str
     * @ignore
     */
    static function tfk_debug($str)
    {
        $str = "\n\n" . @date('Y-m-d H:i:s') . "\n" . $str;
        $fp = @fopen(dirname(__FILE__) . '/../../logs/debug.tfk', 'a');
        if ($fp !== false) {
            @fwrite($fp, $str);
            @fclose($fp);
        } else {
            die('Could not open logs/debug.tfk.');
        }
    }

    /**
     * register session and connect to server
     *
     * @access public
     * @todo   Remove this include from here. This is not good for bytecode caching.
     * @param  string $task
     */
    static function rcmail_startup($task = 'mail')
    {
        $registry     = rc_registry::getInstance();
        $INSTALL_PATH = $registry->get('INSTALL_PATH', 'core');

        self::tfk_debug('Start me up.');

        // check client
        $BROWSER = rcube_browser();
        $registry->set('BROWSER', $BROWSER, 'core');

        self::tfk_debug('// load browser');

        // load configuration
        $CONFIG = self::rcmail_load_config();
        $registry->set('CONFIG', $CONFIG, 'core');

        self::tfk_debug('// load config');

        // set session garbage collecting time according to session_lifetime
        if (empty($CONFIG['session_lifetime']) === FALSE) {
            ini_set('session.gc_maxlifetime', ($CONFIG['session_lifetime']) * 120);
        }
        // prepare DB connection
        require_once 'include/rcube_'.(empty($CONFIG['db_backend']) ? 'db' : $CONFIG['db_backend']).'.inc';

        $DB = new rcube_db($CONFIG['db_dsnw'], $CONFIG['db_dsnr'], $CONFIG['db_persistent']);
        $DB->sqlite_initials = $INSTALL_PATH.'SQL/sqlite.initial.sql';
        $DB->db_connect('w');

        $registry->set('DB', $DB, 'core');

        self::tfk_debug('// init db');

        // use database for storing session data
        include_once 'include/session.inc';

        // init session
        session_start();
        $sess_id = session_id();
        $registry->set('sess_id', $sess_id, 'core');

        // create session and set session vars
        if (!isset($_SESSION['auth_time'])) {
            $_SESSION['user_lang'] = self::rcube_language_prop($CONFIG['locale_string']);
            $_SESSION['auth_time'] = time();
            $_SESSION['temp'] = true;
        }

        // set session vars global
        $sess_user_lang = self::rcube_language_prop($_SESSION['user_lang']);
        $registry->set('sess_user_lang', $sess_user_lang, 'core');

        // overwrite config with user preferences
        if (is_array($_SESSION['user_prefs'])) {
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
        if ($task=='mail') {
            self::rcmail_imap_init();
        }

        // set localization
        if ($CONFIG['locale_string']) {
            setlocale(LC_ALL, $CONFIG['locale_string']);
        }
        else if ($sess_user_lang) {
            setlocale(LC_ALL, $sess_user_lang);
        }
        register_shutdown_function(array('rc_main', 'rcmail_shutdown'));
    }


    // load roundcube configuration into global var
    static function rcmail_load_config()
    {
        $registry     = rc_registry::getInstance();
        $INSTALL_PATH = $registry->get('INSTALL_PATH', 'core');

        // load config file
        $status = @include_once 'config/main.inc.php';
        if ($status === FALSE) {
            die('Could not open config/main.inc.php.');
        }
        $conf = is_array($rcmail_config) ? $rcmail_config : array();

        // load host-specific configuration
        $conf = self::rcmail_load_host_config($conf);

        $conf['skin_path'] = $conf['skin_path'] ? unslashify($conf['skin_path']) : 'skins/default';

        // load db conf
        @include_once 'config/db.inc.php';
        $conf = array_merge($conf, $rcmail_config);

        if (empty($conf['log_dir'])) {
            $conf['log_dir'] = $INSTALL_PATH . 'logs';
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
        return $conf;
    }


    // load a host-specific config file if configured
    static function rcmail_load_host_config($config)
    {
        $fname = NULL;

        if (is_array($config['include_host_config'])) {
            $fname = $config['include_host_config'][$_SERVER['HTTP_HOST']];
        }
        else if (!empty($config['include_host_config'])) {
            $fname = preg_replace('/[^a-z0-9\.\-_]/i', '', $_SERVER['HTTP_HOST']) . '.inc.php';
        }
        if ($fname && is_file('config/' . $fname)) {
            include('config/' . $fname);
            $config = array_merge($config, $rcmail_config);
        }
        return $config;
    }


    // create authorization hash
    static function rcmail_auth_hash($sess_id, $ts)
    {
        $registry = rc_registry::getInstance();
        $CONFIG   = $registry->get('CONFIG', 'core');

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


    // compare the auth hash sent by the client with the local session credentials
    function rcmail_authenticate_session()
    {
        $registry       = rc_registry::getInstance();
        $CONFIG         = $registry->get('CONFIG', 'core');
        $SESS_CLIENT_IP = $registry->get('SESS_CLIENT_IP', 'core');
        $SESS_CHANGED   = $registry->get('SESS_CHANGED', 'core');

        // advanced session authentication
        if ($CONFIG['double_auth']) {
            $now = time();
            $valid = ($_COOKIE['sessauth'] == self::rcmail_auth_hash(session_id(), $_SESSION['auth_time']) ||
                  $_COOKIE['sessauth'] == self::rcmail_auth_hash(session_id(), $_SESSION['last_auth']));

            // renew auth cookie every 5 minutes (only for GET requests)
            if (!$valid || ($_SERVER['REQUEST_METHOD']!='POST' && $now-$_SESSION['auth_time'] > 300)) {
                $_SESSION['last_auth'] = $_SESSION['auth_time'];
                $_SESSION['auth_time'] = $now;
                setcookie('sessauth', self::rcmail_auth_hash(session_id(), $now));
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


    // create IMAP object and connect to server
    static function rcmail_imap_init($connect=FALSE)
    {
        $registry = rc_registry::getInstance();
        $CONFIG   = $registry->get('CONFIG', 'core');
        $DB       = $registry->get('DB', 'core');
        $OUTPUT   = $registry->get('OUTPUT', 'core');

        $IMAP = new rcube_imap($DB);
        $IMAP->debug_level = $CONFIG['debug_level'];
        $IMAP->skip_deleted = $CONFIG['skip_deleted'];

        // connect with stored session data
        if ($connect) {
            $conn = $IMAP->connect(
                            $_SESSION['imap_host'],
                            $_SESSION['username'],
                            decrypt_passwd($_SESSION['password']),
                            $_SESSION['imap_port'],
                            $_SESSION['imap_ssl']
            );
            $registry->set('IMAP', $IMAP, 'core');
            if ($conn === false) {
                $OUTPUT->show_message('imaperror', 'error');
            }
            self::rcmail_set_imap_prop();
        }

        // enable caching of imap data
        if ($CONFIG['enable_caching']===TRUE) {
            $IMAP->set_caching(TRUE);
        }
        // set pagesize from config
        if (isset($CONFIG['pagesize'])) {
            $IMAP->set_pagesize($CONFIG['pagesize']);
        }
        $registry->set('IMAP', $IMAP, 'core');
    }


    // set root dir and last stored mailbox
    // this must be done AFTER connecting to the server
    static function rcmail_set_imap_prop()
    {
        $registry = rc_registry::getInstance();
        $CONFIG   = $registry->get('CONFIG', 'core');
        $IMAP     = $registry->get('IMAP', 'core');

        // set root dir from config
        if (!empty($CONFIG['imap_root'])) {
            $IMAP->set_rootdir($CONFIG['imap_root']);
        }
        if (is_array($CONFIG['default_imap_folders'])) {
            $IMAP->set_default_mailboxes($CONFIG['default_imap_folders']);
        }
        if (!empty($_SESSION['mbox'])) {
            $IMAP->set_mailbox($_SESSION['mbox']);
        }
        if (isset($_SESSION['page'])) {
            $IMAP->set_page($_SESSION['page']);
        }
        $registry->set('IMAP', $IMAP, 'core');
    }


    // do these things on script shutdown
    static function rcmail_shutdown()
    {
        $registry = rc_registry::getInstance();
        $IMAP     = $registry->get('IMAP', 'core');

        if (is_object($IMAP)) {
            $IMAP->close();
            $IMAP->write_cache();
        }

        // before closing the database connection, write session data
        session_write_close();
    }

    // destroy session data and remove cookie
    static function rcmail_kill_session()
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
            rcmail_save_user_prefs($a_user_prefs);
        }

        $_SESSION = array(
                        'user_lang' => $GLOBALS['sess_user_lang'],
                        'auth_time' => time(),
                        'temp' => true
        );
        setcookie('sessauth', '-del-', time()-60);
    }


    // return correct name for a specific database table
    static function get_table_name($table)
    {
        $registry = rc_registry::getInstance();
        $CONFIG   = $registry->get('CONFIG', 'core');

        // return table name if configured
        $config_key = 'db_table_' . $table;

        if (strlen($CONFIG[$config_key])) {
            return $CONFIG[$config_key];
        }
        return $table;
    }


    // return correct name for a specific database sequence
    // (used for Postres only)
    static function get_sequence_name($sequence)
    {
        $registry = rc_registry::getInstance();
        $CONFIG   = $registry->get('CONFIG', 'core');

        // return table name if configured
        $config_key = 'db_sequence_' . $sequence;

        if (strlen($CONFIG[$config_key])) {
            return $CONFIG[$config_key];
        }
        return $table;
    }


    // check the given string and returns language properties
    static function rcube_language_prop($lang, $prop='lang')
    {
        $registry               = rc_registry::getInstance();
        $INSTALL_PATH           = $registry->get('INSTALL_PATH', 'core');
        $rcube_languages        = $registry->get('rcube_languages', 'core');
        $rcube_language_aliases = $registry->get('rcube_language_aliases', 'core');
        $rcube_charsets         = $registry->get('rcube_charsets', 'core');

        if (empty($rcube_languages)) {
            $status = @include($INSTALL_PATH.'program/localization/index.inc');
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
            $lang = self::rcube_language_prop($lang);
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

        $registry->set('rcube_languages', $rcube_languages, 'core');
        $registry->set('rcube_language_aliases', $rcube_language_aliases, 'core');
        $registry->set('rcube_charsets', $rcube_charsets, 'core');

        if ($prop=='charset') {
            return $charset;
        }

        return $lang;
    }


    // init output object for GUI and add common scripts
    static function rcmail_load_gui()
    {
        $registry       = rc_registry::getInstance();
        $CONFIG         = $registry->get('CONFIG', 'core');
        $sess_user_lang = $registry->get('sess_user_lang', 'core');

        // init output page
        $OUTPUT = new rcmail_template($CONFIG, $GLOBALS['_task']);
        $OUTPUT->set_env('comm_path', $GLOBALS['COMM_PATH']);

        if (is_array($CONFIG['javascript_config'])) {
            foreach ($CONFIG['javascript_config'] as $js_config_var) {
                $OUTPUT->set_env($js_config_var, $CONFIG[$js_config_var]);
            }
        }

        if (!empty($GLOBALS['_framed'])) {
            $OUTPUT->set_env('framed', true);
        }
        $registry->set('OUTPUT', $OUTPUT, 'core');
        // set locale setting
        self::rcmail_set_locale($sess_user_lang);

        // set user-selected charset
        if (!empty($CONFIG['charset'])) {
            $OUTPUT->set_charset($CONFIG['charset']);
        }
        $registry->set('OUTPUT', $OUTPUT, 'core');
        // register common UI objects
        $OUTPUT->add_handlers(
                array(
                    'loginform' => 'rcmail_login_form',
                    'username'  => 'rcmail_current_username',
                    'message' => 'rcmail_message_container',
                    'charsetselector' => 'rcmail_charset_selector',
                )
        );
        // add some basic label to client
        if (!$OUTPUT->ajax_call) {
            self::rcube_add_label('loading');
        }
    }


    // set localization charset based on the given language
    static function rcmail_set_locale($lang)
    {
        $registry          = rc_registry::getInstance();
        $OUTPUT            = $registry->get('OUTPUT', 'core');
        $MBSTRING          = $registry->get('MBSTRING', 'core');
        $s_mbstring_loaded = $registry->get('s_mbstring_loaded', 'core');

        // settings for mbstring module (by Tadashi Jokagi)
        if (is_null($s_mbstring_loaded)) {
            $MBSTRING = $s_mbstring_loaded = extension_loaded("mbstring");
        }
        else {
            $MBSTRING = $s_mbstring_loaded = FALSE;
        }
        $registry->set('MBSTRING', $MBSTRING, 'core');
        $registry->set('s_mbstring_loaded', $s_mbstring_loaded, 'core');

        if ($MBSTRING) {
            mb_internal_encoding(RCMAIL_CHARSET);
        }
        $OUTPUT->set_charset(self::rcube_language_prop($lang, 'charset'));
    }


    /**
     * auto-select IMAP host based on the posted login information
     *
     * @access static
     * @link   ./index.php
     * @todo   Remove reference to $_POST superglobal.
     */
    static function rcmail_autoselect_host()
    {
        $registry = rc_registry::getInstance();
        $CONFIG   = $registry->get('CONFIG', 'core');

        $host = '';
        if (isset($_POST['_host']) && empty($_POST['_host']) === false) {
            $host .= self::get_input_value('_host', RCUBE_INPUT_POST);
        }
        else {
            $host .= $CONFIG['default_host'];
        }
        //self::tfk_debug('/ POST: ' . $_POST['_host']);
        //self::tfk_debug('/ default host: ' . var_export($CONFIG['default_host'], true));

        if (is_array($host)) {
            list($user, $domain) = explode('@', self::get_input_value('_user', RCUBE_INPUT_POST));
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
        self::tfk_debug($host);
        return $host;
    }


    // perfom login to the IMAP server and to the webmail service
    static function rcmail_login($user, $pass, $host=NULL)
    {
        $user_id = NULL;

        $registry       = rc_registry::getInstance();
        $CONFIG         = $registry->get('CONFIG', 'core');
        $IMAP           = $registry->get('IMAP', 'core');
        $DB             = $registry->get('DB', 'core');
        $sess_user_lang = $registry->get('sess_user_lang', 'core');

        if (is_null($host) === true) {
            $host = $CONFIG['default_host'];
        }
        // Validate that selected host is in the list of configured hosts
        if (is_array($CONFIG['default_host'])) {
            $allowed = FALSE;
            foreach ($CONFIG['default_host'] as $key => $host_allowed) {
                if (!is_numeric($key)) {
                    $host_allowed = $key;
                }
                if ($host == $host_allowed) {
                    $allowed = TRUE;
                    break;
                }
            }
            if (!$allowed) {
                return FALSE;
            }
        }
        else if (!empty($CONFIG['default_host']) && $host != $CONFIG['default_host']) {
            return FALSE;
        }

        // parse $host URL
        $a_host = parse_url($host);
        if ($a_host['host']) {
            $host = $a_host['host'];
            $imap_ssl = (isset($a_host['scheme']) && in_array($a_host['scheme'], array('ssl','imaps','tls'))) ? TRUE : FALSE;
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

        // try to resolve email address from virtuser table
        if (!empty($CONFIG['virtuser_file']) && strstr($user, '@')) {
            $user = rcmail_email2user($user);
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
                $sess_user_lang = $_SESSION['user_lang'] = $sql_arr['language'];
            }
            // update user's record
            $_query = "UPDATE " . self::get_table_name('users');
            $_query.= " SET last_login=" . $DB->now();
            $_query.= " WHERE user_id=?";
            $DB->query($_query, $user_id);
        }
        // create new system user
        else if ($CONFIG['auto_create_user']) {
            $user_id = self::rcmail_create_user($user, $host);
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
        $_SESSION['user_lang']  = $sess_user_lang;
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
        self::rcmail_set_imap_prop();
        $IMAP->clear_cache('mailboxes');
        $IMAP->create_default_folders();

        return TRUE;
    }


    // create new entry in users and identities table
    static function rcmail_create_user($user, $host)
    {
        $registry = rc_registry::getInstance();
        $DB       = $registry->get('DB', 'core');
        $CONFIG   = $registry->get('CONFIG', 'core');
        $IMAP     = $registry->get('IMAP', 'core');

        $user_email = '';

        // try to resolve user in virtusertable
        if (!empty($CONFIG['virtuser_file']) && strstr($user, '@')==FALSE) {
            $user_email = self::rcmail_user2email($user);
        }
        $_query = "INSERT INTO " . self::get_table_name('users');
        $_query.= " (created, last_login, username, mail_host, alias, language)";
        $_query.= " VALUES (" . $DB->now() . ", " . $DB->now() . ", ?, ?, ?, ?)";
        $DB->query(
                $_query,
                self::strip_newlines($user),
                self::strip_newlines($host),
                self::strip_newlines($user_email),
                $_SESSION['user_lang']
        );

        if ($user_id = $DB->insert_id(self::get_sequence_name('users'))) {
            $mail_domain = self::rcmail_mail_domain($host);

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
                            self::strip_newlines($user_name),
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
                        self::strip_newlines($user_name),
                        self::strip_newlines($user_email)
                );
            }

            // get existing mailboxes
            $a_mailboxes = $IMAP->list_mailboxes();
        }
        else {
            raise_error(
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


    // load virtuser table in array
    function rcmail_getvirtualfile()
    {
        $registry = rc_registry::getInstance();
        $registry->get('CONFIG', 'core');
        if (empty($CONFIG['virtuser_file']) || !is_file($CONFIG['virtuser_file'])) {
            return FALSE;
        }
        // read file
        $a_lines = file($CONFIG['virtuser_file']);
        return $a_lines;
    }


    // find matches of the given pattern in virtuser table
    static function rcmail_findinvirtual($pattern)
    {
        $result  = array();
        $virtual = self::rcmail_getvirtualfile();
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


    // resolve username with virtuser table
    static function rcmail_email2user($email)
    {
        $user = $email;
        $r = self::rcmail_findinvirtual("^$email");

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


    // resolve e-mail address with virtuser table
    static function rcmail_user2email($user)
    {
        $email = "";
        $r = self::rcmail_findinvirtual("$user$");

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


    function rcmail_save_user_prefs($a_user_prefs)
    {
        $registry       = rc_registry::getInstance();
        $DB             = $registry->get('DB', 'core');
        $CONFIG         = $registry->get('CONFIG', 'core');
        $sess_user_lang = $registry->get('sess_user_lang', 'core');

        $_query = "UPDATE " . self::get_table_name('users');
        $_query.= " SET preferences=?,";
        $_query.= " language=?";
        $_query.= " WHERE user_id=?";
        $DB->query(
                $_query,
                serialize($a_user_prefs),
                $sess_user_lang,
                $_SESSION['user_id']
        );

        if ($DB->affected_rows()) {
            $_SESSION['user_prefs'] = $a_user_prefs;
            $CONFIG = array_merge($CONFIG, $a_user_prefs);
            $registry->set('CONFIG', $CONFIG, 'core');
            return TRUE;
        }

        return FALSE;
    }


    // overwrite action variable
    static function rcmail_overwrite_action($action)
    {
        $registry = rc_registry::getInstance();
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
    static function rcmail_url($action, $p=array(), $task=null)
    {
        $registry   = rc_registry::getInstance();
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
     *@deprecated
     */

    function show_message($message, $type='notice', $vars=NULL)
    {
        $registry = rc_registry::getInstance();
        $OUTPUT   = $registry->get('OUTPUT', 'core');
        $OUTPUT->show_message($message, $type, $vars);

    }



    // encrypt IMAP password using DES encryption
    static function encrypt_passwd($pass)
    {
        $cypher = des(self::get_des_key(), $pass, 1, 0, NULL);
        return base64_encode($cypher);
    }

    // decrypt IMAP password using DES encryption
    static function decrypt_passwd($cypher)
    {
        $pass = des(self::get_des_key(), base64_decode($cypher), 0, 0, NULL);
        return preg_replace('/\x00/', '', $pass);
    }


    // return a 24 byte key for the DES encryption
    static function get_des_key()
    {
        $registry = rc_registry::getInstance();
        $CONFIG   = $registry->get('CONFIG', 'core');
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


    // read directory program/localization/ and return a list of available languages
    function rcube_list_languages()
    {
        $registry     = rc_registry::getInstance();
        $CONFIG       = $registry->get('CONFIG', 'core');
        $INSTALL_PATH = $registry->get('INSTALL_PATH', 'core');
        $sa_languages = $registry->get('sa_languages', 'core');

        if (empty($sa_languages)) {
            $sa_languages = array();
            @include($INSTALL_PATH.'program/localization/index.inc');

            if ($dh = @opendir($INSTALL_PATH.'program/localization')) {
                while (($name = readdir($dh)) !== false) {
                    if ($name{0}=='.' || !is_dir($INSTALL_PATH.'program/localization/'.$name))
                        continue;

                    if ($label = $rcube_languages[$name])
                        $sa_languages[$name] = $label ? $label : $name;
                }
                closedir($dh);
            }
            $registry->set('sa_languages', $sa_languages, 'core');
        }
        return $sa_languages;
    }


    // add a localized label to the client environment
    static function rcube_add_label()
    {
        $registry = rc_registry::getInstance();
        $OUTPUT   = $registry->get('OUTPUT', 'core');

        $arg_list = func_get_args();
        foreach ($arg_list as $i => $name) {
            $OUTPUT->command('add_label', $name, rcube_label($name));
        }
    }


    // remove temp files older than two day
    function rcmail_temp_gc()
    {
        $registry = rc_registry::getInstance();
        $CONFIG   = $registry->get('CONFIG', 'core');
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


    // remove all expired message cache records
    static function rcmail_message_cache_gc()
    {
        $registry = rc_registry::getInstance();
        $DB       = $registry->get('DB', 'core');
        $CONFIG   = $registry->get('CONFIG', 'core');

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
     * Convert a string from one charset to another.
     * Uses mbstring and iconv functions if possible
     *
     * @param  string Input string
     * @param  string Suspected charset of the input string
     * @param  string Target charset to convert to; defaults to RCMAIL_CHARSET
     * @return Converted string
     */
    static function rcube_charset_convert($str, $from, $to=NULL)
    {
        $registry = rc_registry::getInstance();
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
            if (($out = mb_convert_encoding($str, $to, $from)) != '') {
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
        $registry        = rc_registry::getInstance();
        $OUTPUT_TYPE     = $registry->get('OUTPUT_TYPE', 'core');
        $OUTPUT          = $registry->get('OUTPUT', 'core');
        $html_encode_arr = $registry->get('html_encode_arr', 'core');
        $js_rep_table    = $registry->get('js_rep_table', 'core');
        $xml_rep_table   = $registry->get('xml_rep_table', 'core');

        if (!$enctype) {
            $enctype = $OUTPUT_TYPE;
        }
        // convert nbsps back to normal spaces if not html
        if ($enctype!='html') {
            $str = str_replace(chr(160), ' ', $str);
        }
        // encode for plaintext
        if ($enctype=='text') {
            return str_replace(
                        "\r\n",
                        "\n",
                        $mode=='remove' ? strip_tags($str) : $str
            );
        }
        // encode for HTML output
        if ($enctype=='html') {

            //self::tfk_debug('/ html :-)');

            if (empty($html_encode_arr)) {
                $html_encode_arr = get_html_translation_table(HTML_SPECIALCHARS);
                unset($html_encode_arr['?']);
                $registry->set(
                            'html_encode_arr',
                            $html_encode_arr,
                            'core'
                );
            }

            $ltpos = strpos($str, '<');
            $encode_arr = $html_encode_arr;

            //self::tfk_debug(var_export($encode_arr, true));

            // don't replace quotes and html tags
            if (
                ($mode=='show' || $mode=='')
                && $ltpos!==false
                && strpos($str, '>', $ltpos)!==false
            ) {
                unset($encode_arr['"']);
                unset($encode_arr['<']);
                unset($encode_arr['>']);
                unset($encode_arr['&']);
            }
            else if ($mode=='remove') {
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

        if ($enctype=='url') {
            return rawurlencode($str);
        }
        // if the replace tables for XML and JS are not yet defined
        if (empty($js_rep_table)) {
            $js_rep_table = $xml_rep_table = array();
            $xml_rep_table['&'] = '&amp;';

            for ($c=160; $c<256; $c++)  // can be increased to support more charsets
            {
                $hex = dechex($c);
                $xml_rep_table[Chr($c)] = "&#$c;";

                if ($OUTPUT->get_charset()=='ISO-8859-1') {
                    $js_rep_table[Chr($c)] = sprintf(
                                                "\u%s%s",
                                                str_repeat('0', 4-strlen($hex)),
                                                $hex
                    );
                }
            }
            $xml_rep_table['"'] = '&quot;';
            $registry->set('js_rep_table', $js_rep_table, 'core');
        }

        // encode for XML
        if ($enctype=='xml') {
            return strtr($str, $xml_rep_table);
        }
        // encode for javascript use
        if ($enctype=='js') {
            if ($OUTPUT->get_charset()!='UTF-8') {
                $str = self::rcube_charset_convert(
                            $str,
                            RCMAIL_CHARSET,
                            $OUTPUT->get_charset()
                );
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
     * Quote a given string. Alias function for rep_specialchars_output
     * @see rep_specialchars_output
     */
    static function Q($str, $mode='strict', $newlines=TRUE)
    {
        return self::rep_specialchars_output($str, 'html', $mode, $newlines);
    }

    /**
     * Quote a given string. Alias function for rep_specialchars_output
     * @see rep_specialchars_output
     */
    static function JQ($str)
    {
        return self::rep_specialchars_output($str, 'js');
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
    static function get_input_value($fname, $source, $allow_html=FALSE, $charset=NULL)
    {
        $registry = rc_registry::getInstance();
        $OUTPUT   = $registry->get('OUTPUT', 'core');
        $value    = NULL;

        if ($source == RCUBE_INPUT_GET && isset($_GET[$fname])) {
            $value = $_GET[$fname];
        }
        else if ($source == RCUBE_INPUT_POST && isset($_POST[$fname])) {
            $value = $_POST[$fname];
        }
        else if ($source == RCUBE_INPUT_GPC) {
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
        if (is_object($OUTPUT)) {
            return self::rcube_charset_convert($value, $OUTPUT->get_charset(), $charset);
        }
        return $value;
    }

    /**
     * Remove single and double quotes from given string
     */
    static function strip_quotes($str)
    {
        return preg_replace('/[\'"]/', '', $str);
    }

    /**
     * Remove new lines characters from given string
     */
    static function strip_newlines($str)
    {
        return preg_replace('/[\r\n]/', '', $str);
    }


    // return boolean if a specific template exists
    static function template_exists($name)
    {
        $registry = rc_registry::getInstance();
        $CONFIG   = $registry->get('CONFIG', 'core');
        $skin_path = $CONFIG['skin_path'];

        // check template file
        return is_file("$skin_path/templates/$name.html");
    }


    // Wrapper for rcmail_template::parse()
    // @deprecated
    static function parse_template($name='main', $exit=true)
    {
        $registry = rc_registry::getInstance();
        $OUTPUT   = $registry->get('OUTPUT', 'core');
        $OUTPUT->parse($name, $exit);
    }

    static function rcube_table_output($attrib, $table_data, $a_show_cols, $id_col)
    {
        $registry = rc_registry::getInstance();
        $DB       = $registry->get('DB', 'core');

        // allow the following attributes to be added to the <table> tag
        $attrib_str = self::create_attrib_string(
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
            $table .= '<td class="'.$col.'">' . self::Q(rcube_label($col)) . "</td>\n";
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
                    $cont = self::Q($sql_arr[$col]);
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
                    $cont = self::Q($row_data[$col]);
                    $table .= '<td class="'.$col.'">' . $cont . "</td>\n";
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
    static function rcmail_get_edit_field($col, $value, $attrib, $type='text')
    {
        $fname = '_'.$col;
        $attrib['name'] = $fname;

        if ($type=='checkbox') {
            $attrib['value'] = '1';
            $input = new checkbox($attrib);
        }
        else if ($type=='textarea') {
            $attrib['cols'] = $attrib['size'];
            $input = new textarea($attrib);
        }
        else {
            $input = new textfield($attrib);
        }
        // use value from post
        if (!empty($_POST[$fname])) {
            $value = $_POST[$fname];
        }
        $out = $input->show($value);

        return $out;
    }


    // return the mail domain configured for the given host
    static function rcmail_mail_domain($host)
    {
        $registry = rc_registry::getInstance();
        $CONFIG   = $registry->get('CONFIG', 'core');

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


    // compose a valid attribute string for HTML tags
    static function create_attrib_string($attrib, $allowed_attribs=array('id', 'class', 'style'))
    {
        // allow the following attributes to be added to the <iframe> tag
        $attrib_str = '';
        foreach ($allowed_attribs as $a) {
            if (isset($attrib[$a])) {
                $attrib_str .= sprintf(
                                ' %s="%s"',
                                $a, str_replace('"', '&quot;', $attrib[$a])
                );
            }
        }
        return $attrib_str;
    }


    // convert a HTML attribute string attributes to an associative array (name => value)
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


    static function format_date($date, $format=NULL)
    {
        $registry       = rc_registry::getInstance();
        $CONFIG         = $registry->get('CONFIG', 'core');
        $sess_user_lang = $registry->get('sess_user_lang', 'core');

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
                    rcube_label('today'),
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
                $out .= rcube_label(strtolower(date('D', $timestamp)));
            // weekday long
            }
            elseif ($format{$i}=='l') {
                $out .= rcube_label(strtolower(date('l', $timestamp)));
            // month name (short)
            }
            elseif ($format{$i}=='M') {
                $out .= rcube_label(strtolower(date('M', $timestamp)));
            // month name (long)
            }
            elseif ($format{$i}=='F') {
                $out .= rcube_label(strtolower(date('F', $timestamp)));
            }
            else {
                $out .= date($format{$i}, $timestamp);
            }
        }
        return $out;
    }


    static function format_email_recipient($email, $name='')
    {
        if ($name && $name != $email) {
            return sprintf('%s <%s>', strpos($name, ",") ? '"'.$name.'"' : $name, $email);
        }
        else {
            return $email;
        }
    }



    // ************** functions delivering gui objects **************



    static function rcmail_message_container($attrib)
    {
        $registry = rc_registry::getInstance();
        $OUPUT    = $registry->get('OUTPUT', 'core');

        if (!$attrib['id']) {
            $attrib['id'] = 'rcmMessageContainer';
        }
        // allow the following attributes to be added to the <table> tag
        $attrib_str = self::create_attrib_string(
                            $attrib,
                            array('style', 'class', 'id')
        );
        $out = '<div' . $attrib_str . "></div>";

        $OUTPUT->add_gui_object('message', $attrib['id']);

        return $out;
    }


    // return the IMAP username of the current session
    static function rcmail_current_username($attrib)
    {
        $registry   = rc_registry::getInstance();
        $DB         = $registry->get('DB', 'core');
        $s_username = $registry->get('s_username', 'core');

        // alread fetched
        if (!empty($s_username)) {
            return $s_username;
        }
        // get e-mail address form default identity
        $_query = "SELECT email AS mailto";
        $_query.= " FROM " . self::get_table_name('identities');
        $_query.= " WHERE user_id=?";
        $_query.= " AND standard=1";
        $_query.= " AND del<>1";
        $sql_result = $DB->query(
                            $_query,
                            $_SESSION['user_id']
        );

        if ($DB->num_rows($sql_result)) {
            $sql_arr = $DB->fetch_assoc($sql_result);
            $s_username = $sql_arr['mailto'];
        }
        elseif (strstr($_SESSION['username'], '@')) {
            $s_username = $_SESSION['username'];
        }
        else {
            $s_username = $_SESSION['username'].'@'.$_SESSION['imap_host'];
        }
        return $s_username;
    }


    // return code for the webmail login form
    function rcmail_login_form($attrib)
    {
        $registry          = rc_registry::getInstance();
        $CONFIG            = $registry->get('CONFIG', 'core');
        $OUTPUT            = $registry->get('OUTPUT', 'core');
        $SESS_HIDDEN_FIELD = $registry->get('SESS_HIDDEN_FIELD', 'core');

        $_SESSION['temp'] = true;

        $labels         = array();
        $labels['user'] = rcube_label('username');
        $labels['pass'] = rcube_label('password');
        $labels['host'] = rcube_label('server');

        $input_user   = new textfield(array('name' => '_user', 'id' => 'rcmloginuser', 'size' => 30, 'autocomplete' => 'off'));
        $input_pass   = new passwordfield(array('name' => '_pass', 'id' => 'rcmloginpwd', 'size' => 30));
        $input_action = new hiddenfield(array('name' => '_action', 'value' => 'login'));

        $fields           = array();
        $fields['user']   = $input_user->show(self::get_input_value('_user', RCUBE_INPUT_POST));
        $fields['pass']   = $input_pass->show();
        $fields['action'] = $input_action->show();

        if (is_array($CONFIG['default_host'])) {
            $select_host = new select(array('name' => '_host', 'id' => 'rcmloginhost'));

            foreach ($CONFIG['default_host'] as $key => $value) {
                if (!is_array($value)) {
                    $select_host->add($value, (is_numeric($key) ? $value : $key));
                }
                else {
                    unset($select_host);
                    break;
                }
            }
            $fields['host'] = isset($select_host) ? $select_host->show($_POST['_host']) : null;
        }
        else if (!strlen($CONFIG['default_host'])) {
            $input_host     = new textfield(array('name' => '_host', 'id' => 'rcmloginhost', 'size' => 30));
            $fields['host'] = $input_host->show($_POST['_host']);
        }

        $form_name  = strlen($attrib['form']) ? $attrib['form'] : 'form';
        $form_start = !strlen($attrib['form']) ? '<form name="form" action="./" method="post">' : '';
        $form_end   = !strlen($attrib['form']) ? '</form>' : '';
        $form_host  = '';

        if ($fields['host']) {
            $form_host.= "</tr><tr>";
            $form_host.= "<td class=\"title\"><label for=\"rcmloginhost\">$labels[host]</label></td>";
            $form_host.= "<td>$fields[host]</td>";

        }
        $OUTPUT->add_gui_object('loginform', $form_name);

        $out = '';
        $out.= $form_start;
        $out.= $SESS_HIDDEN_FIELD;
        $out.= $fields[action];
        $out.= '<table><tr>';
        $out.= '<td class="title"><label for="rcmloginuser">';
        $out.= "$labels[user]</label></td>";
        $out.= "<td>$fields[user]</td>";
        $out.= '</tr><tr>';
        $out.= '<td class="title"><label for="rcmloginpwd">';
        $out.= "$labels[pass]</label></td>";
        $out.= "<td>$fields[pass]</td>";
        $out.= $form_host;
        $out.= '</tr></table>';
        $out.= $form_end;

        return $out;
    }


    static function rcmail_charset_selector($attrib)
    {
        $registry = rc_registry::getInstance();
        $OUTPUT   = $registry->get('OUTPUT', 'core');

        // pass the following attributes to the form class
        $field_attrib = array('name' => '_charset');
        foreach ($attrib as $attr => $value) {
            if (in_array($attr, array('id', 'class', 'style', 'size', 'tabindex'))) {
                $field_attrib[$attr] = $value;
            }
        }
        $charsets = array(
            'US-ASCII'     => 'ASCII (English)',
            'EUC-JP'       => 'EUC-JP (Japanese)',
            'EUC-KR'       => 'EUC-KR (Korean)',
            'BIG5'         => 'BIG5 (Chinese)',
            'GB2312'       => 'GB2312 (Chinese)',
            'ISO-2022-JP'  => 'ISO-2022-JP (Japanese)',
            'ISO-8859-1'   => 'ISO-8859-1 (Latin-1)',
            'ISO-8859-2'   => 'ISO-8895-2 (Central European)',
            'ISO-8859-7'   => 'ISO-8859-7 (Greek)',
            'ISO-8859-9'   => 'ISO-8859-9 (Turkish)',
            'Windows-1251' => 'Windows-1251 (Cyrillic)',
            'Windows-1252' => 'Windows-1252 (Western)',
            'Windows-1255' => 'Windows-1255 (Hebrew)',
            'Windows-1256' => 'Windows-1256 (Arabic)',
            'Windows-1257' => 'Windows-1257 (Baltic)',
            'UTF-8'        => 'UTF-8'
        );

        $select = new select($field_attrib);
        $select->add(array_values($charsets), array_keys($charsets));

        $set = $_POST['_charset'] ? $_POST['_charset'] : $OUTPUT->get_charset();
        return $select->show($set);
    }


    // return code for search function
    static function rcmail_search_form($attrib)
    {
        $registry = rc_registry::getInstance();
        $OUTPUT   = $registry->get('OUTPUT', 'core');

        // add some labels to client
        self::rcube_add_label('searching');

        $attrib['name'] = '_q';

        if (empty($attrib['id'])) {
            $attrib['id'] = 'rcmqsearchbox';
        }
        $input_q = new textfield($attrib);
        $out = $input_q->show();

        $OUTPUT->add_gui_object('qsearchbox', $attrib['id']);

        // add form tag around text field
        if (empty($attrib['form'])) {
            $_html = '<form name="rcmqsearchform" action="./" ';
            $_html.= 'onsubmit="%s.command(\'search\');return false" ';
            $_html.= ' style="display:inline;">%s</form>';
            $out = sprintf(
                    $_html,
                    JS_OBJECT_NAME,
                    $out
            );
        }
        return $out;
    }


    /****** debugging functions ********/


    /**
     * Print or write debug messages
     *
     * @param mixed Debug message or data
     */
    function console($msg)
    {
        $registry = rc_registry::getInstance();
        $CONFIG   = $registry->get('CONFIG', 'core');
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
     * @param $name Name of logfile
     * @param $line Line to append
     */
    function write_log($name, $line)
    {
        $registry = rc_registry::getInstance();
        $CONFIG  = $registry->get('CONFIG', 'core');

        if (!is_string($line)) {
            $line = var_export($line, true);
        }
        $log_entry = sprintf("[%s]: %s\n",
                     date("d-M-Y H:i:s O", mktime()),
                     $line);

        if (empty($CONFIG['log_dir'])) {
            $CONFIG['log_dir'] = $INSTALL_PATH.'logs';
        }
        // try to open specific log file for writing
        if ($fp = @fopen($CONFIG['log_dir'].'/'.$name, 'a')) {
            fwrite($fp, $log_entry);
            fclose($fp);
        }
    }


    function rcube_timer()
    {
        list($usec, $sec) = explode(" ", microtime());
        return ((float)$usec + (float)$sec);
    }


    function rcube_print_time($timer, $label='Timer')
    {
        static $print_count = 0;

        $print_count++;
        $now = rcube_timer();
        $diff = $now-$timer;

        if (empty($label)) {
            $label = 'Timer '.$print_count;
        }
        console(sprintf("%s: %0.4f sec", $label, $diff));
    }
}