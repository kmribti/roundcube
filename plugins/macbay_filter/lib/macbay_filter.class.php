<?php
/**
 * macbay_filter
 *
 * @final
 * @author Till Klampaeckel <till@php.net>
 */
final class macbay_filter
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

    public function saveRules($data)
    {
        $keep = array();
        foreach ($data['filter_name'] AS $filter_key=>$filter_name) {
            $rule_cond         = array();
            $rule_mode         = array();
            $rule_value        = array();
            $rule_action       = array();
            $rule_action_value = array();

            foreach($data['rule_cond'] AS $key=>$value) {
                if (strstr($key, $filter_key) === FALSE) {
                    continue;
                }
                $rule_cond[$key]  = $value;
                $rule_mode[$key]  = $data['rule_mode'][$key];
                $rule_value[$key] = $data['rule_value'][$key];
            }

            foreach($data['rule_action'] AS $key=>$value) {
                if (strstr($key, $filter_key) === FALSE) {
                    continue;
                }
                $rule_action[$key] = $value;

                if (
                    isset($data['rule_action_value'][$key])
                    && empty($data['rule_action_value'][$key]) === FALSE
                ) {
                    $rule_action_value[$key] = $data['rule_action_value'][$key];
                }
            }

            array_push(
                $keep,
                array(
                    'filter_name_new'     => $filter_name,
                    'filter_priority_new' => @$data['filter_prio'][$filter_key],
                    'rule_cond'           => $rule_cond,
                    'rule_mode'           => $rule_mode,
                    'rule_value'          => $rule_value,
                    'rule_action'         => $rule_action,
                    'rule_action_value'   => $rule_action_value
                )
            );
        }
        try {
            $rules = array();
            for ($x=0; $x<count($keep); $x++) {
                array_push($rules, $this->buildRule($keep[$x]));
            }
            $params = $this->params;
            array_push($params, $rules);
            return $this->client->call('cli.saveRules', $params);
        }
        catch(Exception $e) {
            return self::handleError($e, __LINE__);
        }
    }

    /**
     * addRule
     *
     * Tries to build the array construct for a CGPro rule.
     *
     * @access public
     * @param  array $post_data
     * @return mixed
     * @uses   macbay_filter::handleError()
     * @uses   macbay_filter::$client
     * @uses   macbay_filter::$params
     * @uses   macbay_filter::buildRule()
     */
    public function addRule($post_data)
    {
        $new_rule = $this->buildRule($post_data);

        try {
            $params = $this->params;
            array_push($params, $new_rule);
            return $this->client->call('cli.addRule', $params);
        }
        catch(Exception $e) {
            return self::handleError($e, __LINE__);
        }
    }

    /**
     * buildRule
     *
     * @access protected
     * @param  array $post_data
     * @return array
     * @see    macbay_filter::addRule()
     */
    protected function buildRule($post_data)
    {
        $new_rule = array();

        // priority
        $new_rule[0] = (int) @$post_data['filter_priority_new'];
        if (empty($new_rule[0])) {
            $new_rule[0] = 1;
        }

        // name
        $new_rule[1] = (string) @$post_data['filter_name_new'];
        if (empty($new_rule[1])) {
            $new_rule[1] = 'Filtersatz: ' . time();
        }

        // conditions
        $new_rule[2] = array();
        if (isset($post_data['cond_new']) === TRUE) {
            array_push(
                $new_rule[2],
                array(
                    $post_data['cond_new'],
                    $post_data['mode_new'],
                    $post_data['value_new']
                )
            );
        }
        if (empty($post_data['rule_cond']) === FALSE) {
            foreach($post_data['rule_cond'] AS $rule_id=>$rule_cond_value) {
                array_push(
                    $new_rule[2],
                    array(
                        $rule_cond_value,
                        @$post_data['rule_mode'][$rule_id],
                        @$post_data['rule_value'][$rule_id]
                    )
                );
            }
        }

        // actions
        $new_rule[3] = array();
        if (isset($post_data['action_new'])) {
            array_push(
                $new_rule[3],
                array(
                    $post_data['action_new'],
                    $post_data['action_add_new']
                )
            );
        }
        if (empty($post_data['rule_action']) === FALSE) {
            foreach($post_data['rule_action'] AS $action_id=>$rule_action_value) {
                if (isset($post_data['rule_action_value'][$action_id])) {
                    array_push(
                        $new_rule[3],
                        array(
                            $rule_action_value,
                            @$post_data['rule_action_value'][$action_id],
                        )
                    );
                }
                else {
                    array_push(
                        $new_rule[3],
                        array($rule_action_value)
                    );
                }
            }
        }
        return $new_rule;
    }

    /**
     * getRules
     *
     * Retrieves the rules from XMLRPC service, filters through them
     * to modify "#Vacation".
     *
     * @access public
     * @return mixed
     */
    public function getRules()
    {
        try {
            $rules = $this->client->call('cli.getRules', $this->params);
            //echo '<pre>'; var_dump($rules); echo '</pre>';
            /*
            for ($x=0; $x<count($rules); $x++) {
                if ($rules[$x][1] == '#Vacation') {
                    $rules[$x][1] = 'Autoresponder';
                }
            }*/
            return $rules;
        }
        catch(Exception $e) {
            return self::handleError($e, __LINE__);
        }
    }

    /**
     * getMeta
     *
     * Returns info needed to build the forms.
     *
     * @return mixed
     * @uses   macbay_filter::handleError()
     */
    public function getMeta()
    {
        try {
            return $this->client->call('cli.getRuleMeta', array());
        }
        catch(Exception $e) {
            return self::handleError($e, __LINE__);
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