<?php

/**
 * Subscription Options
 *
 * A plugin which can enable or disable the use of imap subscriptions.
 * It includes a toggle on the settings page under "Server Settings".
 * The preference can also be locked
 *
 * Add it to the plugins list in config/main.inc.php to enable the user option
 * The user option can be hidden and set globally by adding 'use_subscriptions'
 * to the the 'dont_override' configure line:
 * $rcmail_config['dont_override'] = array('use_subscriptions');
 * and then set the global preference"
 * $rcmail_config['use_subscriptions'] = true; // or false
 *
 * Roundcube caches folder lists.  When a user changes this option or visits
 * their folder list, this cache is refreshed.  If the option is on the
 * 'dont_override' list and the global option has changed, don't expect
 * to see the change until the folder list cache is refreshed.
 *
 * @version 1.0
 * @author Ziba Scott
 */
class subscriptions_option extends rcube_plugin
{

    function init()
    {
        $this->add_texts('localization/', false);
        $dont_override = rcmail::get_instance()->config->get('dont_override', array());
        if (!in_array('use_subscriptions', $dont_override)){
            $this->add_hook('user_preferences', array($this, 'settings_table'));
            $this->add_hook('save_preferences', array($this, 'save_prefs'));
        }
        $this->add_hook('list_mailboxes', array($this, 'list_mailboxes'));
        $this->add_hook('manage_folders', array($this, 'manage_folders'));
    }

    function settings_table($args)
    {
        if ($args['section'] == 'server') {
            $use_subscriptions = rcmail::get_instance()->config->get('use_subscriptions');
            $field_id = 'rcmfd_use_subscriptions';
            $use_subscriptions = new html_checkbox(array('name' => '_use_subscriptions', 'id' => $field_id, 'value' => 1));

            $args['table']->add('title', html::label($field_id, Q($this->gettext('useimapsubscriptions'))));
            $args['table']->add(null, $use_subscriptions->show($use_subscriptions?1:0));
        }

        return $args;
    }

    function save_prefs($args){
        $rcmail = rcmail::get_instance();
        $use_subscriptions = $rcmail->config->get('use_subscriptions');

        $args['prefs']['use_subscriptions'] = isset($_POST['_use_subscriptions']) ? true : false;
        // if the use_subscriptions preference changes, flush the folder cache
        if (($use_subscriptions && !isset($_POST['_use_subscriptions'])) ||
            (!$use_subscriptions && isset($_POST['_use_subscriptions']))) {
                $rcmail->imap_init(true);
                $rcmail->imap->clear_cache('mailboxes');
            }

        return $args;
    }

    function list_mailboxes($args){
        $rcmail = rcmail::get_instance();
        if (!$rcmail->config->get('use_subscriptions', true)) {
            $args['folders'] = iil_C_ListMailboxes($rcmail->imap->conn, $rcmail->imap->mod_mailbox($args['root']), $args['filter']);
        }
        return $args;
    }

    function manage_folders($args){
        $rcmail = rcmail::get_instance();
        if (!$rcmail->config->get('use_subscriptions', true)) {
            $args['table']->remove_column('subscribed');
        }
        return $args;
    }
}
