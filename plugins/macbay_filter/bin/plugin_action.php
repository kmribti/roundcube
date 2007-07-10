<?php
if (defined('MACBAY_FILTER_MADNESS') === FALSE) {
    die('no go.');
}
$_plugin_action = (string) @$_POST['_plugin_action'];
switch($_plugin_action) {
    case 'add':
        echo '<pre>'; var_dump($_POST); echo '</pre>';
        $macbay_filter->addRule($_POST);
        break;

    case 'save':
        $macbay_filter->saveRules($_POST);
        //echo '<pre style="font-size:8pt;">'; var_dump($_POST); echo '</pre>';
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