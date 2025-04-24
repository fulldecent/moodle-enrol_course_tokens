<?php
require_once('/var/www/vhosts/moodle/config.php'); // Actual path to Moodle's config.php

// Buffer all output to prevent any premature HTML
ob_start();

/**
 * Custom function to send HTML emails consistently
 * 
 * @param object $user User object with email, firstname, lastname
 * @param string $subject Email subject
 * @param string $html_content HTML content of the email 
 * @param string $sender_email Sender email address
 * @param string $sender_firstname Sender first name
 * @param string $sender_lastname Sender last name
 * @return bool True if email was sent successfully, false otherwise
 */
function send_html_email($user, $subject, $html_content, $sender_email = 'support@pacificmedicaltraining.com', 
                          $sender_firstname = 'Pacific', $sender_lastname = 'Medical Training') {
    global $CFG;
    
    // Create sender object
    $sender = new stdClass();
    $sender->firstname = $sender_firstname;
    $sender->lastname = $sender_lastname;
    $sender->email = $sender_email;
    
    // Strip HTML tags for plain text version
    $plain_content = strip_tags($html_content);
    
    // Use PHP's native mail function as a fallback if configured
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
    
    // Fallback to Moodle's email function - FIX: Don't pass headers as array
    try {
        // Capture output to avoid any interference
        ob_start();
        $result = email_to_user($user, $sender, $subject, $plain_content, $html_content);
        ob_end_clean();
        
        return $result;
    } catch (Exception $e) {
        error_log("Email sending error: " . $e->getMessage());
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

// Retrieve secret key from Moodle's configuration
$valid_secret_key = get_config('course_tokens', 'secretkey');
$provided_secret_key = isset($data['secret_key']) ? $data['secret_key'] : null;
$secret_key_match = ($valid_secret_key === $provided_secret_key);

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
$course_id = (int)$data['course_id'];
$email = trim($data['email']);
$extra_json = isset($data['extra_json']) ? json_encode($data['extra_json']) : null;
$quantity = (int)$data['quantity'];
$group_account = isset($data['group_account']) ? trim($data['group_account']) : '';
$firstname = trim($data['firstname']);
$lastname = trim($data['lastname']);

// Extract order number from extra JSON
$order_number = null;
if (!empty($extra_json)) {
    $extra_data = json_decode($extra_json, true); // Decode as associative array
    if (json_last_error() === JSON_ERROR_NONE && isset($extra_data['order_number'])) {
        $order_number = (int) $extra_data['order_number']; // Extract and ensure it's an integer
    }
}

// Validate course ID
$course = $DB->get_record('course', ['id' => $course_id]);
if (empty($course)) {
    http_response_code(400); // Bad Request
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

// Check if the user exists, even if suspended
$user = $DB->get_record('user', ['email' => $email, 'deleted' => 0]);

if ($user && $user->suspended) {
    // Unsuspend the user
    $user->suspended = 0;
    $user->timemodified = time();
    $DB->update_record('user', $user);
}

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
    $new_user->mnethostid = $CFG->mnet_localhost_id;
    // Generate unique username
    do {
        $username = strtolower(explode('@', $email)[0]) . rand(1000, 9999);
    } while ($DB->record_exists('user', ['username' => $username]));
    $new_user->password = hash_internal_user_password($plaintext_password); // Hash the password for Moodle storage
    $new_user->username = $username;
    $new_user->email = $email;
    $new_user->firstname = $firstname;
    $new_user->lastname = $lastname;
    $new_user->timecreated = time();
    $new_user->timemodified = time();

    // Insert new user record
    $new_user->id = $DB->insert_record('user', $new_user);
    
    // FIX: Retrieve full user record to ensure all properties are available
    $user = $DB->get_record('user', ['id' => $new_user->id]);

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

    // Send the new user welcome email
    $subject = "Your new account from Pacific Medical Training";
    $email_sent = send_html_email($user, $subject, $message1html);
    
    // Wait to ensure email is sent before proceeding
    sleep(1);
    
    // Log if email sending failed
    if (!$email_sent) {
        error_log("Failed to send welcome email to new user: {$user->email}");
    }
}

// Get the current user's ID to store as 'created_by'
$created_by = isset($USER->id) ? $USER->id : 364; // Default to Robot user if $USER is not set

// Create tokens
$tokens = [];
try {
    for ($i = 0; $i < $quantity; $i++) {
        $token = new stdClass();
        $token->course_id = $course_id;
        $token->extra_json = $extra_json;
        $course_id_number = $DB->get_field('course', 'idnumber', ['id' => $course_id]);

        $tokenPrefix = $course_id_number ? $course_id_number : $course_id;
        $token->code = $tokenPrefix . '-' . bin2hex(openssl_random_pseudo_bytes(2)) . '-' . bin2hex(openssl_random_pseudo_bytes(2)) . '-' . bin2hex(openssl_random_pseudo_bytes(2));
        $token->timecreated = time();
        $token->timemodified = time();
        $token->user_id = $user->id;
        $token->voided = '';
        $token->user_enrolments_id = null;
        $token->group_account = $group_account;
        $token->created_by = 364; // id of Robot user.

        $token->id = $DB->insert_record('course_tokens', $token);
        $tokens[] = $token->code;
    }
} catch (Exception $e) {
    // Log and handle token creation errors
    error_log("Token creation error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to create tokens: ' . $e->getMessage()]);
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
      <img src='https://pacificmedicaltraining.com/images/logo-pmt.png?v=3' alt='Pacific Medical Training' style='max-width: 200px; height: auto;'>
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

// Send the token email
$subject = "{$course->fullname}: course {$token_word}";
$email_sent = send_html_email($user, $subject, $message2html);

// Log if email sending failed
if (!$email_sent) {
    error_log("Failed to send token email to user: {$user->email}");
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
]);
