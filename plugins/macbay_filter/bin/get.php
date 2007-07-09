<?php
if (defined('MACBAY_FILTER_MADNESS') === FALSE) {
    die('no go.');
}
$mb_rules = $macbay_filter->getRules();
$mb_data  = $macbay_filter->getMeta();
?>