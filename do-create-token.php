<?php
require_once('../../config.php');
require_login();
require_capability('moodle/site:config', context_system::instance());

/**
 * Custom function to send HTML emails consistently
 *
 * @param object $user User object with email, firstname, lastname
 * @param string $subject Email subject
 * @param string $html_content HTML content of the email
 * @param object $sender Sender object with email, firstname, lastname
 * @return bool True if email was sent successfully, false otherwise
 */
function send_html_email($user, $subject, $html_content, $sender) {
    global $CFG;

    // Strip HTML tags for plain text version
    $plain_content = strip_tags($html_content);

    try {
        // Send email using Moodle's function
        $result = email_to_user($user, $sender, $subject, $plain_content, $html_content);
        return $result;
    } catch (Exception $e) {
        error_log("Email sending error: " . $e->getMessage());
        return false;
    }
}

// Process, validate form inputs
require_sesskey();
$course_id = required_param('course_id', PARAM_INT);
$email = required_param('email', PARAM_EMAIL);
$extra_json = optional_param('extra_json', '', PARAM_RAW);
$quantity = required_param('quantity', PARAM_INT);
$group_account = optional_param('group_account', '', PARAM_TEXT);
$firstname = required_param('firstname', PARAM_TEXT); // First name from the form
$lastname = required_param('lastname', PARAM_TEXT); // Last name from the form

// Extract order number from extra JSON
$order_number = null;
if (!empty($extra_json)) {
    $extra_data = json_decode($extra_json, true); // Decode as associative array
    if (json_last_error() === JSON_ERROR_NONE && isset($extra_data['order_number'])) {
        $order_number = (int) $extra_data['order_number']; // Extract and ensure it's an integer
    }
}

// Validate course ID
$course = $DB->get_record('course', array('id' => $course_id));
if (empty($course)) {
    redirect(new moodle_url('/enrol/course_tokens/index.php'), get_string('errorcourse', 'enrol_course_tokens'), null, \core\output\notification::NOTIFY_ERROR);
}

// Validate email
if (!validate_email($email)) {
    redirect(new moodle_url('/enrol/course_tokens/index.php'), get_string('erroremail', 'enrol_course_tokens'), null, \core\output\notification::NOTIFY_ERROR);
}

// Validate JSON
if (!empty($extra_json)) {
    $extra_json = json_decode($extra_json);
    if (json_last_error() !== JSON_ERROR_NONE) {
        redirect(new moodle_url('/enrol/course_tokens/index.php'), get_string('errorjson', 'enrol_course_tokens'), null, \core\output\notification::NOTIFY_ERROR);
    }
}

// Validate quantity
if ($quantity < 1) {
    redirect(new moodle_url('/enrol/course_tokens/index.php'), get_string('errorquantity', 'enrol_course_tokens'), null, \core\output\notification::NOTIFY_ERROR);
}

// Check if a user exists with the given email, even if suspended
$existing_user = $DB->get_record('user', ['email' => $email, 'deleted' => 0]);

// If user exists and is suspended, reactivate the account
if ($existing_user && $existing_user->suspended) {
    // Unsuspend the user
    $existing_user->suspended = 0;
    $existing_user->timemodified = time();
    $DB->update_record('user', $existing_user);

    // Log the reactivation
    \core\notification::add('User account has been reactivated.', \core\output\notification::NOTIFY_SUCCESS);
}

// Use the user record (now guaranteed to be active if it exists)
$user = $existing_user ?: null;

// Function to generate a secure password in the format ###-###-###-###
function generate_hex_password() {
    return sprintf('%s-%s-%s-%s',
        substr(bin2hex(random_bytes(2)), 0, 3),
        substr(bin2hex(random_bytes(2)), 0, 3),
        substr(bin2hex(random_bytes(2)), 0, 3),
        substr(bin2hex(random_bytes(2)), 0, 3)
    );
}

if (empty($user)) {
    global $DB, $CFG, $USER;

    // Generate the password
    $plaintext_password = generate_hex_password();

    // Create new user if not found, using the first name and last name passed from the form
    $new_user = new stdClass();
    $new_user->auth = 'manual';
    $new_user->confirmed = 1;
    $new_user->mnethostid = $CFG->mnet_localhost_id; // Ensure mnethostid matches local host ID
    // Generate unique username
    do {
        $username = strtolower(explode('@', $email)[0]) . rand(1000, 9999);
    } while ($DB->record_exists('user', ['username' => $username]));
    $new_user->password = hash_internal_user_password($plaintext_password); // Hash the password for Moodle storage
    $new_user->email = $email;
    $new_user->username = $username;
    $new_user->firstname = $firstname; // Use the firstname from the form
    $new_user->lastname = $lastname;   // Use the lastname from the form
    $new_user->timecreated = time();
    $new_user->timemodified = time();

    // Insert new user record
    $new_user->id = $DB->insert_record('user', $new_user);

    // Get the full user record to ensure all properties are available
    $user = $DB->get_record('user', ['id' => $new_user->id]);

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

    // Send the HTML email
    send_html_email($user, $subject, $message1html, $sender);
}

// Get the current user's ID to store as 'created_by'
$created_by = $USER->id;

// Create tokens
for ($i = 0; $i < $quantity; $i++) {
    $token = new stdClass();
    $token->course_id = $course_id;
    $token->extra_json = empty($extra_json) ? null : json_encode($extra_json);
    $course_id_number = $DB->get_field('course', 'idnumber', array('id' => $course_id));

    $tokenPrefix = $course_id_number ? $course_id_number : $course_id;
    $token->code = $tokenPrefix . '-' . bin2hex(openssl_random_pseudo_bytes(2)) . '-' . bin2hex(openssl_random_pseudo_bytes(2)) . '-' . bin2hex(openssl_random_pseudo_bytes(2));
    $token->timecreated = time();
    $token->timemodified = time();

    // Set additional fields
    $token->user_id = $user->id;
    $token->voided = '';
    $token->user_enrolments_id = null;
    $token->group_account = $group_account; // New field for Corporate Account
    $token->created_by = $created_by; // Store the creator's user ID

    // Insert the token into the database
    $DB->insert_record('course_tokens', $token);
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
    height: 80px; /* Set a fixed height based on your needs */
    vertical-align: middle;
    line-height: 80px; /* Match the height value */
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
      <img src='https://pacificmedicaltraining.com/images/do-not-delete-this-logo.png' alt='Pacific Medical Training' style='max-width: 200px; height: auto;'>
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

// Send the HTML email
send_html_email($user, $subject, $message2html, $sender);

// Associate the user with a corporate/group account
// This updates or creates a custom profile field entry for the user
// to track which corporate account they belong to
if (!empty($group_account)) {
  global $DB;

  // Find the fieldid for the custom profile field "customer_group"
  $fieldid = $DB->get_field('user_info_field', 'id', ['shortname' => 'customer_group'], MUST_EXIST);

  // Check if an entry already exists for this user and field
  $existing = $DB->get_record('user_info_data', ['userid' => $user->id, 'fieldid' => $fieldid]);

  if ($existing) {
      // Update existing data
      $existing->data = $group_account;
      $DB->update_record('user_info_data', $existing);
  } else {
      // Insert new data
      $record = (object)[
          'userid' => $user->id,
          'fieldid' => $fieldid,
          'data' => $group_account,
          'dataformat' => 0,
      ];
      $DB->insert_record('user_info_data', $record);
  }
}

// Redirect with success message
redirect(new moodle_url('/enrol/course_tokens/'), get_string('tokenscreated', 'enrol_course_tokens', $quantity), null, \core\output\notification::NOTIFY_SUCCESS);
?>
