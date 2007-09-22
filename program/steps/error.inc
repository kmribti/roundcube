<?php

/*
 +-----------------------------------------------------------------------+
 | program/steps/error.inc                                               |
 |                                                                       |
 | This file is part of the RoundCube Webmail client                     |
 | Copyright (C) 2005, RoundCube Dev. - Switzerland                      |
 | Licensed under the GNU GPL                                            |
 |                                                                       |
 | PURPOSE:                                                              |
 |   Display error message page                                          |
 |                                                                       |
 +-----------------------------------------------------------------------+
 | Author: Thomas Bruederli <roundcube@gmail.com>                        |
 +-----------------------------------------------------------------------+

 $Id: error.inc 543 2007-04-28 18:07:12Z thomasb $

*/


function rcube_error_message()
{
    $registry = rcube_registry::get_instance();
    $ERROR_CODE = $registry->get('ERROR_CODE', 'core');
    $ERROR_MESSAGE = $registry->get('ERROR_MESSAGE', 'core');

    // browser is not compatible with this application
    if ($ERROR_CODE == 409)
    {
        $user_agent = $GLOBALS['HTTP_SERVER_VARS']['HTTP_USER_AGENT'];
        $__error_title = 'Your browser does not suit the requirements for this application';
        $__error_text = <<<EOF
<i>Supported browsers:</i><br />
&raquo; &nbsp;Netscape 7+<br />
&raquo; &nbsp;Microsoft Internet Explorer 6+<br />
&raquo; &nbsp;Mozilla Firefox 1.0+<br />
&raquo; &nbsp;Opera 8.0+<br />
&raquo; &nbsp;Safari 1.2+<br />
<br />
&raquo; &nbsp;JavaScript enabled<br />
&raquo; &nbsp;Support for XMLHTTPRequest<br />

<p><i>Your configuration:</i><br />
$user_agent</p>
EOF;
    }

    // authorization error
    else if ($ERROR_CODE == 401)
    {
        $__error_title = "AUTHORIZATION FAILED";
        $__error_text  = "Could not verify that you are authorized to access this service!<br />\n".
            "Please contact your server-administrator.";
    }

    // failed request (wrong step in URL)
    else if ($ERROR_CODE == 404)
    {
        $__error_title = "REQUEST FAILED/FILE NOT FOUND";
        $request_url = htmlentities($_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI']);
        $__error_text  = <<<EOF
The requested page was not found!<br />
Please contact your server-administrator.

<p><i>Failed request:</i><br />
http://$request_url</p>
EOF;
    }

    // database connection error
    else if ($ERROR_CODE == 603)
    {
        $__error_title = "DATABASE ERROR: CONNECTION FAILED!";
        $__error_text  =  "Unable to connect to the database!<br />Please contact your server-administrator.";
    }

    // system error
    else
    {
        $__error_title = "SERVICE CURRENTLY NOT AVAILABLE!";
        $__error_text  = "Please contact your server-administrator.";

        $debug_level = rcube_registry::get_instance()->get('debug_level', 'config');
        if (($debug_level & 4) && $ERROR_MESSAGE)
            $__error_text = $ERROR_MESSAGE;
        else
            $__error_text = sprintf('Error No. [0x%04X]', $ERROR_CODE);
    }


    // compose page content
    return html::div(null, html::tag('h3', array('class' => "error-title"), $__error_title) . html::p('error-text', $__error_text));
}


if (rcube::template_exists('error'))
{
    $OUTPUT = rcube_registry::get_instance()->get('OUTPUT', 'core');
    $OUTPUT->reset();
    $OUTPUT->add_handler('errormessage', 'rcube_error_message');
    $OUTPUT->send('error');
}


// print system error page
$__page_content = rcube_error_message();
print <<<EOF
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml"><head>
<title>RoundCube Webmail : ERROR $ERROR_CODE</title>
</head>
<body>

<table border="0" cellsapcing="0" cellpadding="0" width="100%" height="80%"><tr><td align="center">

$__page_content

</td></tr></table>

</body>
</html>
EOF;

?>