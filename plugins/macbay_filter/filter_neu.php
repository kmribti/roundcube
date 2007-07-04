<?php
if (isset($_mb_filter_form_id) === false) {
    $_mb_filter_form_id = "filter_" . time();
}
$cond_count = 'new';
?>
<div id="<?php echo $_mb_filter_form_id; ?>" class="formrow">
	<h2>Neuer Filter</h2>
</div>
<div id="<?php echo $cond_count; ?>" class="formrow formrow-indent">
	<label for="cond_<?php echo $cond_count; ?>">Bedingungen</label>
	<select onchange="repopulateMode('<?php echo $cond_count; ?>');" id="cond_<?php echo $cond_count; ?>" class="medium" tabindex="">
<?php
    foreach($mb_data['types'] AS $r=>$hr):
?>
        <option value="<?php echo $r; ?>"<?php echo $_selected; ?>><?php echo $hr; ?></option>
<?php endforeach; ?>
	</select>
<?php
if (isset($mb_data['modes'][$_left]) === FALSE):
?>
    <select id="mode_<?php echo $cond_count; ?>" class="medium" tabindex="">
<?php
    foreach($mb_data['modes']['default'] AS $r=>$hr):
?>
        <option value="<?php echo $r; ?>"><?php echo $hr; ?></option>
<?php
    endforeach;
?>
    </select>
<?php
else:
    if (empty($mb_data['modes'][$_left]) === false):
?>
    <select id="mode_<?php echo $cond_count; ?>" class="medium" tabindex="">
<?php
    foreach($mb_data['modes'][$_left] AS $r=>$hr):
?>
        <option value="<?php echo $r; ?>"><?php echo $hr; ?></option>
<?php
    endforeach;
?>
    </select>
<?php
    else:
        echo '<select class="medium">';
        echo '<option value="">Ja</option>';
        echo '</select>';
    endif;
endif;
?>
	<input type="text" value="<?php echo $_right; ?>" class="medium lightTxt" id="value_<?php echo $cond_count; ?>" tabindex="" />
	<span onclick="addRow('<?php echo $cond_count; ?>', 'cond');" title="entfernen">
	   <img class="mod" border="0" src="skins/macbay/img/add.gif" alt="Icon: hinzufuegen" />
	</span>
</div>
<div id="cond_<?php echo $cond_count; ?>_add"></div>

<div class="formrow formrow-indent">
	<label for="action_<?php echo $cond_count; ?>">Aktionen</label>
	<select id="action_<?php echo $cond_count; ?>" class="medium" tabindex="">
	<?php foreach ($mb_data['actions'] AS $r=>$hr): ?>
		<option value="<?php echo $r; ?>"><?php echo $hr; ?></option>
	<?php endforeach; ?>
	</select>
	<textarea class="extralong lightTxt" id="action_add_<?php echo $cond_count; ?>" tabindex=""></textarea>
	<span onclick="addRow('<?php echo $cond_count; ?>', 'action');" title="hinzuf&uuml;gen">
	   <img class="mod" border="0" src="skins/macbay/img/add.gif" alt="Icon: hinzuf&uuml;gen" />
	</span>
</div>
<div id="action_<?php echo $cond_count; ?>_add"></div>
<br clear="left" />
<div id="saveNewBtn">
	<div class="btn btn-active-big">
		<p><span id="saveNewButton">Neuen Regelsatz anlegen</span></p>
	</div>
</div>