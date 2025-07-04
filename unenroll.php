<?php
require_once('../../config.php');
require_login();
require_capability('moodle/site:config', context_system::instance());

// Set JSON header
header('Content-Type: application/json');

$token_id = required_param('token_id', PARAM_INT);
$sesskey = required_param('sesskey', PARAM_ALPHANUM);

if (!confirm_sesskey($sesskey)) {
    echo json_encode(['success' => false, 'message' => 'Invalid session key']);
    exit;
}

// Call the unenroll function
if (unenroll_user_by_token($token_id)) {
    echo json_encode(['success' => true, 'message' => 'User unenrolled successfully']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to unenroll user']);
}
exit;

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

    // Update token: Remove enrolment reference but keep user_id
    $DB->update_record('course_tokens', [
        'id' => $token_id,
        'user_enrolments_id' => null, // Remove enrolment link
        'used_on' => null, // Reset usage date
    ]);

    return true;
}
exit;