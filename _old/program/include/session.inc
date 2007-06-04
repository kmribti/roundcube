<?php

/*
 +-----------------------------------------------------------------------+
 | program/include/session.inc                                           |
 |                                                                       |
 | This file is part of the RoundCube Webmail client                     |
 | Copyright (C) 2005, RoundCube Dev, - Switzerland                      |
 | Licensed under the GNU GPL                                            |
 |                                                                       |
 | PURPOSE:                                                              |
 |   Provide database supported session management                       |
 |                                                                       |
 +-----------------------------------------------------------------------+
 | Author: Thomas Bruederli <roundcube@gmail.com>                        |
 +-----------------------------------------------------------------------+

 $Id: session.inc 477 2007-02-13 17:10:17Z till $

*/


class rcSession
{
    function __construct() {}

    function rcSession() {}

    function factory($DB)
    {
        $cls = new rcSession;
        $cls->DB = $DB;

        session_set_save_handler(
            array($cls, 'sess_open'),
            array($cls, 'sess_close'),
            array($cls, 'sess_read'),
            array($cls, 'sess_write'),
            array($cls, 'sess_destroy'),
            array($cls, 'sess_gc')
        );

        return $cls;
    }

    function sess_open($save_path, $session_name)
    {
        return TRUE;
    }

    function sess_close()
    {
        return TRUE;
    }

    // read session data
    function sess_read($key)
    {
        global $SESS_CHANGED;
        $sql_result = $this->DB->query("SELECT vars, ip, ".$this->DB->unixtimestamp('changed')." AS changed
                            FROM " . rcCore::get_table_name('session') . "
                            WHERE  sess_id=?",
                            $key);

        if ($sql_arr = $this->DB->fetch_assoc($sql_result))
        {
            $SESS_CHANGED = mktime(); //$sql_arr['changed'];
            if (strlen($sql_arr['vars']))
                return $sql_arr['vars'];
        }
        return FALSE;
    }
  
    // save session data
    function sess_write($key, $vars)
    {
        $sql_result = $this->DB->query("SELECT 1
                            FROM ". rcCore::get_table_name('session') . "
                            WHERE  sess_id=?",
                            $key);
        if ($this->DB->num_rows($sql_result))
        {
            session_decode($vars);
            $this->DB->query("UPDATE ". rcCore::get_table_name('session')."
                SET    vars=?,
                       changed=now()
                WHERE  sess_id=?",
                $vars,
                $key);
        }
        else
        {
            $this->DB->query("INSERT INTO ". rcCore::get_table_name('session')."
                (sess_id, vars, ip, created, changed)
                VALUES (?, ?, ?, now(), now())",
                $key,
                $vars,
                $_SERVER['REMOTE_ADDR']);
                

        }
        return TRUE;
    }

    // handler for session_destroy()
    function sess_destroy($key)
    {
        // delete session entries in cache table
        $this->DB->query("DELETE FROM ".rcCore::get_table_name('cache')."
              WHERE  session_id=?",
              $key);
              
        $this->DB->query("DELETE FROM ".rcCore::get_table_name('session')."
              WHERE sess_id=?",
              $key);

        rcmail_clear_session_temp($key);
        return TRUE;
    }


    // garbage collecting function
    function sess_gc($maxlifetime)
    {
        // get all expired sessions  
        $sql_result = $this->DB->query("SELECT sess_id
                            FROM ".rcCore::get_table_name('session')."
                            WHERE ".$this->DB->unixtimestamp('now()')."-".$this->DB->unixtimestamp('changed')." > ?",
                            $maxlifetime);
                                   
        $a_exp_sessions = array();
        while ($sql_arr = $this->DB->fetch_assoc($sql_result))
            $a_exp_sessions[] = $sql_arr['sess_id'];

        if (sizeof($a_exp_sessions))
        {
            // delete session cache records
            $this->DB->query("DELETE FROM ".rcCore::get_table_name('cache')."
                WHERE  session_id IN ('".join("','", $a_exp_sessions)."')");
                
            // delete session records
            $this->DB->query("DELETE FROM ".rcCore::get_table_name('session')."
                WHERE sess_id IN ('".join("','", $a_exp_sessions)."')");
        }

        // remove session specific temp dirs
        foreach ($a_exp_sessions as $key)
        {
            rcmail_clear_session_temp($key);
        }

        // also run message cache GC
        rcmail_message_cache_gc();

        return TRUE;
    }
}

// TODO: GLOBALS bad
//$rcSession = rcSession::factory($db);

// set custom functions for PHP session management
/*
session_set_save_handler(
            array($rcSession, 'sess_open'),
            array($rcSession, 'sess_close'),
            array($rcSession, 'sess_read'),
            array($rcSession, 'sess_write'),
            array($rcSession, 'sess_destroy'),
            array($rcSession, 'sess_gc')
);
*/
?>
