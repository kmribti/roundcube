<?php
/**
 * rcmail_send
 *
 * @todo Write a constructor, make all functions public.
 * @todo Move to own file.
 */
class rcmail_send
{
    /**
     * get_identity
     *
     * @access static
     * @param  unknown_type $id
     * @return unknown
     */
    function get_identity($id)
    {
        $registry = rc_registry::getInstance();
        $DB       = $registry->get('DB', 'core');
        $OUTPUT   = $registry->get('OUTPUT', 'core');

        // get identity record
        $_query = "SELECT *, email AS mailto";
        $_query.= " FROM " . get_table_name('identities');
        $_query.= " WHERE identity_id=?";
        $_query.= " AND user_id=?";
        $_query.= " AND del<>1";

        rc_main::tfk_debug('Identity: ' . $_query);

        $sql_result = $DB->query($_query, $id, $_SESSION['user_id']);
        if ($DB->db_error === true) {
            return FALSE;
        }
        if ($DB->num_rows($sql_result)) {
            $sql_arr = $DB->fetch_assoc($sql_result);
            $out = $sql_arr;
            $name = strpos($sql_arr['name'], ",") ? '"'.$sql_arr['name'].'"' : $sql_arr['name'];
            $out['string'] = sprintf(
                                '%s <%s>',
                                rc_main::rcube_charset_convert($name, RCMAIL_CHARSET, $OUTPUT->get_charset()),
                                $sql_arr['mailto']
            );
            return $out;
        }
        return FALSE;
    }

    /**
     * go from this:
     * <img src=".../tiny_mce/plugins/emotions/images/smiley-cool.gif" border="0" alt="Cool" title="Cool" />
     *
     * to this:
     *
     * <IMG src="cid:smiley-cool.gif"/>
     * ...
     * ------part...
     * Content-Type: image/gif
     * Content-Transfer-Encoding: base64
     * Content-ID: <smiley-cool.gif>
     */
    function attach_emoticons(&$mime_message)
    {
        $registry     = rc_registry::getInstance();
        $INSTALL_PATH = $registry->get('INSTALL_PATH', 'core');
        $CONFIG       = $registry->get('CONFIG', 'core');

        $htmlContents = $mime_message->getHtmlBody();

        // remove any null-byte characters before parsing
        $body = preg_replace('/\x00/', '', $htmlContents);

        $last_img_pos = 0;

        $searchstr = 'program/js/tiny_mce/plugins/emotions/images/';

        // keep track of added images, so they're only added once
        $included_images = array();

        // find emoticon image tags
        while ($pos = strpos($body, $searchstr, $last_img_pos)) {
            $pos2 = strpos($body, '"', $pos);
            $body_pre = substr($body, 0, $pos);
            $image_name = substr(
                            $body,
                            $pos + strlen($searchstr),
                            $pos2 - ($pos + strlen($searchstr))
            );
            // sanitize image name so resulting attachment doesn't leave images dir
            $image_name = preg_replace('/[^a-zA-Z0-9_\.\-]/i','',$image_name);

            $body_post = substr($body, $pos2);

            if (! in_array($image_name, $included_images)) {
                // add the image to the MIME message
                $img_file = $INSTALL_PATH . '/' . $searchstr . $image_name;
                $status   = $mime_message->addHTMLImage(
                                    $img_file,
                                    'image/gif',
                                    '',
                                    true,
                                    '_' . $image_name
                );
                if($status === false) {
                    return $status;
                }
                array_push($included_images, $image_name);
            }

            $body = $body_pre . 'cid:_' . $image_name . $body_post;

            $last_img_pos = $pos2;
        }

        return $mime_message->setHTMLBody($body);
    }
}
?>