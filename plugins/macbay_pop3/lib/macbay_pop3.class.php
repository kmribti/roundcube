<?php
/**
 * macbay_filter
 *
 * @final
 * @author Till Klampaeckel <till@php.net>
 * @uses   Zend_XmlRpc_Client
 */
final class macbay_pop3
{
    protected $client;
    protected $params;

    /**
     * __construct
     *
     * @access public
     * @param  Zend_XmlRpc_Client $rpc_client
     * @param  array $params
     */
    public function __construct(Zend_XmlRpc_Client $rpc_client, $params)
    {
        $this->client = $rpc_client;
        $this->params = $params;
    }

    /**
     * getRpop
     *
     * Queries the XMLRPC service for the user's RPOP accounts. Returns an array
     * with index rpop (the accounts), and maxRpop - the maximum number of RPOPs
     * this user is allowed to have (CGPro configuration item).
     *
     * Also translates the numerical entries from the response to an associative
     * array for easier handling in the template.
     *
     * @access public
     * @return array
     * @uses   macbay_pop3::$client
     * @uses   macbay_pop3::$params
     * @uses   macbay_pop3::handleError()
     */
    public function getRpop()
    {
        try {
            $data = $this->client->call('cli.getRpop', $this->params);
            if (empty($data['rpop'])) {
                return $data;
            }
            $keep = array();
            foreach($data['rpop'] AS $rpop_id=>$rpop_data) {
                $keep[$rpop_id] = array(
                        'servername' => $rpop_data[2],
                        'username'   => $rpop_data[3],
                        'password'   => $rpop_data[4],
                        'interval'   => (intval($rpop_data[5])/60),
                        'leave'      => (($rpop_data[6] == 'Leave')?1:0),
                        'status'     => $rpop_data[9]
                );
            }
            $data['rpop'] = $keep;
            return $data;
        }
        catch (Exception $e) {
            return self::handleError($e);
        }
    }

    /**
     * saveRpop
     *
     * Adds an RPOP account, or saves changes to an existing one. The difference
     * is key=id in rpop array.
     *
     * Saving is not yet implemented, we only add.
     *
     * @param  array $rpop
     * @return mixed
     * @uses   macbay_pop3::handleError()
     */
    public function saveRpop($rpop)
    {
        if (is_array($rpop)) {
            return false;
        }
        try {
            $params = $this->params;
            array_push($params, $rpop);
            return $this->client->call('cli.saveRpop', $params);
        }
        catch(Exception $e) {
            return self::handleError($e);
        }
    }

    /**
     * deleteRpop
     *
     * Deletes an RPOP account based on the internal ID-hash (non CGPro).
     *
     * @param  string $id
     * @return boolean
     * @uses   macbay_pop3::$client
     * @uses   macbay_pop3::$params
     * @uses   macbay_pop3::handleError()
     */
    public function deleteRpop($id)
    {
        try {
            $params = $this->params;
            array_push($params, $id);
            return $this->client->call('cli.deleteRpop', $params);
        }
        catch (Exception $e) {
            return self::handleError($e);
        }
    }

    /**
     * handleError
     *
     * Bridges the exception to RC's build in error handler.
     *
     * @access static
     * @param  Exception $e
     * @param  int $line
     * @uses   rc_bugs::raise_error()
     * @return void
     */
    static function handleError($e, $line)
    {
        rc_bugs::raise_error(
            array(
                'code' => $e->getCode(),
                'type' => 'xmlrpc',
                'message' => $e->getMessage(),
                'file'    => __FILE__,
                'line'    => $line
            ),
            TRUE
        );
        return false;
    }
}