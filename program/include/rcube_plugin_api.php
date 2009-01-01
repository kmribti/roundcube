<?php

/*
 +-----------------------------------------------------------------------+
 | program/include/rcube_plugin_api.php                                  |
 |                                                                       |
 | This file is part of the RoundCube Webmail client                     |
 | Copyright (C) 2008-2009, RoundCube Dev. - Switzerland                 |
 | Licensed under the GNU GPL                                            |
 |                                                                       |
 | PURPOSE:                                                              |
 |   Plugins repository                                                  |
 |                                                                       |
 +-----------------------------------------------------------------------+
 | Author: Thomas Bruederli <roundcube@gmail.com>                        |
 +-----------------------------------------------------------------------+

 $Id: $

*/

/**
 * The plugin loader and global API
 *
 * @package Core
 */
class rcube_plugin_api
{
  static private $instance;
  
  private $handlers = array();
  private $plugins = array();


  /**
   * This implements the 'singleton' design pattern
   *
   * @return object rcube_plugin_api The one and only instance if this class
   */
  static function get_instance()
  {
    if (!self::$instance) {
      self::$instance = new rcube_plugin_api();
    }

    return self::$instance;
  }
  
  
  /**
   * Private constructor
   */
  private function __construct()
  {
    $rcmail = rcmail::get_instance();
    
    // only active in devel_mode for now
    if (!$rcmail->config->get('devel_mode'))
      return;
    
    // load all enabled plugins
    $plugins_dir = dir($rcmail->config->get('plugins_dir'));
    $plugins_enabled = (array)$rcmail->config->get('plugins', array());
    
    foreach ($plugins_enabled as $plugin_name) {
      $fn = $plugins_dir->path . DIRECTORY_SEPARATOR . $plugin_name . DIRECTORY_SEPARATOR . $plugin_name . '.php';
      
      if (file_exists($fn)) {
        include($fn);
        
        // instantiate class if exists
        if (class_exists($plugin_name, false)) {
          $plugin = new $plugin_name($this);
          // check inheritance and task specification
          if (is_subclass_of($plugin, 'rcube_plugin') && (!$plugin->task || $plugin->task == $rcmail->task)) {
            $this->plugins[] = $plugin;
          }
        }
        else {
          trigger_error(array('code' => 520, 'type' => 'php', 'message' => "No plugin class $plugin_name found in $fn"), true, false);
        }
      }
      else {
        trigger_error(array('code' => 520, 'type' => 'php', 'message' => "Failed to load plugin file $fn"), true, false);
      }
    }
    
    // maybe also register a shudown function which triggers shutdown functions of all plugin objects
  }
  
  
  /**
   * Allows a plugin object to register a callback for a certain hook
   *
   * @param string Hook name
   * @param mixed String with global function name or array($obj, 'methodname')
   */
  public function register_hook($hook, $callback)
  {
    if (is_callable($callback))
      $this->handlers[$hook][] = $callback;
    else
      trigger_error(array('code' => 521, 'type' => 'php', 'message' => "Invalid callback function for $hook"), true, false);
  }
  
  
  /**
   * Triggers a plugin hook.
   * This is called from the application and executes all registered handlers
   *
   * @param string Hook name
   * @param array Named arguments (key->value pairs)
   * @return array The (probably) altered hook arguments
   */
  public function exec_hook($hook, $args = array())
  {
    $args += array('abort' => false);
    
    foreach ((array)$this->handlers[$hook] as $callback) {
      $ret = call_user_func($callback, $args);
      if ($ret && is_array($ret))
        $args = $ret + $args;
      
      if ($args['abort'])
        break;
    }
    
    return $args;
  }
  

}

