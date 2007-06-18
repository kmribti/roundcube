<?php
/**
 * rcRegistry
 *
 * Implements a singleton.
 *
 * @final
 * @author Till Klampaeckel <till@php.net>
 * @since  0.1-rc1
 */
class rc_registry
{
    var $holder = null;

    /**
     * rc_registry
     *
     * @return false
     */
    function rc_registry()
    {
        return false;
    }

    /**
     * __construct
     *
     * @uses   rc_registry::rc_registry()
     * @return false
     */
    function __construct()
    {
        return $this->rc_registry();
    }

    /**
     * getInstance
     *
     * Returns rc_registry.
     *
     * @access static
     * @return rc_registry
     */
    function getInstance()
    {
        static $registry = null;
        if (is_null($registry) === true)
        {
            $registry = new rc_registry;
        }
        return $registry;
    }

    /**
     * set
     *
     * Saves a variable (by name and value) into the registry. Also takes an
     * optional argument $ns (namespace).
     *
     * Returns boolean - true on success, false if things go wrong.
     *
     * @access public
     * @param  string $var
     * @param  mixed $val
     * @param  string $ns
     * @return boolean
     * @uses   rc_registry::$holder
     */
    function set($var, $val = '', $ns = null)
    {
        if (empty($var) === true) {
            return false;
        }
        if (is_null($ns) === true) {
            $this->holder[$var] = $val;
            return true;
        }
        if (isset($this->holder[$ns]) === false) {
            $this->holder[$ns] = array();
        }
        $this->holder[$ns][$var] = $val;
        return true;
    }

    /**
     * get
     *
     * Returns a variable - by name. Also uses $ns (namespace) if supplied.
     * Returns boolean (false) when things go wrong - and the variable otherwise.
     *
     * @access public
     * @todo   The return value 'blows' - false could be in the variable. ;-)
     * @param  string $var
     * @param  string $ns
     * @return mixed
     * @uses   rc_registry::$holder
     */
    function get($var, $ns = null)
    {
        if (empty($var) === true) {
            return false;
        }
        if (is_null($ns) === true) {
            if (isset($this->holder[$var]) === false) {
                return false;
            }
            return $this->holder[$var];
        }
        if (isset($this->holder[$ns]) === false) {
            return false;
        }
        if (isset($this->holder[$ns][$var]) === false) {
            return false;
        }
        return $this->holder[$ns][$var];
    }

    /**
     * purge
     *
     * Cleans the $holder
     *
     * @access public
     * @return boolean
     * @uses   rc_registry::$holder
     * @see    rc_registry::__destruct
     */
    function purge()
    {
        $this->holder = null;
        return true;
    }

    /**
     * getAll
     *
     * Returns the entire registry, or false if it's not set.
     *
     * @access public
     * @return mixed
     */
    function getAll()
    {
        if (is_null($this->holder) === true) {
            return false;
        }
        return $this->holder;
    }

    /**
     * __destruct
     *
     * @return boolean
     * @uses   rc_registry::purge()
     */
    function __destruct()
    {
        return $this->purge();
    }
}
?>
