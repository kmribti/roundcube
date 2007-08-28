<?php
/**
 * Class representing a message part
 */
class rcube_message_part
{
    var $mime_id = '';
    var $ctype_primary = 'text';
    var $ctype_secondary = 'plain';
    var $mimetype = 'text/plain';
    var $disposition = '';
    var $filename = '';
    var $encoding = '8bit';
    var $charset = '';
    var $size = 0;
    var $headers = array();
    var $d_parameters = array();
    var $ctype_parameters = array();
}