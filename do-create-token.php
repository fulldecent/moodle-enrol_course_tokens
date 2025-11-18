<?php
require_once('../../config.php');
require_login();
require_capability('moodle/site:config', context_system::instance());

// Configuration for retry attempts
define('MAX_RETRIES', 3);
define('RETRY_DELAY_MS', 200000); // 200ms in microseconds
define('EMAIL_RETRY_DELAY_MS', 500000); // 500ms for email retries

/**
 * Ensure optional name fields exist to avoid debug notices.
 */
function ensure_optional_name_fields(&$user) {
  $optional_fields = ['firstnamephonetic', 'lastnamephonetic', 'middlename', 'alternatename'];
  foreach ($optional_fields as $field) {
    if (!property_exists($user, $field)) {
      $user->$field = '';
    }
  }
}

/**
 * Generic retry wrapper for any operation
 *
 * @param callable $operation The operation to retry
 * @param int $max_retries Maximum number of retry attempts
 * @param int $delay_microseconds Delay between retries in microseconds
 * @param string $operation_name Name of operation for logging
 * @return mixed Result of the operation
 * @throws Exception If all retries fail
 */
function retry_operation($operation, $max_retries = MAX_RETRIES, $delay_microseconds = RETRY_DELAY_MS, $operation_name = 'Operation') {
  $attempt = 0;
  $last_exception = null;

  while ($attempt < $max_retries) {
    try {
      $attempt++;
      $result = $operation();

      // If we get here, operation succeeded
      if ($attempt > 1) {
        error_log("{$operation_name} succeeded on attempt {$attempt}");
      }
      return $result;

    } catch (Exception $e) {
      $last_exception = $e;
      error_log("{$operation_name} failed on attempt {$attempt}/{$max_retries}: " . $e->getMessage());

      if ($attempt < $max_retries) {
        usleep($delay_microseconds);
      }
    }
  }

  // All retries failed
  error_log("{$operation_name} failed after {$max_retries} attempts");
  throw new Exception("{$operation_name} failed after {$max_retries} attempts: " . $last_exception->getMessage());
}

/**
 * Custom function to send HTML emails consistently with retry logic
 *
 * @param object $user User object with email, firstname, lastname
 * @param string $subject Email subject
 * @param string $html_content HTML content of the email
 * @param object $sender Sender object with email, firstname, lastname
 * @return bool True if email was sent successfully, false otherwise
 */
function send_html_email($user, $subject, $html_content, $sender)
{
  global $CFG;

  // Ensure both user and sender have all required name fields
  ensure_optional_name_fields($user);
  ensure_optional_name_fields($sender);

  // Wrap email sending in retry logic
  try {
    return retry_operation(function() use ($user, $sender, $subject, $html_content) {

      // Strip HTML tags for plain text version
      $plain_content = strip_tags($html_content);

      // Send email using Moodle's function
      $result = email_to_user($user, $sender, $subject, $plain_content, $html_content);

      if (!$result) {
        throw new Exception("Email sending returned false");
      }

      return $result;

    }, MAX_RETRIES, EMAIL_RETRY_DELAY_MS, "Email sending to {$user->email}");

  } catch (Exception $e) {
    error_log("All email sending attempts failed for {$user->email}: " . $e->getMessage());
    return false;
  }
}

// Process, validate form inputs
require_sesskey();

// Validate and retrieve form parameters with retry
try {
  $course_id = required_param('course_id', PARAM_INT);
  $email = required_param('email', PARAM_EMAIL);
  $quantity = required_param('quantity', PARAM_INT);
  $firstname = required_param('firstname', PARAM_TEXT);
  $lastname = required_param('lastname', PARAM_TEXT);
} catch (Exception $e) {
  redirect(
    new moodle_url('/enrol/course_tokens/index.php'),
    'Missing required parameters: ' . $e->getMessage(),
    null,
    \core\output\notification::NOTIFY_ERROR
  );
}

$extra_json = optional_param('extra_json', '', PARAM_RAW);
$group_account = optional_param('group_account', '', PARAM_TEXT);

// Extract order number from extra JSON
$order_number = null;
if (!empty($extra_json)) {
  $extra_data = json_decode($extra_json, true);
  if (json_last_error() === JSON_ERROR_NONE && isset($extra_data['order_number'])) {
    $order_number = (int) $extra_data['order_number'];
  }
}

// Validate course ID with retry
try {
  $course = retry_operation(function() use ($course_id) {
    global $DB;
    $course = $DB->get_record('course', array('id' => $course_id));
    if (empty($course)) {
      throw new Exception("Course not found with ID: {$course_id}");
    }
    return $course;
  }, MAX_RETRIES, RETRY_DELAY_MS, "Course validation");
} catch (Exception $e) {
  redirect(
    new moodle_url('/enrol/course_tokens/index.php'),
    get_string('errorcourse', 'enrol_course_tokens'),
    null,
    \core\output\notification::NOTIFY_ERROR
  );
}

// Validate email
if (!validate_email($email)) {
  redirect(
    new moodle_url('/enrol/course_tokens/index.php'),
    get_string('erroremail', 'enrol_course_tokens'),
    null,
    \core\output\notification::NOTIFY_ERROR
  );
}

// Validate JSON
if (!empty($extra_json)) {
  $extra_json_decoded = json_decode($extra_json);
  if (json_last_error() !== JSON_ERROR_NONE) {
    redirect(
      new moodle_url('/enrol/course_tokens/index.php'),
      get_string('errorjson', 'enrol_course_tokens'),
      null,
      \core\output\notification::NOTIFY_ERROR
    );
  }
  $extra_json = $extra_json_decoded;
}

// Validate quantity
if ($quantity < 1) {
  redirect(
    new moodle_url('/enrol/course_tokens/index.php'),
    get_string('errorquantity', 'enrol_course_tokens'),
    null,
    \core\output\notification::NOTIFY_ERROR
  );
}

// Check if user exists with retry logic
try {
  $existing_user = retry_operation(function() use ($email) {
    global $DB;
    $user = $DB->get_record('user', ['email' => $email, 'deleted' => 0]);
    if ($user) {
      ensure_optional_name_fields($user);
    }
    return $user;
  }, MAX_RETRIES, RETRY_DELAY_MS, "User lookup");
} catch (Exception $e) {
  redirect(
    new moodle_url('/enrol/course_tokens/index.php'),
    'Database error during user lookup. Please try again.',
    null,
    \core\output\notification::NOTIFY_ERROR
  );
}

// If user exists and is suspended, reactivate the account with retry
if ($existing_user && $existing_user->suspended) {
  try {
    retry_operation(function() use ($existing_user) {
      global $DB;
      $existing_user->suspended = 0;
      $existing_user->timemodified = time();
      $result = $DB->update_record('user', $existing_user);
      if (!$result) {
        throw new Exception("Failed to update user suspension status");
      }
      return $result;
    }, MAX_RETRIES, RETRY_DELAY_MS, "User unsuspension");

    \core\notification::add('User account has been reactivated.', \core\output\notification::NOTIFY_SUCCESS);
  } catch (Exception $e) {
    error_log("Failed to unsuspend user {$email}: " . $e->getMessage());
    // Continue anyway - user exists but may still be suspended
  }
}

// Use the user record (now guaranteed to be active if it exists)
$user = $existing_user ?: null;

/**
 * Generate a secure password in the format ###-###-###-###
 */
function generate_hex_password()
{
  return sprintf(
    '%s-%s-%s-%s',
    substr(bin2hex(random_bytes(2)), 0, 3),
    substr(bin2hex(random_bytes(2)), 0, 3),
    substr(bin2hex(random_bytes(2)), 0, 3),
    substr(bin2hex(random_bytes(2)), 0, 3)
  );
}

// Create new user if not found with retry logic
if (empty($user)) {
  global $DB, $CFG, $USER;

  $plaintext_password = generate_hex_password();

  try {
    $user = retry_operation(function() use ($email, $firstname, $lastname, $plaintext_password) {
      global $DB, $CFG;

      $new_user = new stdClass();
      $new_user->auth = 'manual';
      $new_user->confirmed = 1;
      $new_user->mnethostid = $CFG->mnet_localhost_id;

      // Generate unique username with retry
      $username = null;
      $max_username_attempts = 10;
      for ($i = 0; $i < $max_username_attempts; $i++) {
        $temp_username = strtolower(explode('@', $email)[0]) . rand(1000, 9999);
        if (!$DB->record_exists('user', ['username' => $temp_username])) {
          $username = $temp_username;
          break;
        }
      }

      if (!$username) {
        throw new Exception("Failed to generate unique username");
      }

      $new_user->password = hash_internal_user_password($plaintext_password);
      $new_user->email = $email;
      $new_user->username = $username;
      $new_user->firstname = $firstname;
      $new_user->lastname = $lastname;
      $new_user->timecreated = time();
      $new_user->timemodified = time();

      // Add optional name fields before inserting
      ensure_optional_name_fields($new_user);

      $new_user->id = $DB->insert_record('user', $new_user);

      // Get the full user record to ensure all properties are available
      $user = $DB->get_record('user', ['id' => $new_user->id]);

      if (!$user) {
        throw new Exception("Failed to retrieve newly created user");
      }

      // Ensure optional fields on retrieved user
      ensure_optional_name_fields($user);

      return $user;

    }, MAX_RETRIES, RETRY_DELAY_MS, "User creation");

  } catch (Exception $e) {
    redirect(
      new moodle_url('/enrol/course_tokens/index.php'),
      'Failed to create user account. Please try again.',
      null,
      \core\output\notification::NOTIFY_ERROR
    );
  }

  // Prepare email details for new users
  $message1html = "
    <html>
    <head>
    <style>
      body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
      .container { max-width: 600px; margin: auto; padding: 20px; }
      .header {
        background-color: #00467f;
        color: white;
        padding: 10px;
        text-align: center;
        border-radius: 5px;
        height: 80px;
        vertical-align: middle;
        line-height: 80px;
      }
      .header img {
        max-width: 200px;
        vertical-align: middle;
        display: inline-block;
      }
      .credentials-box {
        background-color: #f4f4f4;
        padding: 10px;
        border-left: 5px solid #00467f;
        margin: 15px 0;
      }
      .footer { margin-top: 20px; font-size: 0.9em; color: #777; }
    </style>
    </head>
    <body>
      <div class='container'>
        <div class='header'>
          <img src='https://pacificmedicaltraining.com/images/logo-pmt.png?v=3' alt='Pacific Medical Training' style='max-width: 200px; height: auto;'>
        </div>
        <p>Dear {$user->firstname} {$user->lastname},</p>

        <p>Your new account has been created at Pacific Medical Training.</p>
        <p>Here are your login details:</p>

        <blockquote class='credentials-box'>
          <strong>Email:</strong> {$user->email}<br>
          <strong>Password:</strong> {$plaintext_password}
        </blockquote>

        <p>You have the option to access your dashboard in one of two ways:</p>
        <ol>
          <li>Use your email address and password to log in.</li>
          <li>Click the \"Send Magic Link\" button. Check your email for the link to log in. This option does not require a password.</li>
        </ol>

        <p>Please login at <a href='https://learn.pacificmedicaltraining.com/pmt-login'>https://learn.pacificmedicaltraining.com/pmt-login</a></p>

        <p>If you have any concerns, please reply here.</p>

        <p>Thank you.</p>

        <div class='footer'>
          <p>Pacific Medical Training<br>
          <a href='https://pacificmedicaltraining.com'>pacificmedicaltraining.com</a></p>
        </div>
      </div>
    </body>
    </html>
    ";

  // Prepare email subject
  $subject = "Your new account from Pacific Medical Training";

  // Explicitly set the sender details
  $sender = new stdClass();
  $sender->firstname = "Pacific";
  $sender->lastname = "Medical Training";
  $sender->email = "support@pacificmedicaltraining.com";

  // Send the HTML email with retry
  $email_sent = send_html_email($user, $subject, $message1html, $sender);

  if (!$email_sent) {
    error_log("Failed to send welcome email after all retries to new user: {$user->email}");
    \core\notification::add('User created but welcome email could not be sent.', \core\output\notification::NOTIFY_WARNING);
  }

  // Brief delay to ensure email processing
  sleep(1);
}

// Get the current user's ID to store as 'created_by'
$created_by = $USER->id;

// Create tokens with comprehensive retry logic
$tokens = [];
try {
  for ($i = 0; $i < $quantity; $i++) {

    $token_code = retry_operation(function() use ($course_id, $extra_json, $user, $group_account, $created_by) {
      global $DB;

      $token = new stdClass();
      $token->course_id = $course_id;
      $token->extra_json = $extra_json;

      // Get course ID number with inline retry
      $course_id_number = null;
      try {
        $course_id_number = $DB->get_field('course', 'idnumber', ['id' => $course_id]);
      } catch (Exception $e) {
        error_log("Failed to get course idnumber, using course_id instead");
      }

      $tokenPrefix = $course_id_number ? $course_id_number : $course_id;
      $token->code = $tokenPrefix . '-' .
        bin2hex(openssl_random_pseudo_bytes(2)) . '-' .
        bin2hex(openssl_random_pseudo_bytes(2)) . '-' .
        bin2hex(openssl_random_pseudo_bytes(2));

      $token->timecreated = time();
      $token->timemodified = time();
      $token->user_id = $user->id;
      $token->voided = '';
      $token->user_enrolments_id = null;
      $token->group_account = $group_account;
      $token->created_by = $created_by;

      $token->id = $DB->insert_record('course_tokens', $token);

      if (!$token->id) {
        throw new Exception("Failed to insert token record");
      }

      return $token->code;

    }, MAX_RETRIES, RETRY_DELAY_MS, "Token creation (token " . ($i + 1) . "/{$quantity})");

    $tokens[] = $token_code;
  }

} catch (Exception $e) {
  error_log("Critical: Token creation failed after all retries: " . $e->getMessage());
  redirect(
    new moodle_url('/enrol/course_tokens/index.php'),
    "Failed to create tokens after multiple attempts. Created {count($tokens)} of {$quantity} tokens.",
    null,
    \core\output\notification::NOTIFY_ERROR
  );
}

// Determine the correct token wording
$token_word = $quantity === 1 ? 'token' : 'tokens';

// Prepare email details for token creation
$token_url = "https://learn.pacificmedicaltraining.com/my/";

$message2html = "
<html>
<head>
<style>
  body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
  .container { max-width: 600px; margin: auto; padding: 20px; }
  .header {
    background-color: #00467f;
    color: white;
    padding: 10px;
    text-align: center;
    border-radius: 5px;
    height: 80px;
    vertical-align: middle;
    line-height: 80px;
  }
  .header img {
    max-width: 200px;
    vertical-align: middle;
    display: inline-block;
  }
  .footer { margin-top: 20px; font-size: 0.9em; color: #777; }
  .token-box { background-color: #f4f4f4; padding: 10px; border-left: 5px solid #00467f; margin: 15px 0; }
</style>
</head>
<body>
  <div class='container'>
    <div class='header'>
      <img src='https://pacificmedicaltraining.com/images/logo-pmt.webp' alt='Pacific Medical Training' style='max-width: 200px; height: auto;'>
    </div>
    <p>Dear {$user->firstname} {$user->lastname},</p>

    <blockquote class='token-box'>
      You have received {$quantity} {$token_word} for the course <strong>{$course->fullname}</strong>.<br>
      Order Number: #{$order_number}
    </blockquote>

    <p>You can view your tokens at: <a href='{$token_url}'>{$token_url}</a></p>

    <p>Please login at <a href='https://learn.pacificmedicaltraining.com/pmt-login'>https://learn.pacificmedicaltraining.com/pmt-login</a></p>

    <p>Thank you,<br>Pacific Medical Training</p>

    <div class='footer'>
      <p>Pacific Medical Training<br>
      <a href='https://pacificmedicaltraining.com'>pacificmedicaltraining.com</a></p>
    </div>
  </div>
</body>
</html>
";

// Prepare email subject
$subject = "Your course {$token_word} from Pacific Medical Training";

// Explicitly set the sender details
$sender = new stdClass();
$sender->firstname = "Pacific";
$sender->lastname = "Medical Training";
$sender->email = "support@pacificmedicaltraining.com";

// Send the HTML email with retry
$email_sent = send_html_email($user, $subject, $message2html, $sender);

if (!$email_sent) {
  error_log("Failed to send token email after all retries to user: {$user->email}");
  \core\notification::add('Tokens created but notification email could not be sent.', \core\output\notification::NOTIFY_WARNING);
}

// Associate the user with a corporate/group account with retry
if (!empty($group_account)) {
  try {
    retry_operation(function() use ($user, $group_account) {
      global $DB;

      // Find the fieldid for the custom profile field
      $fieldid = $DB->get_field('user_info_field', 'id', ['shortname' => 'customer_group'], MUST_EXIST);

      // Check if an entry already exists
      $existing = $DB->get_record('user_info_data', ['userid' => $user->id, 'fieldid' => $fieldid]);

      if ($existing) {
        $existing->data = $group_account;
        $result = $DB->update_record('user_info_data', $existing);
      } else {
        $record = (object) [
          'userid' => $user->id,
          'fieldid' => $fieldid,
          'data' => $group_account,
          'dataformat' => 0,
        ];
        $result = $DB->insert_record('user_info_data', $record);
      }

      if (!$result) {
        throw new Exception("Failed to update group account");
      }

      return $result;

    }, MAX_RETRIES, RETRY_DELAY_MS, "Group account association");

  } catch (Exception $e) {
    error_log("Failed to associate group account after all retries: " . $e->getMessage());
    \core\notification::add('Tokens created but group account association failed.', \core\output\notification::NOTIFY_WARNING);
  }
}

// Redirect with success message
redirect(
  new moodle_url('/enrol/course_tokens/'),
  get_string('tokenscreated', 'enrol_course_tokens', $quantity),
  null,
  \core\output\notification::NOTIFY_SUCCESS
);
?>
