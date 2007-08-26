<?php

/*
 +-----------------------------------------------------------------------+
 | program/include/rcmail_template.inc                                   |
 |                                                                       |
 | This file is part of the RoundCube Webmail client                     |
 | Copyright (C) 2007, RoundCube Dev. - Switzerland                      |
 | Licensed under the GNU GPL                                            |
 |                                                                       |
 | PURPOSE:                                                              |
 |   Class to handle HTML page output using a skin template.             |
 |   Extends rcube_html_page class from rcube_shared.inc                 |
 |                                                                       |
 +-----------------------------------------------------------------------+
 | Author: Thomas Bruederli <roundcube@gmail.com>                        |
 +-----------------------------------------------------------------------+

 $Id:  $

*/

/**
 * rcube_shared function library
 * @ignore
 */
require_once dirname(__FILE__) . '/rcube_shared.inc';

/**
 * rcmail_template
 *
 * @todo Documentation
 * @todo CS
 * @todo PHP5?
 * @uses rcube_html_page
 */
class rcmail_template extends rcube_html_page
{
    var $config;
    var $task = '';
    var $framed = false;
    var $ajax_call = false;
    var $pagetitle = '';
    var $env = array();
    var $js_env = array();
    var $js_commands = array();
    var $object_handlers = array();


    /**
     * PHP 5 constructor
     *
     * @access public
     * @param  array $config
     * @param  string $task
     * @todo   Use jQuery's $(document).ready() here.
     */
    public function __construct($config, $task)
    {
        parent::__construct();

        $this->task      = $task;
        $this->config    = $config;
        $this->ajax_call = !empty($_GET['_remote']) || !empty($_POST['_remote']);

        // add common javascripts
        if (!$this->ajax_call) {
            $javascript = "var ".JS_OBJECT_NAME." = new rcube_webmail();";

            // don't wait for page onload. Call init at the bottom of the page (delayed)
            $javascript_foot = "if (window.call_init)\n call_init('".JS_OBJECT_NAME."');";

            $this->add_script($javascript, 'head_top');
            $this->add_script($javascript_foot, 'foot');
            $this->scripts_path = 'program/js/';
            $this->include_script('common.js');
            $this->include_script('app.js');
        }
    }

    /**
     * Set environment variable
     */
    function set_env($name, $value, $addtojs=true)
    {
        $this->env[$name] = $value;
        if ($addtojs || isset($this->js_env[$name])) {
            $this->js_env[$name] = $value;
        }
    }


    /**
     * Set page title variable
     */
    function set_pagetitle($title)
    {
        $this->pagetitle = $title;
    }


    /**
     * Register a template object handler
     *
     * @access protected
     * @param  string Object name
     * @param  string Function name to call
     * @return void
     */
    function add_handler($obj, $func)
    {
        $this->object_handlers[$obj] = $func;
    }

    /**
     * Register a list of template object handlers
     *
     * @param  array Hash array with object=>handler pairs
     * @return void
     */
    function add_handlers($arr)
    {
        $this->object_handlers = array_merge($this->object_handlers, $arr);
    }

    /**
     * Register a GUI object to the client script
     *
     * @param  string Object name
     * @param  string Object ID
     * @return void
     */
    function add_gui_object($obj, $id)
    {
        $this->add_script(JS_OBJECT_NAME.".gui_object('$obj', '$id');");
    }

    /**
     * Call a client method
     *
     * @param string Method to call
     * @param ... Additional arguments
     */
    function command()
    {
        $this->js_commands[] = func_get_args();
    }

    /**
     * Invoke display_message command
     *
     * @param  string $message
     * @param  string $type
     * @param  mixed  $vars
     * @return void
     * @uses   self::command()
     */
    function show_message($message, $type='notice', $vars=NULL)
    {
        $this->command(
                'display_message',
                rcube_label(array('name' => $message, 'vars' => $vars)),
                $type
        );
    }

    /**
     * Delete all stored env variables and commands
     *
     * @access public
     * @return void
     * @uses   rcube_html::reset()
     * @uses   self::$env
     * @uses   self::$js_env
     * @uses   self::$js_commands
     * @uses   self::$object_handlers
     */
    public function reset()
    {
        $this->env = array();
        $this->js_env = array();
        $this->js_commands = array();
        $this->object_handlers = array();
        parent::reset();
    }

    /**
     * Send the request output to the client.
     * This will either parse a skin tempalte or send an AJAX response
     *
     * @access public
     * @param  string  $templ Template name
     * @param  boolean $exit True if script should terminate (default)
     */
    function send($templ=null, $exit=true)
    {
        if ($this->ajax_call) {
            $this->remote_response('', !$exit);
        }
        elseif ($templ != 'iframe') {
            //rc_main::tfk_debug("/Parsing $templ");
            $this->parse($templ, false);
        }
        else {
            $this->framed = $templ == 'iframe' ? true : $this->framed;
            $this->write();
        }

        if ($exit) {
            exit;
        }
    }


    /**
     * Send an AJAX response with executable JS code
     *
     * @param  string  Additional JS code
     * @param  boolean True if output buffer should be flushed
     * @return void
     */
    function remote_response($add='', $flush=false)
    {
        static $s_header_sent = FALSE;

        if (!$s_header_sent) {
            $s_header_sent = TRUE;
            send_nocacheing_headers();
            header('Content-Type: application/x-javascript; charset='.RCMAIL_CHARSET);
            print '/** ajax response ['.date('d/M/Y h:i:s O')."] **/\n";
        }

        // unset default env vars
        unset($this->js_env['task'], $this->js_env['action'], $this->js_env['comm_path']);

        // send response code
        echo rc_main::rcube_charset_convert(
                $this->get_js_commands() . $add,
                RCMAIL_CHARSET,
                $this->get_charset()
        );

        if ($flush) {  // flush the output buffer
            flush();
        }
    }

    /**
     * @override
     */
    function write($template='')
    {
        // write all env variables to client
        $js = $this->framed ? "if(window.parent) {\n" : '';
        $js .= $this->get_js_commands() . ($this->framed ? ' }' : '');
        $this->add_script($js, 'head_top');

        // call super method
        parent::write($template, $this->config['skin_path']);
    }

    /**
     * Parse a specific skin template and deliver to stdout
     *
     * Either returns nothing, or exists hard (exit();)
     *
     * @access public
     * @param  string  Template name
     * @param  boolean Exit script
     * @return void
     * @link   http://php.net/manual/en/function.exit.php
     */
    function parse($name='main', $exit=true)
    {
        $skin_path = $this->config['skin_path'];

        //rc_main::tfk_debug(var_export($this->config['skin_path'], true));

        // read template file
        $templ = '';
        $path = "$skin_path/templates/$name.html";

        if(($fp = @fopen($path, 'r')) === false) {
            $message = '';
            ob_start();
            fopen($path, 'r');
            $message.= ob_get_contents();
            ob_end_clean();
            rc_bugs::raise_error(
                array(
                    'code' => 501,
                    'type' => 'php',
                    'line' => __LINE__,
                    'file' => __FILE__,
                    'message' => "Error loading template for '$name': $message"
                ),
                TRUE,
                TRUE
            );
            return FALSE;
        }
        $templ = fread($fp, filesize($path));
        @fclose($fp);

        //rc_main::tfk_debug("// parsed: $path");

        // parse for specialtags
        $output = $this->parse_conditions($templ);
        $output = $this->parse_xml($output);

        // add debug console
        if ($this->config['debug_level'] & 8) {
            $this->add_footer('<div style="position:absolute;top:5px;left:5px;width:400px;padding:0.2em;background:white;opacity:0.8;z-index:9000">
                            <a href="#toggle" onclick="con=document.getElementById(\'dbgconsole\');con.style.display=(con.style.display==\'none\'?\'block\':\'none\');return false">console</a>
                            <form action="/" name="debugform"><textarea name="console" id="dbgconsole" rows="20" cols="40" wrap="off" style="display:none;width:400px;border:none;font-size:x-small"></textarea></form></div>'
            );
        }
        $output = $this->parse_with_globals($output);
        $this->write(trim($output), $skin_path);
        if ($exit) {
            exit;
        }
    }


    /**
     * Return executable javascript code for all registered commands
     * @access private
     * @return string $out
     */
    function get_js_commands()
    {
        $out = '';
        if (!$this->framed) {
            $out .= ($this->ajax_call ? 'this' : JS_OBJECT_NAME) . '.set_env('.json_serialize($this->js_env).");\n";
        }
        foreach ($this->js_commands as $i => $args) {
            $method = array_shift($args);
            foreach ($args as $i => $arg) {
                $args[$i] = json_serialize($arg);
            }
            $parent = $this->framed || preg_match('/^parent\./', $method);
            $out .= sprintf(
                        "%s.%s(%s);\n",
                        $this->ajax_call ? 'this' : ($parent ? 'parent.' : '') . JS_OBJECT_NAME,
                        preg_replace('/^parent\./', '', $method),
                        implode(',', $args)
            );
        }
        return $out;
    }

    /**
     * Make URLs starting with a slash point to skin directory
     *
     * @access protected
     * @param  string $str
     * @todo   Check if str_replace() would be sufficient
     * @return string
     */
    function abs_url($str)
    {
        return preg_replace('/^\//', $this->config['skin_path'].'/', $str);
    }



    /*****  Template parsing methods  *****/

    /**
     * Replace all strings ($varname) with the content
     * of the according global variable.
     */
    function parse_with_globals($input)
    {
        $registry  = rc_registry::getInstance();
        $COMM_PATH = $registry->get('COMM_PATH', 'core');

        $GLOBALS['__comm_path'] = $COMM_PATH;
        return preg_replace('/\$(__[a-z0-9_\-]+)/e', '$GLOBALS["\\1"]', $input);
    }

    /**
     * Public wrapper to dipp into template parsing.
     *
     * @access public
     * @param  string $input
     * @return string
     * @uses   rcmail_template::parse_xml()
     * @since  0.1-rc1
     */
    public function just_parse($input)
    {
        return $this->parse_xml($input);
    }

    /**
     * Parse for conditional tags
     *
     * @access protected
     * @param  string $input
     * @return string
     */
    protected function parse_conditions($input)
    {
        $matches = preg_split(
                        '/<roundcube:(if|elseif|else|endif)\s+([^>]+)>/is',
                        $input,
                        2,
                        PREG_SPLIT_DELIM_CAPTURE
        );
        if ($matches && count($matches)==4) {
            if (preg_match('/^(else|endif)$/i', $matches[1])) {
                return $matches[0] . $this->parse_conditions($matches[3]);
            }
            $attrib = rc_main::parse_attrib_string($matches[2]);
            if (isset($attrib['condition'])) {
                $condmet = $this->check_condition($attrib['condition']);
                $submatches = preg_split(
                                '/<roundcube:(elseif|else|endif)\s+([^>]+)>/is',
                                $matches[3],
                                2,
                                PREG_SPLIT_DELIM_CAPTURE
                );
                if ($condmet) {
                    $result = $submatches[0];
                    $result.= ($submatches[1] != 'endif' ? preg_replace('/.*<roundcube:endif\s+[^>]+>/Uis', '', $submatches[3], 1) : $submatches[3]);
                }
                else {
                    $result = "<roundcube:$submatches[1] $submatches[2]>" . $submatches[3];
                }
                return $matches[0] . $this->parse_conditions($result);
            }
            rc_bugs::raise_error(
                array(
                    'code' => 500,
                    'type' => 'php',
                    'line' => __LINE__,
                    'file' => __FILE__,
                    'message' => "Unable to parse conditional tag " . $matches[2]
                ),
                TRUE,
                FALSE
            );
        }
        return $input;
    }


    /**
     * Determines if a given condition is met
     *
     * @access protected
     * @todo   Get rid off eval() once I understand what this does.
     * @todo   Extend this to allow real conditions, not just "set"
     * @return True if condition is valid, False is not
     */
    protected function check_condition($condition)
    {
        $condition = preg_replace(
                array(
                    '/session:([a-z0-9_]+)/i',
                    '/config:([a-z0-9_]+)/i',
                    '/env:([a-z0-9_]+)/i',
                    '/request:([a-z0-9_]+)/ie'
                ),
                array(
                    "\$_SESSION['\\1']",
                    "\$this->config['\\1']",
                    "\$this->env['\\1']",
                    "get_input_value('\\1', RCUBE_INPUT_GPC)"
                ),
                $condition
        );
        return @eval("return (".$condition.");");
    }


   /**
    * Search for special tags in input and replace them
    * with the appropriate content
    *
    * @access protected
    * @param  string Input string to parse
    * @return Altered input string
    * @todo   Maybe a cache.
    */
   protected function parse_xml($input)
   {
        return preg_replace('/<roundcube:([-_a-z]+)\s+([^>]+)>/Uie', "\$this->xml_command('\\1', '\\2')", $input);
   }


    /**
     * Convert a xml command tag into real content
     *
     * @access protected
     * @param  string Tag command: object,button,label, etc.
     * @param  string Attribute string
     * @return Tag/Object content string
     */
    protected function xml_command($command, $str_attrib, $add_attrib=array())
    {
        $command = strtolower($command);
        $attrib  = rc_main::parse_attrib_string($str_attrib) + $add_attrib;
        //var_dump($attrib); exit;

        //rc_main::tfk_debug("// $command");

        // empty output if required condition is not met
        if (!empty($attrib['condition']) && !$this->check_condition($attrib['condition'])) {
            return '';
        }

        //rc_main::tfk_debug("// $command #2");

        // execute command
        switch ($command) {
            // return a button
            case 'button':
                if ($attrib['command']) {
                    return $this->button($attrib);
                }
                break;

            // show a label
            case 'label':
                if ($attrib['name'] || $attrib['command']) {
                    return rc_main::Q(
                                rcube_label(
                                    $attrib + array('vars' => array('product' => $this->config['product_name']))
                                )
                    );
                }
                break;

            // include a file
            case 'include':
                $path = realpath($this->config['skin_path'].$attrib['file']);
                if ($path === FALSE) {
                    //rc_main::tfk_debug("Does not exist.");
                    return $this->parse_xml('');
                }
                if (($tpl_filesize = filesize($path)) == 0) {
                    return $this->parse_xml('');
                }
                if ($fp = @fopen($path, 'r')) {
                    $incl = fread($fp, $tpl_filesize);
                    fclose($fp);
                    return $this->parse_xml($incl);
                }
                break;

            case 'plugin.include':
                //rc_main::tfk_debug(var_export($this->config['skin_path'], true));
                $path = realpath($this->config['skin_path'].$attrib['file']);
                if ($path === FALSE) {
                    //rc_main::tfk_debug("Does not exist:");
                    //rc_main::tfk_debug($this->config['skin_path']);
                    //rc_main::tfk_debug($attrib['file']);
                    //rc_main::tfk_debug($path);
                }
                $incl = file_get_contents($path);
                if ($incl === FALSE) {
                    //rc_main::tfk_debug("Could not read template.");
                    return $this->parse_xml('');
                }
                return $this->parse_xml($incl);
                break;

            // return code for a specific application object
            case 'object':
                $object = strtolower($attrib['name']);

                //rc_main::tfk_debug("// object: $object");
                //rc_main::tfk_debug(var_export($this->object_handlers[$object], true));

                // execute object handler function
                if ($this->object_handlers[$object] && function_exists($this->object_handlers[$object])) {
                    return call_user_func($this->object_handlers[$object], $attrib);
                }
                else {
                    // we are calling a class/method
                    if ($this->object_handlers[$object] && is_array($this->object_handlers[$object])) {
                        if (class_exists($this->object_handlers[$object][0])) {
                            return call_user_func($this->object_handlers[$object], $attrib);
                        }
                        rc_main::tfk_debug('Unknown handler: ' . var_export($this->object_handlers[$object], true));
                    }
                }
                if ($object=='productname') {
                    $name = !empty($this->config['product_name']) ? $this->config['product_name'] : 'RoundCube Webmail';
                    return rc_main::Q($name);
                }
                if ($object=='version') {
                    return (string)RCMAIL_VERSION;
                }
                if ($object=='pagetitle') {
                    $task  = $this->task;
                    $title = !empty($this->config['product_name']) ? $this->config['product_name'].' :: ' : '';

                    if (!empty($this->pagetitle))
                        $title .= $this->pagetitle;
                    else if ($task == 'login')
                        $title = rcube_label(array('name' => 'welcome', 'vars' => array('product' => $this->config['product_name'])));
                    else
                        $title .= ucfirst($task);

                    return rc_main::Q($title);
                }
                break;
        }
        return '';
    }


    /**
     * Create and register a button
     *
     * @access public
     * @param  array Button attributes
     * @return HTML button
     * @todo   Remove all inline JS calls and use jQuery instead.
     * @todo   Remove all sprintf()'s - they are pretty, but also slow.
     */
    public function button($attrib)
    {
        $registry   = rc_registry::getInstance();
        $CONFIG     = $registry->get('CONFIG', 'core');
        $OUTPUT     = $registry->get('OUTPUT', 'core');
        $BROWSER    = $registry->get('BROWSER', 'core');
        $MAIN_TASKS = $registry->get('MAIN_TASKS', 'core');

        static $sa_buttons = array();
        static $s_button_count = 100;

        // these commands can be called directly via url
        $a_static_commands = array('compose', 'list');

        $skin_path = $this->config['skin_path'];

        if (!($attrib['command'] || $attrib['name'])) {
            return '';
        }
        // try to find out the button type
        if ($attrib['type']) {
            $attrib['type'] = strtolower($attrib['type']);
        }
        else {
            $attrib['type'] = ($attrib['image'] || $attrib['imagepas'] || $attrib['imageact']) ? 'image' : 'link';
        }
        $command = $attrib['command'];

        // take the button from the stack
        if($attrib['name'] && $sa_buttons[$attrib['name']]) {
            $attrib = $sa_buttons[$attrib['name']];
        }
        // add button to button stack
        else if($attrib['image'] || $attrib['imageact'] || $attrib['imagepas'] || $attrib['class'])
        {
            if (!$attrib['name']) {
                $attrib['name'] = $command;
            }
            if (!$attrib['image']) {
                $attrib['image'] = $attrib['imagepas'] ? $attrib['imagepas'] : $attrib['imageact'];
            }
            $sa_buttons[$attrib['name']] = $attrib;
        }

        // get saved button for this command/name
        else if ($command && $sa_buttons[$command]) {
            $attrib = $sa_buttons[$command];
        }
        //else
        //  return '';


        // set border to 0 because of the link arround the button
        if ($attrib['type']=='image' && !isset($attrib['border'])) {
            $attrib['border'] = 0;
        }
        if (!$attrib['id']) {
            $attrib['id'] =  sprintf('rcmbtn%d', $s_button_count++);
        }
        // get localized text for labels and titles
        if ($attrib['title']) {
            $attrib['title'] = rc_main::Q(rcube_label($attrib['title']));
        }
        if ($attrib['label']) {
            $attrib['label'] = rc_main::Q(rcube_label($attrib['label']));
        }
        if ($attrib['alt']) {
            $attrib['alt'] = rc_main::Q(rcube_label($attrib['alt']));
        }
        // set title to alt attribute for IE browsers
        if ($BROWSER['ie'] && $attrib['title'] && !$attrib['alt']) {
            $attrib['alt'] = $attrib['title'];
            unset($attrib['title']);
        }

        // add empty alt attribute for XHTML compatibility
        if (!isset($attrib['alt'])) {
            $attrib['alt'] = '';
        }

        // register button in the system
        if ($attrib['command'])
        {
            $this->add_script(
                sprintf(
                    "%s.register_button('%s', '%s', '%s', '%s', '%s', '%s');",
                    JS_OBJECT_NAME,
                    $command,
                    $attrib['id'],
                    $attrib['type'],
                    $attrib['imageact'] ? $skin_path.$attrib['imageact'] : $attrib['classact'],
                    $attrib['imagesel'] ? $skin_path.$attrib['imagesel'] : $attrib['classsel'],
                    $attrib['imageover'] ? $skin_path.$attrib['imageover'] : ''
                )
            );

            // make valid href to specific buttons
            if (in_array($attrib['command'], $MAIN_TASKS)) {
                $attrib['href'] = rc_main::Q(rc_main::rcmail_url(null, null, $attrib['command']));
            }
            else if (in_array($attrib['command'], $a_static_commands)) {
                $attrib['href'] = rc_main::Q(rc_main::rcmail_url($attrib['command']));
            }
        }

        // overwrite attributes
        if (!$attrib['href']) {
            $attrib['href'] = '#';
        }
        if ($command) {
            $attrib['onclick'] = sprintf(
                                    "return %s.command('%s','%s',this)",
                                    JS_OBJECT_NAME,
                                    $command,
                                    $attrib['prop']
            );
        }
        if ($command && $attrib['imageover']) {
            $attrib['onmouseover'] = sprintf(
                                        "return %s.button_over('%s','%s')",
                                        JS_OBJECT_NAME,
                                        $command,
                                        $attrib['id']
            );
            $attrib['onmouseout'] = sprintf(
                                        "return %s.button_out('%s','%s')",
                                        JS_OBJECT_NAME,
                                        $command,
                                        $attrib['id']
            );
        }

        if ($command && $attrib['imagesel']) {
            $attrib['onmousedown'] = sprintf(
                                        "return %s.button_sel('%s','%s')",
                                        JS_OBJECT_NAME,
                                        $command,
                                        $attrib['id']
            );
            $attrib['onmouseup'] = sprintf(
                                        "return %s.button_out('%s','%s')",
                                        JS_OBJECT_NAME,
                                        $command,
                                        $attrib['id']
            );
        }

        $out = '';

        // generate image tag
        if ($attrib['type']=='image') {
            $attrib_str = rc_main::create_attrib_string(
                                $attrib,
                                array(
                                    'style', 'class', 'id', 'width',
                                    'height', 'border', 'hspace',
                                    'vspace', 'align', 'alt'
                                )
            );
            $img_tag = sprintf('<img src="%%s"%s />', $attrib_str);
            $btn_content = sprintf($img_tag, $skin_path.$attrib['image']);
            if ($attrib['label']) {
                $btn_content .= ' '.$attrib['label'];
            }
            $link_attrib = array('href', 'onclick', 'onmouseover', 'onmouseout', 'onmousedown', 'onmouseup', 'title');
        }
        else if ($attrib['type']=='link') {
            $btn_content = $attrib['label'] ? $attrib['label'] : $attrib['command'];
            $link_attrib = array('href', 'onclick', 'title', 'id', 'class', 'style');
        }
        else if ($attrib['type']=='input') {
            $attrib['type'] = 'button';

            if ($attrib['label'])
                $attrib['value'] = $attrib['label'];

            $attrib_str = rc_main::create_attrib_string(
                                $attrib,
                                array(
                                    'type', 'value', 'onclick',
                                    'id', 'class', 'style'
                                )
            );
            $out = sprintf('<input%s disabled="disabled" />', $attrib_str);
        }

        // generate html code for button
        if ($btn_content) {
            if ($command == 'logout') {
                $btn_content = '<span>Hallo ' . $_SESSION['username'] . ', ' . $btn_content . '</span>';
            }            
            $attrib_str = rc_main::create_attrib_string($attrib, $link_attrib);
            $out = sprintf('<a%s>%s</a>', $attrib_str, $btn_content);
        }

        return $out;
    }

}

?>
