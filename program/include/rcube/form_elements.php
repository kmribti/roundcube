<?php
class input_field extends base_form_element
  {
  var $type = 'text';

  // PHP 5 constructor
  function __construct($attrib=NULL)
    {
    if (is_array($attrib))
      $this->attrib = $attrib;

    if ($attrib['type'])
      $this->type = $attrib['type'];

    if ($attrib['newline'])
      $this->newline = TRUE;
    }

  // PHP 4 compatibility
  function input_field($attrib=array())
    {
    $this->__construct($attrib);
    }

  // compose input tag
  function show($value=NULL, $attrib=NULL)
    {
    // overwrite object attributes
    if (is_array($attrib))
      $this->attrib = array_merge($this->attrib, $attrib);

    // set value attribute
    if ($value!==NULL)
      $this->attrib['value'] = $value;

    $this->attrib['type'] = $this->type;

    // return final tag
    return sprintf('<%s%s />%s',
                   $this->_conv_case('input', 'tag'),
                   $this->create_attrib_string(),
                   ($this->newline ? "\n" : ""));
    }
  }


class textfield extends input_field
  {
  var $type = 'text';
  }

class passwordfield extends input_field
  {
  var $type = 'password';
  }

class radiobutton extends input_field
  {
  var $type = 'radio';
  }

class checkbox extends input_field
  {
  var $type = 'checkbox';


  function show($value='', $attrib=NULL)
    {
    // overwrite object attributes
    if (is_array($attrib))
      $this->attrib = array_merge($this->attrib, $attrib);

    $this->attrib['type'] = $this->type;

    if ($value && (string)$value==(string)$this->attrib['value'])
      $this->attrib['checked'] = TRUE;
    else
      $this->attrib['checked'] = FALSE;

    // return final tag
    return sprintf('<%s%s />%s',
                   $this->_conv_case('input', 'tag'),
                   $this->create_attrib_string(),
                   ($this->newline ? "\n" : ""));
    }
  }


class textarea extends base_form_element
  {
  // PHP 5 constructor
  function __construct($attrib=array())
    {
    $this->attrib = $attrib;

    if ($attrib['newline'])
      $this->newline = TRUE;
    }

  // PHP 4 compatibility
  function textarea($attrib=array())
    {
    $this->__construct($attrib);
    }

  function show($value='', $attrib=NULL)
    {
    // overwrite object attributes
    if (is_array($attrib))
      $this->attrib = array_merge($this->attrib, $attrib);

    // take value attribute as content
    if ($value=='')
      $value = $this->attrib['value'];

    // make shure we don't print the value attribute
    if (isset($this->attrib['value']))
      unset($this->attrib['value']);

    if (!empty($value) && !isset($this->attrib['mce_editable']))
      $value = rc_main::Q($value, 'strict', FALSE);

    // return final tag
    return sprintf('<%s%s>%s</%s>%s',
                   $this->_conv_case('textarea', 'tag'),
                   $this->create_attrib_string(),
                   $value,
                   $this->_conv_case('textarea', 'tag'),
                   ($this->newline ? "\n" : ""));
    }
  }


class hiddenfield extends base_form_element
  {
  var $fields_arr = array();
  var $newline = TRUE;

  // PHP 5 constructor
  function __construct($attrib=NULL)
    {
    if (is_array($attrib))
      $this->add($attrib);
    }

  // PHP 4 compatibility
  function hiddenfield($attrib=NULL)
    {
    $this->__construct($attrib);
    }

  // add a hidden field to this instance
  function add($attrib)
    {
    $this->fields_arr[] = $attrib;
    }


  function show()
    {
    $out = '';
    foreach ($this->fields_arr as $attrib)
      {
      $this->attrib = $attrib;
      $this->attrib['type'] = 'hidden';

      $out .= sprintf('<%s%s />%s',
                   $this->_conv_case('input', 'tag'),
                   $this->create_attrib_string(),
                   ($this->newline ? "\n" : ""));
      }

    return $out;
    }
  }


class select extends base_form_element
  {
  var $options = array();

  /*
  syntax:
  -------
  // create instance. arguments are used to set attributes of select-tag
  $select = new select(array('name' => 'fieldname'));

  // add one option
  $select->add('Switzerland', 'CH');

  // add multiple options
  $select->add(array('Switzerland', 'Germany'),
               array('CH', 'DE'));

  // add 10 blank options with 50 chars
  // used to fill with javascript (necessary for 4.x browsers)
  $select->add_blank(10, 50);

  // generate pulldown with selection 'Switzerland'  and return html-code
  // as second argument the same attributes available to instanciate can be used
  print $select->show('CH');
  */

  // PHP 5 constructor
  function __construct($attrib=NULL)
    {
    if (is_array($attrib))
      $this->attrib = $attrib;

    if ($attrib['newline'])
      $this->newline = TRUE;
    }

  // PHP 4 compatibility
  function select($attrib=NULL)
    {
    $this->__construct($attrib);
    }


  function add($names, $values=NULL)
    {
    if (is_array($names))
      {
      foreach ($names as $i => $text)
        $this->options[] = array('text' => $text, 'value' => (string)$values[$i]);
      }
    else
      {
      $this->options[] = array('text' => $names, 'value' => (string)$values);
      }
    }


  function add_blank($nr, $width=0)
    {
    $text = $width ? str_repeat('&nbsp;', $width) : '';

    for ($i=0; $i < $nr; $i++)
      $this->options[] = array('text' => $text);
    }


  function show($select=array(), $attrib=NULL)
    {
    $options_str = "\n";
    $value_str = $this->_conv_case(' value="%s"', 'attrib');

    if (!is_array($select))
      $select = array((string)$select);

    foreach ($this->options as $option)
      {
      $selected = ((isset($option['value']) &&
                    in_array($option['value'], $select, TRUE)) ||
                   (in_array($option['text'], $select, TRUE))) ?
        $this->_conv_case(' selected', 'attrib') : '';

      $options_str .= sprintf("<%s%s%s>%s</%s>\n",
                             $this->_conv_case('option', 'tag'),
                             !empty($option['value']) ? sprintf($value_str, rc_main::Q($option['value'])) : '',
                             $selected,
                             rc_main::Q($option['text'], 'strict', FALSE),
                             $this->_conv_case('option', 'tag'));
      }

    // return final tag
    return sprintf('<%s%s>%s</%s>%s',
                   $this->_conv_case('select', 'tag'),
                   $this->create_attrib_string(),
                   $options_str,
                   $this->_conv_case('select', 'tag'),
                   ($this->newline ? "\n" : ""));
    }
  }
?>