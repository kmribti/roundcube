#!/usr/bin/php -qC 
<?php

$CWD = $INSTALL_PATH = preg_replace('/bin\/$/', '', getcwd() . '/');
ini_set('include_path',  ini_get('include_path') . PATH_SEPARATOR . $CWD.'program/');
ini_set('memory_limit', -1);

require_once('include/rcube_shared.inc');
require_once('include/rcube_imap.inc');
require_once('include/main.inc');
require_once('include/bugs.inc');

/**
 * Parse commandline arguments into a hash array
 */
function get_opt($aliases=array())
{
	$args = array();
	for ($i=1; $i<count($_SERVER['argv']); $i++)
	{
		$arg = $_SERVER['argv'][$i];
		if (substr($arg, 0, 2) == '--')
		{
			$sp = strpos($arg, '=');
			$key = substr($arg, 2, $sp - 2);
			$value = substr($arg, $sp+1);
		}
		else if ($arg{0} == '-')
		{
			$key = substr($arg, 1);
			$value = $_SERVER['argv'][++$i];
		}
		else
			continue;

		$args[$key] = preg_replace(array('/^["\']/', '/["\']$/'), '', $value);
		
		if ($alias = $aliases[$key])
			$args[$alias] = $args[$key];
	}

	return $args;
}

function print_usage()
{
	print "Usage:  msgexport -h imap-host -u user-name -m mailbox name\n";
	print "--host   IMAP host\n";
	print "--user   IMAP user name\n";
	print "--mbox   Mailbox/folder name\n";
	print "--file   Mailbox/folder name\n";
}

function vputs($str)
{
	$out = $GLOBALS['args']['file'] ? STDOUT : STDERR;
	fwrite($out, $str);
}

function progress_update($pos, $max)
{
	$percent = round(100 * $pos / $max);
	vputs(sprintf("%3d%% [%-51s] %d/%d\033[K\r", $percent, @str_repeat('=', $percent / 2) . '>', $pos, $max));
}


// get arguments
$args = get_opt(array('h' => 'host', 'u' => 'user', 'p' => 'pass', 'm' => 'mbox', 'f' => 'file')) + array('host' => 'localhost', 'mbox' => 'INBOX');

if ($_SERVER['argv'][1] == 'help')
{
	print_usage();
	exit;
}
else if (!$args['host'])
{
	vputs("Missing required parameters.\n");
	print_usage();
	exit;
}

// prompt for username if not set
if (empty($args['user']))
{
	vputs("IMAP user: ");
	$args['user'] = trim(fgets(STDIN));
}

// prompt for password
vputs("Password: ");
$args['pass'] = trim(fgets(STDIN));


// parse $host URL
$a_host = parse_url($args['host']);
if ($a_host['host'])
{
	$host = $a_host['host'];
	$imap_ssl = (isset($a_host['scheme']) && in_array($a_host['scheme'], array('ssl','imaps','tls'))) ? TRUE : FALSE;
	$imap_port = isset($a_host['port']) ? $a_host['port'] : ($imap_ssl ? 993 : 143);
}
else
{
	$host = $args['host'];
	$imap_port = 143;
}

// instantiate IMAP class
$IMAP = new rcube_imap(null);

// try to connect to IMAP server
if ($IMAP->connect($host, $args['user'], $args['pass'], $imap_port, $imap_ssl))
{
	vputs("IMAP login successful.\n");
	
	$IMAP->set_mailbox($args['mbox']);
	
	vputs("Getting message list of {$args['mbox']}...");
	vputs($IMAP->messagecount()." messages\n");
	
	if ($args['file'])
	{
		if (!($out = fopen($args['file'], 'w')))
		{
			vputs("Cannot write to output file\n");
			exit;
		}
	}
	else
		$out = STDOUT;
	
	for ($count = $IMAP->messagecount(), $i=1; $i <= $count; $i++)
	{
		$headers = $IMAP->get_headers($i, null, false);
		$from = current($IMAP->decode_address_list($headers->from, 1, false));
		
		fwrite($out, sprintf("From %s %s UID %d\n", $from['mailto'], $headers->date, $headers->uid));
		fwrite($out, iil_C_FetchPartHeader($IMAP->conn, $IMAP->mailbox, $i, null));
		fwrite($out, iil_C_HandlePartBody($IMAP->conn, $IMAP->mailbox, $i, null, 1));
		fwrite($out, "\n\n\n");
		
		progress_update($i, $count);
	}
	vputs("\ncomplete.\n");
}
else
{
	vputs("IMAP login failed.\n");
}

?>