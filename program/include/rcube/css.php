<?php
class rcube_css
  {
  var $css_data = array();

  var $css_groups = array();

  var $include_files = array();

  var $grouped_output = TRUE;

  var $content_type = 'text/css';

  var $base_path = '';

  var $indent_chars = "\t";


  // add or overwrite a css definition
  // either pass porperty and value as separate arguments
  // or provide an associative array as second argument
  function set_style($selector, $property, $value='')
    {
    $a_elements = $this->_parse_selectors($selector);
    foreach ($a_elements as $element)
      {
      if (!is_array($property))
        $property = array($property => $value);

      foreach ($property as $name => $value)
        $this->css_data[$element][strtolower($name)] = $value;
      }

    // clear goups array
    $this->css_groups = array();
    }


  // unset a style property
  function remove_style($selector, $property)
    {
    if (!is_array($property))
      $property = array($property);

    foreach ($property as $key)
      unset($this->css_data[$selector][strtolower($key)]);

    // clear goups array
    $this->css_groups = array();
    }


  // define base path for external css files
  function set_basepath($path)
    {
    $this->base_path = preg_replace('/\/$/', '', $path);
    }


  // enable/disable grouped output
  function set_grouped_output($grouped)
    {
    $this->grouped_output = $grouped;
    }


  // add a css file as external source
  function include_file($filename, $media='')
    {
    // include multiple files
    if (is_array($filename))
      {
      foreach ($filename as $file)
        $this->include_file($file, $media);
      }
    // add single file
    else if (!in_array($filename, $this->include_files))
      $this->include_files[] = array('file' => $filename,
                                     'media' => $media);
    }


  // parse css code
  function import_string($str)
    {
    $ret = FALSE;
    if (strlen($str))
      $ret = $this->_parse($str);

    return $ret;
    }


  // open and parse a css file
  function import_file($file)
    {
    $ret = FALSE;

    if (!is_file($file))
      return $ret;

    // for php version >= 4.3.0
    if (function_exists('file_get_contents'))
      $ret = $this->_parse(file_get_contents($file));

    // for order php versions
    else if ($fp = fopen($file, 'r'))
      {
      $ret = $this->_parse(fread($fp, filesize($file)));
      fclose($fp);
      }

    return $ret;
    }


  // copy all properties inherited from superior styles to a specific selector
  function copy_inherited_styles($selector)
    {
    // get inherited props from body and tag/class selectors
    $css_props = $this->_get_inherited_styles($selector);

    // write modified props back and clear goups array
    if (sizeof($css_props))
      {
      $this->css_data[$selector] = $css_props;
      $this->css_groups = array();
      }
    }


  // return css definition for embedding in HTML
  function show()
    {
    $out = '';

    // include external css files
    if (sizeof($this->include_files))
      foreach ($this->include_files as $file_arr)
      $out .= sprintf('<link rel="stylesheet" type="%s" href="%s"%s>'."\n",
                        $this->content_type,
                        $this->_get_file_path($file_arr['file']),
                        $file_arr['media'] ? ' media="'.$file_arr['media'].'"' : '');


    // compose css string
    if (sizeof($this->css_data))
      $out .= sprintf("<style type=\"%s\">\n<!--\n\n%s-->\n</style>",
                      $this->content_type,
                      $this->to_string());


    return $out;
    }


  // return valid css code of the current styles grid
  function to_string($selector=NULL)
    {
    // return code for a single selector
    if ($selector)
      {
      $indent_str = $this->indent_chars;
      $this->indent_chars = '';

      $prop_arr = $this->to_array($selector);
      $out = $this->_style2string($prop_arr, TRUE);

      $this->indent_chars = $indent_str;
      }

    // compose css code for complete data grid
    else
      {
      $out = '';
      $css_data = $this->to_array();

      foreach ($css_data as $key => $prop_arr)
        $out .= sprintf("%s {\n%s}\n\n",
                        $key,
                        $this->_style2string($prop_arr, TRUE));
      }

    return $out;
    }


  // return a single-line string of a css definition
  function to_inline($selector)
    {
    if ($this->css_data[$selector])
      return str_replace('"', '\\"', $this->_style2string($this->css_data[$selector], FALSE));
    }


  // return an associative array with selector(s) as key and styles array as value
  function to_array($selector=NULL)
    {
    if (!$selector && $this->grouped_output)
      {
      // build groups if desired
      if (!sizeof($this->css_groups))
        $this->_build_groups();

      // modify group array to get an array(selector => properties)
      $out_arr = array();
      foreach ($this->css_groups as $group_arr)
        {
        $key = join(', ', $group_arr['selectors']);
        $out_arr[$key] = $group_arr['properties'];
        }
      }
    else
      $out_arr = $this->css_data;

    return $selector ? $out_arr[$selector] : $out_arr;
    }


  // create a css file
  function to_file($filepath)
    {
    if ($fp = fopen($filepath, 'w'))
      {
      fwrite($fp, $this->to_string());
      fclose($fp);
      return TRUE;
      }

    return FALSE;
    }


  // alias method for import_string() [DEPRECATED]
  function add($str)
    {
    $this->import_string($str);
    }

  // alias method for to_string() [DEPRECATED]
  function get()
    {
    return $this->to_string();
    }



  // ******** private methods ********


  // parse a string and add styles to internal data grid
  function _parse($str)
    {
    // remove comments
    $str = preg_replace("/\/\*(.*)?\*\//Usi", '', $str);

    // parse style definitions
    if (!preg_match_all ('/([a-z0-9\.#*:_][a-z0-9\.\-_#:*,\[\]\(\)\s\"\'\+\|>~=]+)\s*\{([^\}]*)\}/ims', $str, $matches, PREG_SET_ORDER))
      return FALSE;


    foreach ($matches as $match_arr)
      {
      // split selectors into array
      $a_keys = $this->_parse_selectors(trim($match_arr[1]));

      // parse each property of an element
      $codes = explode(";", trim($match_arr[2]));
      foreach ($codes as $code)
        {
        if (strlen(trim($code))>0)
          {
          // find the property and the value
          if (!($sep = strpos($code, ':')))
            continue;

          $property = strtolower(trim(substr($code, 0, $sep)));
          $value    = trim(substr($code, $sep+1));

          // add the property to the object array
          foreach ($a_keys as $key)
            $this->css_data[$key][$property] = $value;
          }
        }
      }

    // clear goups array
    if (sizeof($matches))
      {
      $this->css_groups = array();
      return TRUE;
      }

    return FALSE;
    }


  // split selector group
  function _parse_selectors($selector)
    {
    // trim selector and remove multiple spaces
    $selector = preg_replace('/\s+/', ' ', trim($selector));

    if (strpos($selector, ','))
      return preg_split('/[\t\s\n\r]*,[\t\s\n\r]*/mi', $selector);
    else
      return array($selector);
    }


  // compare identical styles and make groups
  function _build_groups()
    {
    // clear group array
    $this->css_groups = array();
    $string_group_map = array();

    // bulild css string for each selector and check if the same is already defines
    foreach ($this->css_data as $selector => $prop_arr)
      {
      // make shure to compare props in the same order
      ksort($prop_arr);
      $compare_str = preg_replace('/[\s\t]+/', '', $this->_style2string($prop_arr, FALSE));

      // add selector to extisting group
      if (isset($string_group_map[$compare_str]))
        {
        $group_index = $string_group_map[$compare_str];
        $this->css_groups[$group_index]['selectors'][] = $selector;
        }

      // create new group
      else
        {
        $i = sizeof($this->css_groups);
        $string_group_map[$compare_str] = $i;
        $this->css_groups[$i] = array('selectors' => array($selector),
                                      'properties' => $this->css_data[$selector]);
        }
      }
    }


  // convert the prop array into a valid css definition
  function _style2string($prop_arr, $multiline=TRUE)
    {
    $out = '';
    $delm   = $multiline ? "\n" : '';
    $spacer = $multiline ? ' ' : '';
    $indent = $multiline ? $this->indent_chars : '';

    if (is_array($prop_arr))
      foreach ($prop_arr as $prop => $value)
        if (strlen($value))
          $out .= sprintf('%s%s:%s%s;%s',
                          $indent,
                          $prop,
                          $spacer,
                          $value,
                          $delm);

    return $out;
    }


  // copy all properties inherited from superior styles to a specific selector
  function _get_inherited_styles($selector, $loop=FALSE)
    {
    $css_props = $this->css_data[$selector] ? $this->css_data[$selector] : array();

    // get styles from tag selector
    if (preg_match('/(([a-z0-9]*)(\.[^\s]+)?)$/i', $selector, $regs))
      {
      $sel = $regs[1];
      $tagname = $regs[2];
      $class = $regs[3];

      if ($sel && is_array($this->css_data[$sel]))
        $css_props = $this->_merge_styles($this->css_data[$sel], $css_props);

      if ($class && is_array($this->css_data[$class]))
        $css_props = $this->_merge_styles($this->css_data[$class], $css_props);

      if ($tagname && is_array($this->css_data[$tagname]))
        $css_props = $this->_merge_styles($this->css_data[$tagname], $css_props);
      }

    // analyse inheritance
    if (strpos($selector, ' '))
      {
      $a_hier = split(' ', $selector);
      if (sizeof($a_hier)>1)
        {
        array_pop($a_hier);
        $base_selector = join(' ', $a_hier);

        // call this method recursively
        $new_props = $this->_get_inherited_styles($base_selector, TRUE);
        $css_props = $this->_merge_styles($new_props, $css_props);
        }
      }

    // get body style
    if (!$loop && is_array($this->css_data['body']))
      $css_props = $this->_merge_styles($this->css_data['body'], $css_props);

    return $css_props;
    }


  // merge two arrays with style properties together like a browser would do
  function _merge_styles($one, $two)
    {
    // these properties are additive
    foreach (array('text-decoration') as $prop)
      if ($one[$prop] && $two[$prop])
        {
        // if value contains 'none' it's ignored
        if (strstr($one[$prop], 'none'))
          continue;
        else if (strstr($two[$prop], 'none'))
          unset($two[$prop]);

        $a_values_one = split(' ', $one[$prop]);
        $a_values_two = split(' ', $two[$prop]);
        $two[$prop] = join(' ', array_unique(array_merge($a_values_one, $a_values_two)));
        }

    return array_merge($one, $two);
    }


  // resolve file path
  function _get_file_path($file)
    {
    if (!$this->base_path && $GLOBALS['CSS_PATH'])
      $this->set_basepath($GLOBALS['CSS_PATH']);

    $base = ($file{0}=='/' || $file{0}=='.' || substr($file, 0, 7)=='http://') ? '' :
            ($this->base_path ? $this->base_path.'/' : '');
    return $base.$file;
    }

  }
?>