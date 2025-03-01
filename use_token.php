<?php
require_once('../../config.php');
global $DB, $USER, $PAGE, $OUTPUT;

// Ensure the user is logged in
require_login();

// Set the URL of the page
$PAGE->set_url(new moodle_url('/enrol/course_tokens/use_token.php'));
$PAGE->set_context(context_user::instance($USER->id));
$PAGE->set_title('Use Token');
$PAGE->set_heading('Use Token');

// Get the token code from URL parameter
$token_code = required_param('token_code', PARAM_TEXT);

// Use sql_compare_text() for text column comparisons to ensure case-insensitive matching
$sql = "SELECT * FROM {course_tokens} WHERE " . $DB->sql_compare_text('code') . " = ? AND user_id = ?";
$params = [$token_code, $USER->id];

// Check if the token exists and is associated with the current user
$token = $DB->get_record_sql($sql, $params);

if (!$token) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification('Invalid token or token not associated with your account.', 'error');
    echo $OUTPUT->footer();
    exit();
}

// Prevent use of voided tokens
if (!empty($token->voided)) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification('This token has been voided and cannot be used.', 'error');
    echo $OUTPUT->footer();
    exit();
}

// Check if the token has already been used
if (!empty($token->user_enrolments_id)) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification('This token has already been used.', 'error');
    echo $OUTPUT->footer();
    exit();
}

// Enroll the user in the course
$course = $DB->get_record('course', ['id' => $token->course_id]);
if (!$course) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification('Course not found.', 'error');
    echo $OUTPUT->footer();
    exit();
}

// Set the context for the course
$context = context_course::instance($course->id);

// Get all enrolment instances for the course
$enrolinstances = enrol_get_instances($course->id, true);
$enrolinstance = null;
foreach ($enrolinstances as $instance) {
    if ($instance->enrol === 'course_tokens') {
        $enrolinstance = $instance;
        break;
    }
}

// Check if the course supports the course_tokens enrolment method
if (!$enrolinstance) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification('Course token enrolment method not enabled for this course.', 'error');
    echo $OUTPUT->footer();
    exit();
}

// Get form parameters for user enrollment (email, first name, last name)
$enrol_email = optional_param('email', null, PARAM_EMAIL);

// Check if the provided email address is already enrolled in the course
if ($enrol_email) {
    $enrolled_user = $DB->get_record_sql(
        "SELECT ue.id 
         FROM {user_enrolments} ue
         JOIN {enrol} e ON ue.enrolid = e.id
         WHERE e.courseid = ? AND ue.userid = (SELECT id FROM {user} WHERE email = ? AND deleted = 0 AND suspended = 0)",
        [$course->id, $enrol_email]
    );

    if ($enrolled_user) {
        // Return the error instead of displaying it
        echo json_encode([
            'status' => 'error',
            'message' => 'This user was previously already enrolled in this course. This token is not being spent.'
        ]);
        exit();
    }
}
$first_name = optional_param('first_name', 'New', PARAM_TEXT);
$last_name = optional_param('last_name', 'User', PARAM_TEXT);

// If an email is provided, either lookup an existing user or create a new one
if ($enrol_email) {
    $enrol_user = $DB->get_record('user', ['email' => $enrol_email, 'deleted' => 0, 'suspended' => 0]);

    if (!$enrol_user) {
        // Create a new user if none found with the provided email
        $new_user = new stdClass();
        $new_user->auth = 'manual'; // Authentication method set to 'manual' for simplicity
        $new_user->confirmed = 1; // Confirm the user is active
        $new_user->mnethostid = $CFG->mnet_localhost_id; // Moodle network ID
        $new_user->username = strtolower(explode('@', $enrol_email)[0]) . rand(1000, 9999); // Generate a unique username
        $new_user->password = hash_internal_user_password('changeme'); // Set a default password
        $new_user->email = $enrol_email;
        $new_user->firstname = $first_name;
        $new_user->lastname = $last_name;
        $new_user->timecreated = time(); // Set creation time
        $new_user->timemodified = time(); // Set modification time
    
        // Insert the new user into the database
        $new_user->id = $DB->insert_record('user', $new_user);
        $enrol_user = $new_user;
    }
} else {
    // Use the currently logged-in user if no email is provided
    $enrol_user = $USER;

    // Check if the currently logged-in user is already enrolled in the course
    $enrolled_user = $DB->get_record_sql(
        "SELECT ue.id 
        FROM {user_enrolments} ue
        JOIN {enrol} e ON ue.enrolid = e.id
        WHERE e.courseid = ? AND ue.userid = ?",
        [$course->id, $enrol_user->id]
    );

    if ($enrolled_user) {
        // Return JSON error response instead of HTML/JavaScript
        echo json_encode([
            'status' => 'error',
            'message' => 'You are previously already enrolled in this course. This token is not being spent.'
        ]);
        exit();
    }
}

// Enroll the user into the course using the 'student' role
$roleId = $DB->get_record('role', ['shortname' => 'student'])->id; // Get the student role ID
$enrolPlugin = enrol_get_plugin('course_tokens'); // Get the course_tokens enrolment plugin
$enrolPlugin->enrol_user($enrolinstance, $enrol_user->id, $roleId); // Enroll the user

// Mark the token as used after successful enrolment
$userEnrolment = $DB->get_record('user_enrolments', ['userid' => $enrol_user->id, 'enrolid' => $enrolinstance->id]);
if ($userEnrolment) {
    $token->user_enrolments_id = $userEnrolment->id; // Associate the user enrolment ID with the token
    $token->used_on = time(); // Set the token usage time
    $token->used_by = $enrol_email ?: $USER->email; // Record who used the token (email or current user)
    $DB->update_record('course_tokens', $token); // Update the token record in the database
}

// Get phone number and address from the POST request
$phone_number = isset($_REQUEST['phone_number']) ? optional_param('phone_number', '', PARAM_TEXT) : null;
$address = isset($_REQUEST['address']) ? optional_param('address', '', PARAM_TEXT) : null;

// Check if phone number or address is provided
if ($phone_number || $address) {
    // Prepare data for update
    $data = new stdClass();
    $data->id = $enrol_user->id; // User ID from the logged-in session

    if ($phone_number) {
        $data->phone1 = $phone_number;
    }
    if ($address) {
        $data->address = $address;
    }

    // Update the user's record in the mdl_user table
    $DB->update_record('user', $data);
}

// Send the appropriate email based on whether the user is new or existing
$subject = "Welcome to the {$course->fullname} Course";
if (isset($new_user)) {
    // New user email with username and default password
    $message = "
        Dear {$new_user->firstname} {$new_user->lastname},

        Thank you for purchasing the {$course->fullname} course.
        Please log in to your student workroom at this link: https://learn.pacificmedicaltraining.com/login/

        Your username is {$new_user->username} and your default password is \"changeme\". You will be asked to change your password on the first login. Once completed, please go to the \"My Course\" tab, and you will see your digital purchase - {$course->fullname}.

        Thank you.
    ";
} else {
    // Existing user email
    $message = "
        Dear {$enrol_user->firstname} {$enrol_user->lastname},

        Welcome back! You have been successfully enrolled in the {$course->fullname} course.
        Please visit your student workroom at: https://learn.pacificmedicaltraining.com/login/
        
        We are excited to have you in the course.

        Thank you.
    ";
}

// Create a custom 'from' user object for the sender
$from_user = new stdClass();
$from_user->email = 'support@pacificmedicaltraining.com'; // Set the sender's email address
$from_user->firstname = 'PMT';
$from_user->lastname = 'instructor';
$from_user->maildisplay = 1; // Optional: Ensure the email address is visible

// Send the email
if (!email_to_user($enrol_user, $from_user, $subject, $message)) {
    debugging("Failed to send email to user {$enrol_user->email}");
}

if ($enrol_user->email === $USER->email) {
    // Return JSON response instead of redirecting
    echo json_encode([
        'status' => 'redirect',
        'redirect_url' => (new moodle_url('/course/view.php', ['id' => $course->id]))->out(false),
        'message' => 'You have been successfully enrolled in the course.'
    ]);
    exit();
} else {
    // Return a success response in JSON format
    echo json_encode([
        'status' => 'success',
        'message' => 'User successfully enrolled in the course.'
    ]);
    exit();
}
?>
