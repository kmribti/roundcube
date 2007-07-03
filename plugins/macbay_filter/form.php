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
    <form id="currentRules" onsubmit="return false;" style="width:780px;">
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
    <div id="saveBtn">
		<div class="btn btn-active-big">
			<p><span id="saveButton">&Auml;nderungen speichern</span></p>
		</div>
	</div>
	<br clear="left" />
	<div id="#newFormWrapper">
    <form>
        <?php include dirname(__FILE__) . '/filter_neu.php'; ?>
    </form>
    </div>
<script type="text/javascript">
/* <![CDATA[ */
$(document).ready(function(){
   $('#saveButton').bind('click', function(){ÊsaveForm(); });
   $('#newFormWrapper').hide();
});
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
function repopulateMode(theid) {
    var cond = $('#cond_' + theid).val();
    if (typeof(mb_modes[cond]) == 'undefined') {
        var modes = mb_modes['default'];
    }
    else {
        var modes = mb_modes[cond];
    }
    var html = new String;
    for (var x=0; x<modes.length; x++) {
        var themode = modes[x];
        html+= '<option value="' + themode.r + '">'
        html+= themode.hr + '</option>' + "\n";
    }
    if (html == '') {
        html += '<option value="">Ja</option>';
    }
    $('#mode_' + theid).html('').ready(function(){
        $('#mode_' + theid).html(html);
    });
}
function saveForm()
{
    $('#currentRules .formrow').each(function(n){
        var theid = $(this).attr('id');
        if (typeof(theid) == 'undefined') {
            return true;
        }
        var cond = $('#cond_' + theid).val();
        var mode = $('#mode_' + theid).val();
        var val  = $('#value_' + theid).val();

        if (cond == null) {
            return true;
        }
        console.log(cond + '/' + mode + '/' + val);
    });
}
/* ]]> */
</script>
</body>
</html>