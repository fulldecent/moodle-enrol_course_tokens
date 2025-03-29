<?php
require_once('../../config.php');
require_login();
require_capability('moodle/site:config', context_system::instance());

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

// Check if the user exists or create a new user
$user = $DB->get_record('user', array('email' => $email, 'deleted' => 0, 'suspended' => 0));

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

// Redirect with success message
redirect(new moodle_url('/enrol/course_tokens/'), get_string('tokenscreated', 'enrol_course_tokens', $quantity), null, \core\output\notification::NOTIFY_SUCCESS);
?>
