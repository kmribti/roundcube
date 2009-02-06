<?php

/**
 * Sample plugin to replace remoticons in plain text message body with real icons
 */
class emoticons extends rcube_plugin
{
  public $task = 'mail';
  private $map;

  function init()
  {
    $this->task = 'mail';
    $this->add_hook('message-body-after', array($this, 'replace'));
  
    $this->map = array(
      ':)'  => html::img(array('src' => './program/js/tiny_mce/plugins/emotions/img/smiley-smile.gif', 'alt' => ':)')),
      ':-)' => html::img(array('src' => './program/js/tiny_mce/plugins/emotions/img/smiley-smile.gif', 'alt' => ':-)')),
      ':('  => html::img(array('src' => './program/js/tiny_mce/plugins/emotions/img/smiley-cry.gif', 'alt' => ':(')),
      ':-(' => html::img(array('src' => './program/js/tiny_mce/plugins/emotions/img/smiley-cry.gif', 'alt' => ':-(')),
    );
  }

  function replace($args)
  {
    if ($args['type'] == 'plain')
      return array('body' => strtr($args['body'], $this->map));
  
    return null;
  }

}

