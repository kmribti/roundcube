<?php
/**
 * @author Till Klampaeckel <till@php.net>
 * @link   http://www.stalker.com/CommuniGatePro/QueueRules.html
 */

require_once dirname(__FILE__) . '/bootstrap.php';

try {
    $params = array();
    array_push($params, $_SESSION['username']);
    array_push($params, rc_main::decrypt_passwd($_SESSION['password']));
    $mb_rules = $mb_client->call('cli.getRules', $params);
    $mb_data  = $mb_client->call('cli.getRuleMeta', array());
}
catch(Zend_Exception $e) {
    rc_main::tfk_debug(var_export($e, true));
    echo "Message: {$e->getMessage()}<br />";
    echo "Code: {$e->getCode()}";
    echo '</div></div>';
    echo '</body></html>';
    exit;
}
$registry = rc_registry::getInstance();
$OUTPUT   = $registry->get('OUTPUT', 'core');
//$_header  = $OUTPUT->xml_command('plugin.include', 'file="/includes/header.html"');
//$_footer  = $OUTPUT->xml_command('plugin.include', 'file="/includes/footer.html"');
//echo '<pre style="font-size:8pt;">';
//var_dump($mb_data['actions']);
//var_dump($_header);
//var_dump($mb_rules);
//echo '</pre>';
?>
<?php echo $OUTPUT->parse('header_small', false); ?>
<!-- #content needed to make CSS work - we override inline -->
<div id="content" style="width:780px !important;">
    <form id="currentRules" onsubmit="return false;" style="margin:0 0 0 0 !important;width:760px;">
        <fieldset>
        	<h2>Filter</h2>
        	<?php
        	if (count($mb_rules) > 0):
            	foreach($mb_rules AS $mb_rule):
            	   $_mb_filter_name = $mb_rule[1];
            	   $_mb_filter_prio = $mb_rule[0];
            	   include dirname(__FILE__) . '/filter.php';
        	   endforeach;
        	else:
                echo 'Sie haben noch keine Regeln.';
        	endif;
        	unset($_mb_filter_name);
        	unset($_mb_filter_prio);
        	unset($mb_rule);
        	?>
        </fieldset>
    </form>
    <?php if (count($mb_rules) > 0): ?>
    <div id="saveBtn">
		<div class="btn btn-active-big">
			<p><span id="saveButton">&Auml;nderungen speichern</span></p>
		</div>
	</div>
	<?php endif; ?>
	<br clear="left" />
	<span onclick="slideInOrOut($(this));" class="ajaxfakelink">Neuen Regelsatz anlegen.</span>
	<div id="newFormWrapper" style="padding-top:20px;">
        <form id="newRule" style="margin:none;width:760px;">
            <?php require dirname(__FILE__) . '/filter_neu.php'; ?>
        </form>
    </div>
</div>
<script type="text/javascript">
/* <![CDATA[ */
$(document).ready(function(){
   $('#saveButton').bind('click', function(){ saveForm(); });
   $('#newFormWrapper').hide();
   $('#saveNewButton').bind('click', function(){ addForm(); });
});

/**
 * @global mb_modes
 */
var mb_modes = new Array();
<?php
foreach($mb_data['modes'] AS $cat=>$modes):
    $c = 0;
    echo "mb_modes['$cat'] = new Array;\n";
    foreach ($modes AS $r=>$hr):
        echo "mb_modes['$cat'][$c] = new Object;\n";
        echo "mb_modes['$cat'][$c].r = '$r';\n";
        echo "mb_modes['$cat'][$c].hr = '$hr';\n";
        $c++;
    endforeach;
endforeach;
?>

function slideInOrOut(obj)
{
    var text = $(obj).text();
    if (text == 'Neuen Regelsatz anlegen.') {
        $('#newFormWrapper').slideDown('slow');
        $(obj).text('Nein, doch nicht.');
        return;
    }
    $('#newFormWrapper').slideUp('slow');
    $(obj).text('Neuen Regelsatz anlegen.');
    return;
}

/**
 * local functions, because we need the URI in them.
 */
function addRow(filterId, ruleType)
{
    var wrapper = new String(ruleType + '_' + filterId + '_add');
    $.post(
        '<?php echo $RC_URI; ?>?_task=plugin&_action=macbay_filter/ajax/new_' + ruleType + '.php',
        function(data) {
            $(document.getElementById(wrapper)).append(data);
            return;
        }
    );
}
function deleteFilter(filterName, formId)
{
    var status = confirm('Wollen Sie den Regelsatz "' + filterName + '" wirklich entfernen?');
    if (status != true) {
        return;
    }
    $.post(
        '<?php echo $registry->get('RC_URI', 'core'); ?>?_task=plugin&_action=macbay_filter/delete.php',
        {filterName: filterName},
        function(data) {
            if (data == 'ok') {
                $('#' + formId).slideUp('slow').ready(function(){
                    $('#' + formId).remove();
                });
                return;
            }
            alert('Der Regelsatz konnte nicht entfernt werden.');
            return;
        }
    )
}
/* ]]> */
</script>
</body>
</html>