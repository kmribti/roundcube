<?php
/**
 * macbay_filter
 *
 * @final
 * @author Till Klampaeckel <till@php.net>
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
        exit;
    }
}