<?php
if (isset($_mb_filter_name) === false) {
    $_mb_filter_name = 'Neuer Filter';
}
if (isset($_mb_filter_form_id) === false) {
    $_mb_filter_form_id = "filter_" . time();
}
//var_dump($_mb_filter_name, $_mb_filter_prio);
?>
<!-- START Filter -->
<div id="<?php echo $_mb_filter_form_id; ?>" class="formrow">
	<label for="filter1"><?php echo $_mb_filter_name; ?></label>
	<a href="#" title="entfernen"><img class="mod" border="0" src="skins/macbay/img/delete.gif" alt="Icon: entfernen" /></a>
</div>
<!-- Bedingungen -->
<?php
if (isset($mb_rule[2])):
    $cond_count = 0;
    foreach ($mb_rule[2] AS $mb_rule_cond):
        $_left  = $mb_rule_cond[0];
        $_to    = @$mb_rule_cond[1];
        $_right = @$mb_rule_cond[2];

        //echo "<!-- " . var_export($mb_rule_cond, true) . " -->";
        //echo "<!-- $_left $_to $_right -->";
?>
<div class="formrow formrow-indent">
    <?php if($cond_count == 0): ?>
	   <label onchange="repopulateMode(<?php echo $cond_count; ?>);" for="cond_<?php echo $cond_count; ?>">Bedingungen</label>
	<?php else: ?>
	   <label>&nbsp;</label>
	<?php endif; ?>
	<select id="cond_<?php echo $cond_count; ?>" class="medium" tabindex="">
<?php
    foreach($mb_data['types'] AS $r=>$hr):
        $_selected = (($r == $_left)?' selected="selected"':'');
?>
        <option value="<?php echo $r; ?>"<?php echo $_selected; ?>>
		<?php echo $hr; ?></option>
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
	</select>
	<input type="text" value="<?php echo $_right; ?>" class="medium lightTxt" id="value_<?php echo $cond_count; ?>" tabindex="" />
	<a href="#" title="entfernen"><img class="mod" border="0" src="skins/macbay/img/delete.gif" alt="Icon: entfernen" /></a>
</div>
<?php
        $cond_count++;
    endforeach;
endif;
?>


<!-- Aktionen -->
<div class="formrow formrow-indent">
	<label for="aktion1">Aktionen</label>
	<select id="aktion1" class="medium" tabindex="">
		<option value="">Betreff der Nachricht</option>
		<option value=""></option>
		<option value=""></option>
	</select>
	<input type="text" class="medium lightTxt" name="" id="" tabindex="" />
	<select class="medium" tabindex="">
		<option value="">beinhaltet</option>
		<option value=""></option>
		<option value=""></option>
	</select>
	<a href="#" title="hinzuf&uuml;gen"><img class="mod" border="0" src="skins/macbay/img/add.gif" alt="Icon: hinzuf&uuml;gen" /></a>
</div>
<!-- END Filter -->