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
function addForm()
{
    var rule = new Array();
    $('#newRule .formrow').each(function(n){
        var theid = $(this).attr('id');
        if (typeof(theid) == 'undefined') {
            return true;
        }
        var cond        = $('#cond_' + theid).val();
        var mode        = $('#mode_' + theid).val();
        var cond_val    = $('#value_' + theid).val();
        var action_prop = $('#action_' + theid).val();
        var action_val  = $('#action_add_' + theid).val();

        console.log(cond + '/' + mode + '/' + cond_val + '/' + action_prop + '/' + action_val);
    });
}
function removeRow(rowId)
{
    $('#' + rowId).slideUp('slow').ready(function(){
        $('#' + rowId).remove();
    });
}
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