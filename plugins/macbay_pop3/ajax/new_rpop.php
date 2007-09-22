<form action="<?php echo $BASE_URI; ?>?_task=plugin&_action=macbay_pop3/form.php" id="form_rpop_new" method="post">
<p>Sie k&ouml;nnen noch <?php echo (($rpop_left > 1)?$rpop_left . ' Sammeldienste':' einen Sammeldienst'); ?> anlegen.</p>
<p>Bitte beachten Sie, dass wir die Einstellungen nicht f&uuml;r Sie &uuml;berpr&uuml;fen.</p><br />
<div class="formrow">
    <label for="rpop_new_servername">Servername</label>
    <input id="rpop_new_servername" type="text" name="rpop_new[servername]" value="" />
</div>
<div class="formrow">
    <label for="rpop_new_username">Benutzername</label>
    <input id="rpop_new_username" type="text" name="rpop_new[username]" value="" />
</div>
<div class="formrow">
    <label for="rpop_new_password">Passwort</label>
    <input id="rpop_new_password" type="text" name="rpop_new[password]" value="" />
</div>
<div class="formrow">
    <label for="rpop_new_leave">E-Mails auf dem Server lassen?</label>
    <select id="rpop_new_leave" name="rpop_new[leave]">
        <option value="1">Ja</option>
        <option value="0">Nein</option>
    </select>
</div>
<div id="rpop_new_btn">
	<div class="btn btn-active-big">
		<p><span id="rpop_new_button">Anlegen</span></p>
	</div>
</div>
<div>
	<div class="btn btn-active-big">
		<p><span id="rpop_new_cancle_button">Abbrechen</span></p>
	</div>
</div>
<input type="hidden" name="_plugin_action" value="add" />
</form><br /><br clear="left" />