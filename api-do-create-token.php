<?php
require_once('/var/www/vhosts/moodle/config.php'); // Actual path to Moodle's config.php

// Set PAGE context early to avoid Moodle warnings
global $PAGE;
$PAGE->set_context(context_system::instance());

// Buffer all output to prevent any premature HTML
ob_start();

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
 * @param string $sender_email Sender email address
 * @param string $sender_firstname Sender first name
 * @param string $sender_lastname Sender last name
 * @return bool True if email was sent successfully, false otherwise
 */
function send_html_email(
  $user,
  $subject,
  $html_content,
  $sender_email = 'support@pacificmedicaltraining.com',
  $sender_firstname = 'Pacific',
  $sender_lastname = 'Medical Training'
) {
  global $CFG;

  // Ensure user has all required name fields
  ensure_optional_name_fields($user);

  // Wrap email sending in retry logic
  try {
    return retry_operation(function() use ($user, $subject, $html_content, $sender_email, $sender_firstname, $sender_lastname, $CFG) {

      // Create sender object
      $sender = new stdClass();
      $sender->firstname = $sender_firstname;
      $sender->lastname = $sender_lastname;
      $sender->email = $sender_email;
      ensure_optional_name_fields($sender);

      // Strip HTML tags for plain text version
      $plain_content = strip_tags($html_content);

      // Try PHP's native mail function first if configured
      if (!empty($CFG->noreplyaddress) && $CFG->noreplyaddress !== 'support@pacificmedicaltraining.com') {
        $to = $user->email;
        $headers = "From: $sender_firstname $sender_lastname <$sender_email>\r\n";
        $headers .= "Reply-To: $sender_email\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";

        // Attempt direct mail sending
        $mail_result = mail($to, $subject, $html_content, $headers);

        // If mail sent successfully with direct method, return true
        if ($mail_result) {
          return true;
        }
      }

      // Fallback to Moodle's email function
      ob_start();
      $result = email_to_user($user, $sender, $subject, $plain_content, $html_content);
      ob_end_clean();

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

// Clear any buffered output before setting headers
ob_end_clean();
ob_start();

// Set the content type to JSON
header('Content-Type: application/json');

// Ensure the request method is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405); // Method Not Allowed
  echo json_encode(['error' => 'Invalid request method. Use POST.']);
  exit;
}

// Read and decode the JSON input
$json_input = file_get_contents('php://input');

// Only validate JSON if there's actual input
if (!empty($json_input)) {
  $data = json_decode($json_input, true);

  // Validate JSON decoding
  if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400); // Bad Request
    echo json_encode(['error' => 'Invalid JSON input.']);
    exit;
  }
} else {
  $data = [];
}

// Retrieve secret key from Moodle's configuration with retry
try {
  $valid_secret_key = retry_operation(function() {
    $key = get_config('course_tokens', 'secretkey');
    if (empty($key)) {
      throw new Exception("Secret key not found in config");
    }
    return $key;
  }, MAX_RETRIES, RETRY_DELAY_MS, "Secret key retrieval");
} catch (Exception $e) {
  http_response_code(500);
  echo json_encode(['error' => 'Configuration error. Please try again later.']);
  exit;
}

$provided_secret_key = isset($data['secret_key']) ? $data['secret_key'] : null;

// Check if secret keys match
if (empty($valid_secret_key) || !isset($data['secret_key']) || $data['secret_key'] !== $valid_secret_key) {
  http_response_code(401); // Unauthorized
  echo json_encode(['error' => 'Unauthorized access. Invalid secret key.']);
  exit;
}

// Validate required parameters
$required_fields = ['course_id', 'email', 'quantity', 'firstname', 'lastname'];
foreach ($required_fields as $field) {
  if (empty($data[$field])) {
    http_response_code(400); // Bad Request
    echo json_encode(['error' => "Missing required field: $field"]);
    exit;
  }
}

// Extract parameters
$course_id = (int) $data['course_id'];
$email = trim($data['email']);
$extra_json = isset($data['extra_json']) ? json_encode($data['extra_json']) : null;
$quantity = (int) $data['quantity'];
$group_account = isset($data['group_account']) ? trim($data['group_account']) : '';
$firstname = trim($data['firstname']);
$lastname = trim($data['lastname']);

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
    $course = $DB->get_record('course', ['id' => $course_id]);
    if (empty($course)) {
      throw new Exception("Course not found with ID: {$course_id}");
    }
    return $course;
  }, MAX_RETRIES, RETRY_DELAY_MS, "Course validation");
} catch (Exception $e) {
  http_response_code(400);
  echo json_encode(['error' => 'Invalid course ID.']);
  exit;
}

// Validate email
if (!validate_email($email)) {
  http_response_code(400); // Bad Request
  echo json_encode(['error' => 'Invalid email address.']);
  exit;
}

// Validate quantity
if ($quantity < 1) {
  http_response_code(400); // Bad Request
  echo json_encode(['error' => 'Quantity must be at least 1.']);
  exit;
}

// Check if the user exists with retry logic
try {
  $user = retry_operation(function() use ($email) {
    global $DB;
    $user = $DB->get_record('user', ['email' => $email, 'deleted' => 0]);
    if ($user) {
      ensure_optional_name_fields($user);
    }
    return $user;
  }, MAX_RETRIES, RETRY_DELAY_MS, "User lookup");
} catch (Exception $e) {
  http_response_code(500);
  echo json_encode(['error' => 'Database error during user lookup.']);
  exit;
}

// Unsuspend user if suspended with retry
if ($user && $user->suspended) {
  try {
    retry_operation(function() use ($user) {
      global $DB;
      $user->suspended = 0;
      $user->timemodified = time();
      $DB->update_record('user', $user);
    }, MAX_RETRIES, RETRY_DELAY_MS, "User unsuspension");
  } catch (Exception $e) {
    error_log("Failed to unsuspend user {$email}: " . $e->getMessage());
    // Continue anyway - user exists but may still be suspended
  }
}

/**
 * Generate a secure password in the format ###-###-###-###
 */
function generate_hex_password() {
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
      $new_user->username = $username;
      $new_user->email = $email;
      $new_user->firstname = $firstname;
      $new_user->lastname = $lastname;
      $new_user->timecreated = time();
      $new_user->timemodified = time();

      // Add optional name fields before inserting
      ensure_optional_name_fields($new_user);

      $new_user->id = $DB->insert_record('user', $new_user);

      // Retrieve full user record
      $user = $DB->get_record('user', ['id' => $new_user->id]);

      if (!$user) {
        throw new Exception("Failed to retrieve newly created user");
      }

      // Ensure optional fields on retrieved user
      ensure_optional_name_fields($user);

      return $user;

    }, MAX_RETRIES, RETRY_DELAY_MS, "User creation");

  } catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to create user account: ' . $e->getMessage()]);
    exit;
  }

  // Prepare HTML email for new users
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

  // Send the new user welcome email with retry
  $subject = "Your new account from Pacific Medical Training";
  $email_sent = send_html_email($user, $subject, $message1html);

  if (!$email_sent) {
    error_log("Failed to send welcome email to new user after all retries: {$user->email}");
  }

  // Brief delay to ensure email processing
  sleep(1);
}

// Get the current user's ID to store as 'created_by'
$created_by = isset($USER->id) ? $USER->id : 364; // Default to Robot user

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
      $token->created_by = 364; // Robot user ID.

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
  http_response_code(500);
  echo json_encode([
    'error' => 'Failed to create tokens after multiple attempts.',
    'tokens_created' => count($tokens),
    'tokens_requested' => $quantity
  ]);
  exit;
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
      <img src='https://pacificmedicaltraining.com/images/moodle-email-logo-required.png' alt='Pacific Medical Training' style='max-width: 200px; height: auto;'>
    </div>
    <p>Dear {$user->firstname} {$user->lastname},</p>

    <blockquote class='token-box'>
      You have received {$quantity} {$token_word} for the course <strong>{$course->fullname}</strong>.<br>
      Order Number: #{$order_number}
    </blockquote>

    <p>You can view your tokens at: <a href='{$token_url}'>{$token_url}</a></p>

    <p>Thank you,<br>Pacific Medical Training</p>

    <div class='footer'>
      <p>Pacific Medical Training<br>
      <a href='https://pacificmedicaltraining.com'>pacificmedicaltraining.com</a></p>
    </div>
  </div>
</body>
</html>
";

// Send the token email with retry
$subject = "{$course->fullname}: course {$token_word}";
$email_sent = send_html_email($user, $subject, $message2html);

if (!$email_sent) {
  error_log("Failed to send token email after all retries to user: {$user->email}");
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
    // Don't fail the entire request for this
  }
}

// Clean the output buffer
ob_end_clean();

// Return success response
http_response_code(200);
// Return success response with created tokens
echo json_encode([
  'success' => true,
  'message' => 'Tokens created successfully.',
  'tokens' => $tokens,
  'email_sent' => $email_sent
]);
?>
