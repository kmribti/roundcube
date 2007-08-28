<?php
/**
 * rcube_header_sorter
 *
 * Class for sorting an array of iilBasicHeader objects in a predetermined order.
 *
 * @author  Eric Stadtherr
 * @license GPL
 */
class rcube_header_sorter
{
    var $sequence_numbers = array();

    /**
     * set the predetermined sort order.
     *
     * @param array $seqnums numerically indexed array of IMAP message sequence numbers
     */
    public function set_sequence_numbers($seqnums)
    {
        $this->sequence_numbers = $seqnums;
    }

    /**
     * sort the array of header objects
     *
     * @param array $headers array of iilBasicHeader objects indexed by UID
     */
    public function sort_headers(&$headers)
    {
        /**
         * uksort would work if the keys were the sequence number, but unfortunately
         * the keys are the UIDs.  We'll use uasort instead and dereference the value
         * to get the sequence number (in the "id" field).
         *
         * uksort($headers, array($this, "compare_seqnums"));
         */
        uasort($headers, array($this, "compare_seqnums"));
    }

    /**
     * get the position of a message sequence number in my sequence_numbers array
     *
     * @param integer $seqnum message sequence number contained in sequence_numbers
     */
    public function position_of($seqnum)
    {
        $c = count($this->sequence_numbers);
        for ($pos = 0; $pos <= $c; $pos++) {
            if ($this->sequence_numbers[$pos] == $seqnum) {
                return $pos;
            }
        }
        return -1;
    }

    /**
     * Sort method called by uasort()
     */
    public function compare_seqnums($a, $b)
    {
        // First get the sequence number from the header object (the 'id' field).
        $seqa = $a->id;
        $seqb = $b->id;

        // then find each sequence number in my ordered list
        $posa = $this->position_of($seqa);
        $posb = $this->position_of($seqb);

        // return the relative position as the comparison value
        $ret = $posa - $posb;
        return $ret;
    }
}