<?php
require_once('/var/www/vhosts/moodle/config.php'); // Actual path to Moodle's config.php

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
    $user = $new_user;

    // Prepare email details for new users
    $message1 = "
    Dear {$user->firstname} {$user->lastname},

    Your new account has been created at Pacific Medical Training. 
    Here are your login details:

    Email: {$user->email}
    Password: {$plaintext_password}

    You have the option to access your dashboard in one of two ways:
        1. Use your email address and password to log in.
        2. Click the \"Send Magic Link\" button. Check your email for the link to log in. This option does not require a password.

    Please login at https://learn.pacificmedicaltraining.com/pmt-login

    If you have any concerns, please don't hesitate to contact us at support@pacificmedicaltraining.com

    Thank you.
    ";

    // Prepare email subject
    $subject = "Your new account from Pacific Medical Training";

    // Explicitly set the sender details
    $sender = new stdClass();
    $sender->firstname = "PMT";
    $sender->lastname = "Instructor";
    $sender->email = "support@pacificmedicaltraining.com";

    // Send the email
    email_to_user($user, $sender, $subject, $message1);
}

// Get the current user's ID to store as 'created_by'
$created_by = $USER->id;

// Create tokens
$tokens = [];
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

// Determine the correct token wording
$token_word = $quantity === 1 ? 'token' : 'tokens';

// Prepare email details for token creation
$token_url = "https://learn.pacificmedicaltraining.com/my/";

$message2 = "
    Dear {$user->firstname} {$user->lastname},

    You have received {$quantity} {$token_word} for the course {$course->fullname}. 
    You can view your tokens at: {$token_url}.

    Order Number: #{$order_number}.

    Please login at https://learn.pacificmedicaltraining.com/pmt-login
    
    Thank you.
";

// Prepare email subject
$subject = "Your course {$token_word} from Pacific Medical Training";

// Explicitly set the sender details
$sender = new stdClass();
$sender->firstname = "PMT";
$sender->lastname = "Instructor";
$sender->email = "support@pacificmedicaltraining.com";

// Send the email
email_to_user($user, $sender, $subject, $message2);

// Return success response
http_response_code(200);
// Return success response with created tokens
echo json_encode([
    'success' => true,
    'message' => 'Tokens created successfully.',
    'tokens' => $tokens,
]);
