<?php
/*
 +-----------------------------------------------------------------------+
 | globals.php                                                           |
 |                                                                       |
 | This file is part of the RoundCube PHP suite                          |
 | Copyright (C) 2005-2007, RoundCube Dev. - Switzerland                 |
 | Licensed under the GNU GPL                                            |
 |                                                                       |
 | CONTENTS:                                                             |
 |   Non-application specific but convenient functions                   |
 |                                                                       |
 +-----------------------------------------------------------------------+
 | Author: Thomas Bruederli <roundcube@gmail.com>                        |
 +-----------------------------------------------------------------------+

 $Id$

 */

/**
 * RoundCube global functions
 *
 * @package Core
 */


/**
 * Quote a given string.
 * Shortcut function for rep_specialchars_output
 *
 * @return string HTML-quoted string
 * @see rcube::rep_specialchars_output()
 */
function Q($str = '', $mode = 'strict', $newlines = true) {
    return rcube::rep_specialchars_output($str, 'html', $mode, $newlines);
}

/**
 * Quote a given string for javascript output.
 * Shortcut function for rep_specialchars_output
 *
 * @return string JS-quoted string
 * @see rcube::rep_specialchars_output()
 */
function JQ($str = '') {
    return rcube::rep_specialchars_output($str, 'js');
}

/**
 * Remove all non-ascii and non-word chars
 * except . and -
 */
function asciiwords($str = '') {
    return preg_replace('/[^a-z0-9.-_]/i', '', $str);
}

/**
 * Remove single and double quotes from given string
 *
 * @param string Input value
 * @return string Dequoted string
 */
function strip_quotes($str = '') {
    return preg_replace('/[\'"]/', '', $str);
}

/**
 * Remove new lines characters from given string
 *
 * @param string Input value
 * @return string Stripped string
 */
function strip_newlines($str = '') {
    return preg_replace('/[\r\n]/', '', $str);
}

/**
 * Send HTTP headers to prevent caching this page
 *
 * @return void
 */
function send_nocacheing_headers() {
    if (headers_sent()) {
        return;
    }
    header('Expires: '.gmdate('D, d M Y H:i:s').' GMT');
    header('Last-Modified: '.gmdate('D, d M Y H:i:s').' GMT');
    header('Cache-Control: no-store, no-cache, must-revalidate, post-check=0, pre-check=0');
    header('Pragma: no-cache');
}

/**
 * Send header with expire date 30 days in future
 *
 * @param int Expiration time in seconds
 * @return void
 */
function send_future_expire_header($offset = 2600000) {
    if (headers_sent()) {
        return;
    }

    header('Expires: '.gmdate('D, d M Y H:i:s', mktime()+$offset).' GMT');
    header('Cache-Control: max-age='.$offset);
    header('Pragma: ');
}


/**
 * Check request for If-Modified-Since and send an according response.
 * This will terminate the current script if headers match the given values
 *
 * @param int Modified date as unix timestamp
 * @param string Etag value for caching
 * @return void
 */
function send_modified_header($mdate, $etag = null) {
    if (headers_sent()) {
        return;
    }
    $iscached = false;
    if ($_SERVER['HTTP_IF_MODIFIED_SINCE'] && strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) >= $mdate) {
        $iscached = true;
    }

    $etag = $etag ? '"'.$etag.'"' : null;
    if ($etag && $_SERVER['HTTP_IF_NONE_MATCH'] == $etag) {
        $iscached = true;
    }

    if ($iscached) {
        header('HTTP/1.x 304 Not Modified');
    } else {
        header('Last-Modified: '.gmdate('D, d M Y H:i:s', $mdate).' GMT');
    }
    header('Cache-Control: max-age=0');
    header('Expires: ');
    header('Pragma: ');

    if ($etag) {
        header('Etag: '.$etag);
    }

    if ($iscached) {
        exit;
    }
}

/**
 * Convert a variable into a javascript object notation
 *
 * @param mixed Input value
 * @return string Serialized JSON string
 */
function json_serialize($var) {
    if (is_object($var)) {
        $var = get_object_vars($var);
    }
    if (is_array($var)) {
        // empty array
        if (!sizeof($var)) {
            return '[]';
        } else {
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
                if (!preg_match('/^[_a-zA-Z]{1}[_a-zA-Z0-9]*$/', $key) /* || is_js_reserved_word($key) */) {
                    $key = "'$key'";
                }
                $pairs[] = sprintf("%s%s", $is_assoc ? $key.':' : '', json_serialize($value));
            }
            return $brackets{0} . implode(',', $pairs) . $brackets{1};
        }
    } else if (is_numeric($var) && strval(intval($var)) === strval($var)) {
        return $var;
    } else if (is_bool($var)) {
        return $var ? '1' : '0';
    } else {
        return "'" . JQ($var) . "'";
    }
}

/**
 * Similar function as in_array() but case-insensitive
 *
 * @param mixed Needle value
 * @param array Array to search in
 * @return boolean True if found, False if not
 */
function in_array_nocase($needle, $haystack) {
    foreach ($haystack as $value) {
        if (strtolower($needle) === strtolower($value)) {
            return true;
        }
    }
    return false;
}


/**
 * Find out if the string content means TRUE or FALSE
 *
 * @param string Input value
 * @return boolean Imagine what!
 */
//TODO wtf? purge this
function get_boolean($str) {
    if (in_array(strtolower($str), array('false', '0', 'no', 'nein', ''), true)) {
        return false;
    }
    return true;
}

/**
 * Parse a human readable string for a number of bytes
 *
 * @param string Input string
 * @return int Number of bytes
 */
function parse_bytes($str) {
    if (is_numeric($str)) {
        return intval($str);
    }

    if (preg_match('/([0-9]+)([a-z])/i', $str, $regs)) {
        $bytes = floatval($regs[1]);
        switch (strtolower($regs[2])) {
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


/**
 * Create a human readable string for a number of bytes
 *
 * @param int Number of bytes
 * @return string Byte string
 */
function show_bytes($bytes) {
    if ($bytes > 1073741824) {
        $gb  = $bytes/1073741824;
        $str = sprintf($gb>=10 ? "%d GB" : "%.1f GB", $gb);
    } else if ($bytes > 1048576) {
        $mb  = $bytes/1048576;
        $str = sprintf($mb>=10 ? "%d MB" : "%.1f MB", $mb);
    } else if ($bytes > 1024) {
        $str = sprintf("%d KB",  round($bytes/1024));
    } else {
        $str = sprintf('%d B', $bytes);
    }
    return $str;
}


/**
 * Convert paths like ../xxx to an absolute path using a base url
 *
 * @param string Relative path
 * @param string Base URL
 * @return string Absolute URL
 */
function make_absolute_url($path, $base_url) {
    $host_url = $base_url;
    $abs_path = $path;

    // check if path is an absolute URL
    if (preg_match('/^[fhtps]+:\/\//', $path)) {
        return $path;
    }
    // cut base_url to the last directory
    if (strpos($base_url, '/') > 7) {
        $host_url = substr($base_url, 0, strpos($base_url, '/'));
        $base_url = substr($base_url, 0, strrpos($base_url, '/'));
    }

    // $path is absolute
    if ($path{0} == '/') {
        $abs_path = $host_url.$path;
    } else {
        // strip './' because its the same as ''
        $path = preg_replace('/^\.\//', '', $path);

        if (preg_match_all('/\.\.\//', $path, $matches, PREG_SET_ORDER)) {
            foreach($matches as $a_match) {
                if (strrpos($base_url, '/')) {
                    $base_url = substr($base_url, 0, strrpos($base_url, '/'));
                }
                $path = substr($path, 3);
            }
        }
        $abs_path = $base_url.'/'.$path;
    }
    return $abs_path;
}


/**
 * Wrapper function for strlen
 */
function rc_strlen($str) {
    if (function_exists('mb_strlen')) {
        return mb_strlen($str);
    }
    return strlen($str);
}

/**
 * Wrapper function for strtolower
 */
function rc_strtolower($str) {
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($str);
    }
    return strtolower($str);
}

/**
 * Wrapper function for substr
 */
function rc_substr($str, $start, $len = null) {
    if (function_exists('mb_substr')) {
        return mb_substr($str, $start, $len);
    }
    return substr($str, $start, $len);
}

/**
 * Wrapper function for strpos
 */
function rc_strpos($haystack, $needle, $offset = 0) {
    if (function_exists('mb_strpos')) {
        return mb_strpos($haystack, $needle, $offset);
    }
    return strpos($haystack, $needle, $offset);
}

/**
 * Wrapper function for strrpos
 */
function rc_strrpos($haystack, $needle, $offset = 0) {
    if (function_exists('mb_strrpos')) {
        return mb_strrpos($haystack, $needle, $offset);
    }
    return strrpos($haystack, $needle, $offset);
}

/**
 * Replace the middle part of a string with ...
 * if it is longer than the allowed length
 *
 * @param string Input string
 * @param int    Max. length
 * @param string Replace removed chars with this
 * @return string Abbrevated string
 */
function abbrevate_string($str, $maxlength, $place_holder = '...') {
    $length = rc_strlen($str);
    $first_part_length = floor($maxlength/2) - rc_strlen($place_holder);

    if ($length > $maxlength) {
        $second_starting_location = $length - $maxlength + $first_part_length + 1;
        $str = rc_substr($str, 0, $first_part_length) . $place_holder . rc_substr($str, $second_starting_location, $length);
    }
    return $str;
}

/**
 * Make sure the string ends with a slash
 */
function slashify($str) {
    return unslashify($str).'/';
}

/**
 * Remove slash at the end of the string
 */
function unslashify($str) {
    return preg_replace('/\/$/', '', $str);
}

/**
 * Delete all files within a folder
 *
 * @param string Path to directory
 * @return boolean True on success, False if directory was not found
 */
function clear_directory($dir_path) {
    $dir = opendir($dir_path);
    if (!$dir) {
        return false;
    }

    while ($file = readdir($dir)) {
        if (strlen($file) > 2) {
            unlink($dir_path.'/'.$file);
        }
    }

    closedir($dir);
    return true;
}

/**
 * Create a unix timestamp with a specified offset from now
 *
 * @param string String representation of the offset (e.g. 20min, 5h, 2days)
 * @param int Factor to multiply with the offset
 * @return int Unix timestamp
 * @todo Check the switch() - it looks weird.
 */
function get_offset_time($offset_str, $factor = 1) {
    if (preg_match('/^([0-9]+)\s*([smhdw])/i', $offset_str, $regs)) {
        $amount = (int)$regs[1];
        $unit = strtolower($regs[2]);
    } else {
        $amount = (int)$offset_str;
        $unit = 's';
    }

    $ts = mktime();
    switch ($unit) {
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

function explode_quoted_string($delimiter, $string) {
    $result = array();
    $strlen = strlen($string);
    for ($q=$p=$i=0; $i < $strlen; $i++) {
        if ($string{$i} == "\"" && $string{$i-1} != "\\") {
        $q = $q ? false : true;
        } else if (!$q && preg_match("/$delimiter/", $string{$i})) {
            $result[] = substr($string, $p, $i - $p);
            $p = $i + 1;
        }
    }

    $result[] = substr($string, $p);
    return $result;
}

?>