<?php
/**
 * main.inc
 * @ignore
 */
$INSTALL_PATH = $_SERVER['DOCUMENT_ROOT'];

require_once dirname(__FILE__) . '/../../program/include/bootstrap.php';
require_once dirname(__FILE__) . '/bootstrap.php';
require_once 'program/include/main.inc';

try {


    if (isset($mb_data['types']) === true) {
        $types = $mb_data['types'];
        echo 'var mb_rules_types = new Array;' . "\n";
        $count = 0;
        foreach ($types AS $type_cmd=>$type_hr) {
            echo 'mb_rules_types[' . $count. '] = new Object;' . "\n";
            echo 'mb_rules_types[' . $count. '].cmd = "' . $type_cmd . '"' . "\n";
            echo 'mb_rules_types[' . $count. '].hr  = "' . $type_hr . '"' . "\n";

            if (isset($mb_data['values'][$type_cmd]) === true) {
                $values = $mb_data['values'][$type_cmd];
                echo "var mb_{$type_cmd} = new Array;\n";
                $count_val = 0;
                foreach ($values AS $value_cmd => $value_hr) {
                    echo 'mb_' . $type_cmd . '[' . $count_val. '] = new Object;' . "\n";
                    echo 'mb_' . $type_cmd . '[' . $count_val. '].cmd = "' . $value_cmd . '";' . "\n";
                    echo 'mb_' . $type_cmd . '[' . $count_val. '].hr  = "' . $value_hr . '";' . "\n";

                    $count_val++;
                }
            }

            $count++;
        }
    }
}
catch(Exception $e) {
    echo $e->getMessage();
    exit;
}
?>