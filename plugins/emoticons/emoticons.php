<?php

/**
 * Sample plugin to replace remoticons in plain text message body with real icons
 */
class emoticons extends rcube_plugin
{
private $map;

function init()
{
  $this->add_hook('message-body-after', array($this, 'replace'));
  
  $this->map = array(
    ':)'  => html::img(array('src' => 'http://www.smilies-emoticons.de/smilies1/s37.gif', 'alt' => ':)')),
    ':-)' => html::img(array('src' => 'http://www.smilies-emoticons.de/smilies1/s37.gif', 'alt' => ':-)')),
    ':('  => html::img(array('src' => 'http://www.smilies-emoticons.de/smilies1/s36.gif', 'alt' => ':(')),
    ':-(' => html::img(array('src' => 'http://www.smilies-emoticons.de/smilies1/s36.gif', 'alt' => ':-(')),
  );
}

function replace($args)
{
  if ($args['type'] == 'plain')
    return array('body' => strtr($args['body'], $this->map));
  
  return null;
}

}

