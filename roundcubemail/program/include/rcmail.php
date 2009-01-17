<?php

/*
 +-----------------------------------------------------------------------+
 | program/include/rcmail.php                                            |
 |                                                                       |
 | This file is part of the RoundCube Webmail client                     |
 | Copyright (C) 2008-2009, RoundCube Dev. - Switzerland                 |
 | Licensed under the GNU GPL                                            |
 |                                                                       |
 | PURPOSE:                                                              |
 |   Application class providing core functions and holding              |
 |   instances of all 'global' objects like db- and imap-connections     |
 +-----------------------------------------------------------------------+
 | Author: Thomas Bruederli <roundcube@gmail.com>                        |
 +-----------------------------------------------------------------------+

 $Id: rcmail.php 328 2006-08-30 17:41:21Z thomasb $

*/


/**
 * Application class of RoundCube Webmail
 * implemented as singleton
 *
 * @package Core
 */
class rcmail
{
  static public $main_tasks = array('mail','settings','addressbook','login','logout');
  
  static private $instance;
  
  public $config;
  public $user;
  public $db;
  public $imap;
  public $output;
  public $task = 'mail';
  public $action = '';
  public $comm_path = './';
  
  private $texts;
  
  
  /**
   * This implements the 'singleton' design pattern
   *
   * @return object qvert The one and only instance
   */
  static function get_instance()
  {
    if (!self::$instance) {
      self::$instance = new rcmail();
      self::$instance->startup();  // init AFTER object was linked with self::$instance
    }

    return self::$instance;
  }
  
  
  /**
   * Private constructor
   */
  private function __construct()
  {
    // load configuration
    $this->config = new rcube_config();
    
    register_shutdown_function(array($this, 'shutdown'));
  }
  
  
  /**
   * Initial startup function
   * to register session, create database and imap connections
   *
   * @todo Remove global vars $DB, $USER
   */
  private function startup()
  {
    $config_all = $this->config->all();

    // initialize syslog
    if ($this->config->get('log_driver') == 'syslog') {
      $syslog_id = $this->config->get('syslog_id', 'roundcube');
      $syslog_facility = $this->config->get('syslog_facility', LOG_USER);
      openlog($syslog_id, LOG_ODELAY, $syslog_facility);
    }
    				
    // set task and action properties
    $this->set_task(strip_quotes(get_input_value('_task', RCUBE_INPUT_GPC)));
    $this->action = asciiwords(get_input_value('_action', RCUBE_INPUT_GPC));

    // connect to database
    $GLOBALS['DB'] = $this->get_dbh();

    // use database for storing session data
    include_once('include/session.inc');

    // set session domain
    if (!empty($config_all['session_domain'])) {
      ini_set('session.cookie_domain', $config_all['session_domain']);
    }
    // set session garbage collecting time according to session_lifetime
    if (!empty($config_all['session_lifetime'])) {
      ini_set('session.gc_maxlifetime', ($config_all['session_lifetime']) * 120);
    }

    // start PHP session (if not in CLI mode)
    if ($_SERVER['REMOTE_ADDR'])
      session_start();

    // set initial session vars
    if (!isset($_SESSION['auth_time'])) {
      $_SESSION['auth_time'] = time();
      $_SESSION['temp'] = true;
    }

    // create user object
    $this->set_user(new rcube_user($_SESSION['user_id']));

    // reset some session parameters when changing task
    if ($_SESSION['task'] != $this->task)
      unset($_SESSION['page']);

    // set current task to session
    $_SESSION['task'] = $this->task;

    // create IMAP object
    if ($this->task == 'mail')
      $this->imap_init();
  }
  
  
  /**
   * Setter for application task
   *
   * @param string Task to set
   */
  public function set_task($task)
  {
    if (!in_array($task, self::$main_tasks))
      $task = 'mail';
    
    $this->task = $task;
    $this->comm_path = $this->url(array('task' => $task));
    
    if ($this->output)
      $this->output->set_env('task', $task);
  }
  
  
  /**
   * Setter for system user object
   *
   * @param object rcube_user Current user instance
   */
  public function set_user($user)
  {
    if (is_object($user)) {
      $this->user = $user;
      $GLOBALS['USER'] = $this->user;
      
      // overwrite config with user preferences
      $this->config->merge((array)$this->user->get_prefs());
    }
    
    $_SESSION['language'] = $this->user->language = $this->language_prop($this->config->get('language', $_SESSION['language']));

    // set localization
    setlocale(LC_ALL, $_SESSION['language'] . '.utf8', 'en_US.utf8');

    // workaround for http://bugs.php.net/bug.php?id=18556 
    if (in_array($_SESSION['language'], array('tr_TR', 'ku', 'az_AZ'))) 
      setlocale(LC_CTYPE, 'en_US' . '.utf8'); 
  }
  
  
  /**
   * Check the given string and return a valid language code
   *
   * @param string Language code
   * @return string Valid language code
   */
  private function language_prop($lang)
  {
    static $rcube_languages, $rcube_language_aliases;
    
    // user HTTP_ACCEPT_LANGUAGE if no language is specified
    if (empty($lang) || $lang == 'auto') {
       $accept_langs = explode(',', $_SERVER['HTTP_ACCEPT_LANGUAGE']);
       $lang = str_replace('-', '_', $accept_langs[0]);
     }
     
    if (empty($rcube_languages)) {
      @include(INSTALL_PATH . 'program/localization/index.inc');
    }
    
    // check if we have an alias for that language
    if (!isset($rcube_languages[$lang]) && isset($rcube_language_aliases[$lang])) {
      $lang = $rcube_language_aliases[$lang];
    }
    // try the first two chars
    else if (!isset($rcube_languages[$lang])) {
      $short = substr($lang, 0, 2);
     
      // check if we have an alias for the short language code
      if (!isset($rcube_languages[$short]) && isset($rcube_language_aliases[$short])) {
        $lang = $rcube_language_aliases[$short];
      }
      // expand 'nn' to 'nn_NN'
      else if (!isset($rcube_languages[$short])) {
        $lang = $short.'_'.strtoupper($short);
      }
    }

    if (!isset($rcube_languages[$lang]) || !is_dir(INSTALL_PATH . 'program/localization/' . $lang)) {
      $lang = 'en_US';
    }

    return $lang;
  }
  
  
  /**
   * Get the current database connection
   *
   * @return object rcube_mdb2  Database connection object
   */
  public function get_dbh()
  {
    if (!$this->db) {
      $config_all = $this->config->all();

      $this->db = new rcube_mdb2($config_all['db_dsnw'], $config_all['db_dsnr'], $config_all['db_persistent']);
      $this->db->sqlite_initials = INSTALL_PATH . 'SQL/sqlite.initial.sql';
      $this->db->set_debug((bool)$config_all['sql_debug']);
      $this->db->db_connect('w');
    }

    return $this->db;
  }
  
  
  /**
   * Return instance of the internal address book class
   *
   * @param boolean True if the address book needs to be writeable
   * @return object rcube_contacts Address book object
   */
  public function get_address_book($id, $writeable = false)
  {
    $contacts = null;
    $ldap_config = (array)$this->config->get('ldap_public');
    $abook_type = strtolower($this->config->get('address_book_type'));
    
    if ($id && $ldap_config[$id]) {
      $contacts = new rcube_ldap($ldap_config[$id]);
    }
    else if ($abook_type == 'ldap') {
      // Use the first writable LDAP address book.
      foreach ($ldap_config as $id => $prop) {
        if (!$writeable || $prop['writable']) {
          $contacts = new rcube_ldap($prop);
          break;
        }
      }
    }
    else {
      $contacts = new rcube_contacts($this->db, $this->user->ID);
    }
    
    return $contacts;
  }
  
  
  /**
   * Init output object for GUI and add common scripts.
   * This will instantiate a rcmail_template object and set
   * environment vars according to the current session and configuration
   *
   * @param boolean True if this request is loaded in a (i)frame
   * @return object rcube_template Reference to HTML output object
   */
  public function load_gui($framed = false)
  {
    // init output page
    if (!($this->output instanceof rcube_template))
      $this->output = new rcube_template($this->task, $framed);

    foreach (array('flag_for_deletion','read_when_deleted') as $js_config_var) {
      $this->output->set_env($js_config_var, $this->config->get($js_config_var));
    }
    
    // set keep-alive/check-recent interval
    if ($keep_alive = $this->config->get('keep_alive')) {
      // be sure that it's less than session lifetime
      if ($session_lifetime = $this->config->get('session_lifetime'))
        $keep_alive = min($keep_alive, $session_lifetime * 60 - 30);
      $this->output->set_env('keep_alive', max(60, $keep_alive));
    }

    if ($framed) {
      $this->comm_path .= '&_framed=1';
      $this->output->set_env('framed', true);
    }

    $this->output->set_env('task', $this->task);
    $this->output->set_env('action', $this->action);
    $this->output->set_env('comm_path', $this->comm_path);
    $this->output->set_charset($this->config->get('charset', RCMAIL_CHARSET));

    // add some basic label to client
    $this->output->add_label('loading');
    
    return $this->output;
  }
  
  
  /**
   * Create an output object for JSON responses
   *
   * @return object rcube_json_output Reference to JSON output object
   */
  public function init_json()
  {
    if (!($this->output instanceof rcube_json_output))
      $this->output = new rcube_json_output($this->task);
    
    return $this->output;
  }
  
  
  /**
   * Create global IMAP object and connect to server
   *
   * @param boolean True if connection should be established
   * @todo Remove global $IMAP
   */
  public function imap_init($connect = false)
  {
    $this->imap = new rcube_imap($this->db);
    $this->imap->debug_level = $this->config->get('debug_level');
    $this->imap->skip_deleted = $this->config->get('skip_deleted');

    // enable caching of imap data
    if ($this->config->get('enable_caching')) {
      $this->imap->set_caching(true);
    }

    // set pagesize from config
    $this->imap->set_pagesize($this->config->get('pagesize', 50));
    
    // Setting root and delimiter before iil_Connect can save time detecting them
    // using NAMESPACE and LIST 
    $options = array(
      'imap' => $this->config->get('imap_auth_type', 'check'),
      'delimiter' => isset($_SESSION['imap_delimiter']) ? $_SESSION['imap_delimiter'] : $this->config->get('imap_delimiter'),
    );
    
    if (isset($_SESSION['imap_root']))
      $options['rootdir'] = $_SESSION['imap_root'];
    else if ($imap_root = $this->config->get('imap_root'))
      $options['rootdir'] = $imap_root;
    
    $this->imap->set_options($options);
  
    // set global object for backward compatibility
    $GLOBALS['IMAP'] = $this->imap;
    
    if ($connect)
      $this->imap_connect();
  }


  /**
   * Connect to IMAP server with stored session data
   *
   * @return bool True on success, false on error
   */
  public function imap_connect()
  {
    $conn = false;
    
    if ($_SESSION['imap_host'] && !$this->imap->conn) {
      if (!($conn = $this->imap->connect($_SESSION['imap_host'], $_SESSION['username'], $this->decrypt_passwd($_SESSION['password']), $_SESSION['imap_port'], $_SESSION['imap_ssl']))) {
        if ($this->output)
          $this->output->show_message($this->imap->error_code == -1 ? 'imaperror' : 'sessionerror', 'error');
      }

      $this->set_imap_prop();
    }

    return $conn;
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
  function login($username, $pass, $host=NULL)
  {
    $user = NULL;
    $config = $this->config->all();

    if (!$host)
      $host = $config['default_host'];

    // Validate that selected host is in the list of configured hosts
    if (is_array($config['default_host'])) {
      $allowed = false;
      foreach ($config['default_host'] as $key => $host_allowed) {
        if (!is_numeric($key))
          $host_allowed = $key;
        if ($host == $host_allowed) {
          $allowed = true;
          break;
        }
      }
      if (!$allowed)
        return false;
      }
    else if (!empty($config['default_host']) && $host != $config['default_host'])
      return false;

    // parse $host URL
    $a_host = parse_url($host);
    if ($a_host['host']) {
      $host = $a_host['host'];
      $imap_ssl = (isset($a_host['scheme']) && in_array($a_host['scheme'], array('ssl','imaps','tls'))) ? $a_host['scheme'] : null;
      $imap_port = isset($a_host['port']) ? $a_host['port'] : ($imap_ssl ? 993 : $config['default_port']);
    }
    else
      $imap_port = $config['default_port'];


    /* Modify username with domain if required  
       Inspired by Marco <P0L0_notspam_binware.org>
    */
    // Check if we need to add domain
    if (!empty($config['username_domain']) && !strpos($username, '@')) {
      if (is_array($config['username_domain']) && isset($config['username_domain'][$host]))
        $username .= '@'.$config['username_domain'][$host];
      else if (is_string($config['username_domain']))
        $username .= '@'.$config['username_domain'];
    }

    // try to resolve email address from virtuser table    
    if (!empty($config['virtuser_file']) && strpos($username, '@'))
      $username = rcube_user::email2user($username);

    // lowercase username if it's an e-mail address (#1484473)
    if (strpos($username, '@'))
      $username = rc_strtolower($username);

    // user already registered -> overwrite username
    if ($user = rcube_user::query($username, $host))
      $username = $user->data['username'];

    // exit if IMAP login failed
    if (!($imap_login  = $this->imap->connect($host, $username, $pass, $imap_port, $imap_ssl)))
      return false;

    // user already registered -> update user's record
    if (is_object($user)) {
      $user->touch();
    }
    // create new system user
    else if ($config['auto_create_user']) {
      if ($created = rcube_user::create($username, $host)) {
        $user = $created;

        // get existing mailboxes (but why?)
        // $a_mailboxes = $this->imap->list_mailboxes();
      }
    }
    else {
      raise_error(array(
        'code' => 600,
        'type' => 'php',
        'file' => RCMAIL_CONFIG_DIR."/main.inc.php",
        'message' => "Acces denied for new user $username. 'auto_create_user' is disabled"
        ), true, false);
    }

    // login succeeded
    if (is_object($user) && $user->ID) {
      $this->set_user($user);

      // set session vars
      $_SESSION['user_id']   = $user->ID;
      $_SESSION['username']  = $user->data['username'];
      $_SESSION['imap_host'] = $host;
      $_SESSION['imap_port'] = $imap_port;
      $_SESSION['imap_ssl']  = $imap_ssl;
      $_SESSION['password']  = $this->encrypt_passwd($pass);
      $_SESSION['login_time'] = mktime();
      
      if ($_REQUEST['_timezone'] != '_default_')
        $_SESSION['timezone'] = floatval($_REQUEST['_timezone']);

      // force reloading complete list of subscribed mailboxes
      $this->set_imap_prop();
      $this->imap->clear_cache('mailboxes');

      if ($config['create_default_folders'])
          $this->imap->create_default_folders();

      return true;
    }

    return false;
  }


  /**
   * Set root dir and last stored mailbox
   * This must be done AFTER connecting to the server!
   */
  public function set_imap_prop()
  {
    $this->imap->set_charset($this->config->get('default_charset', RCMAIL_CHARSET));

    if ($default_folders = $this->config->get('default_imap_folders')) {
      $this->imap->set_default_mailboxes($default_folders);
    }
    if (!empty($_SESSION['mbox'])) {
      $this->imap->set_mailbox($_SESSION['mbox']);
    }
    if (isset($_SESSION['page'])) {
      $this->imap->set_page($_SESSION['page']);
    }
    
    // cache IMAP root and delimiter in session for performance reasons
    $_SESSION['imap_root'] = $this->imap->root_dir;
    $_SESSION['imap_delimiter'] = $this->imap->delimiter;
  }


  /**
   * Auto-select IMAP host based on the posted login information
   *
   * @return string Selected IMAP host
   */
  public function autoselect_host()
  {
    $default_host = $this->config->get('default_host');
    $host = null;
    
    if (is_array($default_host)) {
      $post_host = get_input_value('_host', RCUBE_INPUT_POST);
      
      // direct match in default_host array
      if ($default_host[$post_host] || in_array($post_host, array_values($default_host))) {
        $host = $post_host;
      }
      
      // try to select host by mail domain
      list($user, $domain) = explode('@', get_input_value('_user', RCUBE_INPUT_POST));
      if (!empty($domain)) {
        foreach ($default_host as $imap_host => $mail_domains) {
          if (is_array($mail_domains) && in_array($domain, $mail_domains)) {
            $host = $imap_host;
            break;
          }
        }
      }

      // take the first entry if $host is still an array
      if (empty($host)) {
        $host = array_shift($default_host);
      }
    }
    else if (empty($default_host)) {
      $host = get_input_value('_host', RCUBE_INPUT_POST);
    }
    else
      $host = $default_host;

    return $host;
  }


  /**
   * Get localized text in the desired language
   *
   * @param mixed Named parameters array or label name
   * @return string Localized text
   */
  public function gettext($attrib)
  {
    // load localization files if not done yet
    if (empty($this->texts))
      $this->load_language();
    
    // extract attributes
    if (is_string($attrib))
      $attrib = array('name' => $attrib);

    $nr = is_numeric($attrib['nr']) ? $attrib['nr'] : 1;
    $vars = isset($attrib['vars']) ? $attrib['vars'] : '';

    $command_name = !empty($attrib['command']) ? $attrib['command'] : NULL;
    $alias = $attrib['name'] ? $attrib['name'] : ($command_name && $command_label_map[$command_name] ? $command_label_map[$command_name] : '');

    // text does not exist
    if (!($text_item = $this->texts[$alias])) {
      /*
      raise_error(array(
        'code' => 500,
        'type' => 'php',
        'line' => __LINE__,
        'file' => __FILE__,
        'message' => "Missing localized text for '$alias' in '$sess_user_lang'"), TRUE, FALSE);
      */
      return "[$alias]";
    }

    // make text item array 
    $a_text_item = is_array($text_item) ? $text_item : array('single' => $text_item);

    // decide which text to use
    if ($nr == 1) {
      $text = $a_text_item['single'];
    }
    else if ($nr > 0) {
      $text = $a_text_item['multiple'];
    }
    else if ($nr == 0) {
      if ($a_text_item['none'])
        $text = $a_text_item['none'];
      else if ($a_text_item['single'])
        $text = $a_text_item['single'];
      else if ($a_text_item['multiple'])
        $text = $a_text_item['multiple'];
    }

    // default text is single
    if ($text == '') {
      $text = $a_text_item['single'];
    }

    // replace vars in text
    if (is_array($attrib['vars'])) {
      foreach ($attrib['vars'] as $var_key => $var_value)
        $a_replace_vars[$var_key{0}=='$' ? substr($var_key, 1) : $var_key] = $var_value;
    }

    if ($a_replace_vars)
      $text = preg_replace('/\$\{?([_a-z]{1}[_a-z0-9]*)\}?/ei', '$a_replace_vars["\1"]', $text);

    // format output
    if (($attrib['uppercase'] && strtolower($attrib['uppercase']=='first')) || $attrib['ucfirst'])
      return ucfirst($text);
    else if ($attrib['uppercase'])
      return strtoupper($text);
    else if ($attrib['lowercase'])
      return strtolower($text);

    return $text;
  }


  /**
   * Load a localization package
   *
   * @param string Language ID
   */
  public function load_language($lang = null)
  {
    $lang = $this->language_prop(($lang ? $lang : $_SESSION['language']));
    
    // load localized texts
    if (empty($this->texts) || $lang != $_SESSION['language']) {
      $this->texts = array();

      // get english labels (these should be complete)
      @include(INSTALL_PATH . 'program/localization/en_US/labels.inc');
      @include(INSTALL_PATH . 'program/localization/en_US/messages.inc');

      if (is_array($labels))
        $this->texts = $labels;
      if (is_array($messages))
        $this->texts = array_merge($this->texts, $messages);

      // include user language files
      if ($lang != 'en' && is_dir(INSTALL_PATH . 'program/localization/' . $lang)) {
        include_once(INSTALL_PATH . 'program/localization/' . $lang . '/labels.inc');
        include_once(INSTALL_PATH . 'program/localization/' . $lang . '/messages.inc');

        if (is_array($labels))
          $this->texts = array_merge($this->texts, $labels);
        if (is_array($messages))
          $this->texts = array_merge($this->texts, $messages);
      }
      
      $_SESSION['language'] = $lang;
    }
  }


  /**
   * Read directory program/localization and return a list of available languages
   *
   * @return array List of available localizations
   */
  public function list_languages()
  {
    static $sa_languages = array();

    if (!sizeof($sa_languages)) {
      @include(INSTALL_PATH . 'program/localization/index.inc');

      if ($dh = @opendir(INSTALL_PATH . 'program/localization')) {
        while (($name = readdir($dh)) !== false) {
          if ($name{0}=='.' || !is_dir(INSTALL_PATH . 'program/localization/' . $name))
            continue;

          if ($label = $rcube_languages[$name])
            $sa_languages[$name] = $label ? $label : $name;
        }
        closedir($dh);
      }
    }

    return $sa_languages;
  }


  /**
   * Check the auth hash sent by the client against the local session credentials
   *
   * @return boolean True if valid, False if not
   */
  function authenticate_session()
  {
    global $SESS_CLIENT_IP, $SESS_CHANGED;

    // advanced session authentication
    if ($this->config->get('double_auth')) {
      $now = time();
      $valid = ($_COOKIE['sessauth'] == $this->get_auth_hash(session_id(), $_SESSION['auth_time']) ||
                $_COOKIE['sessauth'] == $this->get_auth_hash(session_id(), $_SESSION['last_auth']));

      // renew auth cookie every 5 minutes (only for GET requests)
      if (!$valid || ($_SERVER['REQUEST_METHOD']!='POST' && $now - $_SESSION['auth_time'] > 300)) {
        $_SESSION['last_auth'] = $_SESSION['auth_time'];
        $_SESSION['auth_time'] = $now;
        rcmail::setcookie('sessauth', $this->get_auth_hash(session_id(), $now), 0);
      }
    }
    else {
      $valid = $this->config->get('ip_check') ? $_SERVER['REMOTE_ADDR'] == $SESS_CLIENT_IP : true;
    }

    // check session filetime
    $lifetime = $this->config->get('session_lifetime');
    if (!empty($lifetime) && isset($SESS_CHANGED) && $SESS_CHANGED + $lifetime*60 < time()) {
      $valid = false;
    }

    return $valid;
  }


  /**
   * Destroy session data and remove cookie
   */
  public function kill_session()
  {
    $_SESSION = array('language' => $this->user->language, 'auth_time' => time(), 'temp' => true);
    rcmail::setcookie('sessauth', '-del-', time() - 60);
    $this->user->reset();
  }


  /**
   * Do server side actions on logout
   */
  public function logout_actions()
  {
    $config = $this->config->all();
    
    // on logout action we're not connected to imap server  
    if (($config['logout_purge'] && !empty($config['trash_mbox'])) || $config['logout_expunge']) {
      if (!$this->authenticate_session())
        return;

      $this->imap_init(true);
    }

    if ($config['logout_purge'] && !empty($config['trash_mbox'])) {
      $this->imap->clear_mailbox($config['trash_mbox']);
    }

    if ($config['logout_expunge']) {
      $this->imap->expunge('INBOX');
    }
  }


  /**
   * Function to be executed in script shutdown
   * Registered with register_shutdown_function()
   */
  public function shutdown()
  {
    if (is_object($this->imap)) {
      $this->imap->close();
      $this->imap->write_cache();
    }

    if (is_object($this->contacts))
      $this->contacts->close();

    // before closing the database connection, write session data
    if ($_SERVER['REMOTE_ADDR'])
      session_write_close();
  }
  
  
  /**
   * Create unique authorization hash
   *
   * @param string Session ID
   * @param int Timestamp
   * @return string The generated auth hash
   */
  private function get_auth_hash($sess_id, $ts)
  {
    $auth_string = sprintf('rcmail*sess%sR%s*Chk:%s;%s',
      $sess_id,
      $ts,
      $this->config->get('ip_check') ? $_SERVER['REMOTE_ADDR'] : '***.***.***.***',
      $_SERVER['HTTP_USER_AGENT']);

    if (function_exists('sha1'))
      return sha1($auth_string);
    else
      return md5($auth_string);
  }

  /**
   * Encrypt IMAP password using DES encryption
   *
   * @param string Password to encrypt
   * @return string Encryprted string
   */
  public function encrypt_passwd($pass)
  {
    if (function_exists('mcrypt_module_open') && ($td = mcrypt_module_open(MCRYPT_TripleDES, "", MCRYPT_MODE_ECB, ""))) {
      $iv = mcrypt_create_iv(mcrypt_enc_get_iv_size($td), MCRYPT_RAND);
      mcrypt_generic_init($td, $this->config->get_des_key(), $iv);
      $cypher = mcrypt_generic($td, $pass);
      mcrypt_generic_deinit($td);
      mcrypt_module_close($td);
    }
    else if (function_exists('des')) {
      $cypher = des($this->config->get_des_key(), $pass, 1, 0, NULL);
    }
    else {
      $cypher = $pass;

      raise_error(array(
        'code' => 500,
        'type' => 'php',
        'file' => __FILE__,
        'message' => "Could not convert encrypt password. Make sure Mcrypt is installed or lib/des.inc is available"
        ), true, false);
    }

    return base64_encode($cypher);
  }


  /**
   * Decrypt IMAP password using DES encryption
   *
   * @param string Encrypted password
   * @return string Plain password
   */
  public function decrypt_passwd($cypher)
  {
    if (function_exists('mcrypt_module_open') && ($td = mcrypt_module_open(MCRYPT_TripleDES, "", MCRYPT_MODE_ECB, ""))) {
      $iv = mcrypt_create_iv(mcrypt_enc_get_iv_size($td), MCRYPT_RAND);
      mcrypt_generic_init($td, $this->config->get_des_key(), $iv);
      $pass = mdecrypt_generic($td, base64_decode($cypher));
      mcrypt_generic_deinit($td);
      mcrypt_module_close($td);
    }
    else if (function_exists('des')) {
      $pass = des($this->config->get_des_key(), base64_decode($cypher), 0, 0, NULL);
    }
    else {
      $pass = base64_decode($cypher);
    }

    return preg_replace('/\x00/', '', $pass);
  }


  /**
   * Build a valid URL to this instance of RoundCube
   *
   * @param mixed Either a string with the action or url parameters as key-value pairs
   * @return string Valid application URL
   */
  public function url($p)
  {
    if (!is_array($p))
      $p = array('_action' => @func_get_arg(0));

    if (!$p['task'] || !in_array($p['task'], rcmail::$main_tasks))
      $p['task'] = $this->task;

    $p['_task'] = $p['task'];
    unset($p['task']);

    $url = './';
    $delm = '?';
    foreach (array_reverse($p) as $par => $val)
    {
      if (!empty($val)) {
        $url .= $delm.urlencode($par).'='.urlencode($val);
        $delm = '&';
      }
    }
    return $url;
  }


  /**
   * Helper method to set a cookie with the current path and host settings
   *
   * @param string Cookie name
   * @param string Cookie value
   * @param string Expiration time
   */
  public static function setcookie($name, $value, $exp = 0)
  {
    $cookie = session_get_cookie_params();
    setcookie($name, $value, $exp, $cookie['path'], $cookie['domain'],
      ($_SERVER['HTTPS'] && ($_SERVER['HTTPS'] != 'off')));
  }
}


