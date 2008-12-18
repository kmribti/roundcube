<?php

ob_start();

require_once 'func.php';

if (isset($_POST["download"]) && $file && $lang)
{
	ob_end_clean();
	
	header("Content-Type: text/plain; charset=UTF-8");
	header("Cache-Control: private");
	header("Content-Disposition: attachment; filename=\"{$file}\"");
	
	echo build_localization($lang, $file);
	exit;
}
else
	header("Content-Type: text/html; charset=UTF-8");

ob_end_clean();

?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="et">
<head>
<title>RoundCube Translator</title>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<link rel="stylesheet" href="styles.css" type="text/css" media="screen" />
</head>
<body>

<div id="banner">
  <div class="banner-logo"><a href="http://roundcube.net"><img src="images/banner_logo.gif" width="200" height="56" border="0" alt="RoundCube Webmal Project" /></a></div>
  <div class="banner-right"><img src="images/banner_right.gif" width="10" height="56" alt="" /></div>
  <h2 id="pageheader">RoundCube (Live) Translator</h2>
</div>

<div id="bodycontent">

<form method="post" action="<?php echo $_SERVER['PHP_SELF']; ?>">
<fieldset>
<legend>What to translate</legend>

<?php echo lang_selection($lang); ?>

<select name="file">
	<option value="labels.inc"<?php echo ($file=='labels.inc'?' selected':''); ?>>labels.inc</option>
	<option value="messages.inc"<?php echo ($file=='messages.inc'?' selected':''); ?>>messages.inc</option>
</select>

<div>
<input type="checkbox" name="trans" id="trans" value="1"<?php echo ($translated?' checked':''); ?> />
<label for="trans">Show translated texts</label>
</div>

<p><input type="submit" class="button" value="Select" /></p>
</fieldset>
</form>

<div id="translations">
<form method="post" action="<?php echo $_SERVER['PHP_SELF']; ?>">
<input type="hidden" name="save" value="1" />
<?php

if (!empty($lang) && !empty($file))
{
	echo '<input type="hidden" name="lang" value="'.$lang.'" /><input type="hidden" name="file" value="'.$file.'" />';
	echo '<input type="hidden" name="trans" value="'.($translated?'1':'').'" />';
	echo '<table border="0" cellspacing="0" class="translist" summary="">';
	echo '<thead><tr><td>Label</td><td>Original</td><td>Translation</td></tr></thead><tbody>';
	
	$count = 0;
	foreach($orig_values as $t_key => $t_value)
	{
		// skip translated lines
		if(!$translated && !empty($edit_values[$t_key]))
			continue;

		if ($post_value = get_input_value('t_'.$t_key))
			$edit_values[$t_key] = $post_value;

		echo '<tr class="'.(empty($edit_values[$t_key]) ? 'untranslated' : '')."\">\n";
		echo '<td class="key">'.htmlspecialchars($t_key).'</td>';
		echo '<td class="original">'.htmlspecialchars($t_value).'</td>';
		echo '<td><input name="t_'.$t_key.'" value="'.(!empty($edit_values[$t_key]) ? htmlspecialchars($edit_values[$t_key]) : "").'" size="60" />';
		echo "</td>\n</tr>\n";
		
		$count++;
	}
	
	if (!$count)
		echo '<tr><td colspan="3"><em>No new texts to translate</em></td></tr>';
	
	echo "</tbody></table>\n";
	echo '<p><br /><input type="submit" class="button" name="translate" value="Create translation" />';
	echo '<span style="padding-left:1.5em"><input type="checkbox" name="download" value="1" checked="checked" />&nbsp;Download directly</a></p>';
	echo '<p class="hint">Save the localization file and post it to the <a href="http://lists.roundcube.net/dev">dev-mailing list</a></p>';

}

?>
</form>
</div>

<?php

if (isset($_POST["save"]) && $file && $lang)
{
	echo '<div id="resultsbox">'."<h3>Localization file</h3>\n";
	echo '<form id="select_all" action="./">
	<textarea id="results" name="text_area" rows="'.min(30, count($orig_values)).'" cols="130">';
	echo htmlspecialchars(build_localization($lang, $file), ENT_COMPAT, 'UTF-8');
	echo "</textarea>\n";
	echo '<p><input id="hilight" class="button" type="button" value="Select all" onclick="javascript:this.form.text_area.focus();this.form.text_area.select();" /></p>';
	echo "\n</form></div>";
}
else

	echo '<div align="center">'.localization_stats().'</div>';

?>
</div>

</body>
</html>
