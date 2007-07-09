<?php $add_id = 'row_' . time(); ?>
<div id="<?php echo $add_id; ?>" class="formrow formrow-indent">
	<label for="action_<?php echo $add_id; ?>">Aktionen</label>
	<select id="action_<?php echo $add_id; ?>" name="rule_action[<?php echo $add_id; ?>]" class="medium" tabindex="">
			<option value="Stop Processing">Bearbeitung abbrechen</option>
			<option value="Discard">Verwerfen</option>
			<option value="Reject with">Abweisen mit Text</option>
			<option value="Add Header">Kopfzeile hinzuf&uuml;gen</option>
			<option value="Tag Subject">Betreff taggen</option>
			<option value="Store in">In Ordner verschieben</option>
			<option value="Store Encrypted in">Verschl&uuml;sseln und in Ordner verschieben</option>
			<option value="Redirect to">Umleiten an</option>
			<option value="Forward to">Weiterleiten an</option>
			<option value="Mirror to">Spiegeln an</option>
			<option value="Reply with">Antworten mit</option>
			<option value="Reply to All with">Allen antworten mit</option>
			<option value="React with">Nachricht ausl&ouml;sen</option>
		</select>
	<textarea class="extralong lightTxt" id="action_add_<?php echo $add_id; ?>" name="rule_action_value[<?php echo $add_id; ?>]" tabindex=""></textarea>
	<span onclick="removeRow('<?php echo $add_id; ?>');" title="entfernen">
	   <img class="mod" border="0" src="skins/macbay/img/delete.gif" alt="Icon: hinzuf&uuml;gen" />
	</span>
</div>