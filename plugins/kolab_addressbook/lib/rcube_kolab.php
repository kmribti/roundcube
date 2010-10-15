<?php

/**
 * Glue class to handle access to the Kolab data using the Kolab_* classes
 * from the Horde project.
 *
 * @author Thomas Bruederli
 */
class rcube_kolab
{
    /**
     * Setup the environment needed by the Kolab_* classes to access Kolab data
     */
    public static function setup()
    {
        $rcmail = rcmail::get_instance();
        
        // if we need IMAP access through Roundcube IMAP class
        // $rcmail->imap_init();

        // get some config settings for the IMAP connection
        $imap_auth_method = $rcmail->config->get('imap_auth_type', 'check');
        $imap_delimiter = isset($_SESSION['imap_delimiter']) ? $_SESSION['imap_delimiter'] : $rcmail->config->get('imap_delimiter');

        // this is how we get the current IMAP authentication credentials:
        // $_SESSION['imap_host'], $_SESSION['username'], $rcmail->decrypt($_SESSION['password']), $_SESSION['imap_port'], $_SESSION['imap_ssl']
        
        
    }


}
