/*
 * vcard_attachments plugin script
 * @version @package_version@
 */
function plugin_vcard_save_contact(mime_id)
{
  var lock = rcmail.set_busy(true, 'loading');
  rcmail.http_post('plugin.savevcard', '_uid='+rcmail.env.uid+'&_mbox='+urlencode(rcmail.env.mailbox)+'&_part='+urlencode(mime_id), lock);
  
  return false;
}
