<form action="index.php?_step=3" method="post">

<h3>Check config files</h3>
<?php

require_once 'include/rcube_html.inc';

$read_main = is_readable('../config/main.inc.php');
$read_db = is_readable('../config/db.inc.php');

if ($read_main && !empty($RCI->config)) {
  $RCI->pass('main.inc.php');
}
else if ($read_main) {
  $RCI->fail('main.inc.php', 'Syntax error');
}
else if (!$read_main) {
  $RCI->fail('main.inc.php', 'Unable to read file. Did you create the config files?');
}
echo '<br />';

if ($read_db && !empty($RCI->config['db_table_users'])) {
  $RCI->pass('db.inc.php');
}
else if ($read_db) {
  $RCI->fail('db.inc.php', 'Syntax error');
}
else if (!$read_db) {
  $RCI->fail('db.inc.php', 'Unable to read file. Did you create the config files?');
}

?>

<h3>Check configured database settings</h3>
<?php

$db_working = false;
if (!empty($RCI->config)) {
    if (!empty($RCI->config['db_backend']) && !empty($RCI->config['db_dsnw'])) {

        echo 'Backend: ';
        echo 'PEAR::' . strtoupper($RCI->config['db_backend']) . '<br />';

        $_class = 'rcube_' . strtolower($RCI->config['db_backend']);
        require_once 'include/' . $_class . '.inc';

        $DB = new $_class($RCI->config['db_dsnw'], '', false);
        $DB->db_connect('w');
        if (!($db_error_msg = $DB->is_error())) {
            $RCI->pass('DSN (write)');
            echo '<br />';
            $db_working = true;
        }
        else {
            $RCI->fail('DSN (write)', $db_error_msg);
            echo '<p class="hint">Make sure that the configured database extists and that the user as write privileges<br />';
            echo 'DSN: ' . $RCI->config['db_dsnw'] . '</p>';
        }
    }
    else {
        $RCI->fail('DSN (write)', 'not set');
    }
}
else {
    $RCI->fail('Config', 'Could not read config files');
}

// initialize db with schema found in /SQL/*
if ($db_working && $_POST['initdb']) {
    if (!($success = $RCI->init_db($DB))) {
        $db_working = false;
        echo '<p class="warning">Please try to inizialize the database manually as described in the INSTALL guide.
          Make sure that the configured database extists and that the user as write privileges</p>';
    }
}

// test database
if ($db_working) {
    $db_read = $DB->query("SELECT count(*) FROM {$RCI->config['db_table_users']}");
    if (!$db_read) {
        $RCI->fail('DB Schema', "Database not initialized");
        $db_working = false;
        echo '<p><input type="submit" name="initdb" value="Initialize database" /></p>';
    }
    else {
        $RCI->pass('DB Schema');
    }
    echo '<br />';
}

// more database tests
if ($db_working) {
    // write test
    $db_write = $DB->query("INSERT INTO {$RCI->config['db_table_cache']} (session_id, cache_key, data, user_id) VALUES (?, ?, ?, 0)", '1234567890abcdef', 'test', 'test');
    $insert_id = $DB->insert_id($RCI->config['db_sequence_cache']);
    
    if ($db_write && $insert_id) {
      $RCI->pass('DB Write');
      $DB->query("DELETE FROM {$RCI->config['db_table_cache']} WHERE cache_id=?", $insert_id);
    }
    else {
      $RCI->fail('DB Write', $RCI->get_error());
    }
    echo '<br />';    
    
    // check timezone settings
    $tz_db = 'SELECT ' . $DB->unixtimestamp($DB->now()) . ' AS tz_db';
    $tz_db = $DB->query($tz_db);
    $tz_db = $DB->fetch_assoc($tz_db);
    $tz_db = (int) $tz_db['tz_db'];
    $tz_local = (int) time();
    $tz_diff  = $tz_local - $tz_db;

    // sometimes db and web servers are on separate hosts, so allow a 30 minutes delta
    if (abs($tz_diff) > 1800) {
        $RCI->fail('DB Time', "Database time differs {$td_ziff}s from PHP time");
    }
    else {
        $RCI->pass('DB Time');
    }
}

?>

<h3>Test SMTP settings</h3>

<p>
Server: <?php echo $RCI->getprop('smtp_server', 'PHP mail()'); ?><br />
Port: <?php echo $RCI->getprop('smtp_port'); ?><br />

<?php

if ($RCI->getprop('smtp_server')) {
  $user = $RCI->getprop('smtp_user', '(none)');
  $pass = $RCI->getprop('smtp_pass', '(none)');
  
  if ($user == '%u') {
    $user_field = new textfield(array('name' => '_user'));
    $user = $user_field->show();
  }
  if ($pass == '%p') {
    $pass_field = new passwordfield(array('name' => '_pass'));
    $pass = $pass_field->show();
  }
  
  echo "User: $user<br />";
  echo "Password: $pass<br />";
}

?>
</p>

<?php

if (isset($_POST['sendmail']) && !empty($_POST['_from']) && !empty($_POST['_to'])) {
  
  require_once 'lib/rc_mail_mime.inc';
  require_once 'include/rcube_smtp.inc';
  
  echo '<p>Trying to send email...<br />';
  
  if (preg_match('/^' . $RCI->email_pattern . '$/i', trim($_POST['_from'])) &&
      preg_match('/^' . $RCI->email_pattern . '$/i', trim($_POST['_to']))) {
  
    $headers = array(
      'From' => trim($_POST['_from']),
      'To'  => trim($_POST['_to']),
      'Subject' => 'Test message from RoundCube',
    );

    $body = 'This is a test to confirm that RoundCube can send email.';
    $smtp_response = array();
    
    // send mail using configured SMTP server
    if ($RCI->getprop('smtp_server')) {
      $CONFIG = $RCI->config;
      
      if (!empty($_POST['_user']))
        $CONFIG['smtp_user'] = $_POST['_user'];
      if (!empty($_POST['_pass']))
        $CONFIG['smtp_pass'] = $_POST['_pass'];
      
      $mail_object  = new rc_mail_mime();
      $send_headers = $mail_object->headers($headers);
      
      $status = smtp_mail($headers['From'], $headers['To'],
          ($foo = $mail_object->txtHeaders($send_headers)),
          $body, $smtp_response);
    }
    else {    // use mail()
      $header_str = 'From: ' . $headers['From'];
      
      if (ini_get('safe_mode'))
        $status = mail($headers['To'], $headers['Subject'], $body, $header_str);
      else
        $status = mail($headers['To'], $headers['Subject'], $body, $header_str, '-f'.$headers['From']);
      
      if (!$status)
        $smtp_response[] = 'Mail delivery with mail() failed. Check your error logs for details';
    }

    if ($status) {
        $RCI->pass('SMTP send');
    }
    else {
        $RCI->fail('SMTP send', join('; ', $smtp_response));
    }
  }
  else {
    $RCI->fail('SMTP send', 'Invalid sender or recipient');
  }
}

echo '</p>';

?>

<table>
<tbody>
  <tr><td><label for="sendmailfrom">Sender</label></td><td><input type="text" name="_from" value="" id="sendmailfrom" /></td></tr>
  <tr><td><label for="sendmailto">Recipient</label></td><td><input type="text" name="_to" value="" id="sendmailto" /></td></tr>
</tbody>
</table>

<p><input type="submit" name="sendmail" value="Send test mail" /></p>


<p>[@todo Add tests for IMAP settings]</p>

</form>

<p class="warning">

After completing the installation and the final tests please <b>remove</b> the whole
installer folder from the document root of the webserver.<br />
<br />

These files may expose sensitive configuration data like server passwords and encryption keys
to the public. Make sure you cannot access this installer from your browser.

</p>
