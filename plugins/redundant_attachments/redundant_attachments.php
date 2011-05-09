<?php
/**
 * Redundant attachments
 * 
 * This plugin provides a redundant storage for temporary uploaded
 * attachment files. They are stored in both the database backed
 * as well as on the local file system.
 *
 * This plugin relies on the core filesystem_attachments plugin
 * and combines it with the functionality of the database_attachments plugin.
 *
 * @author Thomas Bruederli <roundcube@gmail.com>
 * 
 */
require_once('plugins/filesystem_attachments/filesystem_attachments.php');

class redundant_attachments extends filesystem_attachments
{
    // A prefix for the cache key used in the session and in the key field of the cache table
    private $cache_prefix = "ATTACH.";
    
    /**
     * Default constructor
     */
    function init()
    {
      parent::init();
      
      $this->db = rcmail::get_instance()->get_dbh();
    }

    /**
     * Helper method to generate a unique key for the given attachment file
     */
    private function _key($args)
    {
        $uname = $args['path'] ? $args['path'] : $args['name'];
        return  $this->cache_prefix . $args['group'] . md5(mktime() . $uname . $_SESSION['user_id']);
    }

    /**
     * Save a newly uploaded attachment
     */
    function upload($args)
    {
        $args = parent::upload($args);
        
        $key = $this->_key($args);
        $data = base64_encode(file_get_contents($args['path']));

        $status = $this->db->query(
            "INSERT INTO ".get_table_name('cache')."
             (created, user_id, cache_key, data)
             VALUES (".$this->db->now().", ?, ?, ?)",
            $_SESSION['user_id'],
            $key,
            $data);
            
        if ($status) {
            $args['id'] = $key;
            $args['status'] = true;
            unset($args['path']);
        }
        
        return $args;
    }

    /**
     * Save an attachment from a non-upload source (draft or forward)
     */
    function save($args)
    {
        $args = parent::save($args);

        if ($args['path'])
          $args['data'] = file_get_contents($args['path']);

        $data = base64_encode($args['data']);
        $key = $this->_key($args);

        $status = $this->db->query(
            "INSERT INTO ".get_table_name('cache')."
             (created, user_id, cache_key, data)
             VALUES (".$this->db->now().", ?, ?, ?)",
            $_SESSION['user_id'],
            $key,
            $data);

        if ($status) {
            $args['id'] = $key;
            $args['status'] = true;
        }

        return $args;
    }

    /**
     * Remove an attachment from storage
     * This is triggered by the remove attachment button on the compose screen
     */
    function remove($args)
    {
        $args['status'] = false;
        $status = $this->db->query(
            "DELETE FROM ".get_table_name('cache')."
             WHERE  user_id=?
             AND    cache_key=?",
            $_SESSION['user_id'],
            $args['id']);

        if ($status)
            $args['status'] = true;

        return parent::remove($args);
    }

    /**
     * When composing an html message, image attachments may be shown
     * For this plugin, $this->get() will check the file and
     * return it's contents
     */
    function display($args)
    {
        return $this->get($args);
    }

    /**
     * When displaying or sending the attachment the file contents are fetched
     * using this method. This is also called by the attachment_display hook.
     */
    function get($args)
    {
        // attempt to get file from local file system
        $args = parent::get($args);
        if ($args['path'] && ($args['status'] = file_exists($args['path'])))
          return $args;
        
        // fetch from database if not found on FS
        $sql_result = $this->db->query(
            "SELECT cache_id, data
             FROM ".get_table_name('cache')."
             WHERE  user_id=?
             AND    cache_key=?",
            $_SESSION['user_id'],
            $args['id']);

        if ($sql_arr = $this->db->fetch_assoc($sql_result)) {
            $args['data'] = base64_decode($sql_arr['data']);
            $args['status'] = true;
        }
        
        return $args;
    }
    
    /**
     * Delete all temp files associated with this user
     */
    function cleanup($args)
    {
        $prefix = $this->cache_prefix . $args['group'];
        $this->db->query(
            "DELETE FROM ".get_table_name('cache')."
             WHERE  user_id=?
             AND cache_key like '{$prefix}%'",
            $_SESSION['user_id']);
            
        parent::cleanup($args);
    }
}
