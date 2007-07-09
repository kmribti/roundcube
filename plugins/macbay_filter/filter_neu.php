<?php
if (isset($_mb_filter_form_id) === false) {
    $_mb_filter_form_id = "filter_" . time();
}
$cond_count = 'new';
?>
<div id="<?php echo $_mb_filter_form_id; ?>" class="formrow">
	<h2>Neuen Regelsatz anlegen</h2>
</div>
<div class="formrow">
    <label for="filter_priority_new">Priorit&auml;t</label>
    <select id="filter_priority_new" class="normal" name="filter_priority_new">
<?php for($x=9; $x>=1; $x--): ?>
        <option value="<?php echo $x; ?>"><?php echo $x; ?></option>
<?php endfor; ?>
    </select>
</div>
<div class="formrow">
    <label for="filter_name_new">Name</label>
    <input type="text" id="filter_name_new" name="filter_name_new" value="Neuer Filter <?php echo time(); ?>" />
</div>
<div id="<?php echo $cond_count; ?>" class="formrow">
	<label for="cond_<?php echo $cond_count; ?>">Bedingungen</label>
	<select onchange="repopulateMode('<?php echo $cond_count; ?>');" name="cond_<?php echo $cond_count; ?>" class="medium" tabindex="">
<?php
    foreach($mb_data['types'] AS $r=>$hr):
?>
        <option value="<?php echo $r; ?>"<?php echo $_selected; ?>><?php echo $hr; ?></option>
<?php endforeach; ?>
	</select>
<?php
if (isset($mb_data['modes'][$_left]) === FALSE):
?>
    <select name="mode_<?php echo $cond_count; ?>" class="medium" tabindex="">
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
    <select name="mode_<?php echo $cond_count; ?>" class="medium" tabindex="">
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
	<input type="text" value="<?php echo $_right; ?>" class="medium lightTxt" name="value_<?php echo $cond_count; ?>" tabindex="" />
	<span onclick="addRow('<?php echo $cond_count; ?>', 'cond');" title="entfernen">
	   <img class="mod" border="0" src="skins/macbay/img/add.gif" alt="Icon: hinzufuegen" />
	</span>
</div>
<div id="cond_<?php echo $cond_count; ?>_add"></div>
<div class="formrow">
	<label for="action_<?php echo $cond_count; ?>">Aktionen</label>
	<select name="action_<?php echo $cond_count; ?>" class="medium" tabindex="">
	<?php foreach ($mb_data['actions'] AS $r=>$hr): ?>
		<option value="<?php echo $r; ?>"><?php echo $hr; ?></option>
	<?php endforeach; ?>
	</select>
	<textarea class="extralong lightTxt" name="action_add_<?php echo $cond_count; ?>" tabindex=""></textarea>
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