<?php
/**
 * to counter a direct call
 */
if (defined('MACBAY_FILTER_MADNESS') === FALSE) {
    die('no go.');
}
$_plugin_action = (string) @$_POST['_plugin_action'];
switch ($_plugin_action) {
    case 'add':
        //echo '<pre>'; var_dump($_POST); echo '</pre>';
        $status = $macbay_filter->addRule($_POST);
        if ($status !== true) {
            array_push($error_msg, 'Der Regelsatz konnte nicht gespeichert werden.');
        }
        break;

    case 'save':
        $status = $macbay_filter->saveRules($_POST);
        if ($status !== true) {
            rc_main::tfk_debug('Response: ' . $status);
            array_push($error_msg, 'Ihre &Auml;nderungen konnten leider nicht gespeichert werden.');
        }
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
}
//var_dump($_POST); exit;
?>
