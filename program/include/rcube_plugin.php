<?php

/*
 +-----------------------------------------------------------------------+
 | program/include/rcube_plugin.php                                      |
 |                                                                       |
 | This file is part of the RoundCube Webmail client                     |
 | Copyright (C) 2008-2009, RoundCube Dev. - Switzerland                 |
 | Licensed under the GNU GPL                                            |
 |                                                                       |
 | PURPOSE:                                                              |
 |  Abstract plugins interface/class                                     |
 |  All plugins need to extend this class                                |
 +-----------------------------------------------------------------------+
 | Author: Thomas Bruederli <roundcube@gmail.com>                        |
 +-----------------------------------------------------------------------+

 $Id: $

*/

/**
 * Plugin interface class
 *
 * @package Core
 */
abstract class rcube_plugin
{
  public $ID;
  public $api;
  public $task;
  protected $home;

  /**
   * Default constructor.
   */
  public function __construct($api)
  {
    $this->ID = get_class($this);
    $this->api = $api;
    $this->home = $api->dir . DIRECTORY_SEPARATOR . $this->ID;

    $this->init();
  }
  
  /**
   * Initialization method, needs to be implemented by the plugin itself
   */
  abstract function init();

  /**
   * Register a callback function for a specific (server-side) hook
   *
   * @param string Hook name
   * @param mixed Callback function as string or array with object reference and method name
   */
  public function add_hook($hook, $callback)
  {
    $this->api->register_hook($hook, $callback);
  }

  /**
    * Register a handler for a specific client-request action
    *
    * The callback will be executed upon a request like /?_task=mail&_action=plugin.myaction
    *
    * @param string Action name (should be unique)
    * @param mixed Callback function as string or array with object reference and method name
   */
  public function register_action($action, $callback)
  {
    $this->api->register_action($action, $this->ID, $callback);
  }

  /**
   * Register a handler function for a template object
   *
   * When parsing a template for display, tags like <roundcube:object name="plugin.myobject" />
   * will be replaced by the return value if the registered callback function.
   *
   * @param string Object name (should be unique and start with 'plugin.')
   * @param mixed Callback function as string or array with object reference and method name
   */
  public function register_handler($name, $callback)
  {
    $this->api->register_handler($name, $this->ID, $callback);
  }

  /**
   * Make this javascipt file available on the client
   *
   * @param string File path; absolute or relative to the plugin directory
   */
  public function include_script($fn)
  {
    // relative file name
    if ($fn[0] != '/' && !eregi('^https?://', $fn)) {
      $fn = $this->ID.'/'.$fn;
    }
    
    $this->api->include_script($fn);
  }


}

