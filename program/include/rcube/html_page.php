<?php
class rcube_html_page
{
    var $css;

    var $scripts_path = '';
    var $script_files = array();
    var $external_scripts = array();
    var $scripts = array();
    var $charset = 'ISO-8859-1';

    var $script_tag_file = "<script type=\"text/javascript\" src=\"%s%s\"></script>\n";
    var $script_tag      = "<script type=\"text/javascript\">\n<!--\n%s\n\n//-->\n</script>\n";
    var $default_template = "<html>\n<head><title></title></head>\n<body></body>\n</html>";
    var $tag_format_external_script = "<script type=\"text/javascript\" src=\"%s\"></script>\n";

    var $title = '';
    var $header = '';
    var $footer = '';
    var $body = '';
    var $body_attrib = array();
    var $meta_tags = array();


    // PHP 5 constructor
    function __construct()
    {
        $this->css = new rcube_css();
    }

    // PHP 4 compatibility
    function rcube_html_page()
    {
        $this->__construct();
    }


    function include_script($file, $position='head')
    {
        static $sa_files = array();

        if (in_array($file, $sa_files)) {
            return;
        }
        if (!is_array($this->script_files[$position])) {
            $this->script_files[$position] = array();
        }
        $this->script_files[$position][] = $file;
    }

    function include_external_script($script_location, $position='head')
    {
        if (!is_array($this->external_scripts[$position])) {
            $this->external_scripts[$position] = array();
        }

        $this->external_scripts[$position][] = $script_location;
    }

    function add_script($script, $position='head')
    {
        if (!isset($this->scripts[$position])) {
            $this->scripts[$position] = "\n".rtrim($script);
        }
        else {
            $this->scripts[$position] .= "\n".rtrim($script);
        }
    }

    function add_header($str)
    {
        $this->header .= "\n".$str;
    }

    function add_footer($str)
    {
        $this->footer .= "\n".$str;
    }

    function set_title($t)
    {
        $this->title = $t;
    }


    function set_charset($charset)
    {
        $registry = rc_registry::getInstance();
        $MBSTRING = $registry->get('MBSTRING', 'core');

        $this->charset = $charset;

        if ($MBSTRING && function_exists("mb_internal_encoding")) {
            if(!@mb_internal_encoding($charset)) {
                $MBSTRING = FALSE;
            }
        }
    }

    function get_charset()
    {
        return $this->charset;
    }


    function reset()
    {
        $this->css = new rcube_css();
        $this->script_files = array();
        $this->scripts = array();
        $this->title = '';
        $this->header = '';
        $this->footer = '';
    }


    function write($templ='', $base_path='')
    {
        $output = empty($templ) ? $this->default_template : trim($templ);

        // set default page title
        if (empty($this->title) === true) {
            $this->title = 'RoundCube Mail';
        }
        // replace specialchars in content
        $__page_title = rc_main::Q($this->title, 'show', FALSE);
        $__page_header = $__page_body = $__page_footer = '';


        // include meta tag with charset
        if (!empty($this->charset)) {
            if (headers_sent() !== TRUE) {
                header('Content-Type: text/html; charset=' . $this->charset);
            }
            $__page_header = '<meta http-equiv="content-type"';
            $__page_header.= ' content="text/html; charset=';
            $__page_header.= $this->charset . '" />'."\n";
        }


        // definition of the code to be placed in the document header and footer
        if (is_array($this->script_files['head'])) {
            foreach ($this->script_files['head'] as $file) {
                $__page_header .= sprintf(
                                    $this->script_tag_file,
                                    $this->scripts_path,
                                    $file
                );
            }
        }

        if (is_array($this->external_scripts['head'])) {
            foreach ($this->external_scripts['head'] as $xscript) {
                $__page_header .= sprintf($this->tag_format_external_script, $xscript);
            }
        }

        $head_script = $this->scripts['head_top'] . $this->scripts['head'];
        if (!empty($head_script))
            $__page_header .= sprintf($this->script_tag, $head_script);

        if (!empty($this->header))
            $__page_header .= $this->header;

        if (is_array($this->script_files['foot']))
            foreach ($this->script_files['foot'] as $file)
                $__page_footer .= sprintf($this->script_tag_file, $this->scripts_path, $file);

        if (!empty($this->scripts['foot']))
            $__page_footer .= sprintf($this->script_tag, $this->scripts['foot']);

        if (!empty($this->footer))
            $__page_footer .= $this->footer;

        $__page_header .= $this->css->show();

        // find page header
        if($hpos = strpos(strtolower($output), '</head>')) {
            $__page_header .= "\n";
        }
        else {
            if (!is_numeric($hpos)) {
                $hpos = strpos(strtolower($output), '<body');
            }
            if (!is_numeric($hpos) && ($hpos = strpos(strtolower($output), '<html'))) {
                while($output[$hpos]!='>')
                    $hpos++;
                $hpos++;
            }

            $__page_header = "<head>\n<title>$__page_title</title>\n$__page_header\n</head>\n";
        }

        // add page hader
        if($hpos) {
            $output = substr($output,0,$hpos) . $__page_header . substr($output,$hpos,strlen($output));
        }
        else {
            $output = $__page_header . $output;
        }

        // find page body
        if($bpos = strpos(strtolower($output), '<body')) {
            while($output[$bpos]!='>') $bpos++;
            $bpos++;
        }
        else {
            $bpos = strpos(strtolower($output), '</head>')+7;
        }

        // add page body
        if($bpos && $__page_body) {
            $output = substr($output,0,$bpos) . "\n$__page_body\n" . substr($output,$bpos,strlen($output));
        }

        // find and add page footer
        $output_lc = strtolower($output);
        if(($fpos = strrstr($output_lc, '</body>')) ||
                ($fpos = strrstr($output_lc, '</html>')))
            $output = substr($output, 0, $fpos) . "$__page_footer\n" . substr($output, $fpos);
        else
            $output .= "\n$__page_footer";


        // reset those global vars
        $__page_header = $__page_footer = '';


        // correct absolute paths in images and other tags
        $output = preg_replace('/(src|href|background)=(["\']?)(\/[a-z0-9_\-]+)/Ui', "\\1=\\2$base_path\\3", $output);
        $output = str_replace('$__skin_path', $base_path, $output);

        print rc_main::rcube_charset_convert($output, 'UTF-8', $this->charset);
    }


    function _parse($templ)
    {

    }
}
