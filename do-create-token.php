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
$group_account = optional_param('group_account', '', PARAM_TEXT); // New field for Corporate Account
$firstname = required_param('firstname', PARAM_TEXT); // First name from the form
$lastname = required_param('lastname', PARAM_TEXT); // Last name from the form

// Validate course ID
$course = $DB->get_record('course', array('id' => $course_id));
if (empty($course)) {
    redirect(new moodle_url('/local/enrollment_tokens/index.php'), get_string('errorcourse', 'local_enrollment_tokens'), null, \core\output\notification::NOTIFY_ERROR);
}

// Validate email
if (!validate_email($email)) {
    redirect(new moodle_url('/local/enrollment_tokens/index.php'), get_string('erroremail', 'local_enrollment_tokens'), null, \core\output\notification::NOTIFY_ERROR);
}

// Validate JSON
if (!empty($extra_json)) {
    $extra_json = json_decode($extra_json);
    if (json_last_error() !== JSON_ERROR_NONE) {
        redirect(new moodle_url('/local/enrollment_tokens/index.php'), get_string('errorjson', 'local_enrollment_tokens'), null, \core\output\notification::NOTIFY_ERROR);
    }
}

// Validate quantity
if ($quantity < 1) {
    redirect(new moodle_url('/local/enrollment_tokens/index.php'), get_string('errorquantity', 'local_enrollment_tokens'), null, \core\output\notification::NOTIFY_ERROR);
}

// Check if the user exists or create a new user
$user = $DB->get_record('user', array('email' => $email, 'deleted' => 0, 'suspended' => 0));
if (empty($user)) {
    // Create new user if not found, using the first name and last name passed from the form
    $new_user = new stdClass();
    $new_user->auth = 'manual';
    $new_user->confirmed = 1;
    $new_user->mnethostid = $CFG->mnet_localhost_id; // Ensure mnethostid matches local host ID
    $new_user->username = strtolower(explode('@', $email)[0]) . rand(1000, 9999); // Generate unique username
    $new_user->password = hash_internal_user_password('changeme');
    $new_user->email = $email;
    $new_user->firstname = $firstname; // Use the firstname from the form
    $new_user->lastname = $lastname;   // Use the lastname from the form
    $new_user->timecreated = time();
    $new_user->timemodified = time();
    $new_user->forcepasswordchange = 1; // Force password change on first login

    // Insert new user record
    $new_user->id = $DB->insert_record('user', $new_user);
    $user = $new_user;
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
    $DB->insert_record('enrollment_tokens', $token);
}

redirect(new moodle_url('/local/enrollment_tokens/index.php'), get_string('tokenscreated', 'local_enrollment_tokens', $quantity), null, \core\output\notification::NOTIFY_SUCCESS);
