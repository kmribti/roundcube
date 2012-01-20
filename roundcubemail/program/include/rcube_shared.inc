<?php

/*
 +-----------------------------------------------------------------------+
 | rcube_shared.inc                                                      |
 |                                                                       |
 | This file is part of the Roundcube PHP suite                          |
 | Copyright (C) 2005-2007, The Roundcube Dev Team                       |
 | Licensed under the GNU GPL                                            |
 |                                                                       |
 | CONTENTS:                                                             |
 |   Shared functions and classes used in PHP projects                   |
 |                                                                       |
 +-----------------------------------------------------------------------+
 | Author: Thomas Bruederli <roundcube@gmail.com>                        |
 +-----------------------------------------------------------------------+

 $Id$

*/


/**
 * Roundcube shared functions
 *
 * @package Core
 */


/**
 * Similar function as in_array() but case-insensitive
 *
 * @param mixed Needle value
 * @param array Array to search in
 * @return boolean True if found, False if not
 */
function in_array_nocase($needle, $haystack)
{
  $needle = mb_strtolower($needle);
  foreach ($haystack as $value)
    if ($needle===mb_strtolower($value))
      return true;

  return false;
}


/**
 * Find out if the string content means TRUE or FALSE
 *
 * @param string Input value
 * @return boolean Imagine what!
 */
function get_boolean($str)
{
  $str = strtolower($str);
  if (in_array($str, array('false', '0', 'no', 'off', 'nein', ''), TRUE))
    return FALSE;
  else
    return TRUE;
}


/**
 * Parse a human readable string for a number of bytes
 *
 * @param string Input string
 * @return float Number of bytes
 */
function parse_bytes($str)
{
  if (is_numeric($str))
    return floatval($str);

  if (preg_match('/([0-9\.]+)\s*([a-z]*)/i', $str, $regs))
  {
    $bytes = floatval($regs[1]);
    switch (strtolower($regs[2]))
    {
      case 'g':
      case 'gb':
        $bytes *= 1073741824;
        break;
      case 'm':
      case 'mb':
        $bytes *= 1048576;
        break;
      case 'k':
      case 'kb':
        $bytes *= 1024;
        break;
    }
  }

  return floatval($bytes);
}


/**
 * Wrapper function for wordwrap
 */
function rcube_wordwrap($string, $width=75, $break="\n", $cut=false)
{
  $para = explode($break, $string);
  $string = '';
  while (count($para)) {
    $line = array_shift($para);
    if ($line[0] == '>') {
      $string .= $line.$break;
      continue;
    }
    $list = explode(' ', $line);
    $len = 0;
    while (count($list)) {
      $line = array_shift($list);
      $l = mb_strlen($line);
      $newlen = $len + $l + ($len ? 1 : 0);

      if ($newlen <= $width) {
        $string .= ($len ? ' ' : '').$line;
        $len += (1 + $l);
      } else {
        if ($l > $width) {
          if ($cut) {
            $start = 0;
            while ($l) {
              $str = mb_substr($line, $start, $width);
              $strlen = mb_strlen($str);
              $string .= ($len ? $break : '').$str;
              $start += $strlen;
              $l -= $strlen;
              $len = $strlen;
            }
          } else {
                $string .= ($len ? $break : '').$line;
            if (count($list)) $string .= $break;
            $len = 0;
          }
        } else {
          $string .= $break.$line;
          $len = $l;
        }
      }
    }
    if (count($para)) $string .= $break;
  }
  return $string;
}


/**
 * Read a specific HTTP request header
 *
 * @access static
 * @param  string $name Header name
 * @return mixed  Header value or null if not available
 */
function rcube_request_header($name)
{
  if (function_exists('getallheaders'))
  {
    $hdrs = array_change_key_case(getallheaders(), CASE_UPPER);
    $key  = strtoupper($name);
  }
  else
  {
    $key  = 'HTTP_' . strtoupper(strtr($name, '-', '_'));
    $hdrs = array_change_key_case($_SERVER, CASE_UPPER);
  }

  return $hdrs[$key];
}


/**
 * Make sure the string ends with a slash
 */
function slashify($str)
{
  return unslashify($str).'/';
}


/**
 * Remove slash at the end of the string
 */
function unslashify($str)
{
  return preg_replace('/\/$/', '', $str);
}


/**
 * Delete all files within a folder
 *
 * @param string Path to directory
 * @return boolean True on success, False if directory was not found
 */
function clear_directory($dir_path)
{
  $dir = @opendir($dir_path);
  if(!$dir) return FALSE;

  while ($file = readdir($dir))
    if (strlen($file)>2)
      unlink("$dir_path/$file");

  closedir($dir);
  return TRUE;
}


/**
 * Create a unix timestamp with a specified offset from now
 *
 * @param string String representation of the offset (e.g. 20min, 5h, 2days)
 * @param int Factor to multiply with the offset
 * @return int Unix timestamp
 */
function get_offset_time($offset_str, $factor=1)
{
  if (preg_match('/^([0-9]+)\s*([smhdw])/i', $offset_str, $regs))
  {
    $amount = (int)$regs[1];
    $unit = strtolower($regs[2]);
  }
  else
  {
    $amount = (int)$offset_str;
    $unit = 's';
  }

  $ts = mktime();
  switch ($unit)
  {
    case 'w':
      $amount *= 7;
    case 'd':
      $amount *= 24;
    case 'h':
      $amount *= 60;
    case 'm':
      $amount *= 60;
    case 's':
      $ts += $amount * $factor;
  }

  return $ts;
}


/**
 * Truncate string if it is longer than the allowed length
 * Replace the middle or the ending part of a string with a placeholder
 *
 * @param string Input string
 * @param int    Max. length
 * @param string Replace removed chars with this
 * @param bool   Set to True if string should be truncated from the end
 * @return string Abbreviated string
 */
function abbreviate_string($str, $maxlength, $place_holder='...', $ending=false)
{
  $length = mb_strlen($str);

  if ($length > $maxlength)
  {
    if ($ending)
      return mb_substr($str, 0, $maxlength) . $place_holder;

    $place_holder_length = mb_strlen($place_holder);
    $first_part_length = floor(($maxlength - $place_holder_length)/2);
    $second_starting_location = $length - $maxlength + $first_part_length + $place_holder_length;
    $str = mb_substr($str, 0, $first_part_length) . $place_holder . mb_substr($str, $second_starting_location);
  }

  return $str;
}


/**
 * Explode quoted string
 * 
 * @param string Delimiter expression string for preg_match()
 * @param string Input string
 */
function rcube_explode_quoted_string($delimiter, $string)
{
  $result = array();
  $strlen = strlen($string);

  for ($q=$p=$i=0; $i < $strlen; $i++) {
    if ($string[$i] == "\"" && $string[$i-1] != "\\") {
      $q = $q ? false : true;
    } 
    else if (!$q && preg_match("/$delimiter/", $string[$i])) {
      $result[] = substr($string, $p, $i - $p);
      $p = $i + 1;
    }
  }

  $result[] = substr($string, $p);
  return $result;
}


/**
 * Get all keys from array (recursive)
 * 
 * @param array Input array
 * @return array
 */
function array_keys_recursive($array)
{
  $keys = array();

  if (!empty($array))
    foreach ($array as $key => $child) {
      $keys[] = $key;
      foreach (array_keys_recursive($child) as $val)
        $keys[] = $val;
    }
  return $keys;
}


/**
 * Remove all non-ascii and non-word chars except ., -, _
 */
function asciiwords($str, $css_id = false, $replace_with = '')
{
    $allowed = 'a-z0-9\_\-' . (!$css_id ? '\.' : '');
    return preg_replace("/[^$allowed]/i", $replace_with, $str);
}


/**
 * Remove single and double quotes from given string
 *
 * @param string Input value
 *
 * @return string Dequoted string
 */
function strip_quotes($str)
{
    return str_replace(array("'", '"'), '', $str);
}


/**
 * Remove new lines characters from given string
 *
 * @param string Input value
 *
 * @return string Stripped string
 */
function strip_newlines($str)
{
    return preg_replace('/[\r\n]/', '', $str);
}


/**
 * Improved equivalent to strtotime()
 *
 * @param string Date string
 *
 * @return int Unix timestamp
 */
function rcube_strtotime($date)
{
    // check for MS Outlook vCard date format YYYYMMDD
    if (preg_match('/^([12][90]\d\d)([01]\d)(\d\d)$/', trim($date), $matches)) {
        return mktime(0,0,0, intval($matches[2]), intval($matches[3]), intval($matches[1]));
    }
    else if (is_numeric($date)) {
        return $date;
    }

    // support non-standard "GMTXXXX" literal
    $date = preg_replace('/GMT\s*([+-][0-9]+)/', '\\1', $date);

    // if date parsing fails, we have a date in non-rfc format.
    // remove token from the end and try again
    while ((($ts = @strtotime($date)) === false) || ($ts < 0)) {
        $d = explode(' ', $date);
        array_pop($d);
        if (!$d) {
            break;
        }
        $date = implode(' ', $d);
    }

    return $ts;
}


/**
 * Compose a valid representation of name and e-mail address
 *
 * @param string E-mail address
 * @param string Person name
 *
 * @return string Formatted string
 */
function format_email_recipient($email, $name='')
{
    if ($name && $name != $email) {
        // Special chars as defined by RFC 822 need to in quoted string (or escaped).
        return sprintf('%s <%s>', preg_match('/[\(\)\<\>\\\.\[\]@,;:"]/', $name) ? '"'.addcslashes($name, '"').'"' : $name, trim($email));
    }

    return trim($email);
}


/**
 * mbstring replacement functions
 */
if (!extension_loaded('mbstring'))
{
    function mb_strlen($str)
    {
	return strlen($str);
    }

    function mb_strtolower($str)
    {
        return strtolower($str);
    }

    function mb_strtoupper($str)
    {
        return strtoupper($str);
    }

    function mb_substr($str, $start, $len=null)
    {
        return substr($str, $start, $len);
    }

    function mb_strpos($haystack, $needle, $offset=0)
    {
        return strpos($haystack, $needle, $offset);
    }

    function mb_strrpos($haystack, $needle, $offset=0)
    {
        return strrpos($haystack, $needle, $offset);
    }
}

/**
 * intl replacement functions
 */

if (!function_exists('idn_to_utf8'))
{
    function idn_to_utf8($domain, $flags=null)
    {
        static $idn, $loaded;

        if (!$loaded) {
            $idn = new Net_IDNA2();
            $loaded = true;
        }

        if ($idn && $domain && preg_match('/(^|\.)xn--/i', $domain)) {
            try {
                $domain = $idn->decode($domain);
            }
            catch (Exception $e) {
            }
        }
        return $domain;
    }
}

if (!function_exists('idn_to_ascii'))
{
    function idn_to_ascii($domain, $flags=null)
    {
        static $idn, $loaded;

        if (!$loaded) {
            $idn = new Net_IDNA2();
            $loaded = true;
        }

        if ($idn && $domain && preg_match('/[^\x20-\x7E]/', $domain)) {
            try {
                $domain = $idn->encode($domain);
            }
            catch (Exception $e) {
            }
        }
        return $domain;
    }
}


/*
 * Idn_to_ascii wrapper.
 * Intl/Idn modules version of this function doesn't work with e-mail address
 */
function rcube_idn_to_ascii($str)
{
    return rcube_idn_convert($str, true);
}

/*
 * Idn_to_ascii wrapper.
 * Intl/Idn modules version of this function doesn't work with e-mail address
 */
function rcube_idn_to_utf8($str)
{
    return rcube_idn_convert($str, false);
}

function rcube_idn_convert($input, $is_utf=false)
{
    if ($at = strpos($input, '@')) {
        $user   = substr($input, 0, $at);
        $domain = substr($input, $at+1);
    }
    else {
        $domain = $input;
    }

    $domain = $is_utf ? idn_to_ascii($domain) : idn_to_utf8($domain);

    if ($domain === false) {
        return '';
    }

    return $at ? $user . '@' . $domain : $domain;
}


/**
 * Use PHP5 autoload for dynamic class loading
 *
 * @todo Make Zend, PEAR etc play with this
 * @todo Make our classes conform to a more straight forward CS.
 */
function rcube_autoload($classname)
{
    $filename = preg_replace(
        array(
            '/MDB2_(.+)/',
            '/Mail_(.+)/',
            '/Net_(.+)/',
            '/Auth_(.+)/',
            '/^html_.+/',
            '/^utf8$/',
        ),
        array(
            'MDB2/\\1',
            'Mail/\\1',
            'Net/\\1',
            'Auth/\\1',
            'html',
            'utf8.class',
        ),
        $classname
    );

    if ($fp = @fopen("$filename.php", 'r', true)) {
        fclose($fp);
        include_once("$filename.php");
        return true;
    }

    return false;
}

/**
 * Local callback function for PEAR errors
 */
function rcube_pear_error($err)
{
    error_log(sprintf("%s (%s): %s",
        $err->getMessage(),
        $err->getCode(),
        $err->getUserinfo()), 0);
}
