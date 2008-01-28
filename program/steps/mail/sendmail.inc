<?php

/*
 +-----------------------------------------------------------------------+
 | program/steps/mail/sendmail.inc                                       |
 |                                                                       |
 | This file is part of the RoundCube Webmail client                     |
 | Copyright (C) 2005-2007, RoundCube Dev. - Switzerland                 |
 | Licensed under the GNU GPL                                            |
 |                                                                       |
 | PURPOSE:                                                              |
 |   Compose a new mail message with all headers and attachments         |
 |   and send it using IlohaMail's SMTP methods or with PHP mail()       |
 |                                                                       |
 +-----------------------------------------------------------------------+
 | Author: Thomas Bruederli <roundcube@gmail.com>                        |
 +-----------------------------------------------------------------------+

 $Id: sendmail.inc 556 2007-05-13 18:25:52Z thomasb $

*/

//rcube::tfk_debug('Yay, sendmail.inc reached!');


$registry = rcube_registry::get_instance();
$OUTPUT   = $registry->get('OUTPUT', 'core');
$MBSTRING = $registry->get('MBSTRING', 'core');
$CONFIG   = $registry->get_all('config');
$IMAP     = $registry->get('IMAP', 'core');

require_once 'lib/html2text.inc';

if (!isset($_SESSION['compose']['id'])) {
    rcube::override_action('list');
    return;
}

//rcube::tfk_debug('Still here!');

/****** message sending functions ********/
require_once 'include/rcube/rcmail_send.php';

//rcube::tfk_debug('Passed require of class rc_mail_send.');

if (strlen($_POST['_draft_saveid']) > 3) {
    $olddraftmessageid = rcube::get_input_value('_draft_saveid', rcube::INPUT_POST);
}
$message_id = sprintf(
    '<%s@%s>',
    md5(uniqid('rcmail' . rand(),true)),
    rcube::mail_domain($_SESSION['imap_host'])
);
$savedraft = ((empty($_POST['_draft']) === false) ? TRUE : FALSE);

//rcube::tfk_debug('Draft?: ' . var_export($savedraft, true));

// remove all scripts and act as called in frame
$OUTPUT->reset();
$OUTPUT->framed = TRUE;


/****** check submission and compose message ********/


if (!$savedraft && empty($_POST['_to']) && empty($_POST['_subject']) && $_POST['_message']) {
    $OUTPUT->show_message("sendingfailed", 'error');
    $OUTPUT->send('iframe');
    return;
}

//rcube::tfk_debug('Passed some checks! Right before charset!');

// set default charset
$input_charset   = $OUTPUT->get_charset();
$message_charset = isset($_POST['_charset']) ? $_POST['_charset'] : $input_charset;

$mailto_regexp  = array('/[,;]\s*[\r\n]+/', '/[\r\n]+/', '/[,;]\s*$/m', '/;/');
$mailto_replace = array(', ', ', ', '', ',');

// replace new lines and strip ending ', '
$mailto = preg_replace(
    $mailto_regexp,
    $mailto_replace,
    rcube::get_input_value('_to', rcube::INPUT_POST, TRUE, $message_charset)
);

//rcube::tfk_debug('We just replaced line endings.');

// decode address strings
$to_address_arr = $IMAP->decode_address_list($mailto);
//rcube::tfk_debug('To: ' . var_export($to_address_arr, true));

$identity_arr = rcmail_send::get_identity(rcube::get_input_value('_from', rcube::INPUT_POST));
if ($identity_arr === false) {
    $OUTPUT->show_message("sendingfailed", 'error');
    $OUTPUT->send('iframe');
    return;
}

//rcube::tfk_debug('We got identity.');

$from     = $identity_arr['mailto'];
$first_to = is_array($to_address_arr[0]) ? $to_address_arr[0]['mailto'] : $mailto;

if (empty($identity_arr['string'])) {
    $identity_arr['string'] = $from;
}
// compose headers array
$headers = array(
    'Date' => date('D, j M Y H:i:s O'),
    'From' => rcube::charset_convert($identity_arr['string'], RCMAIL_CHARSET, $message_charset),
    'To'   => $mailto
);

// additional recipients
if (!empty($_POST['_cc'])) {
    $headers['Cc'] = preg_replace(
        $mailto_regexp,
        $mailto_replace,
        rcube::get_input_value(
            '_cc',
            rcube::INPUT_POST,
            TRUE, $message_charset
        )
    );
}
if (!empty($_POST['_bcc'])) {
    $headers['Bcc'] = preg_replace(
        $mailto_regexp, $mailto_replace,
        rcube::get_input_value('_bcc', rcube::INPUT_POST, TRUE, $message_charset)
    );
}
if (empty($identity_arr['bcc']) === false) {
    $headers['Bcc'] = ($headers['Bcc'] ? $headers['Bcc'].', ' : '') . $identity_arr['bcc'];
}
// add subject
$headers['Subject'] = trim(
    rcube::get_input_value(
        '_subject',
        rcube::INPUT_POST,
        FALSE,
        $message_charset
    )
);

if (empty($identity_arr['organization']) === false) {
    $headers['Organization'] = $identity_arr['organization'];
}
if (empty($identity_arr['reply-to']) === false) {
    $headers['Reply-To'] = $identity_arr['reply-to'];
}
if (empty($_SESSION['compose']['reply_msgid']) === false) {
    $headers['In-Reply-To'] = $_SESSION['compose']['reply_msgid'];
}
if (empty($_SESSION['compose']['references']) === false) {
    $headers['References'] = $_SESSION['compose']['references'];
}
if (empty($_POST['_priority']) === false) {
    $priority = (int)$_POST['_priority'];
    $a_priorities = array(1=>'highest', 2=>'high', 4=>'low', 5=>'lowest');
    if ($str_priority = $a_priorities[$priority]) {
        $headers['X-Priority'] = sprintf(
                                    "%d (%s)",
                                    $priority,
                                    ucfirst($str_priority)
        );
    }
}

if (!empty($_POST['_receipt'])) {
    $headers['Return-Receipt-To']           = $identity_arr['string'];
    $headers['Disposition-Notification-To'] = $identity_arr['string'];
}

// additional headers
$headers['Message-ID'] = $message_id;
$headers['X-Sender']   = $from;

if (!empty($CONFIG['useragent'])) {
    $headers['User-Agent'] = $CONFIG['useragent'];
}
// fetch message body
$message_body = rcube::get_input_value(
                    '_message',
                    rcube::INPUT_POST,
                    TRUE,
                    $message_charset
);

// append generic footer to all messages
if (!$savedraft) {
    if (!empty($CONFIG['generic_message_footer'])) {
        $footer = @file_get_contents(realpath($CONFIG['generic_message_footer']));
        if ($footer !== FALSE) {
            $message_body .= "\r\n";
            $message_body .= rcube::charset_convert(
                                $footer,
                                'UTF-8',
                                $message_charset
            ); 
        }
    }
}

// try to autodetect operating system and use the correct line endings
// use the configured delimiter for headers
if (!empty($CONFIG['mail_header_delimiter'])) {
    $header_delm = $CONFIG['mail_header_delimiter'];
}
elseif (strtolower(substr(PHP_OS, 0, 3)=='win')) {
    $header_delm = "\r\n";
}
elseif (strtolower(substr(PHP_OS, 0, 3) == 'mac')) {
    $header_delm = "\r\n";
}
else {
    $header_delm = "\n";
}

$isHtmlVal = strtolower(rcube::get_input_value('_is_html', rcube::INPUT_POST));
$isHtml    = ($isHtmlVal == "1");

// create extended PEAR::Mail_mime instance
$MAIL_MIME = new rcube_mail_mime($header_delm);

// For HTML-formatted messages, construct the MIME message with both
// the HTML part and the plain-text part

$error_msg = array();

if ($isHtml) {
    $status = $MAIL_MIME->setHTMLBody($message_body);
    if ($status !== true) {
        array_push($error_msg, 'Unable to set HTML body.');
    }

    // add a plain text version of the e-mail as an alternative part.
    $h2t           = new html2text($message_body);
    $plainTextPart = wordwrap($h2t->get_text(), 998, "\r\n", true);
    $status        = $MAIL_MIME->setTXTBody($plainTextPart);

    // look for "emoticon" images from TinyMCE and copy into message as attachments
    $status = rc_mail::attach_emoticons($MAIL_MIME);
    if ($status === false) {
        $OUTPUT->show_message("emoticonerror", 'error');
        $OUTPUT->send('iframe');
        return;
    }
} else {
    $message_body = wordwrap($message_body, 75, "\r\n");
    $message_body = wordwrap($message_body, 998, "\r\n", true);
    $status = $MAIL_MIME->setTXTBody($message_body, FALSE, TRUE);
    if ($status !== true) {
        array_push($error_msg, 'Unable to set TXT body.');
    }
}


// add stored attachments, if any
if (is_array($_SESSION['compose']['attachments'])) {
    foreach ($_SESSION['compose']['attachments'] as $attachment) {
        if (empty($attachment['path'])) {
            continue;
        }
        $status = $MAIL_MIME->addAttachment(
            $attachment['path'],
            $attachment['mimetype'],
            $attachment['name'],
            true,
            'base64',
            'attachment',
            $message_charset
        );
        if (PEAR::isError($status)) {
            array_push(
                $error_msg,
                $status->getMessage() . "\n" . var_export($attachment, true)
            );
        }
    }
}
// add submitted attachments
if (is_array($_FILES['_attachments']['tmp_name'])) {
    foreach ($_FILES['_attachments']['tmp_name'] as $i => $filepath) {
        $status = $MAIL_MIME->addAttachment(
            $filepath,
            $files['type'][$i],
            $files['name'][$i],
            true,
            'base64',
            'attachment',
            $message_charset
        );
        if (PEAR::isError($status)) {
            array_push(
                $error_msg,
                $status->getMessage() . "\n" . var_export($files, true)
            );
        }
    }
}

//rcube::tfk_debug('Errors anyone?: ' . var_export($error_msg, true));

if (count($error_msg) > 0) {
    rcube_error::raise(
        array(
            'code' => 800,
            'type' => 'smtp',
            'line' => __LINE__,
            'file' => __FILE__,
            'message' => "MAIL error: " . implode("\n", $error_msg)
        ),
        TRUE,
        FALSE
    );
    $OUTPUT->show_message(implode("\n", $error_msg), 'error');
    $OUTPUT->send('iframe');
    return;
}


// chose transfer encoding
$charset_7bit = array('ASCII', 'ISO-2022-JP', 'ISO-8859-1', 'ISO-8859-2', 'ISO-8859-15');
$transfer_encoding = in_array(strtoupper($message_charset), $charset_7bit) ? '7bit' : '8bit';

// encoding settings for mail composing
$message_param = array(
    'text_encoding' => $transfer_encoding,
    'html_encoding' => 'quoted-printable',
    'head_encoding' => 'quoted-printable',
    'head_charset'  => $message_charset,
    'html_charset'  => $message_charset,
    'text_charset'  => $message_charset,
);

// compose message body and get headers
$msg_body = $MAIL_MIME->get($message_param);
// unset to save memory.
unset($MAIL_MIME->_parts);

// encoding subject header with mb_encode provides better results with asian characters
if ($MBSTRING && function_exists("mb_encode_mimeheader")) {
    mb_internal_encoding($message_charset);
    $mb_subject = mb_encode_mimeheader($headers['Subject'], $message_charset, 'Q');
    mb_internal_encoding(RCMAIL_CHARSET);
}

//rcube::tfk_debug('Right before sending/draft if!');

// Begin SMTP Delivery Block
if ($savedraft === false) {

    //rcube::tfk_debug('Not a draft. We try to send mail.');

    // send thru SMTP server using custom SMTP library
    if ($_SESSION['smtp_server'] != 'phpMail') {

        //rcube::tfk_debug('Because we can: SMTP.');

        // generate list of recipients
        $a_recipients = array($mailto);

        if (strlen($headers['Cc'])) {
            $a_recipients[] = $headers['Cc'];
        }
        if (strlen($headers['Bcc'])) {
            $a_recipients[] = $headers['Bcc'];
        }
        // clean Bcc from header for recipients
        $send_headers = $headers;
        unset($send_headers['Bcc']);

        if (!empty($mb_subject)) {
            $send_headers['Subject'] = $mb_subject;
        }
        // send message
        $smtp_response = array();
        $sent = smtp_mail(
                    $from,
                    $a_recipients,
                    $MAIL_MIME->txtHeaders($send_headers),
                    $msg_body,
                    $smtp_response
        );
        //rcube::tfk_debug(var_export($smtp_response, true));
        // log error
        if (!$sent) {
            rcube_error::raise(
                array(
                    'code' => 800,
                    'type' => 'smtp',
                    'line' => __LINE__,
                    'file' => __FILE__,
                    'message' => "SMTP error: ".join("\n", $smtp_response)
                ),
                TRUE,
                FALSE
            );
        }
    } else { // send mail using PHP's mail() function

        //rcube::tfk_debug('We try to send using mail().');

        // unset some headers because they will be added by the mail() function
        $headers_enc = $MAIL_MIME->headers($headers);
        $headers_php = $MAIL_MIME->_headers;
        unset($headers_php['To'], $headers_php['Subject']);

        if (!empty($mb_subject)) {
            $headers_enc['Subject'] = $mb_subject;
        }
        // reset stored headers and overwrite
        $MAIL_MIME->_headers = array();
        $header_str = $MAIL_MIME->txtHeaders($headers_php);

        if (ini_get('safe_mode')) {
            $sent = mail(
                        $headers_enc['To'],
                        $headers_enc['Subject'],
                        $msg_body,
                        $header_str
            );
        } else {
            $sent = mail(
                        $headers_enc['To'],
                        $headers_enc['Subject'],
                        $msg_body,
                        $header_str,
                        "-f$from"
            );
        }
        // return to compose page if sending failed
        if ($sent === false) {
            //rcube::tfk_debug('mail(): failed');
            $OUTPUT->show_message("sendingfailed", 'error');
            $OUTPUT->send('iframe');
            return;
        }
    } // End of SMTP Delivery Block
    // set repliead flag
    if ($_SESSION['compose']['reply_uid']) {
        $IMAP->set_flag($_SESSION['compose']['reply_uid'], 'ANSWERED');
    }
}
// Determine which folder to save message
if ($savedraft) {
    $store_target = 'drafts_mbox';
} else {
  $store_target = 'sent_mbox';
}

if ($CONFIG[$store_target]) {
    // create string of complete message headers
    $header_str = $MAIL_MIME->txtHeaders($headers);

    // check if mailbox exists
    if (!in_array_nocase($CONFIG[$store_target], $IMAP->list_mailboxes())) {
        $store_folder = $IMAP->create_mailbox($CONFIG[$store_target], TRUE);
    } else {
        $store_folder = TRUE;
    }

    // add headers to message body
    $msg_body = $header_str."\r\n".$msg_body;

    // append message to sent box
    if ($store_folder) {
        $saved = $IMAP->save_message($CONFIG[$store_target], $msg_body);
    }
    // raise error if saving failed
    if (!$saved) {
        rcube_error::raise(
            array(
                'code' => 800,
                'type' => 'imap',
                'file' => __FILE__,
                'message' => "Could not save message in $CONFIG[$store_target]"
            ),
            TRUE,
            FALSE
        );
        $OUTPUT->show_message('errorsaving', 'error');
        $OUTPUT->send('iframe');
    }

    if ($olddraftmessageid) {
        // delete previous saved draft
        $a_deleteid = $IMAP->search(
                            $CONFIG['drafts_mbox'],
                            'HEADER Message-ID',
                            $olddraftmessageid
        );
        $deleted = $IMAP->delete_message(
                            $IMAP->get_uid($a_deleteid[0],$CONFIG['drafts_mbox']),
                            $CONFIG['drafts_mbox']
        );
        // raise error if deletion of old draft failed
        if (!$deleted) {
            rcube_error::raise(
                    array(
                        'code' => 800,
                        'type' => 'imap',
                        'file' => __FILE__,
                        'message' => "Could not delete message from ".$CONFIG['drafts_mbox']
                    ),
                    TRUE,
                    FALSE
            );
        }
    }
}

if ($savedraft) {
    // display success
    $OUTPUT->show_message('messagesaved', 'confirmation');

    // update "_draft_saveid" and the "cmp_hash" to prevent "Unsaved changes" warning
    $OUTPUT->command('set_draft_id', str_replace(array('<','>'), "", $message_id));
    $OUTPUT->command('compose_field_hash', true);

    // start the auto-save timer again
    $OUTPUT->command('auto_save_start');

    $OUTPUT->send('iframe');
} else {
    if ($CONFIG['smtp_log'] === true) {
        $log_entry = sprintf(
            "[%s] User: %d on %s; Message for %s; %s\n",
            date("d-M-Y H:i:s O", mktime()),
            $_SESSION['user_id'],
            $_SERVER['REMOTE_ADDR'],
            $mailto,
            !empty($smtp_response) ? join('; ', $smtp_response) : ''
        );

        if ($fp = @fopen($CONFIG['log_dir'].'/sendmail', 'a')) {
            fwrite($fp, $log_entry);
            fclose($fp);
        }
    }
    rcmail_compose_cleanup();
    $OUTPUT->command('sent_successfully', rcube::gettext('messagesent'));
    $OUTPUT->send('iframe');
}
?>