<?php

/*
 +-----------------------------------------------------------------------+
 | program/include/session.inc                                           |
 |                                                                       |
 | This file is part of the RoundCube Webmail client                     |
 | Copyright (C) 2005-2007, RoundCube Dev, - Switzerland                 |
 | Licensed under the GNU GPL                                            |
 |                                                                       |
 | PURPOSE:                                                              |
 |   Provide database supported session management                       |
 |                                                                       |
 +-----------------------------------------------------------------------+
 | Author: Thomas Bruederli <roundcube@gmail.com>                        |
 +-----------------------------------------------------------------------+

 $Id: session.inc 521 2007-03-27 09:34:30Z thomasb $

*/


function sess_open($save_path, $session_name)
{
    return true;
}


function sess_close()
{
    return true;
}


// read session data
function sess_read($key)
{
    $registry       = rcube_registry::get_instance();
    $DB             = $registry->get('DB', 'core');
    $SESS_CHANGED   = $registry->get('SESS_CHANGED', 'core', null);
    $SESS_CLIENT_IP = $registry->get('SESS_CLIENT_IP', 'core', null);

    if ($DB->is_error()) {
        return false;
    }
    $_query = "SELECT vars, ip, ".$DB->unixtimestamp('changed')." AS changed";
    $_query.= " FROM " . rcube::get_table_name('session');
    $_query.= " WHERE sess_id=?";
    $sql_result = $DB->query($_query, $key);

    if ($sql_arr = $DB->fetch_assoc($sql_result)) {
        $SESS_CHANGED   = $sql_arr['changed'];
        $SESS_CLIENT_IP = $sql_arr['ip'];

        $registry->set('SESS_CHANGED', $SESS_CHANGED, 'core');
        $registry->set('SESS_CLIENT_IP', $SESS_CLIENT_IP, 'core');

        if (strlen($sql_arr['vars'])) {
            return $sql_arr['vars'];
        }
    }
    
    return false;
}


// save session data
function sess_write($key, $vars)
{
    $DB = rcube_registry::get_instance()->get('DB', 'core');

    if (!is_object($DB) || $DB->is_error())
        return false;

    $sql_result = $DB->query(
        "SELECT 1
         FROM " . rcube::get_table_name('session') . "
         WHERE  sess_id=?",
        $key);

    if ($DB->num_rows($sql_result)) {
        session_decode($vars);
        $DB->query(
            "UPDATE " . rcube::get_table_name('session') . "
             SET    vars=?,
             changed=".$DB->now()."
             WHERE  sess_id=?",
            $vars,
            $key);
    }
    else {
        $DB->query(
            "INSERT INTO " . rcube::get_table_name('session') . "
             (sess_id, vars, ip, created, changed)
             VALUES (?, ?, ?, ".$DB->now().", ".$DB->now().")",
            $key,
            $vars,
            $_SERVER['REMOTE_ADDR']);
    }
    
    return true;
}


// handler for session_destroy()
function sess_destroy($key)
{
    $DB = rcube_registry::get_instance()->get('DB', 'core');

    if (!is_object($DB) || $DB->is_error())
        return false;

    // delete session entries in cache table
    $DB->query(
        "DELETE FROM " . rcube::get_table_name('cache') . "
         WHERE  session_id=?",
        $key);

    $DB->query(
        "DELETE FROM " . rcube::get_table_name('session') . "
         WHERE sess_id=?",
        $key);

    return true;
}


// garbage collecting function
function sess_gc($maxlifetime)
{
    $DB = rcube_registry::get_instance()->get('DB', 'core');

    if (!is_object($DB) || $DB->is_error())
        return FALSE;

    // get all expired sessions
    $_query = "SELECT sess_id";
    $_query.= " FROM " . rcube::get_table_name('session');
    $_query.= " WHERE " . $DB->unixtimestamp($DB->now());
    $_query.= "-" . $DB->unixtimestamp('changed')." > ?";
    $sql_result = $DB->query($_query, $maxlifetime);

    $a_exp_sessions = array();
    while ($sql_arr = $DB->fetch_assoc($sql_result)) {
        $a_exp_sessions[] = $sql_arr['sess_id'];
    }

    if (sizeof($a_exp_sessions)) {
        // delete session cache records
        $_query = "DELETE FROM " . rcube::get_table_name('cache');
        $_query.= " WHERE session_id IN ('" . join("','", $a_exp_sessions) . "')";
        $DB->query($_query);

        // delete session records
        $_query = "DELETE FROM " . rcube::get_table_name('session');
        $_query.= " WHERE sess_id IN ('" . join("','", $a_exp_sessions) . "')";
        $DB->query($_query);
    }

    // also run message cache GC
    rcube::message_cache_gc();
    rcube::temp_gc();
    return TRUE;
}


function sess_regenerate_id()
{
    $randlen = 32;
    $randval = "0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ";
    $random = "";
    for ($i=1; $i <= $randlen; $i++) {
        $random .= substr($randval, rand(0,(strlen($randval) - 1)), 1);
    }

    // use md5 value for id or remove capitals from string $randval
    $random = md5($random);

    // delete old session record
    sess_destroy(session_id());

    session_id($random);
    $cookie = session_get_cookie_params();
    setcookie(session_name(), $random, $cookie['lifetime'], $cookie['path']);

    return true;
}


// set custom functions for PHP session management
session_set_save_handler('sess_open', 'sess_close', 'sess_read', 'sess_write', 'sess_destroy', 'sess_gc');

?>
