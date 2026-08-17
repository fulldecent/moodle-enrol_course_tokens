<?php
require_once('../../config.php');
require_login();
require_capability('moodle/site:config', context_system::instance());

// Set JSON header
header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$token_id = required_param('token_id', PARAM_INT);
$sesskey = required_param('sesskey', PARAM_ALPHANUM);

if (!confirm_sesskey($sesskey)) {
    echo json_encode(['success' => false, 'message' => 'Invalid session key']);
    exit;
}

// The service re-reads and validates all mutable state under both lifecycle locks.
if (\enrol_course_tokens\local\lifecycle_service::refund_and_unenrol($token_id)) {
    echo json_encode(['success' => true, 'message' => 'User unenrolled successfully']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to unenroll user']);
}
exit;
