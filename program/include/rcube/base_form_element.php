<?php
class base_form_element
{
    var $uppertags = FALSE;
    var $upperattribs = FALSE;
    var $upperprops = FALSE;
    var $newline = FALSE;

    var $attrib = array();


  // create string with attributes
  function create_attrib_string($tagname='')
    {
    if (!sizeof($this->attrib))
      return '';

    if ($this->name!='')
      $this->attrib['name'] = $this->name;

    $attrib_arr = array();
    foreach ($this->attrib as $key => $value)
      {
      // don't output some internally used attributes
      if (in_array($key, array('form', 'quicksearch')))
        continue;

      // skip if size if not numeric
      if (($key=='size' && !is_numeric($value)))
        continue;

      // skip empty eventhandlers
      if ((strpos($key,'on')===0 && $value==''))
        continue;

      // encode textarea content
      if ($key=='value')
        $value = rc_main::Q($value, 'strict', FALSE);

      // attributes with no value
      if (in_array($key, array('checked', 'multiple', 'disabled', 'selected')))
        {
        if ($value)
          $attrib_arr[] = $key;
        }
      // don't convert size of value attribute
      else if ($key=='value')
        $attrib_arr[] = sprintf('%s="%s"', $this->_conv_case($key, 'attrib'), $value, 'value');

      // regular tag attributes
      else
        $attrib_arr[] = sprintf('%s="%s"', $this->_conv_case($key, 'attrib'), $this->_conv_case($value, 'value'));
      }

    return sizeof($attrib_arr) ? ' '.implode(' ', $attrib_arr) : '';
    }


    // convert tags and attributes to upper-/lowercase
    // $type can either be "tag" or "attrib"
    function _conv_case($str, $type='attrib')
    {
        if ($type == 'tag') {
            return $this->uppertags ? strtoupper($str) : strtolower($str);
        }
        if ($type == 'attrib') {
            return $this->upperattribs ? strtoupper($str) : strtolower($str);
        }
        if ($type == 'value') {
            return $this->upperprops ? strtoupper($str) : strtolower($str);
        }
    }
}
?>