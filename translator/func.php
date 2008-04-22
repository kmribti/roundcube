<?php

// define locations here
define('LANGDIR', './localization/');  // langdir location
define('LABELS', 'labels.inc');   // name of the labels file
define('MESSAGES', 'messages.inc'); // name of the messages file 
define('ORIGINAL', 'en_US'); // always up-to-date language

// ---- EOF conf ---- //

function get_input_value($fname)
{
	$value = !empty($_REQUEST[$fname]) ? $_REQUEST[$fname] : "";

	// strip slashes if magic_quotes enabled
	if ((bool)get_magic_quotes_gpc())
		$value = stripslashes($value);

	// remove HTML tags if not allowed
	$value = strip_tags($value);

	return $value;
 }


function update_from_svn($lang, $file)
{
	$host = "svn.roundcube.net";
	$base = "/trunk/roundcubemail/program/localization/";
	
	$lang_dir = $lang != "" ? $lang."/" : "";
	$lang_prefix = $lang != "" ? $lang."_" : "";

	// check if original file is up to date
	$stat = @stat(LANGDIR."$lang_prefix$file");
	if (!$stat || ($stat['mtime'] < time() - 3600))
	{
		if ($fp = fsockopen("ssl://$host", 443, $err, $err_str))
			fwrite($fp, "GET $base$lang_dir$file HTTP/1.1\r\nHost: $host\r\nConnection: Close\r\n\r\n");

		$headers = true;
		if ($fp && !$err && ($fl = @fopen(LANGDIR."$lang_prefix$file", 'w')))
		{
			// echo '<div class="console">Update from SVN: '.$lang_dir.$file.'</div>';
			while (!feof($fp))
			{
				$line = fgets($fp, 4096);
				if (trim($line) == "")
					$headers = false;
				if (!$headers)
					fwrite($fl, $line);
			}

			fclose($fp);
			fclose($fl);
		}
	}

	if (is_file(LANGDIR."$lang_prefix$file"))
		return LANGDIR."$lang_prefix$file";
	else
		return false;
}


function lang_selection($lang)
{
	include(update_from_svn('', 'index.inc'));

	$out = "<select name=\"lang\">\n<option value=\"_NEW_\">New Language</option>\n";
	foreach ((array)$rcube_languages as $l_key => $l_value)
	{
		if ($l_key == ORIGINAL)
			continue;

		$out .= '<option value="'.$l_key.'"';
		if ($l_key == $lang) $out .= ' selected';
		$out .= '>' . htmlspecialchars($l_value) . "</option>\n";
	}
	$out .= "</select>";
	
	return $out;
}


// -------- EOF func --------//

$header = array();
$orig_values = array();
$labels = $messages = null;

$file = get_input_value('file');
$lang = get_input_value('lang');
$translated = !empty($_REQUEST['trans']);

if ($file && $lang)
	include(update_from_svn(ORIGINAL, $file));

if ($file == 'labels.inc' && $labels)
	$orig_values = $labels;
else if ($file == 'messages.inc' && $messages)
	$orig_values = $messages;

unset($labels, $messages);

?>