<?php
/*
 +-----------------------------------------------------------------------+
 | program/steps/mail/sendmdn.inc                                        |
 |                                                                       |
 | This file is part of the RoundCube Webmail client                     |
 | Copyright (C) 2007-2008, RoundCube Dev. - Switzerland                 |
 | Licensed under the GNU GPL                                            |
 |                                                                       |
 | PURPOSE:                                                              |
 |   Send a message disposition notification for a specific mail         |
 |                                                                       |
 +-----------------------------------------------------------------------+
 | Author: Thomas Bruederli <roundcube@gmail.com>                        |
 +-----------------------------------------------------------------------+

 $Id: sendmdn.inc 930 2007-11-25 17:34:19Z thomasb $

*/

if (!empty($_POST['_uid'])) {
    $MESSAGE = array('UID' => rcube::get_input_value('_uid', rcube::INPUT_POST));
    
    $MESSAGE['headers'] = $IMAP->get_headers($MESSAGE['UID']);
    $MESSAGE['subject'] = rcube_imap::decode_mime_string($MESSAGE['headers']->subject, $MESSAGE['headers']->charset);
  
    if ($MESSAGE['headers']->mdn_to && !$MESSAGE['headers']->mdn_sent) {
        $identity  = $USER->get_identity();
        $sender    = format_email_recipient($identity['email'], $identity['name']);
        $recipient = array_shift($IMAP->decode_address_list($MESSAGE['headers']->mdn_to));
        $mailto    = $recipient['mailto'];

        $COMPOSE = new rcube_mail_mime(rcube::header_delm());
        $COMPOSE->setParam(array(
            'text_encoding' => 'quoted-printable',
            'html_encoding' => 'quoted-printable',
            'head_encoding' => 'quoted-printable',
            'head_charset'  => RCMAIL_CHARSET,
            'html_charset'  => RCMAIL_CHARSET,
            'text_charset'  => RCMAIL_CHARSET));

        // compose headers array
        $headers = array(
            'Date'         => date('r'),
            'From'         => $sender,
            'To'           => $MESSAGE['headers']->mdn_to,
            'Subject'      => rcube::gettext('receiptread') . ': ' . $MESSAGE['subject'],
            'Message-ID'   => sprintf('<%s@%s>', md5(uniqid('rcmail'.rand(),true)), rcube::mail_domain($_SESSION['imap_host'])),
            'X-Sender'     => $identity['email'],
            'Content-Type' => 'multipart/report; report-type=disposition-notification');

        if (!empty($CONFIG['useragent'])) {
            $headers['User-Agent'] = $CONFIG['useragent'];
        }

        $body  = rcube::gettext('yourmessage') . "\r\n\r\n";
        $body .= "\t" . rcube::gettext('to') . ': ';
        $body .= rcube_imap::decode_mime_string(
            $MESSAGE['headers']->to,
            $MESSAGE['headers']->charset) . "\r\n";
        $body .= "\t" . rcube::gettext('subject') . ': ' . $MESSAGE['subject'] . "\r\n";
        $body .= "\t" . rcube::gettext('sent') . ': ';
        $body .= format_date(
            strtotime($MESSAGE['headers']->date),
            $CONFIG['date_long']) . "\r\n";
        $body .= "\r\n" . rcube::gettext('receiptnote') . "\r\n";
   
        $report = 'Reporting-UA: RoundCube Webmail (Version '.RCMAIL_VERSION.')'."\r\n";

        if ($MESSAGE['headers']->to) {
            $report .= "Original-Recipient: {$MESSAGE['headers']->to}\r\n";
        }
        $report .= "Final-Recipient: rfc822; {$identity['email']}\r\n";
        $report .= "Original-Message-ID: {$MESSAGE['headers']->messageID}\r\n";
        $report .= "Disposition: manual-action/MDN-sent-manually; displayed\r\n";

        $COMPOSE->headers($headers, true);
        $COMPOSE->setTXTBody($body);
        $COMPOSE->addAttachment($report,
            'message/disposition-notification',
            'MDNPart2.txt',
            false,
            '7bit',
            'inline');

        $sent = rcmail_deliver_message($COMPOSE, $identity['email'], $mailto);

        if ($sent) {
            $IMAP->set_flag($MESSAGE['UID'], 'MDNSENT');
            $OUTPUT->set_env('mdn_request', false);
            $OUTPUT->show_message('receiptsent', 'confirmation');
            $OUTPUT->send();
        }
    }
}

// Error if arrive here
$OUTPUT->show_message('errorsendingreceipt', 'error');
$OUTPUT->send();

?>