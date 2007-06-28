<?php
/**
 * Include rc_registry_exception
 * @ignore
 */
require_once dirname(__FILE__) . '/registry/exception.php';


/**
 * rc_registry
 *
 * Implements a singleton-style registry for roundcube and plugins
 * to store variables not in the global scope.
 *
 * @final
 * @author Till Klampaeckel <till@php.net>
 * @since  0.1-rc1
 */
class rc_registry
{
    /**
     * @var    rc_registry
     * @access private
     * @access static
     */
    private static $registry;

    /**
     * @access protected
     * @var    array
     */
    protected $holder = null;

    /**
     * __construct
     *
     * @uses   rc_registry::rc_registry()
     * @return false
     */
    private function __construct()
    {
    }

    /**
     * getInstance
     *
     * Returns rc_registry.
     *
     * @access static
     * @return rc_registry
     */
    static function getInstance()
    {
        if (is_null(self::$registry) === true)
        {
            self::$registry = new rc_registry;
        }
        return self::$registry;
    }

    /**
     * set
     *
     * Saves a variable (by name and value) into the registry. Also takes an
     * optional argument $ns (namespace).
     *
     * Returns boolean (false) if things go wrong, otherwise the value of the
     * variable.
     *
     * @access public
     * @param  string $var
     * @param  mixed $val
     * @param  string $ns
     * @return mixed
     * @uses   rc_registry::$holder
     */
    public function set($var, $val = '', $ns = null)
    {
        if (empty($var) === true) {
            throw new rc_registry_exception('set: please supply a variable name');
        }
        if (is_null($ns) === true) {
            return $this->holder[$var] = $val;
        }
        if (isset($this->holder[$ns]) === false) {
            $this->holder[$ns] = array();
        }
        return $this->holder[$ns][$var] = $val;
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
    public function get($var, $ns = null, $default = null)
    {
        if (empty($var) === true) {
            throw new rc_registry_exception('get: please supply a variable name');
        }
        if (is_null($ns) === true) {
            if (isset($this->holder[$var]) === false) {
                return null;
                //throw new rc_registry_exception('get: not set');
            }
            return $this->holder[$var];
        }
        if (isset($this->holder[$ns]) === false) {
            //throw new rc_registry_exception('get: unknown namespace');
            return null;
        }
        if (isset($this->holder[$ns][$var]) === false) {
            //throw new rc_registry_exception('get: not set');
            return null;
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
    public function purge()
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
    public function getAll()
    {
        if (is_null($this->holder) === true) {
            throw new rc_registry_exception('getAll: nothing to return');
        }
        return $this->holder;
    }

    /**
     * __destruct
     *
     * @return boolean
     * @uses   rc_registry::purge()
     */
    public function __destruct()
    {
        return $this->purge();
    }
}
?>
