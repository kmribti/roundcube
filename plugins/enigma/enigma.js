/* Enigma Plugin */

if (window.rcmail)
{
    rcmail.addEventListener('init', function(evt)
    {
        if (rcmail.env.task == 'settings') {
            rcmail.register_command('plugin.enigma', function() { rcmail.goto_url('plugin.enigma') }, true);
            rcmail.register_command('plugin.enigma-key-import', function() { rcmail.enigma_key_import() }, true);
            rcmail.register_command('plugin.enigma-key-export', function() { rcmail.enigma_key_export() }, true);

            if (rcmail.gui_objects.keyslist)
            {
                var p = rcmail;
                rcmail.keys_list = new rcube_list_widget(rcmail.gui_objects.keyslist,
                    {multiselect:false, draggable:false, keyboard:false});
                rcmail.keys_list.addEventListener('select', function(o){ p.enigma_key_select(o); });
                rcmail.keys_list.init();
                rcmail.keys_list.focus();
            }

            if (rcmail.env.action == 'edit-prefs') {
                rcmail.enable_command('search', 'reset-search', true);
                rcmail.addEventListener('beforesearch', 'enigma_search', rcmail);
                rcmail.addEventListener('beforereset-search', 'enigma_search_reset', rcmail);
            }
            else if (rcmail.env.action == 'plugin.enigma') {
                rcmail.register_command('plugin.enigma-import', function() { rcmail.enigma_import() }, true);
                rcmail.register_command('plugin.enigma-export', function() { rcmail.enigma_export() }, true);
            }
        }
    });
}

/*********************************************************/
/*********       Enigma Settings  methods        *********/
/*********************************************************/

// Display key(s) import form
rcube_webmail.prototype.enigma_key_import = function()
{
    this.enigma_loadframe(null, '&_a=keyimport');
};

// Submit key(s) form
rcube_webmail.prototype.enigma_import = function()
{
    var form, file;
    if (form = this.gui_objects.importform) {
        file = document.getElementById('rcmimportfile');
        if (file && !file.value) {
            alert(this.get_label('selectimportfile'));
            return;
        }
        form.submit();
        this.set_busy(true, 'importwait');
        this.lock_form(form, true);
   }
};

// list row selection handler
rcube_webmail.prototype.enigma_key_select = function(list)
{
    var id;
    if (id = list.get_single_selection())
        this.enigma_loadframe(id);
};

// load key frame
rcube_webmail.prototype.enigma_loadframe = function(id, url)
{
    var frm, win;
    if (this.env.contentframe && window.frames && (frm = window.frames[this.env.contentframe])) {
        if (!id && !url && (win = window.frames[this.env.contentframe])) {
            if (win.location && win.location.href.indexOf(this.env.blankpage)<0)
                win.location.href = this.env.blankpage;
            return;
        }
        this.set_busy(true);
        if (!url)
            url = '&_a=keyinfo&_id='+id;
        frm.location.href = this.env.comm_path+'&_action=plugin.enigma&_framed=1' + url;
    }
};

rcube_webmail.prototype.enigma_search = function(props)
{
    if (!props && this.gui_objects.qsearchbox)
        props = this.gui_objects.qsearchbox.value;

    if (props) {
//        if (this.gui_objects.search_filter)
  //          addurl += '&_filter=' + this.gui_objects.search_filter.value;
        this.env.current_page = 1;
        this.set_busy(true, 'searching');
        this.enigma_loadframe();

        // reload page (for keys list refresh)
        this.http_post('plugin.enigma',
            {'_a': 'keysearch', '_q': urlencode(props)},
            true);
    }

    // skip default 'search' command action
    return false;
}

rcube_webmail.prototype.enigma_search_reset = function(props)
{
    var s = this.env.search_request;
    this.reset_qsearch();

    if (s) {
        this.enigma_loadframe();
        // reload page (for keys list refresh)
    }

    // skip default 'reset-search' command action
    return false;
}

/*********************************************************/
/*********        Enigma Message methods         *********/
/*********************************************************/

// Import attached keys/certs file
rcube_webmail.prototype.enigma_import_attachment = function(mime_id)
{
    this.set_busy(true, 'loading');
    this.http_post('plugin.enigmaimport', '_uid='+this.env.uid+'&_mbox='
        +urlencode(this.env.mailbox)+'&_part='+urlencode(mime_id), true);

    return false;
};
