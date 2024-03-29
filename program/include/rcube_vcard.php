<?php

/*
 +-----------------------------------------------------------------------+
 | program/include/rcube_vcard.php                                       |
 |                                                                       |
 | This file is part of the Roundcube Webmail client                     |
 | Copyright (C) 2008-2011, The Roundcube Dev Team                       |
 |                                                                       |
 | Licensed under the GNU General Public License version 3 or            |
 | any later version with exceptions for skins & plugins.                |
 | See the README file for a full license statement.                     |
 |                                                                       |
 | PURPOSE:                                                              |
 |   Logical representation of a vcard address record                    |
 +-----------------------------------------------------------------------+
 | Author: Thomas Bruederli <roundcube@gmail.com>                        |
 +-----------------------------------------------------------------------+

 $Id$

*/


/**
 * Logical representation of a vcard-based address record
 * Provides functions to parse and export vCard data format
 *
 * @package    Addressbook
 * @author     Thomas Bruederli <roundcube@gmail.com>
 */
class rcube_vcard
{
  private static $values_decoded = false;
  private $raw = array(
    'FN' => array(),
    'N' => array(array('','','','','')),
  );
  private static $fieldmap = array(
    'phone'    => 'TEL',
    'birthday' => 'BDAY',
    'website'  => 'URL',
    'notes'    => 'NOTE',
    'email'    => 'EMAIL',
    'address'  => 'ADR',
    'jobtitle' => 'TITLE',
    'department'  => 'X-DEPARTMENT',
    'gender'      => 'X-GENDER',
    'maidenname'  => 'X-MAIDENNAME',
    'anniversary' => 'X-ANNIVERSARY',
    'assistant'   => 'X-ASSISTANT',
    'manager'     => 'X-MANAGER',
    'spouse'      => 'X-SPOUSE',
    'edit'        => 'X-AB-EDIT',
  );
  private $typemap = array('iPhone' => 'mobile', 'CELL' => 'mobile', 'WORK,FAX' => 'workfax');
  private $phonetypemap = array('HOME1' => 'HOME', 'BUSINESS1' => 'WORK', 'BUSINESS2' => 'WORK2', 'BUSINESSFAX' => 'WORK,FAX');
  private $addresstypemap = array('BUSINESS' => 'WORK');
  private $immap = array('X-JABBER' => 'jabber', 'X-ICQ' => 'icq', 'X-MSN' => 'msn', 'X-AIM' => 'aim', 'X-YAHOO' => 'yahoo', 'X-SKYPE' => 'skype', 'X-SKYPE-USERNAME' => 'skype');

  public $business = false;
  public $displayname;
  public $surname;
  public $firstname;
  public $middlename;
  public $nickname;
  public $organization;
  public $notes;
  public $email = array();

  public static $eol = "\r\n";

  /**
   * Constructor
   */
  public function __construct($vcard = null, $charset = RCMAIL_CHARSET, $detect = false, $fieldmap = array())
  {
    if (!empty($fielmap))
      $this->extend_fieldmap($fieldmap);

    if (!empty($vcard))
      $this->load($vcard, $charset, $detect);
  }


  /**
   * Load record from (internal, unfolded) vcard 3.0 format
   *
   * @param string vCard string to parse
   * @param string Charset of string values
   * @param boolean True if loading a 'foreign' vcard and extra heuristics for charset detection is required
   */
  public function load($vcard, $charset = RCMAIL_CHARSET, $detect = false)
  {
    self::$values_decoded = false;
    $this->raw = self::vcard_decode($vcard);

    // resolve charset parameters
    if ($charset == null) {
      $this->raw = self::charset_convert($this->raw);
    }
    // vcard has encoded values and charset should be detected
    else if ($detect && self::$values_decoded &&
      ($detected_charset = self::detect_encoding(self::vcard_encode($this->raw))) && $detected_charset != RCMAIL_CHARSET) {
        $this->raw = self::charset_convert($this->raw, $detected_charset);
    }
    
    // consider FN empty if the same as the primary e-mail address
    if ($this->raw['FN'][0][0] == $this->raw['EMAIL'][0][0])
      $this->raw['FN'][0][0] = '';

    // find well-known address fields
    $this->displayname = $this->raw['FN'][0][0];
    $this->surname = $this->raw['N'][0][0];
    $this->firstname = $this->raw['N'][0][1];
    $this->middlename = $this->raw['N'][0][2];
    $this->nickname = $this->raw['NICKNAME'][0][0];
    $this->organization = $this->raw['ORG'][0][0];
    $this->business = ($this->raw['X-ABSHOWAS'][0][0] == 'COMPANY') || (join('', (array)$this->raw['N'][0]) == '' && !empty($this->organization));

    foreach ((array)$this->raw['EMAIL'] as $i => $raw_email)
      $this->email[$i] = is_array($raw_email) ? $raw_email[0] : $raw_email;

    // make the pref e-mail address the first entry in $this->email
    $pref_index = $this->get_type_index('EMAIL', 'pref');
    if ($pref_index > 0) {
      $tmp = $this->email[0];
      $this->email[0] = $this->email[$pref_index];
      $this->email[$pref_index] = $tmp;
    }
  }


  /**
   * Return vCard data as associative array to be unsed in Roundcube address books
   *
   * @return array Hash array with key-value pairs
   */
  public function get_assoc()
  {
    $out = array('name' => $this->displayname);
    $typemap = $this->typemap;

    // copy name fields to output array
    foreach (array('firstname','surname','middlename','nickname','organization') as $col) {
      if (strlen($this->$col))
        $out[$col] = $this->$col;
    }

    if ($this->raw['N'][0][3])
      $out['prefix'] = $this->raw['N'][0][3];
    if ($this->raw['N'][0][4])
      $out['suffix'] = $this->raw['N'][0][4];

    // convert from raw vcard data into associative data for Roundcube
    foreach (array_flip(self::$fieldmap) as $tag => $col) {
      foreach ((array)$this->raw[$tag] as $i => $raw) {
        if (is_array($raw)) {
          $k = -1;
          $key = $col;
          $subtype = '';

          if (!empty($raw['type'])) {
            $combined = join(',', self::array_filter((array)$raw['type'], 'internet,pref', true));
            $subtype = $typemap[$combined] ? $typemap[$combined] : ($typemap[$raw['type'][++$k]] ? $typemap[$raw['type'][$k]] : strtolower($raw['type'][$k]));
            while ($k < count($raw['type']) && ($subtype == 'internet' || $subtype == 'pref'))
              $subtype = $typemap[$raw['type'][++$k]] ? $typemap[$raw['type'][$k]] : strtolower($raw['type'][$k]);
          }

          // read vcard 2.1 subtype
          if (!$subtype) {
            foreach ($raw as $k => $v) {
              if (!is_numeric($k) && $v === true && !in_array(strtolower($k), array('pref','internet','voice','base64'))) {
                $subtype = $typemap[$k] ? $typemap[$k] : strtolower($k);
                break;
              }
            }
          }

          // force subtype if none set
          if (!$subtype && preg_match('/^(email|phone|address|website)/', $key))
            $subtype = 'other';

          if ($subtype)
            $key .= ':' . $subtype;

          // split ADR values into assoc array
          if ($tag == 'ADR') {
            list(,, $value['street'], $value['locality'], $value['region'], $value['zipcode'], $value['country']) = $raw;
            $out[$key][] = $value;
          }
          else
            $out[$key][] = $raw[0];
        }
        else {
          $out[$col][] = $raw;
        }
      }
    }

    // handle special IM fields as used by Apple
    foreach ($this->immap as $tag => $type) {
      foreach ((array)$this->raw[$tag] as $i => $raw) {
        $out['im:'.$type][] = $raw[0];
      }
    }

    // copy photo data
    if ($this->raw['PHOTO'])
      $out['photo'] = $this->raw['PHOTO'][0][0];

    return $out;
  }


  /**
   * Convert the data structure into a vcard 3.0 string
   */
  public function export($folded = true)
  {
    $vcard = self::vcard_encode($this->raw);
    return $folded ? self::rfc2425_fold($vcard) : $vcard;
  }


  /**
   * Clear the given fields in the loaded vcard data
   *
   * @param array List of field names to be reset
   */
  public function reset($fields = null)
  {
    if (!$fields)
      $fields = array_merge(array_values(self::$fieldmap), array_keys($this->immap), array('FN','N','ORG','NICKNAME','EMAIL','ADR','BDAY'));

    foreach ($fields as $f)
      unset($this->raw[$f]);

    if (!$this->raw['N'])
      $this->raw['N'] = array(array('','','','',''));
    if (!$this->raw['FN'])
      $this->raw['FN'] = array();

    $this->email = array();
  }


  /**
   * Setter for address record fields
   *
   * @param string Field name
   * @param string Field value
   * @param string Type/section name
   */
  public function set($field, $value, $type = 'HOME')
  {
    $field = strtolower($field);
    $type_uc = strtoupper($type);
    $typemap = array_flip($this->typemap);

    switch ($field) {
      case 'name':
      case 'displayname':
        $this->raw['FN'][0][0] = $value;
        break;

      case 'surname':
        $this->raw['N'][0][0] = $value;
        break;

      case 'firstname':
        $this->raw['N'][0][1] = $value;
        break;

      case 'middlename':
        $this->raw['N'][0][2] = $value;
        break;

      case 'prefix':
        $this->raw['N'][0][3] = $value;
        break;

      case 'suffix':
        $this->raw['N'][0][4] = $value;
        break;

      case 'nickname':
        $this->raw['NICKNAME'][0][0] = $value;
        break;

      case 'organization':
        $this->raw['ORG'][0][0] = $value;
        break;

      case 'photo':
        if (strpos($value, 'http:') === 0) {
            // TODO: fetch file from URL and save it locally?
            $this->raw['PHOTO'][0] = array(0 => $value, 'URL' => true);
        }
        else {
            $encoded = !preg_match('![^a-z0-9/=+-]!i', $value);
            $this->raw['PHOTO'][0] = array(0 => $encoded ? $value : base64_encode($value), 'BASE64' => true);
        }
        break;

      case 'email':
        $this->raw['EMAIL'][] = array(0 => $value, 'type' => array_filter(array('INTERNET', $type_uc)));
        $this->email[] = $value;
        break;

      case 'im':
        // save IM subtypes into extension fields
        $typemap = array_flip($this->immap);
        if ($field = $typemap[strtolower($type)])
          $this->raw[$field][] = array(0 => $value);
        break;

      case 'birthday':
        if ($val = rcube_strtotime($value))
          $this->raw['BDAY'][] = array(0 => date('Y-m-d', $val), 'value' => array('date'));
        break;

      case 'address':
        if ($this->addresstypemap[$type_uc])
          $type = $this->addresstypemap[$type_uc];

        $value = $value[0] ? $value : array('', '', $value['street'], $value['locality'], $value['region'], $value['zipcode'], $value['country']);

        // fall through if not empty
        if (!strlen(join('', $value)))
          break;

      default:
        if ($field == 'phone' && $this->phonetypemap[$type_uc])
          $type = $this->phonetypemap[$type_uc];

        if (($tag = self::$fieldmap[$field]) && (is_array($value) || strlen($value))) {
          $index = count($this->raw[$tag]);
          $this->raw[$tag][$index] = (array)$value;
          if ($type)
            $this->raw[$tag][$index]['type'] = explode(',', ($typemap[$type] ? $typemap[$type] : $type));
        }
        break;
    }
  }

  /**
   * Setter for individual vcard properties
   *
   * @param string VCard tag name
   * @param array Value-set of this vcard property
   * @param boolean Set to true if the value-set should be appended instead of replacing any existing value-set
   */
  public function set_raw($tag, $value, $append = false)
  {
    $index = $append ? count($this->raw[$tag]) : 0;
    $this->raw[$tag][$index] = (array)$value;
  }


  /**
   * Find index with the '$type' attribute
   *
   * @param string Field name
   * @return int Field index having $type set
   */
  private function get_type_index($field, $type = 'pref')
  {
    $result = 0;
    if ($this->raw[$field]) {
      foreach ($this->raw[$field] as $i => $data) {
        if (is_array($data['type']) && in_array_nocase('pref', $data['type']))
          $result = $i;
      }
    }

    return $result;
  }


  /**
   * Convert a whole vcard (array) to UTF-8.
   * If $force_charset is null, each member value that has a charset parameter will be converted
   */
  private static function charset_convert($card, $force_charset = null)
  {
    foreach ($card as $key => $node) {
      foreach ($node as $i => $subnode) {
        if (is_array($subnode) && (($charset = $force_charset) || ($subnode['charset'] && ($charset = $subnode['charset'][0])))) {
          foreach ($subnode as $j => $value) {
            if (is_numeric($j) && is_string($value))
              $card[$key][$i][$j] = rcube_charset_convert($value, $charset);
          }
          unset($card[$key][$i]['charset']);
        }
      }
    }

    return $card;
  }


  /**
   * Extends fieldmap definition
   */
  public function extend_fieldmap($map)
  {
    if (is_array($map))
      self::$fieldmap = array_merge($map, self::$fieldmap);
  }


  /**
   * Factory method to import a vcard file
   *
   * @param string vCard file content
   * @return array List of rcube_vcard objects
   */
  public static function import($data)
  {
    $out = array();

    // check if charsets are specified (usually vcard version < 3.0 but this is not reliable)
    if (preg_match('/charset=/i', substr($data, 0, 2048)))
      $charset = null;
    // detect charset and convert to utf-8
    else if (($charset = self::detect_encoding($data)) && $charset != RCMAIL_CHARSET) {
      $data = rcube_charset_convert($data, $charset);
      $data = preg_replace(array('/^[\xFE\xFF]{2}/', '/^\xEF\xBB\xBF/', '/^\x00+/'), '', $data); // also remove BOM
      $charset = RCMAIL_CHARSET;
    }

    $vcard_block = '';
    $in_vcard_block = false;

    foreach (preg_split("/[\r\n]+/", $data) as $i => $line) {
      if ($in_vcard_block && !empty($line))
        $vcard_block .= $line . "\n";

      $line = trim($line);

      if (preg_match('/^END:VCARD$/i', $line)) {
        // parse vcard
        $obj = new rcube_vcard(self::cleanup($vcard_block), $charset, true, self::$fieldmap);
        if (!empty($obj->displayname) || !empty($obj->email))
          $out[] = $obj;

        $in_vcard_block = false;
      }
      else if (preg_match('/^BEGIN:VCARD$/i', $line)) {
        $vcard_block = $line . "\n";
        $in_vcard_block = true;
      }
    }

    return $out;
  }


  /**
   * Normalize vcard data for better parsing
   *
   * @param string vCard block
   * @return string Cleaned vcard block
   */
  private static function cleanup($vcard)
  {
    // Convert special types (like Skype) to normal type='skype' classes with this simple regex ;)
    $vcard = preg_replace(
      '/item(\d+)\.(TEL|EMAIL|URL)([^:]*?):(.*?)item\1.X-ABLabel:(?:_\$!<)?([\w-() ]*)(?:>!\$_)?./s',
      '\2;type=\5\3:\4',
      $vcard);

    // convert Apple X-ABRELATEDNAMES into X-* fields for better compatibility
    $vcard = preg_replace_callback(
      '/item(\d+)\.(X-ABRELATEDNAMES)([^:]*?):(.*?)item\1.X-ABLabel:(?:_\$!<)?([\w-() ]*)(?:>!\$_)?./s',
      array('self', 'x_abrelatednames_callback'),
      $vcard);

    // Remove cruft like item1.X-AB*, item1.ADR instead of ADR, and empty lines
    $vcard = preg_replace(array('/^item\d*\.X-AB.*$/m', '/^item\d*\./m', "/\n+/"), array('', '', "\n"), $vcard);

    // convert X-WAB-GENDER to X-GENDER
    if (preg_match('/X-WAB-GENDER:(\d)/', $vcard, $matches)) {
      $value = $matches[1] == '2' ? 'male' : 'female';
      $vcard = preg_replace('/X-WAB-GENDER:\d/', 'X-GENDER:' . $value, $vcard);
    }

    // if N doesn't have any semicolons, add some 
    $vcard = preg_replace('/^(N:[^;\R]*)$/m', '\1;;;;', $vcard);

    return $vcard;
  }

  private static function x_abrelatednames_callback($matches)
  {
    return 'X-' . strtoupper($matches[5]) . $matches[3] . ':'. $matches[4];
  }

  private static function rfc2425_fold_callback($matches)
  {
    // chunk_split string and avoid lines breaking multibyte characters
    $c = 71;
    $out .= substr($matches[1], 0, $c);
    for ($n = $c; $c < strlen($matches[1]); $c++) {
      // break if length > 75 or mutlibyte character starts after position 71
      if ($n > 75 || ($n > 71 && ord($matches[1][$c]) >> 6 == 3)) {
        $out .= "\r\n ";
        $n = 0;
      }
      $out .= $matches[1][$c];
      $n++;
    }

    return $out;
  }

  public static function rfc2425_fold($val)
  {
    return preg_replace_callback('/([^\n]{72,})/', array('self', 'rfc2425_fold_callback'), $val);
  }


  /**
   * Decodes a vcard block (vcard 3.0 format, unfolded)
   * into an array structure
   *
   * @param string vCard block to parse
   * @return array Raw data structure
   */
  private static function vcard_decode($vcard)
  {
    // Perform RFC2425 line unfolding and split lines
    $vcard = preg_replace(array("/\r/", "/\n\s+/"), '', $vcard);
    $lines = explode("\n", $vcard);
    $data  = array();

    for ($i=0; $i < count($lines); $i++) {
      if (!preg_match('/^([^:]+):(.+)$/', $lines[$i], $line))
        continue;

      if (preg_match('/^(BEGIN|END)$/i', $line[1]))
        continue;

      // convert 2.1-style "EMAIL;internet;home:" to 3.0-style "EMAIL;TYPE=internet;TYPE=home:"
      if (($data['VERSION'][0] == "2.1") && preg_match('/^([^;]+);([^:]+)/', $line[1], $regs2) && !preg_match('/^TYPE=/i', $regs2[2])) {
        $line[1] = $regs2[1];
        foreach (explode(';', $regs2[2]) as $prop)
          $line[1] .= ';' . (strpos($prop, '=') ? $prop : 'TYPE='.$prop);
      }

      if (preg_match_all('/([^\\;]+);?/', $line[1], $regs2)) {
        $entry = array();
        $field = strtoupper($regs2[1][0]);

        foreach($regs2[1] as $attrid => $attr) {
          if ((list($key, $value) = explode('=', $attr)) && $value) {
            $value = trim($value);
            if ($key == 'ENCODING') {
              // add next line(s) to value string if QP line end detected
              while ($value == 'QUOTED-PRINTABLE' && preg_match('/=$/', $lines[$i]))
                  $line[2] .= "\n" . $lines[++$i];

              $line[2] = self::decode_value($line[2], $value);
            }
            else
              $entry[strtolower($key)] = array_merge((array)$entry[strtolower($key)], (array)self::vcard_unquote($value, ','));
          }
          else if ($attrid > 0) {
            $entry[$key] = true;  // true means attr without =value
          }
        }

        $entry = array_merge($entry, (array)self::vcard_unquote($line[2]));
        $data[$field][] = $entry;
      }
    }

    unset($data['VERSION']);
    return $data;
  }


  /**
   * Decode a given string with the encoding rule from ENCODING attributes
   *
   * @param string String to decode
   * @param string Encoding type (quoted-printable and base64 supported)
   * @return string Decoded 8bit value
   */
  private static function decode_value($value, $encoding)
  {
    switch (strtolower($encoding)) {
      case 'quoted-printable':
        self::$values_decoded = true;
        return quoted_printable_decode($value);

      case 'base64':
        self::$values_decoded = true;
        return base64_decode($value);

      default:
        return $value;
    }
  }


  /**
   * Encodes an entry for storage in our database (vcard 3.0 format, unfolded)
   *
   * @param array Raw data structure to encode
   * @return string vCard encoded string
   */
  static function vcard_encode($data)
  {
    foreach((array)$data as $type => $entries) {
      /* valid N has 5 properties */
      while ($type == "N" && is_array($entries[0]) && count($entries[0]) < 5)
        $entries[0][] = "";

      // make sure FN is not empty (required by RFC2426)
      if ($type == "FN" && empty($entries))
        $entries[0] = $data['EMAIL'][0][0];

      foreach((array)$entries as $entry) {
        $attr = '';
        if (is_array($entry)) {
          $value = array();
          foreach($entry as $attrname => $attrvalues) {
            if (is_int($attrname))
              $value[] = $attrvalues;
            elseif ($attrvalues === true)
              $attr .= ";$attrname";    // true means just tag, not tag=value, as in PHOTO;BASE64:...
            else {
              foreach((array)$attrvalues as $attrvalue)
                $attr .= ";$attrname=" . self::vcard_quote($attrvalue, ',');
            }
          }
        }
        else {
          $value = $entry;
        }

        // skip empty entries
        if (self::is_empty($value))
          continue;

        $vcard .= self::vcard_quote($type) . $attr . ':' . self::vcard_quote($value) . self::$eol;
      }
    }

    return 'BEGIN:VCARD' . self::$eol . 'VERSION:3.0' . self::$eol . $vcard . 'END:VCARD';
  }


  /**
   * Join indexed data array to a vcard quoted string
   *
   * @param array Field data
   * @param string Separator
   * @return string Joined and quoted string
   */
  private static function vcard_quote($s, $sep = ';')
  {
    if (is_array($s)) {
      foreach($s as $part) {
        $r[] = self::vcard_quote($part, $sep);
      }
      return(implode($sep, (array)$r));
    }
    else {
      return strtr($s, array('\\' => '\\\\', "\r" => '', "\n" => '\n', ',' => '\,', ';' => '\;'));
    }
  }


  /**
   * Split quoted string
   *
   * @param string vCard string to split
   * @param string Separator char/string
   * @return array List with splitted values
   */
  private static function vcard_unquote($s, $sep = ';')
  {
    // break string into parts separated by $sep, but leave escaped $sep alone
    if (count($parts = explode($sep, strtr($s, array("\\$sep" => "\007")))) > 1) {
      foreach($parts as $s) {
        $result[] = self::vcard_unquote(strtr($s, array("\007" => "\\$sep")), $sep);
      }
      return $result;
    }
    else {
      return strtr($s, array("\r" => '', '\\\\' => '\\', '\n' => "\n", '\N' => "\n", '\,' => ',', '\;' => ';', '\:' => ':'));
    }
  }


  /**
   * Check if vCard entry is empty: empty string or an array with
   * all entries empty.
   *
   * @param mixed $value Attribute value (string or array)
   *
   * @return bool True if the value is empty, False otherwise
   */
  private static function is_empty($value)
  {
    foreach ((array)$value as $v) {
      if (((string)$v) !== '') {
        return false;
      }
    }

    return true;
  }

  /**
   * Extract array values by a filter
   *
   * @param array Array to filter
   * @param keys Array or comma separated list of values to keep
   * @param boolean Invert key selection: remove the listed values
   * @return array The filtered array
   */
  private static function array_filter($arr, $values, $inverse = false)
  {
    if (!is_array($values))
      $values = explode(',', $values);

    $result = array();
    $keep = array_flip((array)$values);
    foreach ($arr as $key => $val)
      if ($inverse != isset($keep[strtolower($val)]))
        $result[$key] = $val;

    return $result;
  }

  /**
   * Returns UNICODE type based on BOM (Byte Order Mark)
   *
   * @param string Input string to test
   * @return string Detected encoding
   */
  private static function detect_encoding($string)
  {
    if (substr($string, 0, 4) == "\0\0\xFE\xFF") return 'UTF-32BE';  // Big Endian
    if (substr($string, 0, 4) == "\xFF\xFE\0\0") return 'UTF-32LE';  // Little Endian
    if (substr($string, 0, 2) == "\xFE\xFF")     return 'UTF-16BE';  // Big Endian
    if (substr($string, 0, 2) == "\xFF\xFE")     return 'UTF-16LE';  // Little Endian
    if (substr($string, 0, 3) == "\xEF\xBB\xBF") return 'UTF-8';

    // heuristics
    if ($string[0] == "\0" && $string[1] == "\0" && $string[2] == "\0" && $string[3] != "\0") return 'UTF-32BE';
    if ($string[0] != "\0" && $string[1] == "\0" && $string[2] == "\0" && $string[3] == "\0") return 'UTF-32LE';
    if ($string[0] == "\0" && $string[1] != "\0" && $string[2] == "\0" && $string[3] != "\0") return 'UTF-16BE';
    if ($string[0] != "\0" && $string[1] == "\0" && $string[2] != "\0" && $string[3] == "\0") return 'UTF-16LE';

    // use mb_detect_encoding()
    $encodings = array('UTF-8', 'ISO-8859-1', 'ISO-8859-2', 'ISO-8859-3',
      'ISO-8859-4', 'ISO-8859-5', 'ISO-8859-6', 'ISO-8859-7', 'ISO-8859-8', 'ISO-8859-9',
      'ISO-8859-10', 'ISO-8859-13', 'ISO-8859-14', 'ISO-8859-15', 'ISO-8859-16',
      'WINDOWS-1252', 'WINDOWS-1251', 'BIG5', 'GB2312');

    if (function_exists('mb_detect_encoding') && ($enc = mb_detect_encoding($string, $encodings)))
      return $enc;

    // No match, check for UTF-8
    // from http://w3.org/International/questions/qa-forms-utf-8.html
    if (preg_match('/\A(
        [\x09\x0A\x0D\x20-\x7E]
        | [\xC2-\xDF][\x80-\xBF]
        | \xE0[\xA0-\xBF][\x80-\xBF]
        | [\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}
        | \xED[\x80-\x9F][\x80-\xBF]
        | \xF0[\x90-\xBF][\x80-\xBF]{2}
        | [\xF1-\xF3][\x80-\xBF]{3}
        | \xF4[\x80-\x8F][\x80-\xBF]{2}
        )*\z/xs', substr($string, 0, 2048)))
      return 'UTF-8';

    return rcmail::get_instance()->config->get('default_charset', 'ISO-8859-1'); # fallback to Latin-1
  }

}
