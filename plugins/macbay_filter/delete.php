<?php
if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    die('Method not allowed.');
}
if (isset($_POST['filterName']) === false) {
    die('No filterName given.');
}
require_once dirname(__FILE__) . '/bootstrap.php';

try {
    $params = array();
    array_push($params, $_SESSION['username']);
    array_push($params, rc_main::decrypt_passwd($_SESSION['password']));
    array_push($params, $_POST['filterName']);
    $status = $mb_client->call('cli.deleteRule', $params);
    if ($status === true) {
        die('ok');
    }
    throw new Zend_Exception("Unknown response: {$status}");
}
catch (Zend_Exception $e) {
    rc_main::tfk_debug(var_export($e, true));
    die($e->getMessage());
}
?>