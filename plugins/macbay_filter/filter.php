<?php
if (isset($_mb_filter_name) === false) {
    $_mb_filter_name = 'Neuer Filter';
}
if (isset($_mb_filter_form_id) === false) {
    $_mb_filter_form_id = "filter_" . time();
}
//var_dump($_mb_filter_name, $_mb_filter_prio);
//var_dump($mb_rule); exit;
?>
<!-- START Filter -->
<div id="<?php echo $_mb_filter_form_id; ?>" class="formrow">
	<label style="font-weight:bold;"><?php echo $_mb_filter_name; ?></label>
<?php if ($_mb_filter_name != 'Neuer Filter'): ?>
	<span onclick="deleteFilter('<?php echo $_mb_filter_name; ?>', '<?php echo $_mb_filter_form_id; ?>');" title="entfernen">
	   <img class="mod" border="0" src="skins/macbay/img/delete.gif" alt="Icon: entfernen" />
	</span>
<?php endif; ?>
</div>
<!-- Bedingungen -->
<?php
if (isset($mb_rule[2])):
    $cond_count = 0;
    foreach ($mb_rule[2] AS $mb_rule_cond):

        $_suffix = 'cond_' . $_mb_filter_form_id . '_' . $cond_count;

        $_left  = $mb_rule_cond[0];
        $_to    = @$mb_rule_cond[1];
        $_right = @$mb_rule_cond[2];

        //echo "<!-- " . var_export($mb_rule_cond, true) . " -->";
        //echo "<!-- $_left $_to $_right -->";
?>
<div id="<?php echo $_suffix; ?>" class="formrow formrow-indent">
    <?php if($cond_count == 0): ?>
	   <label for="cond_<?php echo $_suffix; ?>">Bedingungen</label>
	<?php else: ?>
	   <label>&nbsp;</label>
	<?php endif; ?>
	<select onchange="repopulateMode('<?php echo $_suffix; ?>');" id="cond_<?php echo $_suffix; ?>" class="medium" tabindex="">
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
    <select id="mode_<?php echo $_suffix; ?>" class="medium" tabindex="">
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
    <select id="mode_<?php echo $_suffix; ?>" class="medium" tabindex="">
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
	<input type="text" value="<?php echo $_right; ?>" class="medium lightTxt" id="value_<?php echo $_suffix; ?>" tabindex="" />
	<?php if ($cond_count == 0): ?>
	   <span onclick="addRow('<?php echo $_mb_filter_form_id; ?>', 'cond');" title="hinzufuegen">
	       <img class="mod" border="0" src="skins/macbay/img/add.gif" alt="Icon: hinzufuegn" />
	   </span>
	<?php else: ?>
	   <span onclick="removeRow('<?php echo $_suffix; ?>');" title="entfernen">
	       <img class="mod" border="0" src="skins/macbay/img/delete.gif" alt="Icon: entfernen" />
	   </span>
	<?php endif; ?>
</div>
<?php
        $cond_count++;
    endforeach;
endif;
?>
<div id="cond_<?php echo $_mb_filter_form_id; ?>_add"></div>
<?php
if (isset($mb_rule[3])):
    $action_count = 0;
    foreach($mb_rule[3] AS $mb_rule_action):

        $_suffix = 'action_' . $_mb_filter_form_id . '_' . $action_count;
        $_left   = $mb_rule_action[0];
        $_right  = @$mb_rule_action[1];
?>
<div id="<?php echo $_suffix; ?>" class="formrow formrow-indent">
    <?php if ($action_count == 0): ?>
	   <label for="action_<?php echo $_suffix; ?>">Aktionen</label>
	<?php else: ?>
	   <label for="action_<?php echo $_suffix; ?>">&nbsp;</label>
	<?php endif; ?>
	<select id="action_<?php echo $_suffix; ?>" class="medium" tabindex="">
<?php
    foreach ($mb_data['actions'] AS $r=>$hr):
        //echo "<!-- $r / " . var_export($mb_rule_action, true) . ' -->';
        $_selected = (($_left == $r)?' selected="selected"':'');
?>
        <option value="<?php echo $r; ?>"<?php echo $_selected; ?>><?php echo $hr; ?></option>
<?php endforeach; ?>
	</select>
	<textarea class="extralong lightTxt" id="action_add_<?php echo $_suffix; ?>" tabindex=""><?php echo htmlentities($_right); ?></textarea>
	<?php if ($action_count == 0): ?>
	   <span onclick="addRow('<?php echo $_mb_filter_form_id; ?>', 'action');" title="hinzuf&uuml;gen">
	       <img class="mod" border="0" src="skins/macbay/img/add.gif" alt="Icon: hinzuf&uuml;gen" />
	   </span>
	<?php else: ?>
	   <span onclick="removeRow('<?php echo $_suffix; ?>');" title="entfernen">
	       <img class="mod" border="0" src="skins/macbay/img/delete.gif" alt="Icon: entfernen" />
	   </span>
	<?php endif; ?>
</div>
<?php
        $action_count++;
    endforeach;
endif;
?>
<div id="action_<?php echo $_mb_filter_form_id; ?>_add"></div>