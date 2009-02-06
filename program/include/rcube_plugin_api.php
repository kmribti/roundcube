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
  
  public $dir;
  public $url = 'plugins/';
  
  private $handlers = array();
  private $plugins = array();
  private $actions = array();
  private $actionmap = array();
  private $templobjects = array();
  private $objectsmap = array();
  private $scripts = array();
  private $output;
  

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
    
    $this->dir = realpath($rcmail->config->get('plugins_dir'));
    
    // load all enabled plugins
    $plugins_dir = dir($this->dir);
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
            $plugin->ID = $plugin_name;
            $this->plugins[] = $plugin;
          }
        }
        else {
          raise_error(array('code' => 520, 'type' => 'php', 'message' => "No plugin class $plugin_name found in $fn"), true, false);
        }
      }
      else {
        raise_error(array('code' => 520, 'type' => 'php', 'message' => "Failed to load plugin file $fn"), true, false);
      }
    }
    
    // maybe also register a shudown function which triggers shutdown functions of all plugin objects
  }
  
  
  /**
   * Add GUI things once the output objects is created
   */
  public function init_gui($output)
  {
    if ($output->type == 'html') {
      $output->add_handlers($this->objectsmap);
      
      foreach ($this->scripts as $script)
        $output->add_header(html::tag('script', array('type' => "text/javascript", 'src' => $script)));
    }
    
    $this->output = $output;
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
      raise_error(array('code' => 521, 'type' => 'php', 'message' => "Invalid callback function for $hook"), true, false);
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


  /**
   * Let a plugin register a handler for a specific request
   *
   * @param string Action name (_task=mail&_action=plugin.foo)
   * @param string Plugin name that registers this action
   * @param mixed Callback: string with global function name or array($obj, 'methodname')
   */
  public function register_action($action, $owner, $callback)
  {
    // check action name
    if (strpos($action, 'plugin.') !== 0)
      $action = 'plugin.'.$action;
    
    // can register action only if it's not taken or registered by myself
    if (!isset($this->actionmap[$action]) || $this->actionmap[$action] == $owner) {
      $this->actions[$action] = $callback;
      $this->actionmap[$action] = $owner;
    }
    else {
      raise_error(array('code' => 523, 'type' => 'php', 'message' => "Cannot register action $action; already taken by another plugin"), true, false);
    }
  }


  /**
   * This method handles requests like _task=mail&_action=plugin.foo
   * It executes the callback function that was registered with the given action.
   *
   * @param string Action name
   */
  public function exec_action($action)
  {
    if (isset($this->actions[$action])) {
      call_user_func($this->actions[$action]);
    }
    else {
      raise_error(array('code' => 524, 'type' => 'php', 'message' => "No handler found for action $action"), true, true);
    }
  }


  /**
   * Register a handler function for template objects
   *
   * @param string Object name
   * @param string Plugin name that registers this action
   * @param mixed Callback: string with global function name or array($obj, 'methodname')
   */
  public function register_handler($name, $owner, $callback)
  {
    // check name
    if (strpos($name, 'plugin.') !== 0)
      $name = 'plugin.'.$name;
    
    // can register handler only if it's not taken or registered by myself
    if (!isset($this->objectsmap[$name]) || $this->objectsmap[$name] == $owner) {
      // output is ready
      if ($this->output) {
        $this->output->add_handler($name, $callback);
      }
      else {
        $this->templobjects[$name] = $callback;
        $this->objectsmap[$name] = $owner;
      }
    }
    else {
      raise_error(array('code' => 525, 'type' => 'php', 'message' => "Cannot register template handler $name; already taken by another plugin"), true, false);
    }
  }
  
  /**
   *
   */
  public function include_script($fn)
  {
    $this->scripts[] = $this->url . $fn;
  }


}

