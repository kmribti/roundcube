<?php

define('INSTALL_PATH', realpath(dirname(__FILE__) . '/..') . '/' );
require INSTALL_PATH.'program/include/iniset.php';

/** callback function for schema dump **/
function print_schema($dump)
{
	foreach ((array)$dump as $part)
		echo $dump . "\n";
}

$config = new rcube_config();

// don't allow public access if not in devel_mode
if (!$config->get('devel_mode') && $_SERVER['REMOTE_ADDR']) {
	header("HTTP/1.0 401 Access denied");
	die("Access denied!");
}

$options = array(
	'use_transactions' => false,
	'log_line_break' => "\n",
	'idxname_format' => '%s',
	'debug' => false,
	'quote_identifier' => true,
	'force_defaults' => false,
	'portability' => false
);

$schema =& MDB2_Schema::factory($config->get('db_dsnw'), $options);
$schema->db->supported['transactions'] = false;

// send as text/xml when opened in browser
if ($_SERVER['REMOTE_ADDR'])
	header('Content-Type: text/xml');


if (PEAR::isError($schema)) {
	$error = $schema->getMessage() . ' ' . $schema->getUserInfo();
}
else {
	$dump_config = array(
		// 'output_mode' => 'file',
		'output' => 'print_schema',
	);

	$definition = $schema->getDefinitionFromDatabase();
	if (PEAR::isError($definition)) {
		$error = $definition->getMessage() . ' ' . $definition->getUserInfo();
	}
	else {
		$operation = $schema->dumpDatabase($definition, $dump_config, MDB2_SCHEMA_DUMP_STRUCTURE);
		if (PEAR::isError($operation)) {
			$error = $operation->getMessage() . ' ' . $operation->getUserInfo();
		}
	}
}

$schema->disconnect();

//if ($error)
//	fputs(STDERR, $error);

?>
