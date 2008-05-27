<?php
/**
 * MyStandard Coding Standard.
 *
 * PHP version 5
 *
 * @category  PHP
 * @package   PHP_CodeSniffer
 * @author    Till Klampaeckel <till@php.net>
 * @license   http://matrix.squiz.net/developer/tools/php_cs/licence BSD Licence
 * @version   CVS: $Id: $
 * @link      http://pear.php.net/package/PHP_CodeSniffer
 */

require_once 'PHP/CodeSniffer/Standards/CodingStandard.php';

/**
 * RoundCube Standard.
 *
 * @category  PHP
 * @package   PHP_CodeSniffer
 * @author    Till Klampaeckel <till@php.net>
 * @license   http://matrix.squiz.net/developer/tools/php_cs/licence BSD Licence
 * @version   Release: @package_version@
 * @link      http://pear.php.net/package/PHP_CodeSniffer
 */
class PHP_CodeSniffer_Standards_RoundCube_RoundCubeCodingStandard extends PHP_CodeSniffer_Standards_CodingStandard
{
    public function getIncludedSniffs()
    {
        return array('PEAR');
    }

}//end class
