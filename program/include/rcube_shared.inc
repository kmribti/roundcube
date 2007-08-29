<?php

/*
 +-----------------------------------------------------------------------+
 | rcube_shared.inc                                                      |
 |                                                                       |
 | This file is part of the RoundCube PHP suite                          |
 | Copyright (C) 2005-2007, RoundCube Dev. - Switzerland                 |
 | Licensed under the GNU GPL                                            |
 |                                                                       |
 | CONTENTS:                                                             |
 |   Shared functions and classes used in PHP projects                   |
 |                                                                       |
 +-----------------------------------------------------------------------+
 | Author: Thomas Bruederli <roundcube@gmail.com>                        |
 +-----------------------------------------------------------------------+

 $Id: rcube_shared.inc 543 2007-04-28 18:07:12Z thomasb $

*/


// ********* round cube schared classes *********
require_once dirname(__FILE__) . '/rcube/html_page.php';

require_once dirname(__FILE__) . '/rcube/css.php';

require_once dirname(__FILE__) . '/rcube/base_form_element.php';

require_once dirname(__FILE__) . '/rcube/form_elements.php';


/**
 * @todo create class file
 */


// ********* rcube schared functions *********


// provide details about the client's browser
function rcube_browser()
  {
  $HTTP_USER_AGENT = $_SERVER['HTTP_USER_AGENT'];

  $bw['ver'] = 0;
  $bw['win'] = stristr($HTTP_USER_AGENT, 'win');
  $bw['mac'] = stristr($HTTP_USER_AGENT, 'mac');
  $bw['linux'] = stristr($HTTP_USER_AGENT, 'linux');
  $bw['unix']  = stristr($HTTP_USER_AGENT, 'unix');

  $bw['ns4'] = stristr($HTTP_USER_AGENT, 'mozilla/4') && !stristr($HTTP_USER_AGENT, 'msie');
  $bw['ns']  = ($bw['ns4'] || stristr($HTTP_USER_AGENT, 'netscape'));
  $bw['ie']  = stristr($HTTP_USER_AGENT, 'msie');
  $bw['mz']  = stristr($HTTP_USER_AGENT, 'mozilla/5');
  $bw['opera'] = stristr($HTTP_USER_AGENT, 'opera');
  $bw['safari'] = stristr($HTTP_USER_AGENT, 'safari');

  if($bw['ns'])
    {
    $test = eregi("mozilla\/([0-9\.]+)", $HTTP_USER_AGENT, $regs);
    $bw['ver'] = $test ? (float)$regs[1] : 0;
    }
  if($bw['mz'])
    {
    $test = ereg("rv:([0-9\.]+)", $HTTP_USER_AGENT, $regs);
    $bw['ver'] = $test ? (float)$regs[1] : 0;
    }
  if($bw['ie'])
    {
    $test = eregi("msie ([0-9\.]+)", $HTTP_USER_AGENT, $regs);
    $bw['ver'] = $test ? (float)$regs[1] : 0;
    }
  if($bw['opera'])
    {
    $test = eregi("opera ([0-9\.]+)", $HTTP_USER_AGENT, $regs);
    $bw['ver'] = $test ? (float)$regs[1] : 0;
    }

  if(eregi(" ([a-z]{2})-([a-z]{2})", $HTTP_USER_AGENT, $regs))
    $bw['lang'] =  $regs[1];
  else
    $bw['lang'] =  'en';

  $bw['dom'] = ($bw['mz'] || $bw['safari'] || ($bw['ie'] && $bw['ver']>=5) || ($bw['opera'] && $bw['ver']>=7));
  $bw['pngalpha'] = $bw['mz'] || $bw['safari'] || ($bw['ie'] && $bw['ver']>=5.5) ||
                    ($bw['ie'] && $bw['ver']>=5 && $bw['mac']) || ($bw['opera'] && $bw['ver']>=7) ? TRUE : FALSE;

  return $bw;
  }

/**
 * Read a specific HTTP request header
 *
 * @access static
 * @param  string $name Header name
 * @return mixed  Header value or null if not available
 */
function rc_request_header($name)
{
    if (function_exists('getallheaders')) {
        $hdrs = getallheaders();
        $hdrs = array_change_key_case($hdrs, CASE_UPPER);
        $key  = strtoupper($name);
    }
    else {
        $key  = 'HTTP_' . strtoupper(strtr($name, '-', '_'));
        $hdrs = array_change_key_case($_SERVER[$key], CASE_UPPER);
    }
    if (isset($hdrs[$key])) {
        return $hdrs[$key];
    }
    return null;
}

// get text in the desired language from the language file
function rcube_label($attrib)
{
    $registry       = rc_registry::getInstance();
    $sess_user_lang = $registry->get('sess_user_lang', 'core');
    $INSTALL_PATH   = $registry->get('INSTALL_PATH', 'core');
    $OUTPUT         = $registry->get('OUTPUT', 'core');

    static $sa_text_data, $s_language, $utf8_decode;

    // extract attributes
    if (is_string($attrib)) {
        $attrib = array('name' => $attrib);
    }
    $nr = is_numeric($attrib['nr']) ? $attrib['nr'] : 1;
    $vars = isset($attrib['vars']) ? $attrib['vars'] : '';

    $command_name = !empty($attrib['command']) ? $attrib['command'] : NULL;
    $alias = $attrib['name'] ? $attrib['name'] : ($command_name && $command_label_map[$command_name] ? $command_label_map[$command_name] : '');


    // load localized texts
    if (!$sa_text_data || $s_language != $sess_user_lang) {
        $sa_text_data = array();

        // get english labels (these should be complete)
        @include($INSTALL_PATH.'program/localization/en_US/labels.inc');
        @include($INSTALL_PATH.'program/localization/en_US/messages.inc');

        if (is_array($labels)) {
            $sa_text_data = $labels;
        }
        if (is_array($messages)) {
            $sa_text_data = array_merge($sa_text_data, $messages);
        }
        // include user language files
        if (
            !empty($sess_user_lang)
            && $sess_user_lang != 'en'
            && is_dir($INSTALL_PATH.'program/localization/'.$sess_user_lang)
        ) {
            include_once $INSTALL_PATH.'program/localization/' . $sess_user_lang . '/labels.inc';
            include_once $INSTALL_PATH.'program/localization/' . $sess_user_lang . '/messages.inc';

            //rc_main::tfk_debug($sess_user_lang);

            if (is_array($labels)) {
                $sa_text_data = array_merge($sa_text_data, $labels);
            }
            if (is_array($messages)) {
                $sa_text_data = array_merge($sa_text_data, $messages);
            }
        }
        $s_language = $sess_user_lang;
    }

    // text does not exist
    if (!($text_item = $sa_text_data[$alias])) {
        /*
        rc_bugs::raise_error(
            array(
                'code' => 500,
                'type' => 'php',
                'line' => __LINE__,
                'file' => __FILE__,
                'message' => "Missing localized text for '$alias' in '$sess_user_lang'"
            ),
            TRUE,
            FALSE
        );
        */
        /**
         * We got as far - so let's see if $_SESSION contains what we are
         * looking for.
         *
         * @author Till Klampaeckel <till@php.net>
         */
        if (substr($alias, 0, 3) == 'RC_') {

            $_sess_ident = substr($alias, 3);

            if (isset($_SESSION[$_sess_ident]) === true) {
                return $_SESSION[$_sess_ident];
            }
        }
        return "[$alias]";
    }

    // make text item array
    $a_text_item = is_array($text_item) ? $text_item : array('single' => $text_item);

    // decide which text to use
    if ($nr==1) {
        $text = $a_text_item['single'];
    }
    elseif ($nr>0) {
        $text = $a_text_item['multiple'];
    }
    elseif ($nr==0) {
        if ($a_text_item['none']) {
            $text = $a_text_item['none'];
        }
        elseif ($a_text_item['single']) {
            $text = $a_text_item['single'];
        }
        elseif ($a_text_item['multiple']) {
            $text = $a_text_item['multiple'];
        }
    }

    // default text is single
    if ($text=='')
        $text = $a_text_item['single'];

    // replace vars in text
    if (is_array($attrib['vars'])) {
        foreach ($attrib['vars'] as $var_key=>$var_value) {
            $a_replace_vars[substr($var_key, 0, 1)=='$' ? substr($var_key, 1) : $var_key] = $var_value;
        }
    }

    if ($a_replace_vars) {
        $text = preg_replace('/\${?([_a-z]{1}[_a-z0-9]*)}?/ei', '$a_replace_vars["\1"]', $text);
    }
    // remove variables in text which were not available in arg $vars and $nr
    eval("\$text = <<<EOF
$text
EOF;
");

    // format output
    if (($attrib['uppercase'] && strtolower($attrib['uppercase']=='first')) || $attrib['ucfirst']) {
        return ucfirst($text);
    }
    elseif ($attrib['uppercase']) {
        return strtoupper($text);
    }
    elseif ($attrib['lowercase']) {
        return strtolower($text);
    }
    return $text;
}


// send HTTP header for no-cacheing steps
function send_nocacheing_headers()
{
    if (headers_sent()) {
        return;
    }
    header("Expires: ".gmdate("D, d M Y H:i:s")." GMT");
    header("Last-Modified: ".gmdate("D, d M Y H:i:s")." GMT");
    header("Cache-Control: no-store, no-cache, must-revalidate, post-check=0, pre-check=0");
    header("Pragma: no-cache");
}


// send header with expire date 30 days in future
function send_future_expire_header($offset=2600000)
  {
  if (headers_sent())
    return;

  header("Expires: ".gmdate("D, d M Y H:i:s", mktime()+$offset)." GMT");
  header("Cache-Control: max-age=$offset");
  header("Pragma: ");
  }


// check request for If-Modified-Since and send an according response
function send_modified_header($mdate, $etag=null)
{
    if (headers_sent()) {
        //rc_main::tfk_debug("/ Headerz sent?!");
        return;
    }
    $iscached = false;
    if (
        $_SERVER['HTTP_IF_MODIFIED_SINCE']
        && strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) >= $mdate
    ) {
        $iscached = true;
    }

    $etag = $etag ? "\"$etag\"" : null;
    if ($etag && $_SERVER['HTTP_IF_NONE_MATCH'] == $etag) {
        $iscached = true;
    }
    if ($iscached) {
        header("HTTP/1.x 304 Not Modified");
    }
    else {
        header("Last-Modified: ".gmdate("D, d M Y H:i:s", $mdate)." GMT");
    }
    header("Cache-Control: max-age=0");
    header("Expires: ");
    header("Pragma: ");

    if ($etag) {
        header("Etag: $etag");
    }
    $iscached = FALSE;
    if ($iscached) {
        //rc_main::tfk_debug("/ serving from cache");
        exit;
    }
}


/**
 * Convert a variable into a javascript notation string
 */
function json_serialize($var)
{
    if (is_object($var)) {
        $var = get_object_vars($var);
    }
    if (is_array($var)) {
        // empty array
        if (!sizeof($var)) {
            return '[]';
        }
        else {
            $keys_arr = array_keys($var);
            $is_assoc = $have_numeric = 0;

            for ($i=0; $i<sizeof($keys_arr); ++$i) {
                if (is_numeric($keys_arr[$i])) {
                    $have_numeric = 1;
                }
                if (!is_numeric($keys_arr[$i]) || $keys_arr[$i] != $i) {
                    $is_assoc = 1;
                }
                if ($is_assoc && $have_numeric) {
                    break;
                }
            }

            $brackets = $is_assoc ? '{}' : '[]';
            $pairs = array();

            foreach ($var as $key => $value) {
                // enclose key with quotes if it is not variable-name conform
                if (!ereg("^[_a-zA-Z]{1}[_a-zA-Z0-9]*$", $key) /* || is_js_reserved_word($key) */)
                    $key = "'$key'";

                $pairs[] = sprintf("%s%s", $is_assoc ? "$key:" : '', json_serialize($value));
            }
            return $brackets{0} . implode(',', $pairs) . $brackets{1};
        }
    }
    else if (is_numeric($var) && strval(intval($var)) === strval($var)) {
        return $var;
    }
    else if (is_bool($var)) {
        return $var ? '1' : '0';
    }
    else {
        return "'" . rc_main::JQ($var) . "'";
    }
}

/**
 * function to convert an array to a javascript array
 * @deprecated
 */
function array2js($arr, $type='')
{
    return json_serialize($arr);
}


/**
 * Similar function as in_array() but case-insensitive
 */
function in_array_nocase($needle, $haystack)
{
    foreach ($haystack as $value) {
        if (strtolower($needle)===strtolower($value)) {
            return TRUE;
        }
    }
    return FALSE;
}


/**
 * Find out if the string content means TRUE or FALSE
 */
function get_boolean($str)
{
    $str = strtolower($str);
    if(in_array($str, array('false', '0', 'no', 'nein', ''), TRUE)) {
        return FALSE;
    }
    else {
        return TRUE;
    }
}


// parse a human readable string for a number of bytes
function parse_bytes($str)
  {
  if (is_numeric($str))
    return intval($str);

  if (preg_match('/([0-9]+)([a-z])/i', $str, $regs))
    {
      $bytes = floatval($regs[1]);
      switch (strtolower($regs[2]))
      {
        case 'g':
          $bytes *= 1073741824;
          break;
        case 'm':
          $bytes *= 1048576;
          break;
        case 'k':
          $bytes *= 1024;
          break;
      }
    }

  return intval($bytes);
  }

// create a human readable string for a number of bytes
function show_bytes($bytes)
  {
  if ($bytes > 1073741824)
    {
    $gb = $bytes/1073741824;
    $str = sprintf($gb>=10 ? "%d GB" : "%.1f GB", $gb);
    }
  else if ($bytes > 1048576)
    {
    $mb = $bytes/1048576;
    $str = sprintf($mb>=10 ? "%d MB" : "%.1f MB", $mb);
    }
  else if ($bytes > 1024)
    $str = sprintf("%d KB",  round($bytes/1024));
  else
    $str = sprintf('%d B', $bytes);

  return $str;
  }


// convert paths like ../xxx to an absolute path using a base url
function make_absolute_url($path, $base_url)
    {
    $host_url = $base_url;
    $abs_path = $path;

    // cut base_url to the last directory
    if (strpos($base_url, '/')>7)
      {
      $host_url = substr($base_url, 0, strpos($base_url, '/'));
      $base_url = substr($base_url, 0, strrpos($base_url, '/'));
      }

    // $path is absolute
    if ($path{0}=='/')
      $abs_path = $host_url.$path;
    else
      {
      // strip './' because its the same as ''
      $path = preg_replace('/^\.\//', '', $path);

      if(preg_match_all('/\.\.\//', $path, $matches, PREG_SET_ORDER))
        foreach($matches as $a_match)
          {
          if (strrpos($base_url, '/'))
            $base_url = substr($base_url, 0, strrpos($base_url, '/'));

          $path = substr($path, 3);
          }

      $abs_path = $base_url.'/'.$path;
      }

    return $abs_path;
    }


// wrapper function for strlen
function rc_strlen($str)
  {
    if (function_exists('mb_strlen'))
      return mb_strlen($str);
    else
      return strlen($str);
  }

// wrapper function for strtolower
function rc_strtolower($str)
  {
    if (function_exists('mb_strtolower'))
      return mb_strtolower($str);
    else
      return strtolower($str);
  }

// wrapper function for substr
function rc_substr($str, $start, $len=null)
  {
  if (function_exists('mb_substr'))
    return mb_substr($str, $start, $len);
  else
    return substr($str, $start, $len);
  }

// wrapper function for strpos
function rc_strpos($haystack, $needle, $offset=0)
  {
  if (function_exists('mb_strpos'))
    return mb_strpos($haystack, $needle, $offset);
  else
    return strpos($haystack, $needle, $offset);
  }

// wrapper function for strrpos
function rc_strrpos($haystack, $needle, $offset=0)
  {
  if (function_exists('mb_strrpos'))
    return mb_strrpos($haystack, $needle, $offset);
  else
    return strrpos($haystack, $needle, $offset);
  }


// replace the middle part of a string with ...
// if it is longer than the allowed length
function abbrevate_string($str, $maxlength, $place_holder='...')
  {
  $length = rc_strlen($str);
  $first_part_length = floor($maxlength/2) - rc_strlen($place_holder);

  if ($length > $maxlength)
    {
    $second_starting_location = $length - $maxlength + $first_part_length + 1;
    $str = rc_substr($str, 0, $first_part_length) . $place_holder . rc_substr($str, $second_starting_location, $length);
    }

  return $str;
  }


// make sure the string ends with a slash
function slashify($str)
  {
  return unslashify($str).'/';
  }


// remove slash at the end of the string
function unslashify($str)
  {
  return preg_replace('/\/$/', '', $str);
  }


// delete all files within a folder
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


// create a unix timestamp with a specified offset from now
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
 * strrstr
 *
 * return the last occurence of a string in another string
 * @param haystack string string in which to search
 * @param needle string string for which to search
 * @return index of needle within haystack, or false if not found
 */
function strrstr($haystack, $needle)
  {
    $pver = phpversion();
    if ($pver[0] >= 5)
      {
        return strrpos($haystack, $needle);
      }
    else
      {
        $index = strpos(strrev($haystack), strrev($needle));
        if($index === false) {
            return false;
        }
        $index = strlen($haystack) - strlen($needle) - $index;
        return $index;
      }
  }


?>
