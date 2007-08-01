<?php
/**
 * @author Till Klampaeckel <till@php.net>
 * @link   http://www.stalker.com/CommuniGatePro/QueueRules.html
 */

/**
 * Include this plugin's bootstrap for all necessary goodness.
 * @ignore
 */
require_once dirname(__FILE__) . '/bootstrap.php';

/**
 * handle $_plugin_action calls
 * @ignore
 */
$error_msg = array();
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    require dirname(__FILE__) . '/bin/plugin_action.php';
}
require dirname(__FILE__) . '/bin/get.php';

//echo '<pre>'; var_dump($mb_rules); echo '</pre>'; exit;

$registry = rc_registry::getInstance();
$OUTPUT   = $registry->get('OUTPUT', 'core');

echo $OUTPUT->parse('header_small', false);
?>
<!-- #content needed to make CSS work - we override inline -->
<div id="content" style="width:780px !important;">
<?php
if (empty($error_msg) === false):
    echo '<div style="width:300px;margin:10px;padding:10px;border:1px solid #ff0000;">';
    echo '<strong>Fehler aufgetreten</strong><br />';
    echo implode('<br />', $error_msg);
    echo '</div>';
endif;
?>
    <div class="navBar">
        <ul>
            <li class="icon icon-add"><span onclick="slideInOrOut($(this));" class="ajaxfakelink" style="padding-bottom:15px; padding-left:15px;">Neuen Regelsatz anlegen.</span></li>
        </ul>
    </div>
	<div id="newFormWrapper" style="padding-top:20px;">
        <form action="<?php echo $RC_URI; ?>?_task=plugin&_action=macbay_filter/form.php" id="newRule" method="post" style="margin:none;width:760px;">
            <?php require dirname(__FILE__) . '/ajax/filter_neu.php'; ?>
            <input type="hidden" name="_plugin_action" value="add" />
        </form>
    </div><br clear="left" />
    <form id="currentRules" method="post" action="<?php echo $RC_URI; ?>?_task=plugin&_action=macbay_filter/form.php" style="margin:0 0 0 0 !important;width:760px;">
        <fieldset>
        	<h2>Filter und Regeln konfigurieren</h2>
        	<?php
        	if (count($mb_rules) > 0):
            	foreach($mb_rules AS $mb_rule):
            	   $_mb_filter_name = $mb_rule[1];
            	   $_mb_filter_prio = $mb_rule[0];
            	   include dirname(__FILE__) . '/ajax/filter.php';

            	   /**
            	    * garbage collection
            	    */
            	   unset($_mb_filter_name);
        	       unset($_mb_filter_prio);
        	       unset($mb_rule);
        	   endforeach;
        	else:
                echo 'Sie haben noch keine Regeln angelegt.';
        	endif;
        	?>
        </fieldset>
        <input type="hidden" name="_plugin_action" value="save" />
    </form>
    <?php if (count($mb_rules) > 0): ?>
    <div id="saveBtn" style="margin:10px;">
		<div class="btn btn-active-big">
			<p><span id="saveButton">&Auml;nderungen speichern</span></p>
		</div>
	</div>
	<br clear="left" />
	<?php endif; ?>
</div>
<script type="text/javascript">
/* <![CDATA[ */
$(document).ready(function(){
   $('#saveButton').bind('click', function(){ $('#currentRules').submit(); });
   $('#newFormWrapper').hide();
   $('#saveNewButton').bind('click', function(){ $('#newRule').submit(); });
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
        $(obj).parent().addClass('icon-stop');
        $(obj).parent().removeClass('icon-add');
        return;
    }
    $('#newFormWrapper').slideUp('slow');
    $(obj).text('Neuen Regelsatz anlegen.');
    $(obj).parent().addClass('icon-add');
    $(obj).parent().removeClass('icon-stop');
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
        {id: wrapper},
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
        {filterName: filterName },
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