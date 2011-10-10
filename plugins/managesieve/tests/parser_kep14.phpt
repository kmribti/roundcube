--TEST--
Test of Kolab's KEP:14 implementation
--SKIPIF--
--FILE--
<?php
include '../lib/rcube_sieve_script.php';

$txt = '
# set "editor" "Roundcube";
# set "editor_version" "123";
';

$s = new rcube_sieve_script($txt, array());
echo $s->as_text();

?>
--EXPECT--
# set "editor" "Roundcube";
# set "editor_version" "123";
