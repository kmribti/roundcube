/* (Manage)Sieve Filters */

if (window.rcmail) {
  rcmail.addEventListener('init', function(evt) {
    // add managesieve-create command to message_commands array,
    // so it's state will be updated on message selection/unselection
    if (rcmail.env.task == 'mail') {
      if (rcmail.env.action != 'show')
        rcmail.env.message_commands.push('managesieve-create');
      else
        rcmail.enable_command('managesieve-create', true);
    }
    else {
      var tab = $('<span>').attr('id', 'settingstabpluginmanagesieve').addClass('tablink'),
        button = $('<a>').attr('href', rcmail.env.comm_path+'&_action=plugin.managesieve')
          .attr('title', rcmail.gettext('managesieve.managefilters'))
          .html(rcmail.gettext('managesieve.filters'))
          .appendTo(tab);

      // add tab
      rcmail.add_element(tab, 'tabs');
    }

    if (rcmail.env.task == 'mail' || rcmail.env.action.indexOf('plugin.managesieve') != -1) {
      // Create layer for form tips
      if (!rcmail.env.framed) {
        rcmail.env.ms_tip_layer = $('<div id="managesieve-tip" class="popupmenu"></div>');
        rcmail.env.ms_tip_layer.appendTo(document.body);
      }
    }

    // register commands
    rcmail.register_command('plugin.managesieve-save', function() { rcmail.managesieve_save() });
    rcmail.register_command('plugin.managesieve-add', function() { rcmail.managesieve_add() });
    rcmail.register_command('plugin.managesieve-del', function() { rcmail.managesieve_del() });
    rcmail.register_command('plugin.managesieve-up', function() { rcmail.managesieve_up() });
    rcmail.register_command('plugin.managesieve-down', function() { rcmail.managesieve_down() });
    rcmail.register_command('plugin.managesieve-set', function() { rcmail.managesieve_set() });
    rcmail.register_command('plugin.managesieve-setadd', function() { rcmail.managesieve_setadd() });
    rcmail.register_command('plugin.managesieve-setdel', function() { rcmail.managesieve_setdel() });
    rcmail.register_command('plugin.managesieve-setact', function() { rcmail.managesieve_setact() });
    rcmail.register_command('plugin.managesieve-setget', function() { rcmail.managesieve_setget() });

    if (rcmail.env.action == 'plugin.managesieve' || rcmail.env.action == 'plugin.managesieve-save') {
      if (rcmail.gui_objects.sieveform) {
        rcmail.enable_command('plugin.managesieve-save', true);
        // resize dialog window
        if (rcmail.env.action == 'plugin.managesieve' && rcmail.env.task == 'mail') {
          parent.rcmail.managesieve_dialog_resize(rcmail.gui_objects.sieveform);
        }
        $('input[type="text"]:first', rcmail.gui_objects.sieveform).focus();
      }
      else {
        rcmail.enable_command('plugin.managesieve-add', 'plugin.managesieve-setadd', !rcmail.env.sieveconnerror);
      }

      if (rcmail.gui_objects.filterslist) {
        var p = rcmail;
        rcmail.filters_list = new rcube_list_widget(rcmail.gui_objects.filterslist, {multiselect:false, draggable:false, keyboard:false});
        rcmail.filters_list.addEventListener('select', function(o){ p.managesieve_select(o); });
        rcmail.filters_list.init();
        rcmail.filters_list.focus();

        rcmail.enable_command('plugin.managesieve-set', true);
        rcmail.enable_command('plugin.managesieve-setact', 'plugin.managesieve-setget', rcmail.gui_objects.filtersetslist.length);
        rcmail.enable_command('plugin.managesieve-setdel', rcmail.gui_objects.filtersetslist.length > 1);

        $('#'+rcmail.buttons['plugin.managesieve-setact'][0].id).attr('title', rcmail.gettext('managesieve.filterset'
          + ($.inArray(rcmail.gui_objects.filtersetslist.value, rcmail.env.active_sets) != -1 ? 'deact' : 'act')));
      }
    }
    if (rcmail.gui_objects.sieveform && rcmail.env.rule_disabled)
      $('#disabled').attr('checked', true);
  });
};

/*********************************************************/
/*********     Managesieve filters methods       *********/
/*********************************************************/

rcube_webmail.prototype.managesieve_add = function()
{
  this.load_managesieveframe();
  this.filters_list.clear_selection();
};

rcube_webmail.prototype.managesieve_del = function()
{
  var id = this.filters_list.get_single_selection();
  if (confirm(this.get_label('managesieve.filterdeleteconfirm')))
    this.http_request('plugin.managesieve',
      '_act=delete&_fid='+this.filters_list.rows[id].uid, true);
};

rcube_webmail.prototype.managesieve_up = function()
{
  var id = this.filters_list.get_single_selection();
  this.http_request('plugin.managesieve',
    '_act=up&_fid='+this.filters_list.rows[id].uid, true);
};

rcube_webmail.prototype.managesieve_down = function()
{
  var id = this.filters_list.get_single_selection();
  this.http_request('plugin.managesieve',
    '_act=down&_fid='+this.filters_list.rows[id].uid, true);
};

rcube_webmail.prototype.managesieve_rowid = function(id)
{
  var i, rows = this.filters_list.rows;

  for (i=0; i<rows.length; i++)
    if (rows[i] != null && rows[i].uid == id)
      return i;
};

rcube_webmail.prototype.managesieve_updatelist = function(action, name, id, disabled)
{
  this.set_busy(true);

  switch (action) {
    case 'delete':
      this.filters_list.remove_row(this.managesieve_rowid(id));
      this.filters_list.clear_selection();
      this.enable_command('plugin.managesieve-del', 'plugin.managesieve-up', 'plugin.managesieve-down', false);
      this.show_contentframe(false);

      // re-numbering filters
      var i, rows = this.filters_list.rows;
      for (i=0; i<rows.length; i++) {
        if (rows[i] != null && rows[i].uid > id)
          rows[i].uid = rows[i].uid-1;
      }
      break;

    case 'down':
      var from, fromstatus, status, rows = this.filters_list.rows;

      // we need only to replace filter names...
      for (var i=0; i<rows.length; i++) {
        if (rows[i]==null) { // removed row
          continue;
        }
        else if (rows[i].uid == id) {
          from = rows[i].obj;
          fromstatus = $(from).hasClass('disabled');
        }
        else if (rows[i].uid == id+1) {
          name = rows[i].obj.cells[0].innerHTML;
          status = $(rows[i].obj).hasClass('disabled');
          rows[i].obj.cells[0].innerHTML = from.cells[0].innerHTML;
          from.cells[0].innerHTML = name;
          $(from)[status?'addClass':'removeClass']('disabled');
          $(rows[i].obj)[fromstatus?'addClass':'removeClass']('disabled');
          this.filters_list.highlight_row(i);
          break;
        }
      }
      // ... and disable/enable Down button
      this.filters_listbuttons();
      break;

    case 'up':
      var from, status, fromstatus, rows = this.filters_list.rows;

      // we need only to replace filter names...
      for (var i=0; i<rows.length; i++) {
        if (rows[i] == null) { // removed row
          continue;
        }
        else if (rows[i].uid == id-1) {
          from = rows[i].obj;
          fromstatus = $(from).hasClass('disabled');
          this.filters_list.highlight_row(i);
        }
        else if (rows[i].uid == id) {
          name = rows[i].obj.cells[0].innerHTML;
          status = $(rows[i].obj).hasClass('disabled');
          rows[i].obj.cells[0].innerHTML = from.cells[0].innerHTML;
          from.cells[0].innerHTML = name;
          $(from)[status?'addClass':'removeClass']('disabled');
          $(rows[i].obj)[fromstatus?'addClass':'removeClass']('disabled');
          break;
        }
      }
      // ... and disable/enable Up button
      this.filters_listbuttons();
      break;

    case 'update':
      var rows = parent.rcmail.filters_list.rows;
      for (var i=0; i<rows.length; i++)
        if (rows[i] && rows[i].uid == id) {
          rows[i].obj.cells[0].innerHTML = name;
          if (disabled)
            $(rows[i].obj).addClass('disabled');
          else
            $(rows[i].obj).removeClass('disabled');
          break;
        }
      break;

    case 'add':
      var row, new_row, td, list = parent.rcmail.filters_list;

      if (!list)
        break;

      for (var i=0; i<list.rows.length; i++)
        if (list.rows[i] != null && String(list.rows[i].obj.id).match(/^rcmrow/))
          row = list.rows[i].obj;

      if (row) {
        new_row = parent.document.createElement('tr');
        new_row.id = 'rcmrow'+id;
        td = parent.document.createElement('td');
        new_row.appendChild(td);
        list.insert_row(new_row, false);
        if (disabled)
          $(new_row).addClass('disabled');
          if (row.cells[0].className)
            td.className = row.cells[0].className;

           td.innerHTML = name;
        list.highlight_row(id);

        parent.rcmail.enable_command('plugin.managesieve-del', 'plugin.managesieve-up', true);
      }
      else // refresh whole page
        parent.rcmail.goto_url('plugin.managesieve');
      break;
  }

  this.set_busy(false);
};

rcube_webmail.prototype.managesieve_select = function(list)
{
  var id = list.get_single_selection();
  if (id != null)
    this.load_managesieveframe(list.rows[id].uid);
};

rcube_webmail.prototype.managesieve_save = function()
{
  if (parent.rcmail && parent.rcmail.filters_list && this.gui_objects.sieveform.name != 'filtersetform') {
    var id = parent.rcmail.filters_list.get_single_selection();
    if (id != null)
      this.gui_objects.sieveform.elements['_fid'].value = parent.rcmail.filters_list.rows[id].uid;
  }
  this.gui_objects.sieveform.submit();
};

// load filter frame
rcube_webmail.prototype.load_managesieveframe = function(id)
{
  if (typeof(id) != 'undefined' && id != null) {
    this.enable_command('plugin.managesieve-del', true);
    this.filters_listbuttons();
  }
  else
    this.enable_command('plugin.managesieve-up', 'plugin.managesieve-down', 'plugin.managesieve-del', false);

  if (this.env.contentframe && window.frames && window.frames[this.env.contentframe]) {
    target = window.frames[this.env.contentframe];
    var msgid = this.set_busy(true, 'loading');
    target.location.href = this.env.comm_path+'&_action=plugin.managesieve&_framed=1'
      +(id ? '&_fid='+id : '')+'&_unlock='+msgid;
  }
};

// enable/disable Up/Down buttons
rcube_webmail.prototype.filters_listbuttons = function()
{
  var id = this.filters_list.get_single_selection(),
    rows = this.filters_list.rows;

  for (var i=0; i<rows.length; i++) {
    if (rows[i] == null) { // removed row
    }
    else if (i == id) {
      this.enable_command('plugin.managesieve-up', false);
      break;
    }
    else {
      this.enable_command('plugin.managesieve-up', true);
      break;
    }
  }

  for (var i=rows.length-1; i>0; i--) {
    if (rows[i] == null) { // removed row
    }
    else if (i == id) {
      this.enable_command('plugin.managesieve-down', false);
      break;
    }
    else {
      this.enable_command('plugin.managesieve-down', true);
      break;
    }
  } 
};

// operations on filters form
rcube_webmail.prototype.managesieve_ruleadd = function(id)
{
  this.http_post('plugin.managesieve', '_act=ruleadd&_rid='+id);
};

rcube_webmail.prototype.managesieve_rulefill = function(content, id, after)
{
  if (content != '') {
    // create new element
    var div = document.getElementById('rules'),
      row = document.createElement('div');

    this.managesieve_insertrow(div, row, after);
    // fill row after inserting (for IE)
    row.setAttribute('id', 'rulerow'+id);
    row.className = 'rulerow';
    row.innerHTML = content;

    this.managesieve_formbuttons(div);
  }
};

rcube_webmail.prototype.managesieve_ruledel = function(id)
{
  if ($('#ruledel'+id).hasClass('disabled'))
    return;

  if (confirm(this.get_label('managesieve.ruledeleteconfirm'))) {
    var row = document.getElementById('rulerow'+id);
    row.parentNode.removeChild(row);
    this.managesieve_formbuttons(document.getElementById('rules'));
  }
};

rcube_webmail.prototype.managesieve_actionadd = function(id)
{
  this.http_post('plugin.managesieve', '_act=actionadd&_aid='+id);
};

rcube_webmail.prototype.managesieve_actionfill = function(content, id, after)
{
  if (content != '') {
    var div = document.getElementById('actions'),
      row = document.createElement('div');

    this.managesieve_insertrow(div, row, after);
    // fill row after inserting (for IE)
    row.className = 'actionrow';
    row.setAttribute('id', 'actionrow'+id);
    row.innerHTML = content;

    this.managesieve_formbuttons(div);
  }
};

rcube_webmail.prototype.managesieve_actiondel = function(id)
{
  if ($('#actiondel'+id).hasClass('disabled'))
    return;

  if (confirm(this.get_label('managesieve.actiondeleteconfirm'))) {
    var row = document.getElementById('actionrow'+id);
    row.parentNode.removeChild(row);
    this.managesieve_formbuttons(document.getElementById('actions'));
  }
};

// insert rule/action row in specified place on the list
rcube_webmail.prototype.managesieve_insertrow = function(div, row, after)
{
  for (var i=0; i<div.childNodes.length; i++) {
    if (div.childNodes[i].id == (div.id == 'rules' ? 'rulerow' : 'actionrow')  + after)
      break;
  }

  if (div.childNodes[i+1])
    div.insertBefore(row, div.childNodes[i+1]);
  else
    div.appendChild(row);
};

// update Delete buttons status
rcube_webmail.prototype.managesieve_formbuttons = function(div)
{
  var i, button, buttons = [];

  // count and get buttons
  for (i=0; i<div.childNodes.length; i++) {
    if (div.id == 'rules' && div.childNodes[i].id) {
      if (/rulerow/.test(div.childNodes[i].id))
        buttons.push('ruledel' + div.childNodes[i].id.replace(/rulerow/, ''));
    }
    else if (div.childNodes[i].id) {
      if (/actionrow/.test(div.childNodes[i].id))
        buttons.push( 'actiondel' + div.childNodes[i].id.replace(/actionrow/, ''));
    }
  }

  for (i=0; i<buttons.length; i++) {
    button = document.getElementById(buttons[i]);
    if (i>0 || buttons.length>1) {
      $(button).removeClass('disabled');
    }
    else {
      $(button).addClass('disabled');
    }
  }
};

// Set change
rcube_webmail.prototype.managesieve_set = function()
{
  var script = $(this.gui_objects.filtersetslist).val();
  location.href = this.env.comm_path+'&_action=plugin.managesieve&_set='+script;
};

// Script download
rcube_webmail.prototype.managesieve_setget = function()
{
  var script = $(this.gui_objects.filtersetslist).val();
  location.href = this.env.comm_path+'&_action=plugin.managesieve&_act=setget&_set='+script;
};

// Set activate
rcube_webmail.prototype.managesieve_setact = function()
{
  if (!this.gui_objects.filtersetslist)
    return false;

  var script = this.gui_objects.filtersetslist.value,
    action = ($.inArray(script, rcmail.env.active_sets) != -1 ? 'deact' : 'setact');

  this.http_post('plugin.managesieve', '_act='+action+'&_set='+script);
};

// Set activate flag in sets list after set activation
rcube_webmail.prototype.managesieve_reset = function()
{
  if (!this.gui_objects.filtersetslist)
    return false;

  var list = this.gui_objects.filtersetslist,
    opts = list.getElementsByTagName('option'),
    label = ' (' + this.get_label('managesieve.active') + ')',
    regx = new RegExp(RegExp.escape(label)+'$');

  for (var x=0; x<opts.length; x++) {
    if ($.inArray(opts[x].value, rcmail.env.active_sets)<0) {
      if (opts[x].innerHTML.match(regx))
        opts[x].innerHTML = opts[x].innerHTML.replace(regx, '');
    }
    else if (!opts[x].innerHTML.match(regx))
      opts[x].innerHTML = opts[x].innerHTML + label;
  }

  // change title of setact button
  $('#'+rcmail.buttons['plugin.managesieve-setact'][0].id).attr('title', rcmail.gettext('managesieve.filterset'
    + ($.inArray(list.value, rcmail.env.active_sets) != -1 ? 'deact' : 'act')));
};

// Set delete
rcube_webmail.prototype.managesieve_setdel = function()
{
  if (!this.gui_objects.filtersetslist)
    return false;

  if (!confirm(this.get_label('managesieve.setdeleteconfirm')))
    return false;

  var script = this.gui_objects.filtersetslist.value;
  this.http_post('plugin.managesieve', '_act=setdel&_set='+script);
};

// Set add
rcube_webmail.prototype.managesieve_setadd = function()
{
  this.filters_list.clear_selection();
  this.enable_command('plugin.managesieve-up', 'plugin.managesieve-down', 'plugin.managesieve-del', false);

  if (this.env.contentframe && window.frames && window.frames[this.env.contentframe]) {
    target = window.frames[this.env.contentframe];
    var msgid = this.set_busy(true, 'loading');
    target.location.href = this.env.comm_path+'&_action=plugin.managesieve&_framed=1&_newset=1&_unlock='+msgid;
  }
};

rcube_webmail.prototype.managesieve_reload = function(set)
{
  this.env.reload_set = set;
  window.setTimeout(function() {
    location.href = rcmail.env.comm_path + '&_action=plugin.managesieve'
      + (rcmail.env.reload_set ? '&_set=' + rcmail.env.reload_set : '')
  }, 500);
};

// Register onmouse(leave/enter) events for tips on specified form element
rcube_webmail.prototype.managesieve_tip_register = function(tips)
{
  var n, framed = parent.rcmail,
    tip = framed ? parent.rcmail.env.ms_tip_layer : rcmail.env.ms_tip_layer;

  for (var n in tips) {
    $('#'+tips[n][0])
      .bind('mouseenter', {str: tips[n][1]},
        function(e) {
          var offset = $(this).offset(),
            left = offset.left,
            top = offset.top - 12;

          if (framed) {
            offset = $((rcmail.env.task == 'mail'  ? '#sievefilterform > iframe' : '#filter-box'), parent.document).offset();
            top  += offset.top;
            left += offset.left;
          }

          tip.html(e.data.str)
          top -= tip.height();
          
          tip.css({left: left, top: top}).show();
        })
      .bind('mouseleave', function(e) { tip.hide(); });
  }
};

/*********************************************************/
/*********     Other Managesieve UI methods      *********/
/*********************************************************/

function rule_header_select(id)
{
  var obj = document.getElementById('header' + id),
    size = document.getElementById('rule_size' + id),
    op = document.getElementById('rule_op' + id),
    target = document.getElementById('rule_target' + id),
    header = document.getElementById('custom_header' + id);

  if (obj.value == 'size') {
    size.style.display = 'inline';
    op.style.display = 'none';
    target.style.display = 'none';
    header.style.display = 'none';
  }
  else {
    header.style.display = obj.value != '...' ? 'none' : 'inline';
    size.style.display = 'none';
    op.style.display = 'inline';
    rule_op_select(id);
  }
};

function rule_op_select(id)
{
  var obj = document.getElementById('rule_op' + id),
    target = document.getElementById('rule_target' + id);

  target.style.display = obj.value == 'exists' || obj.value == 'notexists' ? 'none' : 'inline';
};

function rule_join_radio(value)
{
  $('#rules').css('display', value == 'any' ? 'none' : 'block');
};

function action_type_select(id)
{
  var obj = document.getElementById('action_type' + id),
   	enabled = {},
    elems = {
      mailbox: document.getElementById('action_mailbox' + id),
      target: document.getElementById('action_target' + id),
      target_area: document.getElementById('action_target_area' + id),
      flags: document.getElementById('action_flags' + id),
      vacation: document.getElementById('action_vacation' + id)
    };

  if (obj.value == 'fileinto' || obj.value == 'fileinto_copy') {
    enabled.mailbox = 1;
  }
  else if (obj.value == 'redirect' || obj.value == 'redirect_copy') {
    enabled.target = 1;
  }
  else if (obj.value.match(/^reject|ereject$/)) {
    enabled.target_area = 1;
  }
  else if (obj.value.match(/^(add|set|remove)flag$/)) {
    enabled.flags = 1;
  }
  else if (obj.value == 'vacation') {
    enabled.vacation = 1;
  }

  for (var x in elems) {
    elems[x].style.display = !enabled[x] ? 'none' : 'inline';
  }
};

/*********************************************************/
/*********           Mail UI methods             *********/
/*********************************************************/

rcube_webmail.prototype.managesieve_create = function()
{
  if (!rcmail.env.sieve_headers || !rcmail.env.sieve_headers.length)
    return;

  var i, html, buttons = {}, dialog = $("#sievefilterform");

  // create dialog window
  if (!dialog.length) {
    dialog = $('<div id="sievefilterform"></div>');
    $('body').append(dialog);
  }

  // build dialog window content
  html = '<fieldset><legend>'+this.gettext('managesieve.usedata')+'</legend><ul>';
  for (i in rcmail.env.sieve_headers)
    html += '<li><input type="checkbox" name="headers[]" id="sievehdr'+i+'" value="'+i+'" checked="checked" />'
      +'<label for="sievehdr'+i+'">'+rcmail.env.sieve_headers[i][0]+':</label> '+rcmail.env.sieve_headers[i][1]+'</li>';
  html += '</ul></fieldset>';

  dialog.html(html);

  // [Next Step] button action
  buttons[this.gettext('managesieve.nextstep')] = function () {
    // check if there's at least one checkbox checked
    var hdrs = $('input[name="headers[]"]:checked', dialog);
    if (!hdrs.length) {
      alert(rcmail.gettext('managesieve.nodata'));
      return;
    }

    // build frame URL
    var url = rcmail.get_task_url('mail');
    url = rcmail.add_url(url, '_action', 'plugin.managesieve');
    url = rcmail.add_url(url, '_framed', 1);

    hdrs.map(function() {
      var val = rcmail.env.sieve_headers[this.value];
      url = rcmail.add_url(url, 'r['+this.value+']', val[0]+':'+val[1]);
    });

    // load form in the iframe
    var frame = $('<iframe>').attr({src: url, frameborder: 0})
    frame.height(dialog.height()); // temp. 
    dialog.empty().append(frame);
    dialog.dialog('dialog').resize();

    // Change [Next Step] button with [Save] button
    buttons = {};
    buttons[rcmail.gettext('save')] = function() {  
      var win = $('iframe', dialog).get(0).contentWindow;
      win.rcmail.managesieve_save();
    };
    dialog.dialog('option', 'buttons', buttons);
  };

  // show dialog window
  dialog.dialog({
    modal: false,
    resizable: !bw.ie6,
    closeOnEscape: (!bw.ie6 && !bw.ie7),  // disable for performance reasons
    title: this.gettext('managesieve.newfilter'),
    close: function() { rcmail.managesieve_dialog_close(); },
    buttons: buttons,
    minWidth: 600,
    minHeight: 300
  }).show();

  this.env.managesieve_dialog = dialog;
}

rcube_webmail.prototype.managesieve_dialog_close = function()
{
  var dialog = this.env.managesieve_dialog;
  
  // BUG(?): if we don't remove the iframe first, it will be reloaded
  dialog.html('');
  dialog.dialog('destroy').hide();
}

rcube_webmail.prototype.managesieve_dialog_resize = function(o)
{
  var dialog = this.env.managesieve_dialog,
    win = $(window), form = $(o);
    width = form.width(), height = form.height(),
    w = win.width(), h = win.height();

  dialog.dialog('option', { height: Math.min(h-20, height+120), width: Math.min(w-20, width+65) })
    .dialog('option', 'position', ['center', 'center']);  // only works in a separate call (!?)
}
