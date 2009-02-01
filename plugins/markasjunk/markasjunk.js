/* Mark-as-Junk plugin script */

function rcmail_markasjunk(prop)
{
  if (!rcmail.env.uid && (!rcmail.message_list || !rcmail.message_list.get_selection().length))
    return;
  
    var uids = rcmail.env.uid ? rcmail.env.uid : rcmail.message_list.get_selection().join(',');
    
    rcmail.set_busy(true, 'loading');
    rcmail.http_post('plugin.markasjunk', '_uid='+uids+'&_mbox='+urlencode(rcmail.env.mailbox), true);
}

// callback for app-onload event
if (window.rcmail) {
  rcmail.add_onload(function(){
    
    // create button
    var button = $('<A>').html('<img src="plugins/markasjunk/junk_pas.png" id="rcmButtonMarkAsJunk" width="32" height="32" alt="" title="" />').css('cursor', 'pointer');
    button.bind('click', function(e){ return rcmail.command('plugin.markasjunk', this) });
    
    // add and register
    rcmail.add_element(button, 'toolbar');
    rcmail.register_button('plugin.markasjunk', 'rcmButtonMarkAsJunk', 'image', 'plugins/markasjunk/junk_act.png');
    rcmail.register_command('plugin.markasjunk', rcmail_markasjunk, rcmail.env.uid);
    
    // add event-listener to message list
    if (rcmail.message_list)
      rcmail.message_list.addEventListener('select', function(list){
        rcmail.enable_command('plugin.markasjunk', list.get_selection().length > 0);
      });
  })
}

