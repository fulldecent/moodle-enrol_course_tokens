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

// Use sql_compare_text() for text column comparisons
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

// Check if the token is already used
if (!empty($token->user_enrolments_id)) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification('This token has already been used.', 'error');
    echo $OUTPUT->footer();
    exit();
}

// Enroll in the course
$course = $DB->get_record('course', ['id' => $token->course_id]);
if (!$course) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification('Course not found.', 'error');
    echo $OUTPUT->footer();
    exit();
}

$context = context_course::instance($course->id);
$enrolinstances = enrol_get_instances($course->id, true);
$enrolinstance = null;
foreach ($enrolinstances as $instance) {
    if ($instance->enrol === 'manual') {
        $enrolinstance = $instance;
        break;
    }
}

if (!$enrolinstance) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification('Manual enrollment method not enabled for this course.', 'error');
    echo $OUTPUT->footer();
    exit();
}

// Get the form parameters
$enrol_email = optional_param('email', null, PARAM_EMAIL);
$first_name = optional_param('first_name', 'New', PARAM_TEXT);
$last_name = optional_param('last_name', 'User', PARAM_TEXT);

// If email is provided, handle user creation or lookup
if ($enrol_email) {
    $enrol_user = $DB->get_record('user', ['email' => $enrol_email, 'deleted' => 0, 'suspended' => 0]);

    if (!$enrol_user) {
        // Create new user if not found
        $new_user = new stdClass();
        $new_user->auth = 'manual';
        $new_user->confirmed = 1;
        $new_user->mnethostid = $CFG->mnet_localhost_id;
        $new_user->username = strtolower(explode('@', $enrol_email)[0]) . rand(1000, 9999);
        $new_user->password = hash_internal_user_password('changeme');
        $new_user->email = $enrol_email;
        $new_user->firstname = $first_name;
        $new_user->lastname = $last_name;
        $new_user->timecreated = time();
        $new_user->timemodified = time();

        $new_user->id = $DB->insert_record('user', $new_user);
        $enrol_user = $new_user;
    }
} else {
    $enrol_user = $USER; // Use the current logged-in user
}

// Enroll the user in the course
$roleId = 5; // Role ID for student
$enrolPlugin = enrol_get_plugin('manual');
$enrolPlugin->enrol_user($enrolinstance, $enrol_user->id, $roleId); // Throws on error

// Mark token as used and set the timestamp
$userEnrolment = $DB->get_record('user_enrolments', ['userid' => $enrol_user->id, 'enrolid' => $enrolinstance->id]);
if ($userEnrolment) {
    $token->user_enrolments_id = $userEnrolment->id;
    $token->used_on = time(); // Set the current timestamp
    $token->used_by = $enrol_email ?: $USER->email; // Save the email in the used_by field; if $enrol_email is null, use current user's email
    $DB->update_record('course_tokens', $token);
}

// Redirect to the course view page
redirect(new moodle_url('/course/view.php', ['id' => $course->id]));   
?>
