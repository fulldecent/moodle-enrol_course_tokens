<?php
require_once('../../config.php');
require_login();
require_capability('moodle/site:config', context_system::instance());

$token_id = required_param('token_id', PARAM_INT);
$sesskey = required_param('sesskey', PARAM_ALPHANUM);

if (!confirm_sesskey($sesskey)) {
    print_error('invalidsesskey');
}

// Call the unenroll function
if (unenroll_user_by_token($token_id)) {
    redirect(new moodle_url('/enrol/course_tokens/index.php'), 'User unenrolled successfully.', null, \core\output\notification::NOTIFY_SUCCESS);
} else {
    redirect(new moodle_url('/enrol/course_tokens/index.php'), 'Failed to unenroll user.', null, \core\output\notification::NOTIFY_ERROR);
}

function unenroll_user_by_token($token_id) {
    global $DB;

    // Get token details
    $token = $DB->get_record('course_tokens', ['id' => $token_id], '*', MUST_EXIST);
    if (!$token || empty($token->user_enrolments_id)) {
        return false; // No enrollment found
    }

    // Get enrollment details
    $enrolment = $DB->get_record('user_enrolments', ['id' => $token->user_enrolments_id], '*', MUST_EXIST);
    if (!$enrolment) {
        return false;
    }

    // Get enrol instance
    $enrol = $DB->get_record('enrol', ['id' => $enrolment->enrolid], '*', MUST_EXIST);
    if (!$enrol) {
        return false;
    }

    // Get enrolment plugin
    $enrol_plugin = enrol_get_plugin($enrol->enrol);
    if (!$enrol_plugin) {
        return false;
    }

    // Unenroll user
    $enrol_plugin->unenrol_user($enrol, $enrolment->userid);

    // Update token: Remove enrolment and used_by but **keep user_id**
    $DB->update_record('course_tokens', [
        'id' => $token_id,
        'user_enrolments_id' => null, // Remove enrolment link
        'used_by' => null, // Remove used_by reference
        'used_on' => null, // Reset usage date
    ]);

    return true;
}
