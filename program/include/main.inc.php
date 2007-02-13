<?php

/*
 +-----------------------------------------------------------------------+
 | program/include/main.inc                                              |
 |                                                                       |
 | This file is part of the RoundCube Webmail client                     |
 | Copyright (C) 2005, RoundCube Dev, - Switzerland                      |
 | Licensed under the GNU GPL                                            |
 |                                                                       |
 | PURPOSE:                                                              |
 |   Provide basic functions for the webmail package                     |
 |                                                                       |
 +-----------------------------------------------------------------------+
 | Author: Thomas Bruederli <roundcube@gmail.com>                        |
 +-----------------------------------------------------------------------+

 $Id: main.inc 429 2006-12-22 22:26:24Z thomasb $

*/

/**
 * rcException
 *
 * @author Till Klampaeckel <klampaeckel@lagged.de>
 * @desc   Wrapper to possibly add more methods later on.
 * @uses   Pear_Exception
 */
class rcException extends PEAR_Exception { }


require_once('lib/des.inc');
require_once('lib/utf7.inc');
require_once('lib/utf8.class.php');

include_once('config/main.inc.php');
include_once('config/db.inc.php');
include_once('program/include/session.inc');


// define constannts for input reading
define('RCUBE_INPUT_GET', 0x0101);
define('RCUBE_INPUT_POST', 0x0102);
define('RCUBE_INPUT_GPC', 0x0103);

/**
 * rcCore
 *
 * @author Thomas Bruederli <roundcube@gmail.com>
 * @author Till Klampaeckel <klampaeckel@lagged.de>
 * @desc   Container for most rcmail_*() and rcube_*() calls
 * @uses   Services_JSON
 * @see    rcException
 * @todo   A lot!
 * @since  vNext
 */
class rcCore
{
    var $CHARSET;
    var $CONFIG;
    var $DB;
    var $INSTALL_PATH;
    var $BROWSER;
    var $OUTPUT;
    var $IMAP;
    var $JS_OBJECT_NAME;

    var $MBSTRING;
    var $MBSTRING_ENCOING;

    var $sess_id;
    var $sess_auth;
    var $sess_user_lang;

    var $json;

    /**
     * enforce calling of factory method
     */
    private function __construct() {}

    /**
     * PHP4 equivalent to __construct
     */
    function rcCore() {}

    // TODO: remove references to $GLOBALS
    // TODO: check for PHP5 json extension and use it instead of Services_JSON
    /**
     * factory
     *
     * @access static
     * @return mixed  rcException or rcCore class object
     */
    static function factory()
    {
        //var_dump($GLOBALS['rcmail_config']);
        //throw new rcException('Error: this is a test');

        $json = new Services_JSON;
        if (Services_JSON::isError($json))
        {
            throw new rcException($json->getMessage());
        }
        if (!is_object($json))
        {
            throw new rcException('Could not initialize "Services_JSON"');
        }

        $cls = new rcCore;

        $cls->json = $json;

        $cls->CONFIG = (isset($GLOBALS['rc_mail_config']) && is_array($GLOBALS['rcmail_config'])) ? $GLOBALS['rcmail_config'] : array();
        $cls->CONFIG['skin_path'] = isset($cls->CONFIG['skin_path']) && !empty($cls->CONFIG['skin_path']) ? rcMisc::unslashify($cls->CONFIG['skin_path']) : 'skins/default';
        $cls->CONFIG = array_merge($cls->CONFIG, $GLOBALS['rcmail_config']);

        $cls->INSTALL_PATH = $GLOBALS['INSTALL_PATH'];

        if (!isset($cls->CONFIG['log_dir']) || empty($cls->CONFIG['log_dir']))
        {
            $cls->CONFIG['log_dir'] = $cls->INSTALL_PATH . 'logs';
        }
        else
        {
            $cls->CONFIG['log_dir'] = rcMisc::unslashify($cls->CONFIG['log_dir']);
        }
        //echo $cls->CONFIG['debug_level'];
        //return $cls;
        // set PHP error logging according to config
        if (isset($cls->CONFIG['debug_level']) && $cls->CONFIG['debug_level'] == 1)
        {
            ini_set('log_errors', 1);
            ini_set('error_log', $cls->CONFIG['log_dir'].'/errors');
        }
        if (isset($cls->CONFIG['debug_level']) && $cls->CONFIG['debug_level'] == 4)
        {
            ini_set('display_errors', 1);
        }
        else
        {
            ini_set('display_errors', 0);
        }
        
        // set session garbage collecting time according to session_lifetime
        if (!empty($cls->CONFIG['session_lifetime']))
        {
            ini_set('session.gc_maxlifetime', ($cls->CONFIG['session_lifetime']+2)*60);
        }
        $cls->BROWSER = rcMisc::rcube_browser();
        // load host-specific configuration
        $cls->CONFIG = $cls->rcmail_load_host_config($cls->CONFIG);
        
        // prepare DB connection
        require_once('include/rcube_'.(empty($cls->CONFIG['db_backend']) ? 'db' : $cls->CONFIG['db_backend']).'.inc');
        $DB = new rcube_db(
                    $cls->CONFIG['db_dsnw'],
                    $cls->CONFIG['db_dsnr'],
                    $cls->CONFIG['db_persistent']
        );
        $DB->sqlite_initials = $cls->INSTALL_PATH . 'SQL/sqlite.initial.sql';
        $DB->db_connect('w');
        if ($DB->is_error())
        {
            throw new rcException($DB->db_error_msg);
        }
        $cls->DB = $DB;

        $rcSession = rcSession::factory($DB);

        // we can use the database for storing session data
        //include_once('include/session.inc');
        //return 'foo';
        //session_start();

        $cls->sess_id = session_id();

        $cls->JS_OBJECT_NAME = $GLOBALS['JS_OBJECT_NAME'];
        
        return $cls;
    }

    /**
     * sendError
     *
     * @access public
     * @param  string $component
     * @param  string $reason
     * @return JSON
     * @since  vNext
     */
    public function sendError($component, $reason)
    {
        $errObj = new stdClass;
        $errObj->status = 'failure';

        if (!is_array($component))
        {
            $component = array($component);
        }
        if (!is_array($reason))
        {
            $reason = array($reason);
        }

        $errObj->responses    = array();
        for ($i=0; $i<count($component); $i++)
        {
            $errObj->responses[$i] = new stdClass;
            $errObj->responses[$i]->component = $component[$i];
            $errObj->responses[$i]->actions   = array(
                                                'action' => 'showWarning',
                                                'warning' => ((isset($reason[$i]) && !empty($reason[$i]))?$reason[$i]:'unknown')
            );
        }
        return $this->json->encode($errObj);
    }

    /**
     * sendSuccess
     *
     * @access public
     * @param  string $token
     * @param  array  $data
     * @return JSON
     * @since  vNext
     */
    function sendSuccess($component, $data)
    {
        $respObj = new stdClass;
        $respObj->status = 'ok';
        $respObj->responses = array();

        $o = new stdClass;
        $o->component = $component;
        $o->actions   = $data;

        $respObj->responses[] = $o;

        return $this->json->encode($respObj);
    }

    // register session and connect to server
    public function rcmail_startup($task='mail')
    {
        // create session and set session vars
        if (!isset($_SESSION['auth_time']))
        {
            $_SESSION['user_lang'] = $this->rcube_language_prop($this->CONFIG['locale_string']);
            $_SESSION['auth_time'] = mktime();
            setcookie('sessauth', $this->rcmail_auth_hash($_SESSION['auth_time']));
        }

        // set session vars global
        $this->sess_user_lang = $this->rcube_language_prop($_SESSION['user_lang']);

        // overwrite config with user preferences
        if (isset($_SESSION['user_prefs']) && is_array($_SESSION['user_prefs']))
        {
            $this->CONFIG = array_merge($this->CONFIG, $_SESSION['user_prefs']);
        }

        // reset some session parameters when changing task
        if (isset($_SESSION['task']) && $_SESSION['task'] != $task)
        {
            unset($_SESSION['page']);
        }

        // set current task to session
        $_SESSION['task'] = $task;

        // create IMAP object
        if ($task=='mail')
        {
            $this->rcmail_imap_init();
        }

        // set localization
        if (isset($this->CONFIG['locale_string']))
        {
            setlocale(LC_ALL, $this->CONFIG['locale_string']);
        }
        else if ($this->sess_user_lang)
        {
            setlocale(LC_ALL, $this->sess_user_lang);
        }
        register_shutdown_function(array(&$this, 'rcmail_shutdown'));
    }


    // load a host-specific config file if configured
    function rcmail_load_host_config($config)
    {
        $fname = NULL;
        if (is_array($config['include_host_config']))
            $fname = $config['include_host_config'][$_SERVER['HTTP_HOST']];
        else if (!empty($config['include_host_config']))
            $fname = preg_replace('/[^a-z0-9\.\-_]/i', '', $_SERVER['HTTP_HOST']) . '.inc.php';

        if ($fname && is_file('config/'.$fname))
        {
            include('config/'.$fname);
            $config = array_merge($config, $rcmail_config);
        }
        return $config;
    }


    // create authorization hash
    function rcmail_auth_hash($ts)
    {
        $auth_string = sprintf(
                        'rcmail*sess%sR%s*Chk:%s;%s',
                        $this->sess_id,
                        $ts,
                        $this->CONFIG['ip_check'] ? $_SERVER['REMOTE_ADDR'] : '***.***.***.***',
                        $_SERVER['HTTP_USER_AGENT']
        );
  
        if (function_exists('sha1'))
        {
            return sha1($auth_string);
        }
        return md5($auth_string);
    }


    // compare the auth hash sent by the client with the local session credentials
    function rcmail_authenticate_session()
    {
        $now = mktime();
        $valid = ($_COOKIE['sessauth'] == $this->rcmail_auth_hash(session_id(), $_SESSION['auth_time']));

        // renew auth cookie every 5 minutes (only for GET requests)
        if (!$valid || ($_SERVER['REQUEST_METHOD']!='POST' && $now-$_SESSION['auth_time'] > 300))
        {
            $_SESSION['auth_time'] = $now;
            setcookie('sessauth', rcmail_auth_hash(session_id(), $now));
        }
        return $valid;
    }


    // create IMAP object and connect to server
    function rcmail_imap_init($connect=FALSE)
    {
        $this->IMAP = new rcube_imap($this->DB);
        $this->IMAP->debug_level = $this->CONFIG['debug_level'];
        $this->IMAP->skip_deleted = $this->CONFIG['skip_deleted'];

        // connect with stored session data
        if ($connect !== false)
        {
            $conn = $this->IMAP->connect(
                                $_SESSION['imap_host'],
                                $_SESSION['username'],
                                rcCore::decrypt_passwd($_SESSION['password']),
                                $_SESSION['imap_port'],
                                $_SESSION['imap_ssl']
            );
            if (!$conn)
            {
                throw new rcException('Could not connect to IMAP Server.');
            }
            $this->rcmail_set_imap_prop();
        }

        // enable caching of imap data
        if ($this->CONFIG['enable_caching']===TRUE)
        {
            $this->IMAP->set_caching(TRUE);
        }
        // set pagesize from config
        if (isset($this->CONFIG['pagesize']) && !empty($this->CONFIG['pagesize']))
        {
            $this->IMAP->set_pagesize($this->CONFIG['pagesize']);
        }
    }


    // set root dir and last stored mailbox
    // this must be done AFTER connecting to the server
    function rcmail_set_imap_prop()
    {
        // set root dir from config
        if (!empty($this->CONFIG['imap_root']))
            $this->IMAP->set_rootdir($this->CONFIG['imap_root']);

        if (is_array($this->CONFIG['default_imap_folders']))
            $this->IMAP->set_default_mailboxes($this->CONFIG['default_imap_folders']);

        if (!empty($_SESSION['mbox']))
            $this->IMAP->set_mailbox($_SESSION['mbox']);

        if (isset($_SESSION['page']))
            $this->IMAP->set_page($_SESSION['page']);
    }


    // do these things on script shutdown
    function rcmail_shutdown()
    {
        if (is_object($this->IMAP))
        {
            $this->IMAP->close();
            $this->IMAP->write_cache();
        }
    
        // before closing the database connection, write session data
        session_write_close();
    }


    // destroy session data and remove cookie
    function rcmail_kill_session()
    {
        // save user preferences
        $a_user_prefs = $_SESSION['user_prefs'];
        if (!is_array($a_user_prefs))
            $a_user_prefs = array();
    
        if ((isset($_SESSION['sort_col']) && $_SESSION['sort_col']!=$a_user_prefs['message_sort_col']) ||
            (isset($_SESSION['sort_order']) && $_SESSION['sort_order']!=$a_user_prefs['message_sort_order']))
        {
            $a_user_prefs['message_sort_col'] = $_SESSION['sort_col'];
            $a_user_prefs['message_sort_order'] = $_SESSION['sort_order'];
            $this->rcmail_save_user_prefs($a_user_prefs);
        }

        $_SESSION = array();
        session_destroy();
    }

    /**
     * return correct name for a specific database table
     *
     * @access static, protected
     * @param  string $table
     * @return string $table
     */
    function get_table_name($table)
    {
        $cls = null;
        if (!isset($this) || !is_object($this))
        {
            $cls = rcCore::factory();
        }
        else
        {
            $cls = $this;
        }
        // return table name if configured
        $config_key = 'db_table_' . $table;

        if (strlen($cls->CONFIG[$config_key]))
        {
            return $cls->CONFIG[$config_key];
        }
  
        return $table;
    }


    // return correct name for a specific database sequence
    // (used for Postres only)
    function get_sequence_name($sequence)
    {
        // return table name if configured
        $config_key = 'db_sequence_'.$sequence;

        if (strlen($this->CONFIG[$config_key]))
            return $this->CONFIG[$config_key];
  
        return $table;
    }


    // check the given string and returns language properties
    function rcube_language_prop($lang, $prop='lang')
    {
        static $rcube_languages, $rcube_language_aliases, $rcube_charsets;

        if (empty($rcube_languages))
            @include($this->INSTALL_PATH . 'program/localization/index.inc');
    
        // check if we have an alias for that language
        if (!isset($rcube_languages[$lang]) && isset($rcube_language_aliases[$lang]))
            $lang = $rcube_language_aliases[$lang];
    
        // try the first two chars
        if (!isset($rcube_languages[$lang]) && strlen($lang)>2)
        {
            $lang = substr($lang, 0, 2);
            $lang = $this->rcube_language_prop($lang);
        }

        if (!isset($rcube_languages[$lang]))
            $lang = 'en_US';

        // language has special charset configured
        if (isset($rcube_charsets[$lang]))
            $charset = $rcube_charsets[$lang];
        else
            $charset = 'UTF-8';    

        if ($prop=='charset')
            return $charset;
        else
            return $lang;
    }
  

    // init output object for GUI and add common scripts
    function load_gui()
    {
        // init output page
        $this->OUTPUT = new rcube_html_page();
  
        // add common javascripts
        $javascript = "var " . $this->JS_OBJECT_NAME . " = new rcube_webmail();\n";
        $javascript.= $this->JS_OBJECT_NAME . ".set_env('comm_path', '$COMM_PATH');\n";

        if (isset($this->CONFIG['javascript_config'] ))
        {
            foreach ($this->CONFIG['javascript_config'] as $js_config_var)
            {
                $javascript.= $this->JS_OBJECT_NAME . ".set_env('$js_config_var', '";
                $javascript.= $this->CONFIG[$js_config_var] . "');\n";
            }
        }
  
        if (!empty($GLOBALS['_framed']))
            $javascript.= $this->JS_OBJECT_NAME . ".set_env('framed', true);\n";
    
        $this->OUTPUT->add_script($javascript);
        $this->OUTPUT->include_script('common.js');
        $this->OUTPUT->include_script('app.js');
        $this->OUTPUT->scripts_path = 'program/js/';

        // set locale setting
        $this->rcmail_set_locale($this->sess_user_lang);

        // set user-selected charset
        if (!empty($this->CONFIG['charset']))
            $this->OUTPUT->set_charset($this->CONFIG['charset']);

        // add some basic label to client
        $this->rcube_add_label('loading','checkingmail');
    }


    // set localization charset based on the given language
    function rcmail_set_locale($lang)
    {
        static $s_mbstring_loaded = NULL;
  
        // settings for mbstring module (by Tadashi Jokagi)
        if ($s_mbstring_loaded===NULL)
        {
            if ($s_mbstring_loaded = extension_loaded("mbstring"))
            {
                $this->MBSTRING = TRUE;
                if (function_exists("mb_mbstring_encodings"))
                    $this->MBSTRING_ENCODING = mb_mbstring_encodings();
                else
                    $this->MBSTRING_ENCODING = array(
                                            "ISO-8859-1", "UTF-7", "UTF7-IMAP", "UTF-8",
                                            "ISO-2022-JP", "EUC-JP", "EUCJP-WIN",
                                            "SJIS", "SJIS-WIN"
                    );

                $this->MBSTRING_ENCODING = array_map("strtoupper", $this->MBSTRING_ENCODING);
                if (in_array("SJIS", $this->MBSTRING_ENCODING))
                    $this->MBSTRING_ENCODING[] = "SHIFT_JIS";
            }
            else
            {
                $this->MBSTRING = FALSE;
                $this->MBSTRING_ENCODING = array();
            }
        }

        if ($this->MBSTRING && function_exists("mb_language"))
        {
            if (!@mb_language(strtok($lang, "_")))
                $this->MBSTRING = FALSE;   //  unsupport language
        }

        $this->OUTPUT->set_charset($this->rcube_language_prop($lang, 'charset'));
    }


    // perfom login to the IMAP server and to the webmail service
    function rcmail_login($user, $pass, $host=NULL)
    {
        $user_id = NULL;
  
        if (is_null($host) || empty($host))
        {
            $host = $this->CONFIG['default_host'];
        }

        // Validate that selected host is in the list of configured hosts
        if (is_array($this->CONFIG['default_host']))
        {
            $allowed = FALSE;
            foreach ($this->CONFIG['default_host'] as $key => $host_allowed)
            {
                if (!is_numeric($key))
                     $host_allowed = $key;
                if ($host == $host_allowed)
                {
                    $allowed = TRUE;
                    break;
                }
            }
            if (!$allowed)
            {
                throw new rcException(sprintf('Host %s is not allowed.', $host));
            }
        }
        else if (!empty($this->CONFIG['default_host']) && $host != $this->CONFIG['default_host'])
        {
            throw new rcException('Unknown host.');
        }
        // parse $host URL
        //var_dump($host);
        $a_host = @parse_url($host);
        if ($a_host === false)
        {
            throw new rcException('Unable to parse IMAP DSN: ' . $host);
        }
        if (isset($a_host['host']))
        {
            $host = $a_host['host'];
            $imap_ssl = (isset($a_host['scheme']) && in_array($a_host['scheme'], array('ssl','imaps','tls'))) ? TRUE : FALSE;
            $imap_port = isset($a_host['port']) ? $a_host['port'] : ($imap_ssl ? 993 : $this->CONFIG['default_port']);
        }
        else
        {
            $imap_port = $this->CONFIG['default_port'];
            $imap_ssl  = false;
            $imap_port = 993;
        }


        /**
         * Modify username with domain if required  
         * Inspired by Marco <P0L0_notspam_binware.org>
         */
        // Check if we need to add domain
        if ($this->CONFIG['username_domain'] && !strstr($user, '@'))
        {
            if (is_array($this->CONFIG['username_domain']) && isset($this->CONFIG['username_domain'][$host]))
            {
                $user = sprintf('%s@%s', $user, $this->CONFIG['username_domain'][$host]);
            }
            else if (!empty($this->CONFIG['username_domain']))
            {
                $user = sprintf('%s@%s', $user, $this->CONFIG['username_domain']);
            }
        }


        // query if user already registered
        $query = (string) '';
        $query.= "SELECT user_id, username, language, preferences";
        $query.= " FROM %s";
        $query.= " WHERE mail_host=%s AND (username=%s OR alias=%s)";
        $query = sprintf(
                    $query,
                    $this->get_table_name('users'),
                    $host,
                    $user,
                    $user
        );
        $sql_result = $this->DB->query($query);
        if ($this->DB->is_error())
        {
            throw new rcException('Database error: ' . $this->DB->db_error_msg);
        }
        if ($this->DB->num_rows($sql_result) > 0)
        {
            // user already registered -> overwrite username
            if ($sql_arr = $this->DB->fetch_assoc($sql_result))
            {
                $user_id = $sql_arr['user_id'];
                $user = $sql_arr['username'];
            }
            // try to resolve email address from virtuser table    
            if (!empty($this->CONFIG['virtuser_file']) && strstr($user, '@'))
            {
                $user = $this->rcmail_email2user($user);
            }
        }

        // exit if IMAP login failed
        if (!($imap_login  = $this->IMAP->connect($host, $user, $pass, $imap_port, $imap_ssl)))
        {
            return $this->sendError('formauth', sprintf('Could not connect to IMAP server: %s', $host));
        }

        // user already registered
        if ($user_id && !empty($sql_arr))
        {
            // get user prefs
            if (strlen($sql_arr['preferences']))
            {
                $user_prefs = unserialize($sql_arr['preferences']);
                $_SESSION['user_prefs'] = $user_prefs;
                array_merge($this->CONFIG, $user_prefs);
            }

            // set user specific language
            if (strlen($sql_arr['language']))
                $this->sess_user_lang = $_SESSION['user_lang'] = $sql_arr['language'];
      
            // update user's record
            $this->DB->query("UPDATE ".$this->get_table_name('users')."
                SET    last_login=now()
                WHERE  user_id=?",
                $user_id);
        }
        // create new system user
        else if ($this->CONFIG['auto_create_user'])
        {
            $user_id = $this->rcmail_create_user($user, $host);
        }

        if ($user_id)
        {
            $_SESSION['user_id']   = $user_id;
            $_SESSION['imap_host'] = $host;
            $_SESSION['imap_port'] = $imap_port;
            $_SESSION['imap_ssl']  = $imap_ssl;
            $_SESSION['username']  = $user;
            $_SESSION['user_lang'] = $this->sess_user_lang;
            $_SESSION['password']  = $this->encrypt_passwd($pass);

            // force reloading complete list of subscribed mailboxes
            $this->rcmail_set_imap_prop();
            $this->IMAP->clear_cache('mailboxes');
            $this->IMAP->create_default_folders();

            $actions = array();

            $data             = new stdClass;
            $data->action     = 'confirmLogin';
            $data->token      = 'foo';
            $data->expiration = 'foo';

            $actions[] = $data;

            return $this->sendSuccess('formauth', $actions);
        }
        return $this->sendError('formauth', 'Could not login.');
    }


    // create new entry in users and identities table
    function rcmail_create_user($user, $host)
    {
        $user_email = '';

        // try to resolve user in virtusertable
        if (!empty($this->CONFIG['virtuser_file']) && strstr($user, '@')==FALSE)
        {
            $user_email = rcmail_user2email($user);
        }
        $this->DB->query("INSERT INTO ".$this->get_table_name('users')."
              (created, last_login, username, mail_host, alias, language)
              VALUES (now(), now(), ?, ?, ?, ?)",
              $user,
              $host,
              $user_email,
		      $_SESSION['user_lang']);

        if ($user_id = $this->DB->insert_id($this->get_sequence_name('users')))
        {
            $mail_domain = $host;
            if (is_array($this->CONFIG['mail_domain']))
            {
                if (isset($this->CONFIG['mail_domain'][$host]))
                    $mail_domain = $this->CONFIG['mail_domain'][$host];
            }
            else if (!empty($this->CONFIG['mail_domain']))
                $mail_domain = $this->CONFIG['mail_domain'];
   
            if ($user_email=='')
                $user_email = strstr($user, '@') ? $user : sprintf('%s@%s', $user, $mail_domain);

             $user_name = $user!=$user_email ? $user : '';

            // try to resolve the e-mail address from the virtuser table
	        if (!empty($this->CONFIG['virtuser_query']))
	        {
                $sql_result = $this->DB->query(preg_replace('/%u/', $user, $this->CONFIG['virtuser_query']));
                if ($sql_arr = $this->DB->fetch_array($sql_result))
                    $user_email = $sql_arr[0];
            }

            // also create new identity records
            $this->DB->query("INSERT INTO ".$this->get_table_name('identities')."
                (user_id, del, standard, name, email)
                VALUES (?, 0, 1, ?, ?)",
                $user_id,
                $user_name,
                $user_email);

                       
            // get existing mailboxes
            $a_mailboxes = $this->IMAP->list_mailboxes();
        }
        else
        {
            raise_error(array('code' => 500,
                      'type' => 'php',
                      'line' => __LINE__,
                      'file' => __FILE__,
                      'message' => "Failed to create new user"), TRUE, FALSE);
        }
        return $user_id;
    }


    // load virtuser table in array
    function rcmail_getvirtualfile()
    {
        if (empty($this->CONFIG['virtuser_file']) || !is_file($this->CONFIG['virtuser_file']))
            return FALSE;
  
        // read file 
        $a_lines = file($this->CONFIG['virtuser_file']);
        return $a_lines;
    }


    // find matches of the given pattern in virtuser table
    function rcmail_findinvirtual($pattern)
    {
        $result = array();
        $virtual = $this->rcmail_getvirtualfile();
        if ($virtual==FALSE)
            return $result;

        // check each line for matches
        foreach ($virtual as $line)
        {
            $line = trim($line);
            if (empty($line) || $line{0}=='#')
            {
                continue;
            }
            if (eregi($pattern, $line))
            {
                $result[] = $line;
            }
        }

        return $result;
    }


    // resolve username with virtuser table
    function rcmail_email2user($email)
    {
        $user = $email;
        $r = $this->rcmail_findinvirtual("^$email");

        for ($i=0; $i<count($r); $i++)
        {
            $data = $r[$i];
            $arr = preg_split('/\s+/', $data);
            if(count($arr)>0)
            {
                $user = trim($arr[count($arr)-1]);
                break;
            }
        }
        return $user;
    }


    // resolve e-mail address with virtuser table
    function rcmail_user2email($user)
    {
        $email = "";
        $r = $this->rcmail_findinvirtual("$user$");

        for ($i=0; $i<count($r); $i++)
        {
            $data=$r[$i];
            $arr = preg_split('/\s+/', $data);
            if (count($arr)>0)
            {
                $email = trim($arr[0]);
                break;
            }
        }
        return $email;
    } 


    function rcmail_save_user_prefs($a_user_prefs)
    {
        $this->DB->query("UPDATE ".$this->get_table_name('users')."
              SET preferences=?,
              language=?
              WHERE  user_id=?",
              serialize($a_user_prefs),
              $this->sess_user_lang,
              $_SESSION['user_id']);

        if ($this->DB->affected_rows())
        {
            $_SESSION['user_prefs'] = $a_user_prefs;  
            $this->CONFIG = array_merge($this->CONFIG, $a_user_prefs);
            return TRUE;
        }
        return FALSE;
    }


    // overwrite action variable  
    function rcmail_overwrite_action($action)
    {
        $GLOBALS['_action'] = $action;

        $this->OUTPUT->add_script(sprintf("\n%s.set_env('action', '%s');", $this->JS_OBJECT_NAME, $action));  
    }


    function show_message($message, $type='notice', $vars=NULL)
    {
        global $REMOTE_REQUEST;
  
        $framed = $GLOBALS['_framed'];
        $command = sprintf(
                    "display_message('%s', '%s');",
                    addslashes(
                        rep_specialchars_output(
                            rcMisc::rcube_label(
                                array('name' => $message, 'vars' => $vars)
                            )
                        )
                    ),
                    $type
        );

        if ($REMOTE_REQUEST)
        {
            return 'this.'.$command;
        }  
        else
        {
            $this->OUTPUT->add_script(sprintf("%s%s.%s\n",
                                $framed ? sprintf('if(parent.%s)parent.', $this->JS_OBJECT_NAME) : '',
                                $this->JS_OBJECT_NAME,
                                $command));
        }
        // console(rcMisc::rcube_label($message));
    }


    function console($msg, $type=1)
    {
        if ($GLOBALS['REMOTE_REQUEST'])
            print "// $msg\n";
        else
        {
            echo $msg;
            echo "\n<hr>\n";
        }
    }


    // encrypt IMAP password using DES encryption
    function encrypt_passwd($pass)
    {
        $cypher = des(rcCore::get_des_key(), $pass, 1, 0, NULL);
        return base64_encode($cypher);
    }


    // decrypt IMAP password using DES encryption
    static function decrypt_passwd($cypher)
    {
        $pass = des(rcCore::get_des_key(), base64_decode($cypher), 0, 0, NULL);
        return preg_replace('/\x00/', '', $pass);
    }


    // return a 24 byte key for the DES encryption
    // TODO: make this a config option
    function get_des_key()
    {
        $key = !empty($this->CONFIG['des_key']) ? $this->CONFIG['des_key'] : 'rcmail?24BitPwDkeyF**ECB';
        $len = strlen($key);
        //}  
        // make sure the key is exactly 24 chars long
        if ($len<24)
            $key .= str_repeat('_', 24-$len);
        else if ($len>24)
            substr($key, 0, 24);
  
        return $key;
    }


    // send correct response on a remote request
    function rcube_remote_response($js_code, $flush=FALSE)
    {
        static $s_header_sent = FALSE;
  
        if (!$s_header_sent)
        {
            $s_header_sent = TRUE;
            send_nocacheing_headers();
            header('Content-Type: application/x-javascript; charset=' . $this->CHARSET);
            echo '/** remote response ['.date('d/M/Y h:i:s O')."] **/\n";
        }

        // send response code
        echo $this->rcube_charset_convert($js_code, $this->CHARSET, $this->OUTPUT->get_charset());

        //if ($flush)  // flush the output buffer
            flush();
        //else         // terminate script
            //exit;
    }


    // send correctly formatted response for a request posted to an iframe
    function rcube_iframe_response($js_code='')
    {
        if (!empty($js_code))
            $this->OUTPUT->add_script("if(parent." . $this->JS_OBJECT_NAME . "){\n" . $js_code . "\n}");

        $this->OUTPUT->write();
        exit;
    }


    // read directory program/localization/ and return a list of available languages
    function rcube_list_languages()
    {
        static $sa_languages = array();

        if (!sizeof($sa_languages))
        {
            @include($this->INSTALL_PATH . 'program/localization/index.inc');

            if ($dh = @opendir($this->INSTALL_PATH . 'program/localization'))
            {
                while (($name = readdir($dh)) !== false)
                {
                    if ($name{0}=='.' || !is_dir($this->INSTALL_PATH . 'program/localization/'.$name))
                        continue;

                    if ($label = $rcube_languages[$name])
                        $sa_languages[$name] = $label ? $label : $name;
                }
                closedir($dh);
            }
        }
        return $sa_languages;
    }


    // add a localized label to the client environment
    function rcube_add_label()
    {
        $arg_list = func_get_args();
        foreach ($arg_list as $i => $name)
            $this->OUTPUT->add_script(
                            sprintf(
                                "%s.add_label('%s', '%s');",
                                $this->JS_OBJECT_NAME,
                                $name,
                                $this->rep_specialchars_output(rcMisc::rcube_label($name), 'js')
                            )
            );  
    }

    // remove temp files of a session
    function rcmail_clear_session_temp()
    {
        $temp_dir = rcMisc::slashify($this->CONFIG['temp_dir']);
        $cache_dir = $temp_dir . $this->sess_id;

        if (is_dir($cache_dir))
        {
            clear_directory($cache_dir);
            rmdir($cache_dir);
        }
    }


    // remove all expired message cache records
    // TODO: Error checking & exception
    function rcmail_message_cache_gc()
    {
        // no cache lifetime configured
        if (empty($this->CONFIG['message_cache_lifetime']))
            return;

        // get target timestamp
        $ts = get_offset_time($this->CONFIG['message_cache_lifetime'], -1);
  
        $query = (string) '';
        $query.= "DELETE FROM %s";
        $query.= " WHERE created < %s";
        $query = sprintf(
                    $query,
                    $this->get_table_name('messages'),
                    $this->DB->fromunixtime($ts)
        );
        $this->DB-query($query);
    }


    // convert a string from one charset to another
    // this function is not complete and not tested well
    function rcube_charset_convert($str, $from, $to=NULL)
    {
        $from = strtoupper($from);
        $to = $to==NULL ? strtoupper($this->CHARSET) : strtoupper($to);

        if ($from==$to)
            return $str;
    
        // convert charset using mbstring module  
        if ($this->MBSTRING)
        {
            $to = $to=="UTF-7" ? "UTF7-IMAP" : $to;
            $from = $from=="UTF-7" ? "UTF7-IMAP": $from;
    
            if (in_array($to, $this->MBSTRING_ENCODING) && in_array($from, $this->MBSTRING_ENCODING))
                return mb_convert_encoding($str, $to, $from);
        }

        // convert charset using iconv module  
        if (function_exists('iconv') && $from!='UTF-7' && $to!='UTF-7')
            return iconv($from, $to, $str);

        $conv = new utf8();

        // convert string to UTF-8
        if ($from=='UTF-7')
            $str = $this->rcube_charset_convert(UTF7DecodeString($str), 'ISO-8859-1');
        else if ($from=='ISO-8859-1' && function_exists('utf8_encode'))
            $str = utf8_encode($str);
        else if ($from!='UTF-8')
        {
            $conv->loadCharset($from);
            $str = $conv->strToUtf8($str);
        }

        // encode string for output
        if ($to=='UTF-7')
            return UTF7EncodeString($str);
        else if ($to=='ISO-8859-1' && function_exists('utf8_decode'))
            return utf8_decode($str);
        else if ($to!='UTF-8')
        {
            $conv->loadCharset($to);
            return $conv->utf8ToStr($str);
        }

        // return UTF-8 string
        return $str;
    }



    // replace specials characters to a specific encoding type
    function rep_specialchars_output($str, $enctype='', $mode='', $newlines=TRUE)
    {
        global $OUTPUT_TYPE;
        static $html_encode_arr, $js_rep_table, $rtf_rep_table, $xml_rep_table;

        if (!$enctype)
            $enctype = $GLOBALS['OUTPUT_TYPE'];

        // convert nbsps back to normal spaces if not html
        if ($enctype!='html')
            $str = str_replace(chr(160), ' ', $str);

        // encode for plaintext
        if ($enctype=='text')
            return str_replace("\r\n", "\n", $mode=='remove' ? strip_tags($str) : $str);

        // encode for HTML output
        if ($enctype=='html')
        {
            if (!$html_encode_arr)
            {
                $html_encode_arr = get_html_translation_table(HTML_SPECIALCHARS);        
                unset($html_encode_arr['?']);
                unset($html_encode_arr['&']);
            }

            $ltpos = strpos($str, '<');
            $encode_arr = $html_encode_arr;

            // don't replace quotes and html tags
            if (($mode=='show' || $mode=='') && $ltpos!==false && strpos($str, '>', $ltpos)!==false)
            {
                unset($encode_arr['"']);
                unset($encode_arr['<']);
                unset($encode_arr['>']);
            }
            else if ($mode=='remove')
                $str = strip_tags($str);

            $out = strtr($str, $encode_arr);
      
            return $newlines ? nl2br($out) : $out;
        }


        if ($enctype=='url')
            return rawurlencode($str);


        // if the replace tables for RTF, XML and JS are not yet defined
        if (!$js_rep_table)
        {
            $js_rep_table = $rtf_rep_table = $xml_rep_table = array();
            $xml_rep_table['&'] = '&amp;';

            for ($c=160; $c<256; $c++)  // can be increased to support more charsets
            {
                $hex = dechex($c);
                $rtf_rep_table[Chr($c)] = "\\'$hex";
                $xml_rep_table[Chr($c)] = "&#$c;";
      
                if ($this->OUTPUT->get_charset()=='ISO-8859-1')
                    $js_rep_table[Chr($c)] = sprintf("\u%s%s", str_repeat('0', 4-strlen($hex)), $hex);
            }

            $js_rep_table['"'] = sprintf("\u%s%s", str_repeat('0', 4-strlen(dechex(34))), dechex(34));
            $xml_rep_table['"'] = '&quot;';
        }

        // encode for RTF
        if ($enctype=='xml')
            return strtr($str, $xml_rep_table);

        // encode for javascript use
        if ($enctype=='js')
        {
            if ($this->OUTPUT->get_charset()!='UTF-8')
                $str = $this->rcube_charset_convert($str, $this->CHARSET, $this->OUTPUT->get_charset());
      
            return preg_replace(array("/\r\n/", '/"/', "/([^\\\])'/"), array('\n', '\"', "$1\'"), strtr($str, $js_rep_table));
        }

        // encode for RTF
        if ($enctype=='rtf')
            return preg_replace("/\r\n/", "\par ", strtr($str, $rtf_rep_table));

        // no encoding given -> return original string
        return $str;
    }


    /**
     * Read input value and convert it for internal use
     * Performs stripslashes() and charset conversion if necessary
     * 
     * @access static
     * @param  string   Field name to read
     * @param  int      Source to get value from (GPC)
     * @param  boolean  Allow HTML tags in field value
     * @param  string   Charset to convert into
     * @return string   Field value or NULL if not available
     */
    function get_input_value($fname, $source, $allow_html=FALSE, $charset=NULL)
    {
        $value = NULL;
  
        if ($source==RCUBE_INPUT_GET && isset($_GET[$fname]))
            $value = $_GET[$fname];
        else if ($source==RCUBE_INPUT_POST && isset($_POST[$fname]))
            $value = $_POST[$fname];
        else if ($source==RCUBE_INPUT_GPC)
        {
            if (isset($_POST[$fname]))
                $value = $_POST[$fname];
            else if (isset($_GET[$fname]))
                $value = $_GET[$fname];
            else if (isset($_COOKIE[$fname]))
                $value = $_COOKIE[$fname];
        }
  
        // strip slashes if magic_quotes enabled
        if ((bool)get_magic_quotes_gpc())
            $value = stripslashes($value);

        // remove HTML tags if not allowed    
        if (!$allow_html)
            $value = strip_tags($value);
  
        // convert to internal charset
        /*if (is_object($this->OUTPUT))
            return $this->rcube_charset_convert($value, $this->OUTPUT->get_charset(), $charset);
        else
            return $value;
        */
        return $value;
    }

    /**
     * Remove single and double quotes from given string
     */
    function strip_quotes($str)
    {
        return preg_replace('/[\'"]/', '', $str);
    }

    // ************** template parsing and gui functions **************

    // return boolean if a specific template exists
    function template_exists($name)
    {
        $skin_path = $this->CONFIG['skin_path'];

        // check template file
        return is_file("$skin_path/templates/$name.html");
    }

    // get page template an replace variable
    // similar function as used in nexImage
    function parse_template($name='main', $exit=TRUE)
    {
        $skin_path = $this->CONFIG['skin_path'];

        // read template file
        $templ = '';
        $path = "$skin_path/templates/$name.html";

        if($fp = @fopen($path, 'r'))
        {
            $templ = fread($fp, filesize($path));
            fclose($fp);
        }
        else
        {
            raise_error(array('code' => 500,
                      'type' => 'php',
                      'line' => __LINE__,
                      'file' => __FILE__,
                      'message' => "Error loading template for '$name'"), TRUE, TRUE);
            return FALSE;
        }

        // parse for specialtags
        $_output = $this->parse_rcube_xml($templ);
        $this->OUTPUT->write(
                    trim(
                        $this->parse_with_globals($_output)
                    ),
                    $skin_path
        );

        if ($exit)
            exit;
    }

    // replace all strings ($varname) with the content of the according global variable
    function parse_with_globals($input)
    {
        $GLOBALS['__comm_path'] = $GLOBALS['COMM_PATH'];
        $_output = preg_replace('/\$(__[a-z0-9_\-]+)/e', '$GLOBALS["\\1"]', $input);
        return $_output;
    }

    function parse_rcube_xml($input)
    {
        $_output = preg_replace('/<roundcube:([-_a-z]+)\s+([^>]+)>/Uie', "rcube_xml_command('\\1', '\\2')", $input);
        return $_output;
    }

    function rcube_xml_command($command, $str_attrib, $add_attrib=array())
    {
        $command = strtolower($command);
        $attrib = parse_attrib_string($str_attrib) + $add_attrib;

        // execute command
        switch ($command)
        {
            // return a button
            case 'button':
            if ($attrib['command'])
                return $this->rcube_button($attrib);
            break;

            // show a label
            case 'label':
                if ($attrib['name'] || $attrib['command'])
                    return $this->rep_specialchars_output(rcMisc::rcube_label($attrib));
                break;

            // create a menu item
            case 'menu':
                if ($attrib['command'] && $attrib['group'])
                    $this->rcube_menu($attrib);
                break;

            // include a file 
            case 'include':
                $path = realpath($this->CONFIG['skin_path'].$attrib['file']);
      
                if($fp = @fopen($path, 'r'))
                {
                    $incl = fread($fp, filesize($path));
                    fclose($fp);        
                    return $this->parse_rcube_xml($incl);
                }
                break;

            // return code for a specific application object
            case 'object':
                $object = strtolower($attrib['name']);

                $object_handlers = array(
                                    // GENERAL
                                    'loginform' => 'rcmail_login_form',
                                    'username'  => 'rcmail_current_username',
        
                                    // MAIL
                                    'mailboxlist' => 'rcmail_mailbox_list',
                                    'message' => 'rcmail_message_container',
                                    'messages' => 'rcmail_message_list',
                                    'messagecountdisplay' => 'rcmail_messagecount_display',
                                    'quotadisplay' => 'rcmail_quota_display',
                                    'messageheaders' => 'rcmail_message_headers',
                                    'messagebody' => 'rcmail_message_body',
                                    'messageattachments' => 'rcmail_message_attachments',
                                    'blockedobjects' => 'rcmail_remote_objects_msg',
                                    'messagecontentframe' => 'rcmail_messagecontent_frame',
                                    'messagepartframe' => 'rcmail_message_part_frame',
                                    'messagepartcontrols' => 'rcmail_message_part_controls',
                                    'composeheaders' => 'rcmail_compose_headers',
                                    'composesubject' => 'rcmail_compose_subject',
                                    'composebody' => 'rcmail_compose_body',
                                    'composeattachmentlist' => 'rcmail_compose_attachment_list',
                                    'composeattachmentform' => 'rcmail_compose_attachment_form',
                                    'composeattachment' => 'rcmail_compose_attachment_field',
                                    'priorityselector' => 'rcmail_priority_selector',
                                    'charsetselector' => 'rcmail_charset_selector',
                                    'searchform' => 'rcmail_search_form',
                                    'receiptcheckbox' => 'rcmail_receipt_checkbox',
        
                                    // ADDRESS BOOK
                                    'addresslist' => 'rcmail_contacts_list',
                                    'addressframe' => 'rcmail_contact_frame',
                                    'recordscountdisplay' => 'rcmail_rowcount_display',
                                    'contactdetails' => 'rcmail_contact_details',
                                    'contacteditform' => 'rcmail_contact_editform',
                                    'ldappublicsearch' => 'rcmail_ldap_public_search_form',
                                    'ldappublicaddresslist' => 'rcmail_ldap_public_list',

                                    // USER SETTINGS
                                    'userprefs' => 'rcmail_user_prefs_form',
                                    'itentitieslist' => 'rcmail_identities_list',
                                    'identityframe' => 'rcmail_identity_frame',
                                    'identityform' => 'rcube_identity_form',
                                    'foldersubscription' => 'rcube_subscription_form',
                                    'createfolder' => 'rcube_create_folder_form',
                                    'renamefolder' => 'rcube_rename_folder_form',
                                    'composebody' => 'rcmail_compose_body'
                );
                // execute object handler function
                if ($object_handlers[$object] && function_exists($object_handlers[$object]))
                    return call_user_func($object_handlers[$object], $attrib);
                else if ($object=='productname')
                {
                    $name = !empty($this->CONFIG['product_name']) ? $this->CONFIG['product_name'] : 'RoundCube Webmail';
                    return $this->rep_specialchars_output($name, 'html', 'all');
                }
                else if ($object=='version')
                {
                    return (string)RCMAIL_VERSION;
                }
                else if ($object=='pagetitle')
                {
                    $task = $GLOBALS['_task'];
                    $title = !empty($this->CONFIG['product_name']) ? $this->CONFIG['product_name'].' :: ' : '';
        
                    if ($task=='login')
                        $title = rcMisc::rcube_label(array('name' => 'welcome', 'vars' => array('product' => $this->CONFIG['product_name'])));
                    else if ($task=='mail' && isset($GLOBALS['MESSAGE']['subject']))
                        $title .= $GLOBALS['MESSAGE']['subject'];
                    else if (isset($GLOBALS['PAGE_TITLE']))
                        $title .= $GLOBALS['PAGE_TITLE'];
                    else if ($task=='mail' && ($mbox_name = $this->IMAP->get_mailbox_name()))
                        $title .= $this->rcube_charset_convert($mbox_name, 'UTF-7', 'UTF-8');
                    else
                        $title .= ucfirst($task);
          
                    return $this->rep_specialchars_output($title, 'html', 'all');
                }
                break;
        }
        return '';
    }


    // create and register a button
    function rcube_button($attrib)
    {
        global $COMM_PATH, $MAIN_TASKS;
        static $sa_buttons = array();
        static $s_button_count = 100;
  
        // these commands can be called directly via url
        $a_static_commands = array('compose', 'list');
  
        $skin_path = $this->CONFIG['skin_path'];
  
        if (!($attrib['command'] || $attrib['name']))
            return '';

        // try to find out the button type
        if ($attrib['type'])
            $attrib['type'] = strtolower($attrib['type']);
        else
            $attrib['type'] = ($attrib['image'] || $attrib['imagepas'] || $arg['imageact']) ? 'image' : 'link';
  
  
        $command = $attrib['command'];
  
        // take the button from the stack
        if($attrib['name'] && $sa_buttons[$attrib['name']])
            $attrib = $sa_buttons[$attrib['name']];

        // add button to button stack
        else if($attrib['image'] || $arg['imageact'] || $attrib['imagepas'] || $attrib['class'])
        {
            if(!$attrib['name'])
                $attrib['name'] = $command;

            if (!$attrib['image'])
                $attrib['image'] = $attrib['imagepas'] ? $attrib['imagepas'] : $attrib['imageact'];

            $sa_buttons[$attrib['name']] = $attrib;
        }

        // get saved button for this command/name
        else if ($command && $sa_buttons[$command])
            $attrib = $sa_buttons[$command];

        //else
            //return '';


        // set border to 0 because of the link arround the button
        if ($attrib['type']=='image' && !isset($attrib['border']))
            $attrib['border'] = 0;
    
        if (!$attrib['id'])
            $attrib['id'] =  sprintf('rcmbtn%d', $s_button_count++);

        // get localized text for labels and titles
        if ($attrib['title'])
            $attrib['title'] = $this->rep_specialchars_output(rcMisc::rcube_label($attrib['title']));
        if ($attrib['label'])
            $attrib['label'] = $this->rep_specialchars_output(rcMisc::rcube_label($attrib['label']));

        if ($attrib['alt'])
            $attrib['alt'] = $this->rep_specialchars_output(rcMisc::rcube_label($attrib['alt']));

        // set title to alt attribute for IE browsers
        if ($this->BROWSER['ie'] && $attrib['title'] && !$attrib['alt'])
        {
            $attrib['alt'] = $attrib['title'];
            unset($attrib['title']);
        }

        // add empty alt attribute for XHTML compatibility
        if (!isset($attrib['alt']))
            $attrib['alt'] = '';


        // register button in the system
        if ($attrib['command'])
        {
            $this->OUTPUT->add_script(
                        sprintf(
                                "%s.register_button('%s', '%s', '%s', '%s', '%s', '%s');",
                                $this->JS_OBJECT_NAME,
                                $command,
                                $attrib['id'],
                                $attrib['type'],
                                $attrib['imageact'] ? $skin_path.$attrib['imageact'] : $attrib['classact'],
                                $attrib['imagesel'] ? $skin_path.$attrib['imagesel'] : $attrib['classsel'],
                                $attrib['imageover'] ? $skin_path.$attrib['imageover'] : ''
                        )
            );
            // make valid href to specific buttons
            if (in_array($attrib['command'], $MAIN_TASKS))
                $attrib['href'] = htmlentities(ereg_replace('_task=[a-z]+', '_task='.$attrib['command'], $COMM_PATH));
            else if (in_array($attrib['command'], $a_static_commands))
                $attrib['href'] = htmlentities($COMM_PATH.'&_action='.$attrib['command']);
        }

        // overwrite attributes
        if (!$attrib['href'])
            $attrib['href'] = '#';

        if ($command)
            $attrib['onclick'] = sprintf("return %s.command('%s','%s',this)", $this->JS_OBJECT_NAME, $command, $attrib['prop']);
    
        if ($command && $attrib['imageover'])
        {
            $attrib['onmouseover'] = sprintf("return %s.button_over('%s','%s')", $this->JS_OBJECT_NAME, $command, $attrib['id']);
            $attrib['onmouseout'] = sprintf("return %s.button_out('%s','%s')", $this->JS_OBJECT_NAME, $command, $attrib['id']);
        }

        if ($command && $attrib['imagesel'])
        {
            $attrib['onmousedown'] = sprintf("return %s.button_sel('%s','%s')", $this->JS_OBJECT_NAME, $command, $attrib['id']);
            $attrib['onmouseup'] = sprintf("return %s.button_out('%s','%s')", $this->JS_OBJECT_NAME, $command, $attrib['id']);
        }

        $out = '';

        // generate image tag
        if ($attrib['type']=='image')
        {
            $attrib_str = $this->create_attrib_string(
                                    $attrib,
                                    array(
                                        'style', 'class', 'id',
                                        'width', 'height', 'border',
                                        'hspace', 'vspace', 'align',
                                        'alt'
                                    )
            );
            $img_tag = sprintf('<img src="%%s"%s />', $attrib_str);
            $btn_content = sprintf($img_tag, $skin_path.$attrib['image']);
            if ($attrib['label'])
                $btn_content .= ' '.$attrib['label'];
    
            $link_attrib = array('href', 'onclick', 'onmouseover', 'onmouseout', 'onmousedown', 'onmouseup', 'title');
        }
        else if ($attrib['type']=='link')
        {
            $btn_content = $attrib['label'] ? $attrib['label'] : $attrib['command'];
            $link_attrib = array('href', 'onclick', 'title', 'id', 'class', 'style');
        }
        else if ($attrib['type']=='input')
        {
            $attrib['type'] = 'button';
    
            if ($attrib['label'])
                $attrib['value'] = $attrib['label'];
      
            $attrib_str = $this->create_attrib_string($attrib, array('type', 'value', 'onclick', 'id', 'class', 'style'));
            $out = sprintf('<input%s disabled />', $attrib_str);
        }

        // generate html code for button
        if ($btn_content)
        {
            $attrib_str = $this->create_attrib_string($attrib, $link_attrib);
            $out = sprintf('<a%s>%s</a>', $attrib_str, $btn_content);
        }
        return $out;
    }


    function rcube_menu($attrib)
    {
        return '';
    }



    function rcube_table_output($attrib, $table_data, $a_show_cols, $id_col)
    {
        // allow the following attributes to be added to the <table> tag
        $attrib_str = $this->create_attrib_string($attrib, array('style', 'class', 'id', 'cellpadding', 'cellspacing', 'border', 'summary'));
        $table = '<table' . $attrib_str . ">\n";
    
        // add table title
        $table .= "<thead><tr>\n";

        foreach ($a_show_cols as $col)
            $table.= '<td class="'.$col.'">';
            $table.= $this->rep_specialchars_output(rcMisc::rcube_label($col)) . "</td>\n";

        $table .= "</tr></thead>\n<tbody>\n";
  
        $c = 0;

        if (!is_array($table_data)) 
        {
            while ($table_data && ($sql_arr = $this->DB->fetch_assoc($table_data)))
            {
                $zebra_class = $c%2 ? 'even' : 'odd';
                $table .= sprintf('<tr id="rcmrow%d" class="contact '.$zebra_class.'">'."\n", $sql_arr[$id_col]);

                // format each col
                foreach ($a_show_cols as $col)
                {
                    $cont = $this->rep_specialchars_output($sql_arr[$col]);
	                $table .= '<td class="'.$col.'">' . $cont . "</td>\n";
                }

                $table .= "</tr>\n";
                $c++;
            }
        }
        else 
        {
            foreach ($table_data as $row_data)
            {
                $zebra_class = $c%2 ? 'even' : 'odd';

                $table .= sprintf('<tr id="rcmrow%d" class="contact '.$zebra_class.'">'."\n", $row_data[$id_col]);

                // format each col
                foreach ($a_show_cols as $col)
                {
                    $cont = $this->rep_specialchars_output($row_data[$col]);
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


    function rcmail_get_edit_field($col, $value, $attrib, $type='text')
    {
        $fname = '_'.$col;
        $attrib['name'] = $fname;
  
        if ($type=='checkbox')
        {
            $attrib['value'] = '1';
            $input = new checkbox($attrib);
        }
        else if ($type=='textarea')
        {
            $attrib['cols'] = $attrib['size'];
            $input = new textarea($attrib);
        }
        else
            $input = new textfield($attrib);

        // use value from post
        if (!empty($_POST[$fname]))
            $value = $_POST[$fname];

        $out = $input->show($value);
        return $out;
    }


    // compose a valid attribute string for HTML tags
    function create_attrib_string($attrib, $allowed_attribs=array('id', 'class', 'style'))
    {
        // allow the following attributes to be added to the <iframe> tag
        $attrib_str = '';
        foreach ($allowed_attribs as $a)
        if (isset($attrib[$a]))
            $attrib_str .= sprintf(' %s="%s"', $a, str_replace('"', '&quot;', $attrib[$a]));

        return $attrib_str;
    }

    // convert a HTML attribute string attributes to an associative array (name => value)
    function parse_attrib_string($str)
    {
        $attrib = array();
        preg_match_all('/\s*([-_a-z]+)=["]([^"]+)["]?/i', stripslashes($str), $regs, PREG_SET_ORDER);

        // convert attributes to an associative array (name => value)
        if ($regs)
        {
            foreach ($regs as $attr)
            {
                $attrib[strtolower($attr[1])] = $attr[2];
            }
        }
        return $attrib;
    }


    function format_date($date, $format=NULL)
    {
        $ts = NULL;
  
        if (is_numeric($date))
            $ts = $date;
        else if (!empty($date))
            $ts = @strtotime($date);
    
        if (empty($ts))
            return '';
   
        // get user's timezone
        $tz = $this->CONFIG['timezone'];
        if ($this->CONFIG['dst_active'])
            $tz++;

        // convert time to user's timezone
        $timestamp = $ts - date('Z', $ts) + ($tz * 3600);
  
        // get current timestamp in user's timezone
        $now = time();  // local time
        $now -= (int)date('Z'); // make GMT time
        $now += ($tz * 3600); // user's time
        $now_date = getdate();

        $today_limit = mktime(0, 0, 0, $now_date['mon'], $now_date['mday'], $now_date['year']);
        $week_limit = mktime(0, 0, 0, $now_date['mon'], $now_date['mday']-6, $now_date['year']);

        // define date format depending on current time  
        if ($this->CONFIG['prettydate'] && !$format && $timestamp > $today_limit)
            return sprintf('%s %s', rcMisc::rcube_label('today'), date('H:i', $timestamp));
        else if ($this->CONFIG['prettydate'] && !$format && $timestamp > $week_limit)
            $format = $this->CONFIG['date_short'] ? $this->CONFIG['date_short'] : 'D H:i';
        else if (!$format)
            $format = $this->CONFIG['date_long'] ? $this->CONFIG['date_long'] : 'd.m.Y H:i';


        // parse format string manually in order to provide localized weekday and month names
        // an alternative would be to convert the date() format string to fit with strftime()
        $out = '';
        for($i=0; $i<strlen($format); $i++)
        {
            if ($format{$i}=='\\')  // skip escape chars
                continue;
    
            // write char "as-is"
            if ($format{$i}==' ' || $format{$i-1}=='\\')
                $out .= $format{$i};
            // weekday (short)
            else if ($format{$i}=='D')
                $out .= rcMisc::rcube_label(strtolower(date('D', $timestamp)));
            // weekday long
            else if ($format{$i}=='l')
                $out .= rcMisc::rcube_label(strtolower(date('l', $timestamp)));
            // month name (short)
            else if ($format{$i}=='M')
                $out .= rcMisc::rcube_label(strtolower(date('M', $timestamp)));
            // month name (long)
            else if ($format{$i}=='F')
                $out .= rcMisc::rcube_label(strtolower(date('F', $timestamp)));
            else
                $out .= date($format{$i}, $timestamp);
        }
        return $out;
    }


    // ************** functions delivering gui objects **************

    function rcmail_message_container($attrib)
    {
        if (!$attrib['id'])
            $attrib['id'] = 'rcmMessageContainer';

        // allow the following attributes to be added to the <table> tag
        $attrib_str = $this->create_attrib_string($attrib, array('style', 'class', 'id'));
        $out = '<div' . $attrib_str . "></div>";
  
        $this->OUTPUT->add_script($this->JS_OBJECT_NAME . ".gui_object('message', '$attrib[id]');");
  
        return $out;
    }


    // return the IMAP username of the current session
    function rcmail_current_username($attrib)
    {
        static $s_username;

        // alread fetched  
        if (!empty($s_username))
            return $s_username;

        // get e-mail address form default identity
        $sql_result = $this->DB->query("SELECT email AS mailto
                            FROM ".$this->get_table_name('identities')."
                            WHERE  user_id=?
                            AND    standard=1
                            AND    del<>1",
                            $_SESSION['user_id']);
                                   
        if ($this->DB->num_rows($sql_result))
        {
            $sql_arr = $this->DB->fetch_assoc($sql_result);
            $s_username = $sql_arr['mailto'];
        }
        else if (strstr($_SESSION['username'], '@'))
            $s_username = $_SESSION['username'];
        else
            $s_username = $_SESSION['username'] . '@' . $_SESSION['imap_host'];

        return $s_username;
    }


    /**
     * @deprecated vNext - 2007/02/07
     */
    // return code for the webmail login form
    function rcmail_login_form($attrib)
    {
        global $SESS_HIDDEN_FIELD;
  
        $labels = array();
        $labels['user'] = rcMisc::rcube_label('username');
        $labels['pass'] = rcMisc::rcube_label('password');
        $labels['host'] = rcMisc::rcube_label('server');
  
        $input_user = new textfield(array('name' => '_user', 'id' => 'rcmloginuser', 'size' => 30));
        $input_pass = new passwordfield(array('name' => '_pass', 'id' => 'rcmloginpwd', 'size' => 30));
        $input_action = new hiddenfield(array('name' => '_action', 'value' => 'login'));
    
        $fields = array();
        $fields['user'] = $input_user->show(get_input_value('_user', RCUBE_INPUT_POST));
        $fields['pass'] = $input_pass->show();
        $fields['action'] = $input_action->show();
  
        if (is_array($this->CONFIG['default_host']))
        {
            $select_host = new select(array('name' => '_host', 'id' => 'rcmloginhost'));
    
            foreach ($this->CONFIG['default_host'] as $key => $value)
                $select_host->add($value, (is_numeric($key) ? $value : $key));
      
            $fields['host'] = $select_host->show($_POST['_host']);
        }
        else if (!strlen($this->CONFIG['default_host']))
        {
	        $input_host = new textfield(array('name' => '_host', 'id' => 'rcmloginhost', 'size' => 30));
	        $fields['host'] = $input_host->show($_POST['_host']);
        }

        $form_name = strlen($attrib['form']) ? $attrib['form'] : 'form';
        $form_start = !strlen($attrib['form']) ? '<form name="form" action="./" method="post">' : '';
        $form_end = !strlen($attrib['form']) ? '</form>' : '';
  
        if ($fields['host'])
            $form_host = <<<EOF
</tr><tr>

<td class="title"><label for="rcmloginhost">$labels[host]</label></td>
<td>$fields[host]</td>

EOF;

        $this->OUTPUT->add_script($this->JS_OBJECT_NAME . ".gui_object('loginform', '$form_name');");
  
        $out = <<<EOF
$form_start
$SESS_HIDDEN_FIELD
$fields[action]
<table><tr>

<td class="title"><label for="rcmloginuser">$labels[user]</label></td>
<td>$fields[user]</td>

</tr><tr>

<td class="title"><label for="rcmloginpwd">$labels[pass]</label></td>
<td>$fields[pass]</td>
$form_host
</tr></table>
$form_end
EOF;

        return $out;
    }


    /**
     * @deprecated vNext - 2007/02/12
     */
    function rcmail_charset_selector($attrib)
    {
        // pass the following attributes to the form class
        $field_attrib = array('name' => '_charset');
        foreach ($attrib as $attr => $value)
        if (in_array($attr, array('id', 'class', 'style', 'size', 'tabindex')))
            $field_attrib[$attr] = $value;
      
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
  
        $set = $_POST['_charset'] ? $_POST['_charset'] : $this->OUTPUT->get_charset();
        return $select->show($set);
    }

    /****** debugging function ********/
    function rcube_timer()
    {
        list($usec, $sec) = explode(" ", microtime());
        return ((float)$usec + (float)$sec);
    }
  

    function rcube_print_time($timer, $label='Timer')
    {
        static $print_count = 0;
  
        $print_count++;
        $now = $this->rcube_timer();
        $diff = $now-$timer;
  
        if (empty($label))
            $label = 'Timer '.$print_count;
  
        console(sprintf("%s: %0.4f sec", $label, $diff));
    }

    /**
     * imap_delete_messages
     *
     * Wrapper for delete_message call to internal IMAP object.
     *
     * @access public
     * @param  mixed  $uids
     * @param  string $mailbox
     * @see    rcube_imap::delete_message
     * @return mixed
     * @since  vNext
     */
    public function imap_delete_messages($uids, $mailbox='')
    {
        if (empty($uids))
        {
            throw new rcException('No message ids.');
        }
        if (!is_array($uids) && strstr($uids, ',') !== false)
        {
            $uids = explode(',', $uids);
        }
        if (!is_array($uids))
        {
            $uids = array($uids);
        }
        $this->IMAP->delete_message($uids, $mbox_name='');

        $actions = array(); 
   
        for ($i=0; $i<count($uids); $i++)
        {
            $data             = new stdClass;
            $data->action     = 'confirmDeleteMessage';
            $data->messageId  = $uids[$i];

            $actions[] = $data;
        }
        return $this->sendSuccess('webmail', $actions);
    }

    /**
     * @access public
     * @todo   Implement
     * @since  vNext
     */
    function imap_get_messages($mailbox)
    {
        return true;
    }
}
?>
