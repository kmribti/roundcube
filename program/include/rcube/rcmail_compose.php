<?php
/**
 * @todo Create a class.
 * @ignore
 */

function rcmail_compose_headers($attrib)
{
    $registry      = rcube_registry::get_instance();
    $IMAP          = $registry->get('IMAP', 'core');
    $MESSAGE       = $registry->get('MESSAGE', 'core');
    $DB            = $registry->get('DB', 'core');
    $compose_mode  = $registry->get('compose_mode', 'core');
    $sa_recipients = $registry->get('sa_recipients', 'core');

    if (empty($sa_recipients)) {
        $sa_recipients = array();
    }

    list($form_start, $form_end) = get_form_tags($attrib);

    $out = '';
    $part = strtolower($attrib['part']);

    switch ($part) {
        case 'from':
            return rcmail_compose_header_from($attrib);

        case 'to':
            $fname = '_to';
            $header = 'to';

            // we have a set of recipients stored is session
            if (
                ($mailto_id = rcube::get_input_value('_mailto', rcube::INPUT_GET))
                && $_SESSION['mailto'][$mailto_id]
            ) {
                $fvalue = $_SESSION['mailto'][$mailto_id];
            }
            elseif (!empty($_GET['_to'])) {
                $fvalue = rcube::get_input_value('_to', rcube::INPUT_GET);
            }

        case 'cc':
            if (!$fname) {
                $fname = '_cc';
                $header = 'cc';
            }
        case 'bcc':
            if (!$fname) {
                $fname = '_bcc';
                $header = 'bcc';
            }
            $allow_attrib = array('id', 'class', 'style', 'cols', 'rows', 'wrap', 'tabindex');
            $field_type = 'html_textarea';
            break;

        case 'replyto':
        case 'reply-to':
            $fname = '_replyto';
            $allow_attrib = array('id', 'class', 'style', 'size', 'tabindex');
            $field_type = 'html_inputfield';
            break;
    }

    /**
     * Init local variables to keep overview in following control-flow.
     * @ignore
     */
    $reply_to = (string) @$MESSAGE['headers']->replyto;
    $from     = (string) @$MESSAGE['headers']->from;
    $to       = (string) @$MESSAGE['headers']->to;
    $cc       = (string) @$MESSAGE['headers']->cc;
    $bcc      = (string) @$MESSAGE['headers']->bcc;

    if ($fname && !empty($_POST[$fname])) {
        $fvalue = rcube::get_input_value($fname, rcube::INPUT_POST, TRUE);
    }
    else if ($header && $compose_mode == RCUBE_COMPOSE_REPLY) {
        // get recipent address(es) out of the message headers
        if ($header=='to' && !empty($reply_to)) {
            $fvalue = $reply_to;
        }
        else if ($header=='to' && !empty($from)) {
            $fvalue = $from;
        }
        // add recipent of original message if reply to all
        else if ($header=='cc' && !empty($MESSAGE['reply_all'])) {
            if ($v = $to) {
                $fvalue .= $v;
            }
            if ($v = $cc) {
                $fvalue .= (!empty($fvalue) ? ', ' : '') . $v;
            }
        }

        // split recipients and put them back together in a unique way
        if (!empty($fvalue)) {
            $to_addresses = $IMAP->decode_address_list($fvalue);
            $fvalue = '';

            //rcube::tfk_debug("/ test: " . var_export($sa_recipients, true));

            foreach ($to_addresses as $addr_part) {
                if (
                    !empty($addr_part['mailto'])
                    && !in_array($addr_part['mailto'], $sa_recipients)
                    && (
                        !$MESSAGE['FROM']
                        || !in_array($addr_part['mailto'], $MESSAGE['FROM'])
                    )
                ) {
                    $fvalue .= (strlen($fvalue) ? ', ':'') . $addr_part['string'];
                    $sa_recipients[] = $addr_part['mailto'];
                }
            }
        }
    }
    else if ($header && $compose_mode == RCUBE_COMPOSE_DRAFT) {
        // get drafted headers
        if ($header=='to' && !empty($to)) {
            $fvalue = $IMAP->decode_header($to);
        }
        if ($header=='cc' && !empty($cc)) {
            $fvalue = $IMAP->decode_header($cc);
        }
        if ($header=='bcc' && !empty($bcc)) {
            $fvalue = $IMAP->decode_header($bcc);
        }
    }


    if ($fname && $field_type) {
        // pass the following attributes to the form class
        $field_attrib = array('name' => $fname);
        foreach ($attrib as $attr => $value) {
            if (in_array($attr, $allow_attrib)) {
                $field_attrib[$attr] = $value;
            }
        }
        // create teaxtarea object
        $input = new $field_type($field_attrib);
        $out   = $input->show($fvalue);
    }

    if ($form_start) {
        $out = $form_start.$out;
    }

    $registry->set('sa_recipients', $sa_recipients, 'core');

    return $out;
}

function rcmail_compose_header_from($attrib)
{
    $registry      = rcube_registry::get_instance();
    $MESSAGE       = $registry->get('MESSAGE', 'core');
    $DB            = $registry->get('DB', 'core');
    $compose_mode  = $registry->get('compose_mode', 'core');
    $OUTPUT        = $registry->get('OUTPUT', 'core');

    // pass the following attributes to the form class
    $field_attrib = array('name' => '_from');
    foreach ($attrib as $attr => $value) {
        if (in_array($attr, array('id', 'class', 'style', 'size', 'tabindex'))) {
            $field_attrib[$attr] = $value;
        }
    }

    // extract all recipients of the reply-message
    $a_recipients = array();
    if ($compose_mode == RCUBE_COMPOSE_REPLY && is_array($MESSAGE['structure']->headers)) {
        $MESSAGE['FROM'] = array();

        $a_to = $IMAP->decode_address_list($MESSAGE['structure']->headers['to']);
        foreach ($a_to as $addr) {
            if (!empty($addr['mailto'])) {
                $a_recipients[] = $addr['mailto'];
            }
        }

        if (!empty($MESSAGE['structure']->headers['cc'])) {
            $a_cc = $IMAP->decode_address_list($MESSAGE['structure']->headers['cc']);
            foreach ($a_cc as $addr) {
                if (!empty($addr['mailto']))
                    $a_recipients[] = $addr['mailto'];
            }
        }
    }

    // get this user's identities
    $_query = "SELECT identity_id, name, email, signature, html_signature";
    $_query.= " FROM " . rcube::get_table_name('identities');
    $_query.= " WHERE user_id=?";
    $_query.= " AND del<>1";
    $_query.= " ORDER BY " . $DB->quoteIdentifier('standard')." DESC, name ASC";

    //rcube::tfk_debug($_query);

    $sql_result = $DB->query($_query, $_SESSION['user_id']);


    if ($DB->num_rows($sql_result) == 0) {
        $input_from = new html_inputfield($field_attrib);
        $out        = $input_from->show($_POST['_from']);

        if ($form_start) {
            $out = $form_start.$out;
        }
        return $out;
    }


    $from_id = 0;
    $a_signatures = array();

    $field_attrib['onchange'] = JS_OBJECT_NAME.".change_identity(this)";
    $select_from = new html_select($field_attrib);

    while ($sql_arr = $DB->fetch_assoc($sql_result)) {
        $identity_id = $sql_arr['identity_id'];
        $select_from->add(
                rcube::format_email_recipient(
                        $sql_arr['email'],
                        $sql_arr['name']
                ),
                $identity_id
        );

        // add signature to array
        if (!empty($sql_arr['signature'])) {
            $a_signatures[$identity_id]['text'] = $sql_arr['signature'];
            $a_signatures[$identity_id]['is_html'] = ($sql_arr['html_signature'] == 1) ? true : false;
            if ($a_signatures[$identity_id]['is_html']) {
                $h2t = new html2text($a_signatures[$identity_id]['text'], false, false);
                $plainTextPart = $h2t->get_text();
                $a_signatures[$identity_id]['plain_text'] = trim($plainTextPart);
            }
        }

        // set identity if it's one of the reply-message recipients
        if (in_array($sql_arr['email'], $a_recipients)) {
            $from_id = $sql_arr['identity_id'];
        }
        if ($compose_mode == RCUBE_COMPOSE_REPLY && is_array($MESSAGE['FROM'])) {
            $MESSAGE['FROM'][] = $sql_arr['email'];
        }
        if ($compose_mode == RCUBE_COMPOSE_DRAFT && strstr($MESSAGE['headers']->from, $sql_arr['email'])) {
            $from_id = $sql_arr['identity_id'];
        }
    }

    // overwrite identity selection with post parameter
    if (isset($_POST['_from'])) {
        $from_id = rcube::get_input_value('_from', rcube::INPUT_POST);
    }
    $out = $select_from->show($from_id);

    // add signatures to client
    $OUTPUT->set_env('signatures', $a_signatures);

    if ($form_start) {
        $out = $form_start.$out;
    }
    return $out;
}

function rcmail_compose_body($attrib)
{
    $registry     = rcube_registry::get_instance();
    $CONFIG       = $registry->get_all('config');
    $MESSAGE      = $registry->get('MESSAGE', 'core');
    $OUTPUT       = $registry->get('OUTPUT', 'core');
    $compose_mode = $registry->get('compose_mode', 'core');
    $COMM_PATH    = $registry->get('COMM_PATH', 'core');

    list($form_start, $form_end) = get_form_tags($attrib);
    unset($attrib['form']);

    if (empty($attrib['id'])) {
        $attrib['id'] = 'rcmComposeMessage';
    }
    $attrib['name'] = '_message';

    if ($CONFIG['htmleditor']) {
        $isHtml = true;
    }
    else {
        $isHtml = false;
    }
    $body = '';

    // use posted message body
    if (!empty($_POST['_message'])) {
        $body = rcube::get_input_value('_message', rcube::INPUT_POST, TRUE);
    }
    // compose reply-body
    elseif ($compose_mode == RCUBE_COMPOSE_REPLY) {
        $hasHtml = rcmail_has_html_part($MESSAGE['parts']);
        if ($hasHtml && $CONFIG['htmleditor']) {
            $body = rcmail_first_html_part($MESSAGE);
            $isHtml = true;
        }
        else {
            $body = rcmail_first_text_part($MESSAGE);
            $isHtml = false;
        }
        $body = rcmail_create_reply_body($body, $isHtml);
    }
    // forward message body inline
    elseif ($compose_mode == RCUBE_COMPOSE_FORWARD) {
        $hasHtml = rcmail_has_html_part($MESSAGE['parts']);
        if ($hasHtml && $CONFIG['htmleditor']) {
            $body = rcmail_first_html_part($MESSAGE);
            $isHtml = true;
        }
        else {
            $body = rcmail_first_text_part($MESSAGE);
            $isHtml = false;
        }
        $body = rcmail_create_forward_body($body, $isHtml);
    }
    elseif ($compose_mode == RCUBE_COMPOSE_DRAFT) {
        $hasHtml = rcmail_has_html_part($MESSAGE['parts']);
        if ($hasHtml && $CONFIG['htmleditor']) {
            $body = rcmail_first_html_part($MESSAGE);
            $isHtml = true;
        }
        else {
           $body = rcmail_first_text_part($MESSAGE);
            $isHtml = false;
        }
        $body = rcmail_create_draft_body($body, $isHtml);
    }
    $out = $form_start ? "$form_start\n" : '';

    //tfk_debug($MESSAGE['headers']);

    $saveid = new html_hiddenfield(
                    array(
                        'name' => '_draft_saveid',
                        'value' => $compose_mode==RCUBE_COMPOSE_DRAFT ? str_replace(array('<','>'),
                        "",
                        $MESSAGE['headers']->messageID) : ''
                    )
    );
    $out .= $saveid->show();

    $drafttoggle = new html_hiddenfield(array('name' => '_draft', 'value' => 'yes'));
    $out .= $drafttoggle->show();

    $msgtype = new html_hiddenfield(array('name' => '_is_html', 'value' => ($isHtml?"1":"0")));
    $out .= $msgtype->show();

    // If desired, set this text area to be editable by TinyMCE
    if ($isHtml) {
        $attrib['mce_editable'] = "true";
    }
    $textarea = new html_textarea($attrib);
    $out .= $textarea->show($body);
    $out .= $form_end ? "\n$form_end" : '';

    // include GoogieSpell
    if (!empty($CONFIG['enable_spellcheck']) && !$isHtml) {
        $lang_set = '';
        if (!empty($CONFIG['spellcheck_languages']) && is_array($CONFIG['spellcheck_languages'])) {
            $lang_set = "googie.setLanguages(".array2js($CONFIG['spellcheck_languages']).");\n";
        }
        $OUTPUT->include_script('googiespell.js');
        $OUTPUT->add_script(
                    sprintf(
                      "var googie = new GoogieSpell('\$__skin_path/images/googiespell/','%s&_action=spell&lang=');\n".
                      "googie.lang_chck_spell = \"%s\";\n".
                      "googie.lang_rsm_edt = \"%s\";\n".
                      "googie.lang_close = \"%s\";\n".
                      "googie.lang_revert = \"%s\";\n".
                      "googie.lang_no_error_found = \"%s\";\n%s".
                      "googie.setCurrentLanguage('%s');\n".
                      "googie.decorateTextarea('%s');\n".
                      "%s.set_env('spellcheck', googie);",
                      $COMM_PATH,
                      JQ(Q(rcube::gettext('checkspelling'))),
                      JQ(Q(rcube::gettext('resumeediting'))),
                      JQ(Q(rcube::gettext('close'))),
                      JQ(Q(rcube::gettext('revertto'))),
                      JQ(Q(rcube::gettext('nospellerrors'))),
                      $lang_set,
                      substr($_SESSION['user_lang'], 0, 2),
                      $attrib['id'],
                      JS_OBJECT_NAME), 'foot'
        );
        $OUTPUT->add_label('checking');
    }
    $out .= "\n".'<iframe name="savetarget" src="program/blank.gif" style="width:0;height:0;visibility:hidden;"></iframe>';

    return $out;
}

/**
 * rcmail_create_reply_body
 *
 * @access protected
 * @param  string $body
 * @param  boolean $bodyIsHtml
 * @return string
 */
function rcmail_create_reply_body($body, $bodyIsHtml)
{
    $registry = rcube_registry::get_instance();
    $IMAP     = $registry->get('IMAP', 'core');
    $MESSAGE  = $registry->get('MESSAGE', 'core');

    $_date = $MESSAGE['headers']->date;
    $_from = $IMAP->decode_header($MESSAGE['headers']->from);

    //tfk_debug('From: ' . $_from);

    if (! $bodyIsHtml) {
        // soft-wrap message first
        $body = wordwrap($body, 75);

        // split body into single lines
        $a_lines = preg_split('/\r?\n/', $body);

        // add > to each line
        for($n=0; $n<sizeof($a_lines); $n++) {
            if (strpos($a_lines[$n], '>')===0) {
                $a_lines[$n] = '>'.$a_lines[$n];
            }
            else {
                $a_lines[$n] = '> '.$a_lines[$n];
            }
        }

        $body = join("\n", $a_lines);

        // add title line
        $prefix = sprintf(
                        "\n\n\nOn %s, %s wrote:\n",
                        $_date,
                        $_from
        );

        // try to remove the signature
        if ($sp = strrstr($body, '-- ')) {
            if ($body{$sp+3}==' ' || $body{$sp+3}=="\n" || $body{$sp+3}=="\r") {
                $body = substr($body, 0, $sp-1);
            }
        }
        $suffix = '';
    }
    else {
        $_msg = "<br><br>On %s, %s wrote:<br><blockquote type=\"cite\" ";
        $_msg.= "style=\"padding-left: 5px; border-left: #1010ff 2px solid; ";
        $_msg.= "margin-left: 5px; width: 100%%\">";
        $prefix = sprintf(
                        $_msg,
                        $_date,
                        $_from
        );
        $suffix = "</blockquote>";
    }
    return $prefix.$body.$suffix;
}


function rcmail_create_forward_body($body, $bodyIsHtml)
{
    $registry = rcube_registry::get_instance();
    $IMAP     = $registry->get('IMAP', 'core');
    $MESSAGE  = $registry->get('MESSAGE', 'core');


    $_date    = $MESSAGE['headers']->date;
    $_from    = $MESSAGE['headers']->from;
    $_subject = $MESSAGE['headers']->subject;
    $_to      = $MESSAGE['headers']->to;

    if (! $bodyIsHtml) {
        // soft-wrap message first
        $body = wordwrap($body, 80);

        $prefix = sprintf(
                    "\n\n\n-------- Original Message --------\nSubject: %s\nDate: %s\nFrom: %s\nTo: %s\n\n",
                    $_subject,
                    $_date,
                    $IMAP->decode_header($_from),
                    $IMAP->decode_header($_to)
        );
    }
    else {
        $prefix = sprintf(
                "<br><br>-------- Original Message --------" .
                "<table border=\"0\" cellpadding=\"0\" cellspacing=\"0\"><tbody>" .
                "<tr><th align=\"right\" nowrap=\"nowrap\" valign=\"baseline\">Subject: </th><td>%s</td></tr>" .
                "<tr><th align=\"right\" nowrap=\"nowrap\" valign=\"baseline\">Date: </th><td>%s</td></tr>" .
                "<tr><th align=\"right\" nowrap=\"nowrap\" valign=\"baseline\">From: </th><td>%s</td></tr>" .
                "<tr><th align=\"right\" nowrap=\"nowrap\" valign=\"baseline\">To: </th><td>%s</td></tr>" .
                "</tbody></table><br>",
                Q($_subject),
                Q($_date),
                Q($IMAP->decode_header($_from)),
                Q($IMAP->decode_header($_to))
        );
    }

    // add attachments
    if (!isset($_SESSION['compose']['forward_attachments']) && is_array($MESSAGE['parts'])) {
        rcmail_write_compose_attachments($MESSAGE);
    }
    return $prefix.$body;
}


function rcmail_create_draft_body($body, $bodyIsHtml)
{
    $registry = rcube_registry::get_instance();
    $IMAP     = $registry->get('IMAP', 'core');
    $MESSAGE  = $registry->get('MESSAGE', 'core');

    // add attachments
    if (
        !isset($_SESSION['compose']['forward_attachments']) &&
        is_array($MESSAGE['parts']) && sizeof($MESSAGE['parts'])
        >1
    ) {
        rcmail_write_compose_attachments($MESSAGE);
    }
    return $body;
}


function rcmail_write_compose_attachments(&$message)
{
    $registry = rcube_registry::get_instance();
    $CONFIG   = $registry->get_all('config');
    $IMAP     = $registry->get('IMAP', 'core');

    $temp_dir = unslashify($CONFIG['temp_dir']);

    if (!is_array($_SESSION['compose']['attachments'])) {
        $_SESSION['compose']['attachments'] = array();
    }
    foreach ($message['parts'] as $pid => $part) {
        if (
            $part->ctype_primary != 'message'
            && $part->ctype_primary != 'text'
            && (
                $part->disposition=='attachment'
                || $part->disposition=='inline'
                || $part->headers['content-id']
                || (
                    empty($part->disposition)
                    && $part->filename
                )
            )
        ) {
            $tmp_path = tempnam($temp_dir, 'rcmAttmnt');
            if ($fp = fopen($tmp_path, 'w')) {
                fwrite($fp, $IMAP->get_message_part($message['UID'], $pid, $part->encoding));
                fclose($fp);

                $_SESSION['compose']['attachments'][] = array(
                    'mimetype' => $part->ctype_primary . '/' . $part->ctype_secondary,
                    'name' => $part->filename,
                    'path' => $tmp_path
                );
            }
        }
    }

    $_SESSION['compose']['forward_attachments'] = TRUE;
}


/**
 * rcmail_compose_subject
 *
 * @param  array $attrib
 * @return string
 * @todo   Fix possible bug in $MESSAGE init.
 */
function rcmail_compose_subject($attrib)
{
    $registry     = rcube_registry::get_instance();
    $CONFIG       = $registry->get_all('config');
    $MESSAGE      = $registry->get('MESSAGE', 'core');
    $compose_mode = $registry->get('compose_mode', 'core');
    $IMAP         = $registry->get('IMAP', 'core');

    list($form_start, $form_end) = get_form_tags($attrib);
    unset($attrib['form']);

    $attrib['name'] = '_subject';
    $inputfield = new html_inputfield($attrib);

    $subject  = '';

    $_subject = $MESSAGE['headers']->subject;
    $_subject = $IMAP->decode_mime_string($_subject);

    // use subject from post
    if (isset($_POST['_subject'])) {
        $subject = rcube::get_input_value('_subject', rcube::INPUT_POST, TRUE);
    }
    // create a reply-subject
    else if ($compose_mode == RCUBE_COMPOSE_REPLY) {
        if (eregi('^re:', $_subject)) {
            $subject = $_subject;
        }
        else {
            $subject = 'Re: ' . $_subject;
        }
    }
    // create a forward-subject
    else if ($compose_mode == RCUBE_COMPOSE_FORWARD) {
        if (eregi('^fwd:', $_subject)) {
            $subject = $_subject;
        }
        else {
            $subject = 'Fwd: '.$_subject;
        }
    }
    // creeate a draft-subject
    else if ($compose_mode == RCUBE_COMPOSE_DRAFT) {
        $subject = $_subject;
    }
    $out = $form_start ? "$form_start\n" : '';
    $out .= $inputfield->show($subject);
    $out .= $form_end ? "\n$form_end" : '';

    return $out;
}

/**
 * rcmail_compose_attachment
 *
 * @todo   Get rid off inline styles.
 * @todo   Move html into a template.
 * @param  unknown_type $attrib
 * @return unknown
 */
function rcmail_compose_attachment_list($attrib)
{
    $registry = rcube_registry::get_instance();
    $CONFIG   = $registry->get_all('config');
    $OUTPUT   = $registry->get('OUTPUT', 'core');

    // add ID if not given
    if (!$attrib['id']) {
        $attrib['id'] = 'rcmAttachmentList';
    }
    // allow the following attributes to be added to the <ul> tag
    $attrib_str = rcube::create_attrib_string($attrib, array('id', 'class', 'style'));

    $out = '<ul'. $attrib_str . ">\n";

    if (is_array($_SESSION['compose']['attachments'])) {
        if ($attrib['deleteicon']) {
            $button = sprintf(
                        '<img src="%s%s" alt="%s" border="0" style="padding-right:2px;vertical-align:middle" />',
                        $CONFIG['skin_path'],
                        $attrib['deleteicon'],
                        rcube::gettext('delete')
            );
        }
        else {
            $button = rcube::gettext('delete');
        }
        foreach ($_SESSION['compose']['attachments'] as $id => $a_prop) {
            $out .= sprintf(
                        '<li id="rcmfile%d"><a href="#delete" onclick="return %s.command(\'remove-attachment\',\'rcmfile%d\', this)" title="%s">%s</a>%s</li>',
                        $id,
                        JS_OBJECT_NAME,
                        $id,
                        Q(rcube::gettext('delete')),
                        $button,
                        Q($a_prop['name'])
            );
        }
    }

    $OUTPUT->add_gui_object('attachmentlist', $attrib['id']);

    $out .= '</ul>';
    return $out;
}



/**
 * @todo Move attachment form into a template
 */
function rcmail_compose_attachment_form($attrib)
{
    $registry          = rcube_registry::get_instance();
    $OUTPUT            = $registry->get('OUTPUT', 'core');
    $SESS_HIDDEN_FIELD = $registry->get('SESS_HIDDEN_FIELD', 'core');

    // add ID if not given
    if (!$attrib['id']) {
        $attrib['id'] = 'rcmUploadbox';
    }
    // allow the following attributes to be added to the <div> tag
    $attrib_str  = rcube::create_attrib_string($attrib, array('id', 'class', 'style'));
    $input_field = rcmail_compose_attachment_field(array('style="height:15px;"'));
    $label_send  = rcube::gettext('upload');
    $label_close = rcube::gettext('close');
    $js_instance = JS_OBJECT_NAME;



  $out = <<<EOF
<div$attrib_str>
<form action="./" method="post" id="attachmentUploadForm" enctype="multipart/form-data">
$SESS_HIDDEN_FIELD
$input_field<br />
<div class="btn btn-active-big">
    <p><span onclick="$('#$attrib[id]').slideUp('slow');">$label_close</span></p>
</div>
<div class="btn btn-active-big">
    <p><span onclick="$js_instance.command('send-attachment', $('#attachmentUploadForm'));">$label_send</span></p>
</div>
</form>
</div>
EOF;

    $OUTPUT->add_gui_object('uploadbox', $attrib['id']);
    return $out;
}


function rcmail_compose_attachment_field($attrib)
{
    // allow the following attributes to be added to the <input> tag
    $attrib_str = rcube::create_attrib_string($attrib, array('id', 'class', 'style', 'size'));

    $out = '<input type="file" name="_attachments[]"'. $attrib_str . " />";
    return $out;
}


function rcmail_priority_selector($attrib)
{
    list($form_start, $form_end) = get_form_tags($attrib);
    unset($attrib['form']);

    $attrib['name'] = '_priority';
    $selector = new html_select($attrib);

    $selector->add(
                array(
                    rcube::gettext('lowest'),
                    rcube::gettext('low'),
                    rcube::gettext('normal'),
                    rcube::gettext('high'),
                    rcube::gettext('highest')
                ),
                array(5, 4, 0, 2, 1)
    );

    $sel = isset($_POST['_priority']) ? $_POST['_priority'] : 0;

    $out = $form_start ? "$form_start\n" : '';
    $out .= $selector->show($sel);
    $out .= $form_end ? "\n$form_end" : '';

    return $out;
}


function rcmail_receipt_checkbox($attrib)
{
    list($form_start, $form_end) = get_form_tags($attrib);
    unset($attrib['form']);

    if (!isset($attrib['id']))
        $attrib['id'] = 'receipt';

    $attrib['name'] = '_receipt';
    $attrib['value'] = '1';
    $checkbox = new html_checkbox($attrib);

    $out = $form_start ? "$form_start\n" : '';
    $out .= $checkbox->show(0);
    $out .= $form_end ? "\n$form_end" : '';

    return $out;
}


function rcmail_editor_selector($attrib)
{
    $registry     = rcube_registry::get_instance();
    $CONFIG       = $registry->get_all('config');
    $MESSAGE      = $registry->get('MESSAGE', 'core');
    $compose_mode = $registry->get('compose_mode', 'core');

    $choices = array(
        'html'  => 'htmltoggle',
        'plain' => 'plaintoggle'
    );

    // determine whether HTML or plain text should be checked
    if ($CONFIG['htmleditor'])
        $useHtml = true;
    else
        $useHtml = false;

    if (
        $compose_mode == RCUBE_COMPOSE_REPLY ||
        $compose_mode == RCUBE_COMPOSE_FORWARD ||
        $compose_mode == RCUBE_COMPOSE_DRAFT
    ) {
        $hasHtml = rcmail_has_html_part($MESSAGE['parts']);
        $useHtml = ($hasHtml && $CONFIG['htmleditor']);
    }

    $selector = '';

    $attrib['name'] = '_editorSelect';
    $attrib['onchange'] = 'return rcmail_toggle_editor(this)';
    foreach ($choices as $value => $text) {
        $checked = '';
        if ((($value == 'html') && $useHtml) || (($value != 'html') && !$useHtml)) {
            $attrib['checked'] = 'true';
        }
        else {
            unset($attrib['checked']);
        }
        $attrib['id'] = '_' . $value;
        $rb = new html_radiobutton($attrib);
        $selector .= sprintf(
                        "%s<label for=\"%s\">%s</label>",
                        $rb->show($value),
                        $attrib['id'],
                        rcube::gettext($text)
        );
    }
    return $selector;
}

function get_form_tags($attrib)
{
    $registry          = rcube_registry::get_instance();
    $CONFIG            = $registry->get_all('config');
    $MESSAGE_FORM      = $registry->get('MESSAGE_FORM', 'core');
    $OUTPUT            = $registry->get('OUTPUT', 'core');
    $SESS_HIDDEN_FIELD = $registry->get('SESS_HIDDEN_FIELD', 'core');

    $form_start = '';
    if (!strlen($MESSAGE_FORM)) {
        $hiddenfields = new html_hiddenfield(array('name' => '_task', 'value' => $registry->get('task', 'core')));
        $hiddenfields->add(array('name' => '_action', 'value' => 'send'));

        $form_start = empty($attrib['form']) ? '<form name="form" action="./" method="post">' : '';
        $form_start .= "\n$SESS_HIDDEN_FIELD\n";
        $form_start .= $hiddenfields->show();
    }

    $form_end = (strlen($MESSAGE_FORM) && !strlen($attrib['form'])) ? '</form>' : '';
    $form_name = !empty($attrib['form']) ? $attrib['form'] : 'form';

    if (!strlen($MESSAGE_FORM)) {
        $OUTPUT->add_gui_object('messageform', $form_name);
    }
    $MESSAGE_FORM = $form_name;

    $registry->set('MESSAGE_FORM', $MESSAGE_FORM, 'core');

    return array($form_start, $form_end);
}
?>
