<?php
/**
 * @author Till Klampaeckel <till@php.net>
 * @link   http://www.stalker.com/CommuniGatePro/QueueRules.html
 * @link   http://www.stalker.com/CommuniGatePro/RPOP.html
 */

/**
 * Include this plugin's bootstrap for all necessary goodness.
 * @ignore
 */
require_once dirname(__FILE__) . '/bootstrap.php';

$error_msg = array();
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    require dirname(__FILE__) . '/bin/plugin_action.php';
}

$rpops     = $macbay_pop3->getRpop();
$rpop_left = (intval($rpops['maxRpop'])-count($rpops['rpop']));

$registry = rcube_registry::get_instance();
$OUTPUT   = $registry->get('OUTPUT', 'core');
$BASE_URI   = $registry->get('BASE_URI', 'core');

echo $OUTPUT->send('header_small', false);
?>
<!-- #content needed to make CSS work - we override inline -->
<div id="content" style="width:760px !important;">

<?php
if (empty($error_msg) === false):
    echo '<div style="width:300px;margin:10px;padding:10px;border:1px solid #ff0000;">';
    echo '<strong>Fehler aufgetreten</strong><br />';
    echo implode('<br />', $error_msg);
    echo '</div>';
endif;

if ($rpop_left > 0): ?>
    <div class="navBar">
        <ul>
            <li class="icon icon-add">
                <span id="rpop_new_trigger" class="ajaxfakelink" style="margin-left:14px""">Neuen Sammeldienst anlegen</span>
            </li>
        </ul>
    </div><br />
    <div id="rpop_new_wrapper" style="display:none;">
        <?php include dirname(__FILE__) . '/ajax/new_rpop.php'; ?>
    </div>
<?php elseif ($rpops['maxRpop'] == 0): ?>
    <div class="navBar">Ihr Zugang beinhaltet keine Sammeldienste</div>
    </div>
</div>
</body>
</html>
<?php exit; ?>
<?php else: ?>
    <div class="navBar">
        Sie haben <?php $rpops['maxRpop']; ?> Sammeldienste angelegt.<br />
        Bitte l&ouml;schen Sie zuerst einen Sammeldienst, um einen neuen anzulegen.
    </div>
<?php endif; ?>
    <div class="rpop_txt">
        <h2>Sammeldienste Konfigurieren</h2>
<?php
if (count($rpops['rpop']) > 0):
?>
    <p >Die folgenden Sammeldienste werden 4x pro Stunde ausgef&uuml;hrt.</p><br /><br />
    <table border="0" width="100%">
    <thead>
        <tr>
            <th>Mailserver</th>
            <th>Benutzername</th>
            <th>Passwort</th>
            <th>E-Mails l&ouml;schen?</th>
            <th>Status</th>
            <th>&nbsp;</th>
        </tr>
    </thead>
    <tbody>
    <?php
        foreach($rpops['rpop'] AS $rpop_id=>$rpop_data):
    ?>
    <tr>
        <td><?php echo $rpop_data['servername']; ?></td>
        <td><?php echo $rpop_data['username']; ?></td>
        <td>***<?php //echo $rpop_data['password']; ?></td>
        <td><?php echo (($rpop_data['leave'] == 1)?'Nein':'Ja'); ?></td>
        <td><?php echo $rpop_data['status']; ?></td>
        <td>
            <?php
            $rpop_delete_form_id = 'form_' . $rpop_id;
            include dirname(__FILE__) . '/ajax/delete_form.php';
            ?>
            <span onclick="$('form#<?php echo $rpop_delete_form_id; ?>').submit();" title="Button: L&ouml;schen">
                <img alt="" border="0" width="16" height="16" src="skins/macbay/img/bin.gif" />
            </span>
        </td>
    </tr>
    <?php
        endforeach;
    ?>
    </tbody>
    </table><br /><br />
<?php
else:
    echo '<p>Sie haben noch keine Sammeldienste konfiguriert.</p>';
endif;
?>
    </div>
</div>
<script type="text/javascript">
/* <![CDATA[ */
$(document).ready(function(){
    $('span#rpop_new_trigger').bind('click', function(){
        $('div#rpop_new_wrapper').slideToggle('slow');
        //$('span#rpop_new_trigger').text($('#rpop_new_trigger').css('height'));
    });
    $('span#rpop_new_button').bind('click', function(){
        $('form#form_rpop_new').submit();
    });
    $('span#rpop_new_cancle_button').bind('click', function(){
        $('div#rpop_new_wrapper').slideUp('slow');
    });
});
/* ]]> */
</script>
</body>
</html>
