<?php
/**
 * rcube_header_sorter
 *
 * Class for sorting an array of iilBasicHeader objects in a predetermined order.
 *
 * @author  Eric Stadtherr
 * @license GPL
 */
class rcube_header_sorter {
    private $sequence_numbers = array();

    /**
     * Set the predetermined sort order.
     *
     * @param array Numerically indexed array of IMAP message sequence numbers
     */
    public function set_sequence_numbers($seqnums = array()) {
        $this->sequence_numbers = $seqnums;
    }

    /**
     * Sort the array of header objects
     *
     * @param array Array of iilBasicHeader objects indexed by UID
     */
    public function sort_headers($headers) {
        /**
         * uksort would work if the keys were the sequence number, but unfortunately
         * the keys are the UIDs.  We'll use uasort instead and dereference the value
         * to get the sequence number (in the "id" field).
         *
         * uksort($headers, array($this, "compare_seqnums"));
         */
        uasort($headers, array($this, 'compare_seqnums'));
    }

    /**
     * Get the position of a message sequence number in my sequence_numbers array
     *
     * @param int Message sequence number contained in sequence_numbers
     * @return int Position, -1 if not found
     */
    public function position_of($seqnum) {
        for ($pos = 0, $c = count($this->sequence_numbers); $pos <= $c; $pos++) {
            if ($this->sequence_numbers[$pos] == $seqnum) {
                return $pos;
            }
        }
        return -1;
    }

    /**
     * Sort method called by uasort()
     */
    public function compare_seqnums($a, $b) {
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

?>