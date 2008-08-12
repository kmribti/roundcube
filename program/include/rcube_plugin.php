<?php

/*
 +-----------------------------------------------------------------------+
 | program/include/rcube_plugin.php                                      |
 |                                                                       |
 | This file is part of the RoundCube Webmail client                     |
 | Copyright (C) 2008, RoundCube Dev. - Switzerland                      |
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
class rcube_plugin
{
  public $api;
  public $task;

  public function __construct($api)
  {
    $this->api = $api;
    $this->init();
  }
  
  protected function init()
  {
    
  }

  public function add_hook($hook, $callback)
  {
    $this->api->register_hook($hook, $callback);
  }


}

