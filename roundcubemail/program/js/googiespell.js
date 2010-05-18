/*
 SpellCheck
    jQuery'fied spell checker based on GoogieSpell 4.0
 Copyright Amir Salihefendic 2006
 Copyright Aleksander Machniak 2009
     LICENSE
         GPL
     AUTHORS
         4mir Salihefendic (http://amix.dk) - amix@amix.dk
	 Aleksander Machniak - alec [at] alec.pl
*/

var SPELL_CUR_LANG = null;
var GOOGIE_DEFAULT_LANG = 'en';

function GoogieSpell(img_dir, server_url) {
    var ref = this;

    this.array_keys = function(arr) {
	var res = [];
	for (var key in arr) { res.push([key]); }
	return res;
    }
    
    var cookie_value = getCookie('language');
    GOOGIE_CUR_LANG = cookie_value != null ? cookie_value : GOOGIE_DEFAULT_LANG;

    this.img_dir = img_dir;
    this.server_url = server_url;

    this.org_lang_to_word = {
	"da": "Dansk", "de": "Deutsch", "en": "English",
        "es": "Espa&#241;ol", "fr": "Fran&#231;ais", "it": "Italiano", 
        "nl": "Nederlands", "pl": "Polski", "pt": "Portugu&#234;s",
        "fi": "Suomi", "sv": "Svenska"
    };
    this.lang_to_word = this.org_lang_to_word;
    this.langlist_codes = this.array_keys(this.lang_to_word);
    this.show_change_lang_pic = true;
    this.change_lang_pic_placement = 'right';
    this.report_state_change = true;

    this.ta_scroll_top = 0;
    this.el_scroll_top = 0;

    this.lang_chck_spell = "Check spelling";
    this.lang_revert = "Revert to";
    this.lang_close = "Close";
    this.lang_rsm_edt = "Resume editing";
    this.lang_no_error_found = "No spelling errors found";
    this.lang_no_suggestions = "No suggestions";
    
    this.show_spell_img = false; // roundcube mod.
    this.decoration = true;
    this.use_close_btn = true;
    this.edit_layer_dbl_click = true;
    this.report_ta_not_found = true;

    //Extensions
    this.custom_ajax_error = null;
    this.custom_no_spelling_error = null;
    this.custom_menu_builder = []; //Should take an eval function and a build menu function
    this.custom_item_evaulator = null; //Should take an eval function and a build menu function
    this.extra_menu_items = [];
    this.custom_spellcheck_starter = null;
    this.main_controller = true;

    //Observers
    this.lang_state_observer = null;
    this.spelling_state_observer = null;
    this.show_menu_observer = null;
    this.all_errors_fixed_observer = null;

    //Focus links - used to give the text box focus
    this.use_focus = false;
    this.focus_link_t = null;
    this.focus_link_b = null;

    //Counters
    this.cnt_errors = 0;
    this.cnt_errors_fixed = 0;
    
    //Set document's onclick to hide the language and error menu
    $(document).bind('click', function(e) {
        if($(e.target).attr('googie_action_btn') != '1' && ref.isLangWindowShown())
	    ref.hideLangWindow();
	if($(e.target).attr('googie_action_btn') != '1' && ref.isErrorWindowShown())
            ref.hideErrorWindow();
    });


this.decorateTextarea = function(id) {
    this.text_area = typeof(id) == 'string' ? document.getElementById(id) : id;

    if (this.text_area) {
        if (!this.spell_container && this.decoration) {
            var table = document.createElement('table');
            var tbody = document.createElement('tbody');
            var tr = document.createElement('tr');
            var spell_container = document.createElement('td');

            var r_width = this.isDefined(this.force_width) ? this.force_width : this.text_area.offsetWidth;
            var r_height = this.isDefined(this.force_height) ? this.force_height : 16;

            tr.appendChild(spell_container);
            tbody.appendChild(tr);
            $(table).append(tbody).insertBefore(this.text_area).width('100%').height(r_height);
            $(spell_container).height(r_height).width(r_width).css('text-align', 'right');

            this.spell_container = spell_container;
        }

        this.checkSpellingState();
    }
    else 
        if (this.report_ta_not_found)
            alert('Text area not found');
}

//////
// API Functions (the ones that you can call)
/////
this.setSpellContainer = function(id) {
    this.spell_container = typeof(id) == 'string' ? document.getElementById(id) : id;
}

this.setLanguages = function(lang_dict) {
    this.lang_to_word = lang_dict;
    this.langlist_codes = this.array_keys(lang_dict);
}

this.setCurrentLanguage = function(lan_code) {
    GOOGIE_CUR_LANG = lan_code;

    //Set cookie
    var now = new Date();
    now.setTime(now.getTime() + 365 * 24 * 60 * 60 * 1000);
    setCookie('language', lan_code, now);
}

this.setForceWidthHeight = function(width, height) {
    // Set to null if you want to use one of them
    this.force_width = width;
    this.force_height = height;
}

this.setDecoration = function(bool) {
    this.decoration = bool;
}

this.dontUseCloseButtons = function() {
    this.use_close_btn = false;
}

this.appendNewMenuItem = function(name, call_back_fn, checker) {
    this.extra_menu_items.push([name, call_back_fn, checker]);
}

this.appendCustomMenuBuilder = function(eval, builder) {
    this.custom_menu_builder.push([eval, builder]);
}

this.setFocus = function() {
    try {
        this.focus_link_b.focus();
        this.focus_link_t.focus();
        return true;
    }
    catch(e) {
        return false;
    }
}


//////
// Set functions (internal)
/////
this.setStateChanged = function(current_state) {
    this.state = current_state;
    if (this.spelling_state_observer != null && this.report_state_change)
        this.spelling_state_observer(current_state, this);
}

this.setReportStateChange = function(bool) {
    this.report_state_change = bool;
}


//////
// Request functions
/////
this.getUrl = function() {
    return this.server_url + GOOGIE_CUR_LANG;
}

this.escapeSpecial = function(val) {
    return val.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
}

this.createXMLReq = function (text) {
    return '<?xml version="1.0" encoding="utf-8" ?>'
	+ '<spellrequest textalreadyclipped="0" ignoredups="0" ignoredigits="1" ignoreallcaps="1">'
	+ '<text>' + text + '</text></spellrequest>';
}

this.spellCheck = function(ignore) {
    this.cnt_errors_fixed = 0;
    this.cnt_errors = 0;
    this.setStateChanged('checking_spell');

    if (this.main_controller)
        this.appendIndicator(this.spell_span);

    this.error_links = [];
    this.ta_scroll_top = this.text_area.scrollTop;
    this.ignore = ignore;
    this.hideLangWindow();

    if ($(this.text_area).val() == '' || ignore) {
        if (!this.custom_no_spelling_error)
            this.flashNoSpellingErrorState();
        else
            this.custom_no_spelling_error(this);
        this.removeIndicator();
        return;
    }

    this.createEditLayer(this.text_area.offsetWidth, this.text_area.offsetHeight);
    this.createErrorWindow();
    $('body').append(this.error_window);

    try { netscape.security.PrivilegeManager.enablePrivilege("UniversalBrowserRead"); } 
    catch (e) { }

    if (this.main_controller)
        $(this.spell_span).unbind('click');

    this.orginal_text = $(this.text_area).val();
    var req_text = this.escapeSpecial(this.orginal_text);
    var ref = this;

    $.ajax({ type: 'POST', url: this.getUrl(),
	data: this.createXMLReq(req_text), dataType: 'text',
	error: function(o) {
    	    if (ref.custom_ajax_error)
        	ref.custom_ajax_error(ref);
    	    else
        	alert('An error was encountered on the server. Please try again later.');
    	    if (ref.main_controller) {
        	$(ref.spell_span).remove();
        	ref.removeIndicator();
    	    }
    	    ref.checkSpellingState();
	},
        success: function(data) {
	    var r_text = data;
    	    ref.results = ref.parseResult(r_text);
    	    if (r_text.match(/<c.*>/) != null) {
        	//Before parsing be sure that errors were found
        	ref.showErrorsInIframe();
        	ref.resumeEditingState();
    	    } else {
        	if (!ref.custom_no_spelling_error)
		    ref.flashNoSpellingErrorState();
        	else
            	    ref.custom_no_spelling_error(ref);
    	    }
    	    ref.removeIndicator();
	}
    });
}


//////
// Spell checking functions
/////
this.parseResult = function(r_text) {
    // Returns an array: result[item] -> ['attrs'], ['suggestions']
    var re_split_attr_c = /\w+="(\d+|true)"/g;
    var re_split_text = /\t/g;

    var matched_c = r_text.match(/<c[^>]*>[^<]*<\/c>/g);
    var results = new Array();

    if (matched_c == null)
        return results;
    
    for (var i=0; i < matched_c.length; i++) {
        var item = new Array();
        this.errorFound();

        //Get attributes
        item['attrs'] = new Array();
        var split_c = matched_c[i].match(re_split_attr_c);
        for (var j=0; j < split_c.length; j++) {
            var c_attr = split_c[j].split(/=/);
            var val = c_attr[1].replace(/"/g, '');
            item['attrs'][c_attr[0]] = val != 'true' ? parseInt(val) : val;
        }

        //Get suggestions
        item['suggestions'] = new Array();
        var only_text = matched_c[i].replace(/<[^>]*>/g, '');
        var split_t = only_text.split(re_split_text);
        for (var k=0; k < split_t.length; k++) {
    	    if(split_t[k] != '')
        	item['suggestions'].push(split_t[k]);
    	}
        results.push(item);
    }

    return results;
}


//////
// Error menu functions
/////
this.createErrorWindow = function() {
    this.error_window = document.createElement('div');
    $(this.error_window).addClass('googie_window').attr('googie_action_btn', '1');
}

this.isErrorWindowShown = function() {
    return $(this.error_window).is(':visible');
}

this.hideErrorWindow = function() {
    $(this.error_window).css('visibility', 'hidden');
    $(this.error_window_iframe).css('visibility', 'hidden');
}

this.updateOrginalText = function(offset, old_value, new_value, id) {
    var part_1 = this.orginal_text.substring(0, offset);
    var part_2 = this.orginal_text.substring(offset+old_value.length);
    this.orginal_text = part_1 + new_value + part_2;
    $(this.text_area).val(this.orginal_text);
    var add_2_offset = new_value.length - old_value.length;
    for (var j=0; j < this.results.length; j++) {
        //Don't edit the offset of the current item
        if (j != id && j > id)
            this.results[j]['attrs']['o'] += add_2_offset;
    }
}

this.saveOldValue = function(elm, old_value) {
    elm.is_changed = true;
    elm.old_value = old_value;
}

this.createListSeparator = function() {
    var td = document.createElement('td');
    var tr = document.createElement('tr');

    $(td).html(' ').attr('googie_action_btn', '1')
	.css({'cursor': 'default', 'font-size': '3px', 'border-top': '1px solid #ccc', 'padding-top': '3px'});
    tr.appendChild(td);

    return tr;
}

this.correctError = function(id, elm, l_elm, rm_pre_space) {
    var old_value = elm.innerHTML;
    var new_value = l_elm.nodeType == 3 ? l_elm.nodeValue : l_elm.innerHTML;
    var offset = this.results[id]['attrs']['o'];

    if (rm_pre_space) {
        var pre_length = elm.previousSibling.innerHTML;
        elm.previousSibling.innerHTML = pre_length.slice(0, pre_length.length-1);
        old_value = " " + old_value;
        offset--;
    }

    this.hideErrorWindow();
    this.updateOrginalText(offset, old_value, new_value, id);

    $(elm).html(new_value).css('color', 'green').attr('is_corrected', true);

    this.results[id]['attrs']['l'] = new_value.length;

    if (!this.isDefined(elm.old_value))
        this.saveOldValue(elm, old_value);
    
    this.errorFixed();
}

this.showErrorWindow = function(elm, id) {
    if (this.show_menu_observer)
        this.show_menu_observer(this);

    var ref = this;
    var pos = $(elm).offset();
    pos.top -= this.edit_layer.scrollTop;

    $(this.error_window).css({'visibility': 'visible',
	'top': (pos.top+20)+'px', 'left': (pos.left)+'px'}).html('');

    var table = document.createElement('table');
    var list = document.createElement('tbody');

    $(table).addClass('googie_list').attr('googie_action_btn', '1');

    //Check if we should use custom menu builder, if not we use the default
    var changed = false;
    for (var k=0; k<this.custom_menu_builder.length; k++) {
        var eb = this.custom_menu_builder[k];
        if(eb[0]((this.results[id]))){
            changed = eb[1](this, list, elm);
            break;
        }
    }
    if (!changed) {
        //Build up the result list
        var suggestions = this.results[id]['suggestions'];
        var offset = this.results[id]['attrs']['o'];
        var len = this.results[id]['attrs']['l'];

        if (suggestions.length == 0) {
            var row = document.createElement('tr');
            var item = document.createElement('td');
            var dummy = document.createElement('span');

            $(dummy).text(this.lang_no_suggestions);
            $(item).attr('googie_action_btn', '1').css('cursor', 'default');

            item.appendChild(dummy);
            row.appendChild(item);
            list.appendChild(row);
        }

        for (i=0; i < suggestions.length; i++) {
            var row = document.createElement('tr');
            var item = document.createElement('td');
            var dummy = document.createElement('span');

            $(dummy).html(suggestions[i]);
            
            $(item).bind('mouseover', this.item_onmouseover)
        	.bind('mouseout', this.item_onmouseout)
        	.bind('click', function(e) { ref.correctError(id, elm, e.target.firstChild) });

            item.appendChild(dummy);
            row.appendChild(item);
            list.appendChild(row);
        }

        //The element is changed, append the revert
        if (elm.is_changed && elm.innerHTML != elm.old_value) {
            var old_value = elm.old_value;
            var revert_row = document.createElement('tr');
            var revert = document.createElement('td');
            var rev_span = document.createElement('span');
	    
	    $(rev_span).addClass('googie_list_revert').html(this.lang_revert + ' ' + old_value);

            $(revert).bind('mouseover', this.item_onmouseover)
        	.bind('mouseout', this.item_onmouseout)
        	.bind('click', function(e) {
            	    ref.updateOrginalText(offset, elm.innerHTML, old_value, id);
            	    $(elm).attr('is_corrected', true).css('color', '#b91414').html(old_value);
            	    ref.hideErrorWindow();
        	});

            revert.appendChild(rev_span);
            revert_row.appendChild(revert);
            list.appendChild(revert_row);
        }

        //Append the edit box
        var edit_row = document.createElement('tr');
        var edit = document.createElement('td');
        var edit_input = document.createElement('input');
        var ok_pic = document.createElement('img');
	var edit_form = document.createElement('form');

        var onsub = function () {
            if (edit_input.value != '') {
                if (!ref.isDefined(elm.old_value))
                    ref.saveOldValue(elm, elm.innerHTML);

                ref.updateOrginalText(offset, elm.innerHTML, edit_input.value, id);
		$(elm).attr('is_corrected', true).css('color', 'green').html(edit_input.value);
                ref.hideErrorWindow();
            }
            return false;
        };

	$(edit_input).width(120).css({'margin': 0, 'padding': 0});
	$(edit_input).val(elm.innerHTML).attr('googie_action_btn', '1');
	$(edit).css('cursor', 'default').attr('googie_action_btn', '1');

	$(ok_pic).attr('src', this.img_dir + 'ok.gif')
	    .width(32).height(16)
	    .css({'cursor': 'pointer', 'margin-left': '2px', 'margin-right': '2px'})
	    .bind('click', onsub);

        $(edit_form).attr('googie_action_btn', '1')
	    .css({'margin': 0, 'padding': 0, 'cursor': 'default', 'white-space': 'nowrap'})
	    .bind('submit', onsub);
        
	edit_form.appendChild(edit_input);
	edit_form.appendChild(ok_pic);
        edit.appendChild(edit_form);
        edit_row.appendChild(edit);
        list.appendChild(edit_row);

        //Append extra menu items
        if (this.extra_menu_items.length > 0)
	    list.appendChild(this.createListSeparator());
	
        var loop = function(i) {
                if (i < ref.extra_menu_items.length) {
                    var e_elm = ref.extra_menu_items[i];

                    if (!e_elm[2] || e_elm[2](elm, ref)) {
                        var e_row = document.createElement('tr');
                        var e_col = document.createElement('td');

			$(e_col).html(e_elm[0])
                    	    .bind('mouseover', ref.item_onmouseover)
                    	    .bind('mouseout', ref.item_onmouseout)
			    .bind('click', function() { return e_elm[1](elm, ref) });
			
			e_row.appendChild(e_col);
                        list.appendChild(e_row);
                    }
                    loop(i+1);
                }
        }
	
        loop(0);
        loop = null;

        //Close button
        if (this.use_close_btn) {
    	    list.appendChild(this.createCloseButton(this.hideErrorWindow));
        }
    }

    table.appendChild(list);
    this.error_window.appendChild(table);

    //Dummy for IE - dropdown bug fix
    if ($.browser.msie) {
	if (!this.error_window_iframe) {
            var iframe = $('<iframe>').css({'position': 'absolute', 'z-index': -1});
	    $('body').append(iframe);
    	    this.error_window_iframe = iframe;
        }

	$(this.error_window_iframe).css({'visibility': 'visible',
	    'top': this.error_window.offsetTop, 'left': this.error_window.offsetLeft,
    	    'width': this.error_window.offsetWidth, 'height': this.error_window.offsetHeight});
    }
}


//////
// Edit layer (the layer where the suggestions are stored)
//////
this.createEditLayer = function(width, height) {
    this.edit_layer = document.createElement('div');
    $(this.edit_layer).addClass('googie_edit_layer').width(width-10).height(height);

    if (this.text_area.nodeName.toLowerCase() != 'input' || $(this.text_area).val() == '') {
        $(this.edit_layer).css('overflow', 'auto').height(height-4);
    } else {
        $(this.edit_layer).css('overflow', 'hidden');
    }

    var ref = this;
    if (this.edit_layer_dbl_click) {
        $(this.edit_layer).bind('click', function(e) {
            if (e.target.className != 'googie_link' && !ref.isErrorWindowShown()) {
                ref.resumeEditing();
                var fn1 = function() {
                    $(ref.text_area).focus();
                    fn1 = null;
                };
                window.setTimeout(fn1, 10);
            }
            return false;
        });
    }
}

this.resumeEditing = function() {
    this.setStateChanged('ready');

    if (this.edit_layer)
        this.el_scroll_top = this.edit_layer.scrollTop;

    this.hideErrorWindow();

    if (this.main_controller)
        $(this.spell_span).removeClass().addClass('googie_no_style');

    if (!this.ignore) {
        if (this.use_focus) {
            $(this.focus_link_t).remove();
            $(this.focus_link_b).remove();
        }

        $(this.edit_layer).remove();
        $(this.text_area).show();

        if (this.el_scroll_top != undefined)
            this.text_area.scrollTop = this.el_scroll_top;
    }
    this.checkSpellingState(false);
}

this.createErrorLink = function(text, id) {
    var elm = document.createElement('span');
    var ref = this;
    var d = function (e) {
    	    ref.showErrorWindow(elm, id);
    	    d = null;
    	    return false;
    };
    
    $(elm).html(text).addClass('googie_link').bind('click', d)
	.attr({'googie_action_btn' : '1', 'g_id' : id, 'is_corrected' : false});

    return elm;
}

this.createPart = function(txt_part) {
    if (txt_part == " ")
        return document.createTextNode(" ");

    txt_part = this.escapeSpecial(txt_part);
    txt_part = txt_part.replace(/\n/g, "<br>");
    txt_part = txt_part.replace(/    /g, " &nbsp;");
    txt_part = txt_part.replace(/^ /g, "&nbsp;");
    txt_part = txt_part.replace(/ $/g, "&nbsp;");
    
    var span = document.createElement('span');
    $(span).html(txt_part);
    return span;
}

this.showErrorsInIframe = function() {
    var output = document.createElement('div')
    var pointer = 0;
    var results = this.results;

    if (results.length > 0) {
        for (var i=0; i < results.length; i++) {
            var offset = results[i]['attrs']['o'];
            var len = results[i]['attrs']['l'];
            var part_1_text = this.orginal_text.substring(pointer, offset);
            var part_1 = this.createPart(part_1_text);
    
            output.appendChild(part_1);
            pointer += offset - pointer;
            
            //If the last child was an error, then insert some space
            var err_link = this.createErrorLink(this.orginal_text.substr(offset, len), i);
            this.error_links.push(err_link);
            output.appendChild(err_link);
            pointer += len;
        }
        //Insert the rest of the orginal text
        var part_2_text = this.orginal_text.substr(pointer, this.orginal_text.length);
        var part_2 = this.createPart(part_2_text);

        output.appendChild(part_2);
    }
    else
        output.innerHTML = this.orginal_text;

    $(output).css('text-align', 'left');

    var me = this;
    if (this.custom_item_evaulator)
        $.map(this.error_links, function(elm){me.custom_item_evaulator(me, elm)});
    
    $(this.edit_layer).append(output);

    //Hide text area and show edit layer
    $(this.text_area).hide();
    $(this.edit_layer).insertBefore(this.text_area);

    if (this.use_focus) {
        this.focus_link_t = this.createFocusLink('focus_t');
        this.focus_link_b = this.createFocusLink('focus_b');

        $(this.focus_link_t).insertBefore(this.edit_layer);
        $(this.focus_link_b).insertAfter(this.edit_layer);
    }

//    this.edit_layer.scrollTop = this.ta_scroll_top;
}


//////
// Choose language menu
//////
this.createLangWindow = function() {
    this.language_window = document.createElement('div');
    $(this.language_window).addClass('googie_window')
	.width(100).attr('googie_action_btn', '1');

    //Build up the result list
    var table = document.createElement('table');
    var list = document.createElement('tbody');
    var ref = this;

    $(table).addClass('googie_list').width('100%');
    this.lang_elms = new Array();

    for (i=0; i < this.langlist_codes.length; i++) {
        var row = document.createElement('tr');
        var item = document.createElement('td');
        var span = document.createElement('span');
	
	$(span).text(this.lang_to_word[this.langlist_codes[i]]);
        this.lang_elms.push(item);

        $(item).attr('googieId', this.langlist_codes[i])
    	    .bind('click', function(e) {
        	ref.deHighlightCurSel();
        	ref.setCurrentLanguage($(this).attr('googieId'));

        	if (ref.lang_state_observer != null) {
            	    ref.lang_state_observer();
        	}

        	ref.highlightCurSel();
        	ref.hideLangWindow();
    	    })
    	    .bind('mouseover', function(e) { 
        	if (this.className != "googie_list_selected")
            	    this.className = "googie_list_onhover";
    	    })
    	    .bind('mouseout', function(e) { 
        	if (this.className != "googie_list_selected")
            	    this.className = "googie_list_onout"; 
    	    });

	item.appendChild(span);
        row.appendChild(item);
        list.appendChild(row);
    }

    //Close button
    if (this.use_close_btn) {
        list.appendChild(this.createCloseButton(function () { ref.hideLangWindow.apply(ref) }));
    }

    this.highlightCurSel();

    table.appendChild(list);
    this.language_window.appendChild(table);
}

this.isLangWindowShown = function() {
    return $(this.language_window).is(':hidden');
}

this.hideLangWindow = function() {
    $(this.language_window).css('visibility', 'hidden');
    $(this.switch_lan_pic).removeClass().addClass('googie_lang_3d_on');
}

this.deHighlightCurSel = function() {
    $(this.lang_cur_elm).removeClass().addClass('googie_list_onout');
}

this.highlightCurSel = function() {
    if (GOOGIE_CUR_LANG == null)
        GOOGIE_CUR_LANG = GOOGIE_DEFAULT_LANG;
    for (var i=0; i < this.lang_elms.length; i++) {
        if ($(this.lang_elms[i]).attr('googieId') == GOOGIE_CUR_LANG) {
            this.lang_elms[i].className = "googie_list_selected";
            this.lang_cur_elm = this.lang_elms[i];
        }
        else {
            this.lang_elms[i].className = "googie_list_onout";
        }
    }
}

this.showLangWindow = function(elm) {
    if (this.show_menu_observer)
        this.show_menu_observer(this);

    this.createLangWindow();
    $('body').append(this.language_window);

    var pos = $(elm).offset();
    var top = pos.top + $(elm).height();
    var left = this.change_lang_pic_placement == 'right' ? 
	pos.left - 100 + $(elm).width() : pos.left + $(elm).width();

    $(this.language_window).css({'visibility': 'visible', 'top' : top+'px','left' : left+'px'});

    this.highlightCurSel();
}

this.createChangeLangPic = function() {
    var img = $('<img>')
	.attr({src: this.img_dir + 'change_lang.gif', 'alt': 'Change language', 'googie_action_btn': '1'});

    var switch_lan = document.createElement('span');
    var ref = this;

    $(switch_lan).addClass('googie_lang_3d_on')
	.append(img)
	.bind('click', function(e) {
    	    var elm = this.tagName.toLowerCase() == 'img' ? this.parentNode : this;
    	    if($(elm).hasClass('googie_lang_3d_click')) {
        	elm.className = 'googie_lang_3d_on';
        	ref.hideLangWindow();
    	    }
    	    else {
        	elm.className = 'googie_lang_3d_click';
        	ref.showLangWindow(elm);
    	    }
	});

    return switch_lan;
}

this.createSpellDiv = function() {
    var span = document.createElement('span');

    $(span).addClass('googie_check_spelling_link').text(this.lang_chck_spell);

    if (this.show_spell_img) {
	$(span).append(' ').append($('<img>').attr('src', this.img_dir + 'spellc.gif'));
    }
    return span;
}


//////
// State functions
/////
this.flashNoSpellingErrorState = function(on_finish) {
    this.setStateChanged('no_error_found');

    var ref = this;
    if (this.main_controller) {
	var no_spell_errors;
	if (on_finish) {
    	    var fn = function() {
        	on_finish();
        	ref.checkSpellingState();
    	    };
    	    no_spell_errors = fn;
	}
	else
    	    no_spell_errors = function () { ref.checkSpellingState() };

        var rsm = $('<span>').text(this.lang_no_error_found);

        $(this.switch_lan_pic).hide();
	    $(this.spell_span).empty().append(rsm)
	    .removeClass().addClass('googie_check_spelling_ok');

        window.setTimeout(no_spell_errors, 1000);
    }
}

this.resumeEditingState = function() {
    this.setStateChanged('resume_editing');

    //Change link text to resume
    if (this.main_controller) {
        var rsm = $('<span>').text(this.lang_rsm_edt);
	var ref = this;

        $(this.switch_lan_pic).hide();
        $(this.spell_span).empty().unbind().append(rsm)
    	    .bind('click', function() { ref.resumeEditing() })
    	    .removeClass().addClass('googie_resume_editing');
    }

    try { this.edit_layer.scrollTop = this.ta_scroll_top; }
    catch (e) {};
}

this.checkSpellingState = function(fire) {
    if (fire)
        this.setStateChanged('ready');

    if (this.show_change_lang_pic)
        this.switch_lan_pic = this.createChangeLangPic();
    else
        this.switch_lan_pic = document.createElement('span');

    var span_chck = this.createSpellDiv();
    var ref = this;

    if (this.custom_spellcheck_starter)
        $(span_chck).bind('click', function(e) { ref.custom_spellcheck_starter() });
    else {
        $(span_chck).bind('click', function(e) { ref.spellCheck() });
    }

    if (this.main_controller) {
        if (this.change_lang_pic_placement == 'left') {
	    $(this.spell_container).empty().append(this.switch_lan_pic).append(' ').append(span_chck);
        } else {
	    $(this.spell_container).empty().append(span_chck).append(' ').append(this.switch_lan_pic);
	}
    }

    this.spell_span = span_chck;
}


//////
// Misc. functions
/////
this.isDefined = function(o) {
    return (o != 'undefined' && o != null)
}

this.errorFixed = function() { 
    this.cnt_errors_fixed++; 
    if (this.all_errors_fixed_observer)
        if (this.cnt_errors_fixed == this.cnt_errors) {
            this.hideErrorWindow();
            this.all_errors_fixed_observer();
        }
}

this.errorFound = function() {
    this.cnt_errors++;
}

this.createCloseButton = function(c_fn) {
    return this.createButton(this.lang_close, 'googie_list_close', c_fn);
}

this.createButton = function(name, css_class, c_fn) {
    var btn_row = document.createElement('tr');
    var btn = document.createElement('td');
    var spn_btn;

    if (css_class) {
        spn_btn = document.createElement('span');
	$(spn_btn).addClass(css_class).html(name);
    } else {
        spn_btn = document.createTextNode(name);
    }

    $(btn).bind('click', c_fn)
	.bind('mouseover', this.item_onmouseover)
	.bind('mouseout', this.item_onmouseout);

    btn.appendChild(spn_btn);
    btn_row.appendChild(btn);

    return btn_row;
}

this.removeIndicator = function(elm) {
    //$(this.indicator).remove();
    // roundcube mod.
    if (window.rcmail)
	rcmail.set_busy(false);
}

this.appendIndicator = function(elm) {
    // modified by roundcube
    if (window.rcmail)
	rcmail.set_busy(true, 'checking');
/*    
    this.indicator = document.createElement('img');
    $(this.indicator).attr('src', this.img_dir + 'indicator.gif')
	.css({'margin-right': '5px', 'text-decoration': 'none'}).width(16).height(16);
    
    if (elm)
	$(this.indicator).insertBefore(elm);
    else
	$('body').append(this.indicator);
*/				    
}

this.createFocusLink = function(name) {
    var link = document.createElement('a');
    $(link).attr({'href': 'javascript:;', 'name': name});
    return link;
}

this.item_onmouseover = function(e) {
    if (this.className != "googie_list_revert" && this.className != "googie_list_close")
        this.className = "googie_list_onhover";
    else
        this.parentNode.className = "googie_list_onhover";
}
this.item_onmouseout = function(e) {
    if (this.className != "googie_list_revert" && this.className != "googie_list_close")
        this.className = "googie_list_onout";
    else
        this.parentNode.className = "googie_list_onout";
}


};
