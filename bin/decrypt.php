#!/usr/bin/env php
<?php
/*

 +-----------------------------------------------------------------------+
 | bin/decrypt.php                                                       |
 |                                                                       |
 | This file is part of the RoundCube Webmail client                     |
 | Copyright (C) 2005-2009, RoundCube Dev. - Switzerland                 |
 | Licensed under the GNU GPL                                            |
 |                                                                       |
 | PURPOSE:                                                              |
 |   Decrypt the encrypted parts of the HTTP Received: headers           |
 |                                                                       |
 +-----------------------------------------------------------------------+
 | Author: Tomas Tevesz <ice@extreme.hu>                                 |
 +-----------------------------------------------------------------------+

 $Id$
*/

/*-
 * If http_received_header_encrypt is configured, the IP address and the
 * host name of the added Received: header is encrypted with 3DES, to
 * protect information that some could consider sensitve, yet their
 * availability is a must in some circumstances.
 *
 * Such an encrypted Received: header might look like:
 *
 * Received: from DzgkvJBO5+bw+oje5JACeNIa/uSI4mRw2cy5YoPBba73eyBmjtyHnQ==
 * 	[my0nUbjZXKtl7KVBZcsvWOxxtyVFxza4]
 *	with HTTP/1.1 (POST); Thu, 14 May 2009 19:17:28 +0200
 *
 * In this example, the two encrypted components are the sender host name
 * (DzgkvJBO5+bw+oje5JACeNIa/uSI4mRw2cy5YoPBba73eyBmjtyHnQ==) and the IP
 * address (my0nUbjZXKtl7KVBZcsvWOxxtyVFxza4).
 *
 * Using this tool, they can be decrypted into plain text:
 *
 * $ bin/decrypt_received.php 'my0nUbjZXKtl7KVBZcsvWOxxtyVFxza4' \
 * > 'DzgkvJBO5+bw+oje5JACeNIa/uSI4mRw2cy5YoPBba73eyBmjtyHnQ=='
 * 84.3.187.208
 * 5403BBD0.catv.pool.telekom.hu
 * $
 *
 * Thus it is known that this particular message was sent by 84.3.187.208,
 * having, at the time of sending, the name of 5403BBD0.catv.pool.telekom.hu.
 *
 * If (most likely binary) junk is shown, then
 *  - either the encryption password has, between the time the mail was sent
 *    and `now', changed, or
 *  - you are dealing with counterfeit header data.
 */

if (php_sapi_name() != 'cli') {
	die("Not on the 'shell' (php-cli).\n");
}

define('INSTALL_PATH', realpath(dirname(__FILE__).'/..') . '/');
require INSTALL_PATH . 'program/include/iniset.php';

$config = new rcube_config();
if (!$config->get('http_received_header_encrypt')) {
	die("http_received_header_encrypt is not configured\n");
}

if ($argc < 2) {
	die("Usage: " . basename($argv[0]) . " encrypted-hdr-part [encrypted-hdr-part ...]\n");
}

$RCMAIL = rcmail::get_instance();

for ($i = 1; $i < $argc; $i++) {
	printf("%s\n", $RCMAIL->decrypt($argv[$i]));
};
