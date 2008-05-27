<?php
/**
 * PHP_CodeSniffer tokenises PHP code and detects violations of a
 * defined set of coding standards.
 *
 * PHP version 5
 *
 * @category  PHP
 * @package   PHP_CodeSniffer
 * @author    Till Klampaeckel <till@php.net>
 * @license   http://www.opensource.org/licenses/bsd-license.php BSD License
 * @version   CVS: $Id: $
 * @link      http://pear.php.net/package/PHP_CodeSniffer
 */

require_once 'PHP/CodeSniffer/Sniff.php';

/**
 * This sniff prohibits PEAR-style, if/else/elseif
 *
 * An example of the PEAR-style is:
 *
 * <code>
 *  if (...) {
 *  ...
 *  } elseif (...) {
 *  ...
 *  } else {
 *  ...
 *  }
 * </code>
 * 
 * @category  PHP
 * @package   PHP_CodeSniffer
 * @author    Till Klampaeckel <till@php.net>
 * @license   http://matrix.squiz.net/developer/tools/php_cs/licence BSD Licence
 * @version   Release: @package_version@
 * @link      http://pear.php.net/package/PHP_CodeSniffer
 */
class RoundCube_Sniffs_ControlStructures_DisallowPEARIfElseElseifSniff implements PHP_CodeSniffer_Sniff
{


    /**
     * Returns the token types that this sniff is interested in.
     *
     * @return array()
     */
    public function register()
    {
        return array(T_ELSE,T_ELSEIF);

    }//end register()


    /**
     * Processes the tokens that this sniff is interested in.
     *
     * @param PHP_CodeSniffer_File $phpcsFile The file where the token was found.
     * @param int                  $stackPtr  The position in the stack where
     *                                        the token was found.
     *
     * @return void
     */
    public function process(PHP_CodeSniffer_File $phpcsFile, $stackPtr)
    {
        $tokens = $phpcsFile->getTokens();
        $count  = 0;
        if ($tokens[$stackPtr - 1] !== T_WHITESPACE) {
            $phpcsFile->addError('} else {/} elseif (...) { not allowed.', $stackPtr);
            return;
        }
        while(true) {
            $count++;
            if ($tokens[$stackPtr - 1] === "\n") {
                break;
            }
            if ($tokens[$stackPtr - 1] === "\r") {
                break;
            }
            if ($tokens[$stackPtr - 1] === "\r\n") {
                break;
            }
            if ($count > 3) {
                $phpcsFile->addError('} else {/} elseif (...) { not allowed.', $stackPtr);
                return;
            }
        }
        return;
    }//end process()

}//end class
