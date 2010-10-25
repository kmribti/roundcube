<?php

require_once 'Horde/Kolab/Format/XML.php';
require_once 'Horde/Kolab/Storage/List.php';
require_once 'Horde/Auth.php';
require_once 'Horde/Auth/kolab.php';
require_once 'Horde/Perms.php';

/**
 * Glue class to handle access to the Kolab data using the Kolab_* classes
 * from the Horde project.
 *
 * @author Thomas Bruederli
 */
class rcube_kolab
{
    private static $horde_auth;
    
    /**
     * Setup the environment needed by the Kolab_* classes to access Kolab data
     */
    public static function setup()
    {
        global $conf;
        
        // setup already done
        if (self::$horde_auth)
            return;
        
        $rcmail = rcmail::get_instance();
        
        // load ldap credentials from local config
        $conf['kolab'] = $rcmail->config->get('kolab');
        
        $conf['kolab']['ldap']['server'] = 'ldap://' . $_SESSION['imap_host'] . ':389';
        $conf['kolab']['imap']['server'] = $_SESSION['imap_host'];
        $conf['kolab']['imap']['port'] = $_SESSION['imap_port'];
        
        // pass the current IMAP authentication credentials to the Horde auth system
        self::$horde_auth = Auth::singleton('kolab');
        if (self::$horde_auth->authenticate($_SESSION['username'], array('password' => ($pwd = $rcmail->decrypt($_SESSION['password']))), false)) {
            $_SESSION['__auth'] = array(
                'authenticated' => true,
                'userId' => $_SESSION['username'],
                'timestamp' => time(),
                'remote_addr' => $_SERVER['REMOTE_ADDR'],
            );
            Auth::setCredential('password', $pwd);
        }
    }


}
