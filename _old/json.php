<?php
// RC STUFF
require_once dirname(__FILE__) . '/program/etc/main.cfg.php';

try
{
    $rcCore = rcCore::factory();
}
catch(rcException $e)
{
    echo $e->getMessage();
    exit;
}

$component = rcCore::get_input_value('component', RCUBE_INPUT_GET);
if (is_null($component))
{
    echo $rcCore->sendError('unknown', 'Unknown component.');
    exit;
}
$action = rcCore::get_input_value('action', RCUBE_INPUT_GET);
if (is_null($action))
{
    echo $rcCore->sendError($component, 'Unknown action.');
    exit;
}
/*
$json = rcCore::get_input_value('json', RCUBE_INPUT_GPC);
if (!is_null($json))
{
    echo $rcCore->sendError($component, 'No JSON calls supplied.');
}
*/
$call = sprintf('%s.%s', $component, $action);
$err  = null;

switch($call)
{
    default:
        echo $rcCore->sendError($component, sprintf('Unknown component/action: %s', $call));
        break;

    case 'formauth.login':
        $data = $rcCore->json->decode($json);

        $_user = $data[0]->_user;
        $_pass = $data[0]->_pass;
        $_host = $data[0]->_host;

        $host = ((isset($_host) && !empty($_host))?$_host:null);

        try
        {
            $rcCore->rcmail_startup();
            $status = $rcCore->rcmail_login($_user, $_pass, $host);
        }
        catch(rcException $e)
        {
            echo $rcCore->sendError($component, $e->getMessage());
            exit;
        }
        if ($status === false)
        {
            echo $rcCore->sendError($component, 'Unknown response from login.');
        }
        echo $status;
        break;

    case 'webmail.deleteMessage':
        $o = new stdClass;
        $o->requests = new stdClass;
        
        $a = array();
        
        $ao = new stdClass;
        $ao->component  = 'webmail';
        $ao->action     = 'deleteMessage';
        $ao->messageId  = '123';
        $ao->mailbox    = 'INBOX';

        $a[] = $ao;

        $ao = new stdClass;
        $ao->component  = 'webmail';
        $ao->action     = 'deleteMessage';
        $ao->messageId  = '456';
        $ao->mailbox    = 'INBOX';

        $a[] = $ao;

        $o->requests = $a;

        var_dump($o->requests); exit;


        $data = $rcCore->decode($json);
        try 
        {
            $status = $rcCore->imap_deleteMessages($uids, $mailbox);
        }
        catch(rcException $e)
        {
            echo $rcCore->sendError($component, $e->getMessage());
        }
        echo $status;
        break;

    case 'webmail.getUpdates':
        $data = $rcCore->decode($json);
        try
        {
            $status = $rcCore->imap_getMessages($mailbox);
        }
        catch(rcException $e)
        {
            echo $rcCore->sendError($component, $e->getMessage());
        }
        break;
}
?>
