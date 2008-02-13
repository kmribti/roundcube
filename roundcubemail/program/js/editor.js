/*
 +-----------------------------------------------------------------------+
 | RoundCube editor js library                                           |
 |                                                                       |
 | This file is part of the RoundCube web development suite              |
 | Copyright (C) 2006, RoundCube Dev, - Switzerland                      |
 | Licensed under the GNU GPL                                            |
 |                                                                       |
 +-----------------------------------------------------------------------+
 | Author: Eric Stadtherr <estadtherr@gmail.com>                         |
 +-----------------------------------------------------------------------+

 $Id: editor.js 000 2006-05-18 19:12:28Z roundcube $
*/

// Initialize the message editor

function rcmail_editor_init(skin_path)
  {
  tinyMCE.init({ mode : 'specific_textareas',
                 accessibility_focus : false,
                 apply_source_formatting : true,
                 theme : 'advanced',
                 plugins : 'emotions,media,nonbreaking,table,searchreplace,spellchecker,visualchars',
                 theme_advanced_buttons1 : 'bold,italic,underline,separator,justifyleft,justifycenter,justifyright,justifyfull,separator,bullist,numlist,outdent,indent,separator,emotions,charmap,code,forecolor,backcolor,fontselect,fontsizeselect, separator,undo,redo,image,media',
                 theme_advanced_buttons2 : '',
                 theme_advanced_buttons3 : '',
                 theme_advanced_toolbar_location : 'top',
                 theme_advanced_toolbar_align : 'left',
                 extended_valid_elements : 'font[face|size|color|style],span[id|class|align|style]',
                 content_css : skin_path + '/editor_content.css',
                 editor_css : skin_path + '/editor_ui.css',
                 external_image_list_url : 'program/js/editor_images.js'
               });
  }

// Set the state of the HTML/Plain toggles based on the _is_html field value
function rcmail_set_editor_toggle_states()
  {
  // set the editor toggle based on the state of the editor

  var htmlFlag = document.getElementsByName('_is_html')[0];
  var toggles = document.getElementsByName('_editorSelect');
  for(var t=0; t<toggles.length; t++)
    {
	if (toggles[t].value == 'html')
	  {
	  toggles[t].checked = (htmlFlag.value == "1");
	  }
	else
	  {
	  toggles[t].checked = (htmlFlag.value == "0");
	  }
	}
  }

// Toggle between the HTML and Plain Text editors

function rcmail_toggle_editor(toggler)
  {
  var selectedEditor = toggler.value;

  // determine the currently displayed editor

  var htmlFlag = document.getElementsByName('_is_html')[0];
  var currentEditor = htmlFlag.value;

  if (selectedEditor == currentEditor)
    {
    return;
    }

  // do the appropriate conversion

  var composeElement = document.getElementById('compose-body');

  if (selectedEditor == 'html')
    {
    var existingPlainText = composeElement.value;
    var htmlText = "<pre>" + existingPlainText + "</pre>";
    composeElement.value = htmlText;
    tinyMCE.execCommand('mceAddControl', true, '_message');
    htmlFlag.value = "1";
    }
  else
    {
    rcmail.set_busy(true, 'converting');
    var thisMCE = tinyMCE.getInstanceById('_message');
    var existingHtml = tinyMCE.getContent();
    rcmail_html2plain(existingHtml);
    tinyMCE.execCommand('mceRemoveControl', true, '_message');
    htmlFlag.value = "0";
    }
  }

function rcmail_html2plain(htmlText)
  {
  var http_request = new rcube_http_request();

  http_request.onerror = function(o) { rcmail_handle_toggle_error(o); };
  http_request.oncomplete = function(o) { rcmail_set_text_value(o); };
  var url = rcmail.env.bin_path+'html2text.php';
  console.log('HTTP request: ' + url);
  http_request.POST(url, htmlText, 'application/octet-stream');
  }

function rcmail_set_text_value(httpRequest)
  {
  rcmail.set_busy(false);
  var composeElement = document.getElementById('compose-body');
  composeElement.value = httpRequest.get_text();
  }

function rcmail_handle_toggle_error(httpRequest)
  {
  alert('html2text request returned with error ' + httpRequest.xmlhttp.status);
  }
