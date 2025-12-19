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

// Get the token code from URL parameter
$token_code = required_param('token_code', PARAM_TEXT);

// Use sql_compare_text() for text column comparisons to ensure case-insensitive matching
$sql = "SELECT * FROM {course_tokens} WHERE " . $DB->sql_compare_text('code') . " = ? AND user_id = ?";
$params = [$token_code, $USER->id];

// Check if the token exists and is associated with the current user
$token = $DB->get_record_sql($sql, $params);

if (!$token) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid token or token not associated with your account.'
    ]);
    exit();
}

// Prevent use of voided tokens
if (!empty($token->voided)) {
    echo json_encode([
        'status' => 'error',
        'message' => 'This token has been voided and cannot be used.'
    ]);
    exit();
}

// Check if the token has already been used
if (!empty($token->user_enrolments_id)) {
    echo json_encode([
        'status' => 'error',
        'message' => 'This token has already been used.'
    ]);
    exit();
}

// Enroll the user in the course
$course = $DB->get_record('course', ['id' => $token->course_id]);
if (!$course) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Course not found.'
    ]);
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
    echo json_encode([
        'status' => 'error',
        'message' => 'Course token enrolment method not enabled for this course.'
    ]);
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
$is_new_user = false;
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

        // Add optional name fields to prevent notices
        ensure_optional_name_fields($new_user);

        // Insert the new user into the database
        $new_user->id = $DB->insert_record('user', $new_user);
        $enrol_user = $new_user;
        $is_new_user = true;
    } else {
        // Ensure optional fields exist for existing user
        ensure_optional_name_fields($enrol_user);
    }
} else {
    // Use the currently logged-in user if no email is provided
    $enrol_user = clone $USER; // Clone to avoid modifying global $USER
    ensure_optional_name_fields($enrol_user);

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

/**
 * START: AUTOMATIC GROUP ASSIGNMENT FOR AHA COURSES
 * * PURPOSE:
 * As per GitHub Issue #375 (https://github.com/modern-training-solutions/learn.PacificMedicalTraining.com/issues/375), 
 * the business logic for AHA courses (ACLS, BLS, PALS) has changed. 
 * * Previously, students used a 'Group Choice' activity to select between 'Blended', 'Skills Only', or 'Full Class'.
 * To simplify the user experience and standardize the learning path, we are now only offering the 'Full Class' 
 * option. This code removes the need for manual group selection by automatically placing every new 
 * enrollee into the "FULL class" group for these specific courses.
 * * TARGET COURSES: 16 (ACLS), 18 (BLS), 20 (PALS)
 * * @issue: https://github.com/modern-training-solutions/learn.PacificMedicalTraining.com/issues/375
 */

$target_course_ids = [16, 18, 20]; // AHA courses [acls, bls, pals]
$group_name = 'FULL class'; // Add all users to the "FULL class" group by default

if (in_array($course->id, $target_course_ids)) {
    require_once($CFG->dirroot . '/group/lib.php');
    
    // Check if the group exists in this course, if not, create it
    $group_id = groups_get_group_by_name($course->id, $group_name);
    
    if (!$group_id) {
        $groupdata = new stdClass();
        $groupdata->courseid = $course->id;
        $groupdata->name = $group_name;
        $group_id = groups_create_group($groupdata);
    }
    
    // Check if user is already a member (to avoid duplicates)
    if (!groups_is_member($group_id, $enrol_user->id)) {
        groups_add_member($group_id, $enrol_user->id);
    }
}

// Mark the token as used after successful enrolment
$userEnrolment = $DB->get_record('user_enrolments', ['userid' => $enrol_user->id, 'enrolid' => $enrolinstance->id]);
if ($userEnrolment) {
    $token->user_enrolments_id = $userEnrolment->id; // Associate the user enrolment ID with the token
    $token->used_on = time(); // Set the token usage time
    $token->used_by = $enrol_email ?: $USER->email; // Record who used the token (email or current user)
    $DB->update_record('course_tokens', $token); // Update the token record in the database
}

// Notify token owner if someone else is enrolled using their token
if ($USER->id !== $enrol_user->id) {
    $token_owner = $DB->get_record('user', ['id' => $USER->id]);

    if ($token_owner) {
        ensure_optional_name_fields($token_owner);

        $notify_subject = "Your course token has been used";
        $notify_message = "
            Dear {$token_owner->firstname} {$token_owner->lastname},

            Your course token '{$token->code}' was just used to enroll {$enrol_user->firstname} {$enrol_user->lastname} ({$enrol_user->email}) in the course: {$course->fullname}.

            The enrollment was successful. The enrolled user {$enrol_user->firstname} {$enrol_user->lastname} will receive an email shortly with login instructions.

            Thank you,
            PMT Team
        ";

        $from_user = new stdClass();
        $from_user->email = 'support@pacificmedicaltraining.com';
        $from_user->firstname = 'PMT';
        $from_user->lastname = 'instructor';
        $from_user->maildisplay = 1;
        ensure_optional_name_fields($from_user);

        if (!email_to_user($token_owner, $from_user, $notify_subject, $notify_message)) {
            debugging("Failed to send token use notification email to token owner {$token_owner->email}");
        }
    }
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
if ($is_new_user) {
    // New user email with username and default password
    $message = "
        Dear {$enrol_user->firstname} {$enrol_user->lastname},

        Thank you for purchasing the {$course->fullname} course.
        Please log in to your student workroom at this link: https://learn.pacificmedicaltraining.com/login/

        Your username is {$enrol_user->username} and your default password is \"changeme\". You will be asked to change your password on the first login. Once completed, please go to the \"My Course\" tab, and you will see your digital purchase - {$course->fullname}.

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
ensure_optional_name_fields($from_user);

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
