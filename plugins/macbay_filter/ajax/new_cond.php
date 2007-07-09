<?php $add_id = 'cond_' . time(); ?>
<div id="<?php echo $add_id; ?>" class="formrow formrow-indent">
	<label for="cond_<?php echo $add_id; ?>">Bedingungen</label>
	<select name="rule_cond[<?php echo $add_id; ?>]" onchange="repopulateMode('<?php echo $add_id; ?>');" id="cond_<?php echo $add_id; ?>" class="medium" tabindex="">
        <option value="Subject">Betreff</option>
        <option value="Sender">Versender</option>
        <option value="From">Absenders Emailadresse</option>
        <option value="To">Empf&auml;nger</option>
        <option value="Cc">Empf&auml;nger (Kopie)</option>
        <option value="Reply-To">Antwort an</option>
        <option value="Any To or Cc">Mindestens ein Empf&auml;nger (auch Kopie)</option>
        <option value="Each To or CC">Jeder Empf&auml;nger (auch Kopie)</option>
        <option value="Return-Path">Envelope Sender</option>
        <option value="'From' Name">Absenders Name</option>
        <option value="Message-ID">Message-ID</option>
        <option value="Message Size">Nachrichtengr&ouml;&szlig;e</option>
        <option value="Human Generated">Mailinglisten, Newsletter, &hellip;</option>
        <option value="Header Field">Kopfzeile</option>
        <option value="Any Recipient">Mindestens ein Empf&auml;nger</option>
        <option value="Each Recipient">Jeder Empf&auml;nger</option>
        <option value="Security">Sicherheit</option>
	</select>
    <select name="rule_mode[<?php echo $add_id; ?>]" class="medium" tabindex="">
        <option value="in">enh&auml;lt</option>
        <option value="not in">enth&auml;lt nicht</option>
        <option value="is">entspricht</option>
        <option value="is not">entspricht nicht</option>
    </select>
	<input type="text" value="" class="medium lightTxt" name="rule_value[<?php echo $add_id; ?>]" tabindex="" />
	<span onclick="removeRow('<?php echo $add_id; ?>');" title="entfernen">
	   <img class="mod" border="0" src="skins/macbay/img/delete.gif" alt="Icon: entfernen" />
	</span>
</div>