<?php
if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    echo 'Method not allowed.';
    exit;
}
require_once dirname(__FILE__) . '/../program/lib/html2text.inc';

$htmlText  = $HTTP_RAW_POST_DATA;
$converter = new html2text($htmlText);

header('Content-Type: text/plain; charset=UTF-8');
$plaintext = $converter->get_text();

if (function_exists('html_entity_decode')) {
    echo html_entity_decode($plaintext, ENT_COMPAT, 'UTF-8');
}
else {
    echo $plaintext;
}
?>