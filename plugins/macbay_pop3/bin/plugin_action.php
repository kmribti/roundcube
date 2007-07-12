<?php
if (defined('MACBAY_POP3_MADNESS') === FALSE) {
    die('no go.');
}

//echo '<pre>'; var_dump($_POST); echo '</pre>'; exit;

$_plugin_action = (string) @$_POST['_plugin_action'];
switch($_plugin_action) {
    case 'add':
        $status = $macbay_pop3->saveRpop($_POST['rpop_new']);
        break;

    case 'delete':
        $status = $macbay_pop3->deleteRpop($_POST['rpop_id']);
        //var_dump($status);
        break;

    default:
        rc_bugs::raise_error(
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