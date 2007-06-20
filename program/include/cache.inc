<?php

/*
 +-----------------------------------------------------------------------+
 | program/include/cache.inc                                             |
 |                                                                       |
 | This file is part of the RoundCube Webmail client                     |
 | Copyright (C) 2005, RoundCube Dev, - Switzerland                      |
 | Licensed under the GNU GPL                                            |
 |                                                                       |
 | PURPOSE:                                                              |
 |   Provide access to the application cache                             |
 |                                                                       |
 +-----------------------------------------------------------------------+
 | Author: Thomas Bruederli <roundcube@gmail.com>                        |
 +-----------------------------------------------------------------------+

 $Id: cache.inc 88 2005-12-03 16:54:12Z roundcube $

*/


function rcube_read_cache($key)
{
    $registry   = rc_registry::getInstance();
    $DB         = $registry->get('DB', 'core');
    $CACHE_KEYS = $registry->get('CACHE_KEYS', 'core');

    // query db
    $_query = "SELECT cache_id, data";
    $_query.= " FROM " . rc_main::get_table_name('cache');
    $_query.= " WHERE user_id=?";
    $_query.= " AND cache_key=?";
    $sql_result = $DB->query($_query, $_SESSION['user_id'], $key);

    // get cached data
    if ($sql_arr = $DB->fetch_assoc($sql_result)) {
        $data = $sql_arr['data'];
        $CACHE_KEYS[$key] = $sql_arr['cache_id'];
    }
    else {
        $data = FALSE;
    }
    $registry->set('CACHE_KEYS', $CACHE_KEYS, 'core');
    return $data;
}


function rcube_write_cache($key, $data, $session_cache=FALSE)
{
    $registry   = rc_registry::getInstance();
    $DB         = $registry->get('DB', 'core');
    $CACHE_KEYS = $registry->get('CACHE_KEYS', 'core');
    $sess_id    = $registry->get('sess_id', 'core');

    // check if we already have a cache entry for this key
    if (!isset($CACHE_KEYS[$key])) {
        $_query = "SELECT cache_id";
        $_query.= " FROM " . rc_main::get_table_name('cache');
        $_query.= " WHERE user_id=?";
        $_query.= " AND cache_key=?";
        $sql_result = $DB->query($_query, $_SESSION['user_id'], $key);

        if ($sql_arr = $DB->fetch_assoc($sql_result)) {
            $CACHE_KEYS[$key] = $sql_arr['cache_id'];
        }
        else {
            $CACHE_KEYS[$key] = FALSE;
        }
        $registry->set('CACHE_KEYS', $CACHE_KEYS, 'core');
    }

    // update existing cache record
    if ($CACHE_KEYS[$key]) {
        $_query = "UPDATE " . rc_main::get_table_name('cache');
        $_query.= " SET created=now(),";
        $_query.= " data=?";
        $_query.= " WHERE user_id=?";
        $_query.= " AND cache_key=?";
        $DB->query($_query, $data, $_SESSION['user_id'], $key);
    }
    // add new cache record
    else {
        $_query = "INSERT INTO " . rc_main::get_table_name('cache');
        $_query.= " (created, user_id, session_id, cache_key, data)";
        $_query.= " VALUES (now(), ?, ?, ?, ?)";
        $DB->query(
                $_query,
                $_SESSION['user_id'],
                $session_cache ? $sess_id : 'NULL',
                $key,
                $data
        );
    }
}


function rcube_clear_cache($key)
{
    $registry = rc_registry::getInstance();
    $DB       = $registry->get('DB', 'core');

    $_query = "DELETE FROM " . rc_main::get_table_name('cache');
    $_query.= " WHERE user_id=?";
    $_query.= " AND cache_key=?";
    $DB->query($_query, $_SESSION['user_id'], $key);
}
?>