<?php

/**
 * Sample plugin that adds a new button to the mailbox toolbar
 * to mark the selected messages as Junk and move them to the Junk folder
 */
class markasjunk extends rcube_plugin
{

  function init()
  {
    $this->task = 'mail';
    
    $this->register_action('plugin.markasjunk', array($this, 'request_action'));
    $GLOBALS['IMAP_FLAGS']['JUNK'] = 'Junk';
    
    $rcmail = rcmail::get_instance();
    if ($rcmail->action == '' || $rcmail->action == 'show')
      $this->include_script('markasjunk.js');
  }

  function request_action()
  {
    $rcmail = rcmail::get_instance();
    
    $count = sizeof(explode(',', ($uids = get_input_value('_uid', RCUBE_INPUT_POST))));
    $mbox = get_input_value('_mbox', RCUBE_INPUT_POST);
    
    $rcmail->imap->set_flag($uids, 'JUNK');
    
    if (($junk_mbox = $rcmail->config->get('junk_mbox')) && $mbox != $junk_mbox) {
      $rcmail->output->command('move_messages', $junk_mbox);
    }
    
    $rcmail->output->show_message('reportedasspam', 'confirmation');
    $rcmail->output->send();
  }

}