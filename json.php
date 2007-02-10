<?php
// RC STUFF
require_once dirname(__FILE__) . '/etc/main.cfg.php';

$sj = new Services_JSON;
if (Pear::isError($sj))
{
    die('Unexpected error.');
}

$component = ((isset($_REQUEST['component']) && !empty($_REQUEST['component']))?$_REQUEST['component']:null);
if (is_null($component))
{
    echo $sj->encode('error');
    exit;
}
$action = ((isset($_REQUEST['action']) && !empty($_REQUEST['action']))?$_REQUEST['action']:null);
if (is_null($action))
{
    echo $sh->encode('error');
    exit;
}

$call = sprintf('%s.%s', $component, $action);

$err    = null;

try
{
    $rcCore = rcCore::factory();
}
catch(rcException $e)
{
    var_dump($e);
    exit;
}

function sendError($reason, $token, $data = null)
{
    $errObj = new stdClass;
    $errObj->status = 'failure';
    $errObj->reason = $reason;
    $errObj->token  = $token;
    $errObj->data   = $data;

    return $GLOBALS['sj']->encode($errObj);
}

function sendSuccess($token, $data)
{
    $respObj = new stdClass;
    $respObj->status = 'ok';
    $respObj->token  = $token;
    $respObj->data   = $data;

    return $GLOBALS['sj']->encode($respObj);
}


switch($call)
{
    default:
        if (empty($call)):
            $err = array(0 => 'No action');
        else:
            $err = array(0 => $action);
        endif;
        echo $sj->encode($err);
        break;

    case 'formauth.login':
        $r = '[ { _user: "foo", _pass: "bar", _host: "" } ]';
        $data = $sj->decode($r);

        $_user = $data[0]->_user;
        $_pass = $data[0]->_pass;
        $_host = $data[0]->_host;

        $host = ((isset($_host) && !empty($_host))?$_host:$rcCore->CONFIG['default_host']);

        try
        {
            $rcCore->rcmail_startup();
            $status = $rcCore->rcmail_login($_user, $_pass, $host);
        }
        catch(rcException $e)
        {
            var_dump($e); exit;
        }
        var_dump($status);
        break;

    /*
    case 'brennan':
        $o = new stdClass;
        $o->developer = true;
        $o->location = 'U.S.';
        $o->props = array('gender' => 'male', 'skin' => 'white');
        echo $sj->encode($o);
        break;

    case 'till':
        $a = array('this', 'is', 'till');
        echo $sj->encode($a);
        break;

    case 'instructions':
        $a = array();

        $o = new stdClass;
        $o->instruction = 'addControlTrayButton';
        $o->name = 'Four';
        $o->action = 'alert("4");';
        $a[] = $o;

        $o = new stdClass;
        $o->instruction = 'addControlTrayButton';
        $o->name = 'Five';
        $o->action = 'alert("5");';
        $a[] = $o;

        $o = new stdClass;
        $o->instruction = 'addControlTrayButton';
        $o->name = 'Six';
        $o->action = 'alert("6");';
        $a[] = $o;

        echo $sj->encode($a);
        break;
    */
}
?>
