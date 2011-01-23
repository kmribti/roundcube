--TEST--
Main test of script parser
--SKIPIF--
--FILE--
<?php
include('../lib/rcube_sieve.php');

$txt = '
require ["fileinto","vacation"];
# rule:[spam]
if anyof (header :contains "X-DSPAM-Result" "Spam")
{
	fileinto "Spam";
	stop;
}
# rule:[test1]
if anyof (header :contains ["From","To"] "test@domain.tld")
{
	fileinto "roundcube-trac";
	stop;
}
# rule:[test2]
if anyof (not header :contains "Subject" "[test]", header :contains "Subject" "[test2]")
{
	fileinto "test";
	stop;
}
# rule:[test-vacation]
if anyof (header :contains "Subject" "vacation")
{
	vacation :days 1 text:
# test
test test /* test */
test
.
;
	stop;
}
# rule:[comments]
if anyof (true) /* comment
 * "comment" #comment */ {
    /* comment */ stop;
# comment
}
';

$s = new rcube_sieve_script($txt);
echo $s->as_text();

?>
--EXPECT--
require ["fileinto","vacation"];
# rule:[spam]
if anyof (header :contains "X-DSPAM-Result" "Spam")
{
	fileinto "Spam";
	stop;
}
# rule:[test1]
if anyof (header :contains ["From","To"] "test@domain.tld")
{
	fileinto "roundcube-trac";
	stop;
}
# rule:[test2]
if anyof (not header :contains "Subject" "[test]", header :contains "Subject" "[test2]")
{
	fileinto "test";
	stop;
}
# rule:[test-vacation]
if anyof (header :contains "Subject" "vacation")
{
	vacation :days 1 text:
# test
test test /* test */
test
.
;
	stop;
}
# rule:[comments]
if anyof (true)
{
	stop;
}
