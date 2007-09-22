<?php
/*
 +-----------------------------------------------------------------------+
 | program/include/rcube_error.php                                       |
 |                                                                       |
 | This file is part of the RoundCube Webmail client                     |
 | Copyright (C) 2005-2007, RoundCube Dev, - Switzerland                 |
 | Licensed under the GNU GPL                                            |
 |                                                                       |
 | PURPOSE:                                                              |
 |   Provide error handling and logging functions                        |
 |                                                                       |
 +-----------------------------------------------------------------------+
 | Author: Thomas Bruederli <roundcube@gmail.com>                        |
 +-----------------------------------------------------------------------+

 $Id: bugs.inc 347 2006-09-16 22:58:51Z estadtherr $

*/

/**
 * rcube_error
 *
 * @final
 */
class rcube_error
{
    /**
     * Throw system error and show error page
     *
     * @param  array Named parameters:
     *  - code: Error code
     *  - type: Error type (php|db|imap|javascript)
     *  - message: Error message
     *  - file: File path where error occured
     *  - line: Line number
     * @param  boolean Log this error
     * @param  boolean Set to true in order to terminate the script
     * @uses   rcube_registry::get_instance()
     * @return void
     */
    static function raise($arg=array(), $log=false, $terminate=false)
    {
        // report bug (if not incompatible browser)
        if ($log && $arg['type'] && $arg['message']) {
            self::log($arg);
        }
        // display error page and terminate script
        if ($terminate) {
            $registry = rcube_registry::get_instance();
            $registry->set('ERROR_CODE', $arg['code'], 'core');
            $registry->set('ERROR_MESSAGE', $arg['message'], 'core');

            include 'program/steps/error.inc';
            exit;
        }
    }


    /**
     * Report error
     *
     * @uses   rcube_registry::get_instance()
     * @return void
     */
    static function log($arg_arr)
    {
        $registry     = rcube_registry::get_instance();
        $log_dir      = $registry->get('log_dir', 'config');
        $debug_level  = $registry->get('debug_level', 'config');

        $program = $arg_arr['type']=='xpath' ? 'XPath' : strtoupper($arg_arr['type']);

        // write error to local log file
        if ($debug_level & 1) {
            $log_entry = sprintf(
                "[%s] %s Error: %s in %s on line %d\n",
                date('d-M-Y H:i:s O'),
                $program,
                $arg_arr['message'],
                $arg_arr['file'],
                $arg_arr['line']
            );

            if (empty($log_dir)) {
                $log_dir = INSTALL_PATH . 'logs';
                $registry->set('log_dir', $log_dir, 'config');
            }

            // try to open specific log file for writing
            if ($fp = @fopen($log_dir . '/errors', 'a')) {
                fwrite($fp, $log_entry);
                fclose($fp);
            }
            else {
                // send error to PHPs error handler
                trigger_error($arg_arr['message']);
            }
        }

        // show error if debug_mode is on
        if ($debug_level & 4) {
            echo "<b>$program Error";

            if (!empty($arg_arr['file']) && !empty($arg_arr['line'])) {
                echo " in {$arg_arr['file']} ({$arg_arr['line']})";
            }

            echo ':</b>&nbsp;';
            echo nl2br($arg_arr['message']);
            echo '<br />';
            flush();
        }
    }
}

