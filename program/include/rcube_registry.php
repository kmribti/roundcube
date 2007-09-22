<?php


/**
 * rcube_registry
 *
 * Implements a singleton-style registry for roundcube and plugins
 * to store variables not in the global scope.
 *
 * @final
 * @author Till Klampaeckel <till@php.net>
 * @since  0.1-rc1
 */
class rcube_registry
{
    /**
     * @var    rcube_registry
     */
    private static $registry;

    /**
     * @var    array
     */
    protected $holder = null;

    /**
     * __construct
     *
     * @uses   rcube_registry::rcube_registry()
     * @return false
     */
    private function __construct()
    {
    }

    /**
     * getInstance
     *
     * Returns rcube_registry.
     *
     * @return rcube_registry
     */
    static function get_instance()
    {
        if (is_null(self::$registry) === true) {
            self::$registry = new rcube_registry;
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
     * @param  string $var
     * @param  mixed $val
     * @param  string $ns
     * @return mixed
     * @uses   rcube_registry::$holder
     */
    public function set($var, $val = '', $ns = null)
    {
        if (empty($var) === true) {
            throw new rcube_registry_exception('set: please supply a variable name');
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
     * @todo   The return value 'blows' - false could be in the variable. ;-)
     * @param  string $var
     * @param  string $ns
     * @return mixed
     * @uses   rcube_registry::$holder
     */
    public function get($var, $ns = null, $default = null)
    {
        if (empty($var) === true) {
            throw new rcube_registry_exception('get: please supply a variable name');
        }
        if (is_null($ns) === true) {
            if (isset($this->holder[$var]) === false) {
                return $default;
                //throw new rcube_registry_exception('get: not set');
            }
            return $this->holder[$var];
        }
        if (isset($this->holder[$ns]) === false) {
            //throw new rcube_registry_exception('get: unknown namespace');
            return $default;
        }
        if (isset($this->holder[$ns][$var]) === false) {
            //throw new rcube_registry_exception('get: not set');
            return $default;
        }
        return $this->holder[$ns][$var];
    }

    /**
     * purge
     *
     * Cleans the $holder
     *
     * @return boolean
     * @uses   rcube_registry::$holder
     * @see    rcube_registry::__destruct
     */
    public function purge()
    {
        $this->holder = null;
        return true;
    }

    /**
     * get_all
     *
     * Returns the entire content of a specific namespace.
     *
     * @param string Namespace identifier
     * @return array or null if it's not set
     */
    public function get_all($ns)
    {
        if (is_null($this->holder[$ns])) {
            throw new rcube_registry_exception('get_all: nothing to return');
        }
        return $this->holder[$ns];
    }

    /**
     * __destruct
     *
     * @return boolean
     * @uses   rcube_registry::purge()
     */
    public function __destruct()
    {
        return $this->purge();
    }
}

