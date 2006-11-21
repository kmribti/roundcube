/*
 +-----------------------------------------------------------------------+
 | RoundCube List Widget                                                 |
 |                                                                       |
 | This file is part of the RoundCube Webmail client                     |
 | Copyright (C) 2006, RoundCube Dev, - Switzerland                      |
 | Licensed under the GNU GPL                                            |
 |                                                                       |
 +-----------------------------------------------------------------------+
 | Authors: Thomas Bruederli <roundcube@gmail.com>                       |
 |          Charles McNulty <charles@charlesmcnulty.com>                 |
 +-----------------------------------------------------------------------+
 | Requires: common.js                                                   |
 +-----------------------------------------------------------------------+

  $Id: list.js 344 2006-09-18 03:49:28Z thomasb $
*/


/**
 * RoundCube List Widget class
 * @contructor
 */
function rcube_list_widget(list, p)
  {
  // static contants
  this.ENTER_KEY = 13;
  this.DELETE_KEY = 46;
  
  this.list = list ? list : null;
  this.frame = null;
  this.rows = [];
  this.selection = [];
  
  this.shiftkey = false;

  this.multiselect = false;
  this.draggable = false;
  this.keyboard = false;
  
  this.dont_select = false;
  this.drag_active = false;
  this.last_selected = 0;
  this.in_selection_before = false;
  this.focused = false;
  this.drag_mouse_start = null;
  this.dblclick_time = 600;
  this.row_init = function(){};
  this.events = { click:[], dblclick:[], select:[], keypress:[], dragstart:[], dragend:[] };
  
  // overwrite default paramaters
  if (p && typeof(p)=='object')
    for (var n in p)
      this[n] = p[n];
  }


rcube_list_widget.prototype = {


/**
 * get all message rows from HTML table and init each row
 */
init: function()
{
  if (this.list && this.list.tBodies[0])
  {
    this.rows = new Array();

    var row;
    for(var r=0; r<this.list.tBodies[0].childNodes.length; r++)
    {
      row = this.list.tBodies[0].childNodes[r];
      while (row && (row.nodeType != 1 || row.style.display == 'none'))
      {
        row = row.nextSibling;
        r++;
      }

      this.init_row(row);
    }

    this.frame = this.list.parentNode;

    // set body events
    if (this.keyboard)
      rcube_event.add_listener({element:document, event:'keydown', object:this, method:'key_press'});
  }
},


/**
 *
 */
init_row: function(row)
{
  // make references in internal array and set event handlers
  if (row && String(row.id).match(/rcmrow([0-9]+)/))
  {
    var p = this;
    var uid = RegExp.$1;
    row.uid = uid;
    this.rows[uid] = {uid:uid, id:row.id, obj:row, classname:row.className};

    // set eventhandlers to table row
    row.onmousedown = function(e){ return p.drag_row(e, this.uid); };
    row.onmouseup = function(e){ return p.click_row(e, this.uid); };

    if (document.all)
      row.onselectstart = function() { return false; };

    this.row_init(this.rows[uid]);
  }
},


/**
 *
 */
clear: function()
{
  var tbody = document.createElement('TBODY');
  this.list.insertBefore(tbody, this.list.tBodies[0]);
  this.list.removeChild(this.list.tBodies[1]);
  this.rows = new Array();  
},


/**
 * 'remove' message row from list (just hide it)
 */
remove_row: function(uid)
{
  if (this.rows[uid].obj)
    this.rows[uid].obj.style.display = 'none';

  this.rows[uid] = null;
},


/**
 *
 */
insert_row: function(row, attop)
{
  var tbody = this.list.tBodies[0];

  if (attop && tbody.rows.length)
    tbody.insertBefore(row, tbody.firstChild);
  else
    tbody.appendChild(row);

  this.init_row(row);
},



/**
 * Set focur to the list
 */
focus: function(e)
{
  this.focused = true;
  for (var n=0; n<this.selection.length; n++)
  {
    id = this.selection[n];
    if (this.rows[id].obj)
    {
      this.set_classname(this.rows[id].obj, 'selected', true);
      this.set_classname(this.rows[id].obj, 'unfocused', false);
    }
  }

  if (e || (e = window.event))
    rcube_event.cancel(e);
},


/**
 * remove focus from the list
 */
blur: function()
{
  var id;
  this.focused = false;
  for (var n=0; n<this.selection.length; n++)
  {
    id = this.selection[n];
    if (this.rows[id] && this.rows[id].obj)
    {
      this.set_classname(this.rows[id].obj, 'selected', false);
      this.set_classname(this.rows[id].obj, 'unfocused', true);
    }
  }
},


/**
 * onmousedown-handler of message list row
 */
drag_row: function(e, id)
{
  this.in_selection_before = this.in_selection(id) ? id : false;

  // don't do anything (another action processed before)
  if (this.dont_select)
    return false;

  // selects currently unselected row
  if (!this.in_selection_before)
  {
    var mod_key = rcube_event.get_modifier(e);
    this.select_row(id, mod_key, false);
  }

  if (this.draggable && this.selection.length)
  {
    this.drag_start = true;
	this.drag_mouse_start = rcube_event.get_mouse_pos(e);
    rcube_event.add_listener({element:document, event:'mousemove', object:this, method:'drag_mouse_move'});
    rcube_event.add_listener({element:document, event:'mouseup', object:this, method:'drag_mouse_up'});
  }

  return false;
},


/**
 * onmouseup-handler of message list row
 */
click_row: function(e, id)
{
  var now = new Date().getTime();
  var mod_key = rcube_event.get_modifier(e);

  // don't do anything (another action processed before)
  if (this.dont_select)
    {
    this.dont_select = false;
    return false;
    }
    
  var dblclicked = now - this.rows[id].clicked < this.dblclick_time;

  // unselects currently selected row
  if (!this.drag_active && this.in_selection_before == id && !dblclicked)
    this.select_row(id, mod_key, false);

  this.drag_start = false;
  this.in_selection_before = false;

  // row was double clicked
  if (this.rows && dblclicked && this.in_selection(id))
    this.trigger_event('dblclick');
  else
    this.trigger_event('click');

  if (!this.drag_active)
    rcube_event.cancel(e);

  this.rows[id].clicked = now;
  return false;
},


/**
 * get next and previous rows that are not hidden
 */
get_next_row: function()
{
  if (!this.rows)
    return false;

  var last_selected_row = this.rows[this.last_selected];
  var new_row = last_selected_row && last_selected_row.obj.nextSibling;
  while (new_row && (new_row.nodeType != 1 || new_row.style.display == 'none'))
    new_row = new_row.nextSibling;

  return new_row;
},

get_prev_row: function()
{
  if (!this.rows)
    return false;

  var last_selected_row = this.rows[this.last_selected];
  var new_row = last_selected_row && last_selected_row.obj.previousSibling;
  while (new_row && (new_row.nodeType != 1 || new_row.style.display == 'none'))
    new_row = new_row.previousSibling;

  return new_row;
},


// selects or unselects the proper row depending on the modifier key pressed
select_row: function(id, mod_key, with_mouse)
{
  var select_before = this.selection.join(',');
  if (!this.multiselect)
    mod_key = 0;

  if (!mod_key)
  {
    this.shift_start = id;
    this.highlight_row(id, false);
  }
  else
  {
    switch (mod_key)
    {
      case SHIFT_KEY:
        this.shift_select(id, false); 
        break;

      case CONTROL_KEY:
        this.shift_start = id;
        if (!with_mouse)
          this.highlight_row(id, true); 
        break; 

      case CONTROL_SHIFT_KEY:
        this.shift_select(id, true);
        break;

      default:
        this.highlight_row(id, false); 
        break;
    }
  }

  // trigger event if selection changed
  if (this.selection.join(',') != select_before)
    this.trigger_event('select');

  if (this.last_selected != 0 && this.rows[this.last_selected])
    this.set_classname(this.rows[this.last_selected].obj, 'focused', false);

  this.last_selected = id;
  this.set_classname(this.rows[id].obj, 'focused', true);        
},


/**
 * Alias method for select_row
 */
select: function(id)
{
  this.select_row(id, false);
  this.scrollto(id);
},


/**
 * Select row next to the last selected one.
 * Either below or above.
 */
select_next: function()
{
  var next_row = this.get_next_row();
  var prev_row = this.get_prev_row();
  var new_row = (next_row) ? next_row : prev_row;
  if (new_row)
    this.select_row(new_row.uid, false, false);  
},


/**
 * Perform selection when shift key is pressed
 */
shift_select: function(id, control)
{
  var from_rowIndex = this.rows[this.shift_start].obj.rowIndex;
  var to_rowIndex = this.rows[id].obj.rowIndex;

  var i = ((from_rowIndex < to_rowIndex)? from_rowIndex : to_rowIndex);
  var j = ((from_rowIndex > to_rowIndex)? from_rowIndex : to_rowIndex);

  // iterate through the entire message list
  for (var n in this.rows)
  {
    if ((this.rows[n].obj.rowIndex >= i) && (this.rows[n].obj.rowIndex <= j))
    {
      if (!this.in_selection(n))
        this.highlight_row(n, true);
    }
    else
    {
      if  (this.in_selection(n) && !control)
        this.highlight_row(n, true);
    }
  }
},


/**
 * Check if given id is part of the current selection
 */
in_selection: function(id)
{
  for(var n in this.selection)
    if (this.selection[n]==id)
      return true;

  return false;    
},


/**
 * Select each row in list
 */
select_all: function(filter)
{
  if (!this.rows || !this.rows.length)
    return false;

  // reset selection first
  this.clear_selection();

  for (var n in this.rows)
  {
    if (!filter || this.rows[n][filter]==true)
    {
      this.last_selected = n;
      this.highlight_row(n, true);
    }
  }

  return true;  
},


/**
 * Unselect all selected rows
 */
clear_selection: function()
{
  for(var n=0; n<this.selection.length; n++)
    if (this.rows[this.selection[n]])
    {
      this.set_classname(this.rows[this.selection[n]].obj, 'selected', false);
      this.set_classname(this.rows[this.selection[n]].obj, 'unfocused', false);
    }

  this.selection = new Array();    
},


/**
 * Getter for the selection array
 */
get_selection: function()
{
  return this.selection;
},


/**
 * Return the ID if only one row is selected
 */
get_single_selection: function()
{
  if (this.selection.length == 1)
    return this.selection[0];
  else
    return null;
},


/**
 * Highlight/unhighlight a row
 */
highlight_row: function(id, multiple)
{
  if (this.rows[id] && !multiple)
  {
    this.clear_selection();
    this.selection[0] = id;
    this.set_classname(this.rows[id].obj, 'selected', true)
  }
  else if (this.rows[id])
  {
    if (!this.in_selection(id))  // select row
    {
      this.selection[this.selection.length] = id;
      this.set_classname(this.rows[id].obj, 'selected', true);
    }
    else  // unselect row
    {
      var p = find_in_array(id, this.selection);
      var a_pre = this.selection.slice(0, p);
      var a_post = this.selection.slice(p+1, this.selection.length);
      this.selection = a_pre.concat(a_post);
      this.set_classname(this.rows[id].obj, 'selected', false);
      this.set_classname(this.rows[id].obj, 'unfocused', false);
    }
  }
},


/**
 * Handler for keyboard events
 */
key_press: function(e)
{
  if (this.focused != true) 
    return true;

  this.shiftkey = e.shiftKey;

  var keyCode = document.layers ? e.which : document.all ? event.keyCode : document.getElementById ? e.keyCode : 0;
  var mod_key = rcube_event.get_modifier(e);
  switch (keyCode)
  {
    case 40:
    case 38: 
      return this.use_arrow_key(keyCode, mod_key);
      break;

    default:
      this.key_pressed = keyCode;
      this.trigger_event('keypress');
  }
  
  return true;
},


/**
 * Special handling method for arrow keys
 */
use_arrow_key: function(keyCode, mod_key)
{
  var new_row;
  if (keyCode == 40) // down arrow key pressed
    new_row = this.get_next_row();
  else if (keyCode == 38) // up arrow key pressed
    new_row = this.get_prev_row();

  if (new_row)
  {
    this.select_row(new_row.uid, mod_key, true);
    this.scrollto(new_row.uid);
  }

  return false;
},


/**
 * Try to scroll the list to make the specified row visible
 */
scrollto: function(id)
{
  var row = this.rows[id].obj;
  if (row && this.frame)
  {
    var scroll_to = Number(row.offsetTop);

    if (scroll_to < Number(this.frame.scrollTop))
      this.frame.scrollTop = scroll_to;
    else if (scroll_to + Number(row.offsetHeight) > Number(this.frame.scrollTop) + Number(this.frame.offsetHeight))
      this.frame.scrollTop = (scroll_to + Number(row.offsetHeight)) - Number(this.frame.offsetHeight);
  }
},


/**
 * Handler for mouse move events
 */
drag_mouse_move: function(e)
{
  if (this.drag_start)
  {
    // check mouse movement, of less than 3 pixels, don't start dragging
    var m = rcube_event.get_mouse_pos(e);
    if (!this.drag_mouse_start || (Math.abs(m.x - this.drag_mouse_start.x) < 3 && Math.abs(m.y - this.drag_mouse_start.y) < 3))
      return false;
  
    if (!this.draglayer)
      this.draglayer = new rcube_layer('rcmdraglayer', {x:0, y:0, width:300, vis:0, zindex:2000});
  
    // get subjects of selectedd messages
    var names = '';
    var c, subject, obj;
    for(var n=0; n<this.selection.length; n++)
    {
      if (n>12)  // only show 12 lines
      {
        names += '...';
        break;
      }

      if (this.rows[this.selection[n]].obj)
      {
        obj = this.rows[this.selection[n]].obj;
        subject = '';

        for(c=0; c<obj.childNodes.length; c++)
          if (!subject && obj.childNodes[c].nodeName=='TD' && obj.childNodes[c].firstChild && obj.childNodes[c].firstChild.nodeType==3)
          {
            subject = obj.childNodes[c].firstChild.data;
            names += (subject.length > 50 ? subject.substring(0, 50)+'...' : subject) + '<br />';
          }
      }
    }

    this.draglayer.write(names);
    this.draglayer.show(1);

    this.drag_active = true;
    this.trigger_event('dragstart');
  }

  if (this.drag_active && this.draglayer)
  {
    var pos = rcube_event.get_mouse_pos(e);
    this.draglayer.move(pos.x+20, pos.y-5);
  }

  this.drag_start = false;

  return false;
},


/**
 * Handler for mouse up events
 */
drag_mouse_up: function(e)
{
  document.onmousemove = null;

  if (this.draglayer && this.draglayer.visible)
    this.draglayer.show(0);

  this.drag_active = false;
  this.trigger_event('dragend');

  rcube_event.remove_listener({element:document, event:'mousemove', object:this, method:'drag_mouse_move'});
  rcube_event.remove_listener({element:document, event:'mouseup', object:this, method:'drag_mouse_up'});

  return rcube_event.cancel(e);
},



/**
 * set/unset a specific class name
 */
set_classname: function(obj, classname, set)
{
  var reg = new RegExp('\s*'+classname, 'i');
  if (!set && obj.className.match(reg))
    obj.className = obj.className.replace(reg, '');
  else if (set && !obj.className.match(reg))
    obj.className += ' '+classname;
},


/**
 * Setter for object event handlers
 *
 * @param {String}   Event name
 * @param {Function} Handler function
 * @return Listener ID (used to remove this handler later on)
 */
addEventListener: function(evt, handler)
{
  if (this.events[evt]) {
    var handle = this.events[evt].length;
    this.events[evt][handle] = handler;
    return handle;
  }
  else
    return false;
},


/**
 * Removes a specific event listener
 *
 * @param {String} Event name
 * @param {Int}    Listener ID to remove
 */
removeEventListener: function(evt, handle)
{
  if (this.events[evt] && this.events[evt][handle])
    this.events[evt][handle] = null;
},


/**
 * This will execute all registered event handlers
 * @private
 */
trigger_event: function(evt)
{
  if (this.events[evt] && this.events[evt].length) {
    for (var i=0; i<this.events[evt].length; i++)
      if (typeof(this.events[evt][i]) == 'function')
        this.events[evt][i](this);
  }
}


};

