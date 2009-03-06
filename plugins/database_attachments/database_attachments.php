<?php
/**
 * Filesystem Attachments
 * 
 * This plugin which provides database backed storage for temporary
 * attachment file handling.  The primary advantage of this plugin
 * is its compatibility with round-robin dns multi-server roundcube
 * installations.
 *
 * This plugin relies on the core filesystem_attachments plugin
 *
 * @author Ziba Scott <ziba@umich.edu>
 * 
 */
require_once('plugins/filesystem_attachments/filesystem_attachments.php');
class database_attachments extends filesystem_attachments
{

    // A prefix for the cache key used in the session and in the key field of the cache table
    private $cache_prefix = "db_attach";


    function _key($filepath){
        return  $this->cache_prefix.md5(mktime().$filepath.$_SESSION['user_id']); 
    }

    // Save a newly uploaded attachment
    function upload($args){
        $args['status'] = TRUE;
        $rcmail = rcmail::get_instance();
        $key = $this->_key($args['filepath']);
        $data = base64_encode(file_get_contents($args['filepath']));  

        $status = $rcmail->db->query(
            "INSERT INTO ".get_table_name('cache')."
            (created, user_id, cache_key, data)
            VALUES (".$rcmail->db->now().", ?, ?, ?)",          
            $_SESSION['user_id'],
            $key,
            $data);   
        if($status){
            $args['id'] = $key;
            $_SESSION['compose']['attachments'][$key] = array(
                'name' => $_FILES['_attachments']['name'][$args['index']],
                'mimetype' => rc_mime_content_type($args['filepath'], $_FILES['_attachments']['type'][0]),
                'path' => "stored in database",
            );
        } else {
            $args['status'] = FALSE;
        }
        return $args;
    }

    // Save an attachment from a non-upload source (draft or forward)
    function save($args){
        $args['status'] = TRUE;
        $rcmail = rcmail::get_instance();

        $key = $this->_key($args['filename']);
        $data = base64_encode($args['attachment']);  

        $status = $rcmail->db->query(
            "INSERT INTO ".get_table_name('cache')."
            (created, user_id, cache_key, data)
            VALUES (".$rcmail->db->now().", ?, ?, ?)",          
            $_SESSION['user_id'],
            $key,
            $data);   
        $args['id'] = $key;
        if (!$status)
        {
            $args['status'] = FALSE;
        }

        return $args;
    }

    // Remove an attachment from storage
    // This is triggered by the remove attachment button on the compose screen
    function remove($args){
        $args['status'] = TRUE;
        $rcmail = rcmail::get_instance();
        $status = $rcmail->db->query(
            "DELETE FROM ".get_table_name('cache')."
            WHERE  user_id=?
            AND    cache_key=?",
            $_SESSION['user_id'],
            $args['id']);
    
        if(!$status){
            $args['status'] = false;
        }
        return $args;
    }

    // When composing an html message, image attachments may be shown
    // For this plugin, $this->get_attachment will check the file and
    // place it on disk
    function display($args){
        return $this->get_attachment($args);
    }

    // When displaying or sending the attachment the file must be temporarily
    // copied to disk.  This function is also called by the display_attachment hook.
    function get_attachment($args){
        $args['status'] = TRUE;
        $args['erase_after_send'] = TRUE;
        $rcmail = rcmail::get_instance();
        if (!is_array($_SESSION['compose']['attachments'][$args['id']])){
            $args['status'] = FALSE;
        }
        else{
          $sql_result = $rcmail->db->query(
            "SELECT cache_id, data
             FROM ".get_table_name('cache')."
             WHERE  user_id=?
             AND    cache_key=?",
            $_SESSION['user_id'],
            $args['id']);

          if ($sql_arr = $rcmail->db->fetch_assoc($sql_result)) {
              $cache_data = base64_decode($sql_arr['data']);
              $temp_dir = unslashify($rcmail->config->get('temp_dir'));
              $tmp_path = tempnam($temp_dir, 'rcmAttmnt');
              file_put_contents($tmp_path, $cache_data);
              $_SESSION['compose']['attachments'][$args['id']]['path'] = $tmp_path;
              $_SESSION['plugins']['database_attachments']['tmp_files'][] = $tmp_path;
              $args['attachment']['path'] = $tmp_path;
          } else {
            $args['status'] = FALSE;
          }

        }
        return $args;
    }
    // Delete all temp files associated with this user
    function cleanup($args){
        $rcmail = rcmail::get_instance();
        $rcmail->db->query(
            "DELETE FROM ".get_table_name('cache')."
            WHERE  user_id=?
            AND cache_key like '{$this->cache_prefix}%'",
                $_SESSION['user_id']);

        // When sending, attachments are copied to disk and should now be cleaned up
        // Note that the cleanup must happen during the same php script execution
        // as the send so that we can be sure it's the same machine in load ballanced 
        // environments.
        if (is_array($_SESSION['plugins']['database_attachments']['tmp_files'])){
            foreach ($_SESSION['plugins']['database_attachments']['tmp_files'] as $i=>$filename){
                if(file_exists($filename)){
                    unlink($filename);
                }
                unset($_SESSION['plugins']['database_attachments']['tmp_files']);
            }
        }
        return $args;
    }
}
