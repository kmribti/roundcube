<?php
if (defined('MACBAY_POP3_MADNESS') === FALSE) {
    die('no go.');
}

$_plugin_action = (string) @$_POST['_plugin_action'];
switch($_plugin_action) {
    case 'add':
        $status = $macbay_pop3->saveRpop($_POST['rpop_new']);
        if ($status !== true) {
            array_push($error_msg, 'Der Sammeldienst konnte nicht angelegt werden.');
        }
        break;

    case 'delete':
        $status = $macbay_pop3->deleteRpop($_POST['rpop_id']);
        if ($status === false) {
            array_push($error_msg, 'Der Sammeldienst konnte nicht gel&ouml;scht werden.');
        }
        break;

    default:
        rcube_error::raise(
                array(
                    'code'    => 666,
                    'message' => 'Unknown plugin_action',
                    'file'    => __FILE__,
                    'line'    => __LINE__
                ),
                TRUE
        );
        break;
}
