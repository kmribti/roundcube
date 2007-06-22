<?php
/**
 * RoundCube result set class.
 * Representing an address directory result set.
 */
class rcube_result_set
{
    var $count = 0;
    var $first = 0;
    var $current = 0;
    var $records = array();

    function __construct($c=0, $f=0)
    {
        $this->count = (int)$c;
        $this->first = (int)$f;
    }

    function add($rec)
    {
        $this->records[] = $rec;
    }

    function iterate()
    {
        return $this->records[$this->current++];
    }

    function first()
    {
        $this->current = 0;
        return $this->records[$this->current++];
    }

    // alias
    function next()
    {
        return $this->iterate();
    }

    function seek($i)
    {
        $this->current = $i;
    }
}
?>