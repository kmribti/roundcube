<?php
/**
 * rcRegistry
 *
 * @final
 * @author Till Klampaeckel <till@php.net>
 * @since  0.1-rc1
 */
class rcRegistry
{
    function rcRegistry()
    {
        return false;
    }

    function __construct()
    {
        return false;
    }

    function set($var, $val = '', $ns = null)
    {
        if (empty($var) === true) {
            return false;
        }
        if (isset($GLOBALS['rcRegistry']) === false) {
            $GLOBALS['rcRegistry'] = array();
        }
        if (is_null($ns) === true) {
            $GLOBALS['rcRegistry'][$var] = $val;
            return true;
        }
        if (isset($GLOBALS['rcRegistry'][$ns]) === false) {
            $GLOBALS['rcRegistry'][$ns] = array();
        }
        $GLOBALS['rcRegistry'][$ns][$var] = $val;
        return true;
    }

    function get($var, $ns = null)
    {
        if (empty($var) === true) {
            return false;
        }
        if (is_null($ns) === true) {
            if (isset($GLOBALS['rcRegistry'][$var]) === false) {
                return false;
            }
            return $GLOBALS['rcRegistry'][$var];
        }
        if (isset($GLOBALS['rcRegistry'][$ns]) === false) {
            return false;
        }
        if (isset($GLOBALS['rcRegistry'][$ns][$var]) === false) {
            return false;
        }
        return $GLOBALS['rcRegistry'][$ns][$var];
    }

    function purge()
    {
        unset($GLOBALS['rcRegistry']);
        return true;
    }

    function getAll()
    {
        if (isset($GLOBALS['rcRegistry']) === false) {
            return false;
        }
        return $GLOBALS['rcRegistry'];
    }
}

?>
