<?php

require_once('../../config.php');
require_login();
require_capability('moodle/site:config', context_system::instance());

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$tokenid = required_param('tokenid', PARAM_INT);
$void_notes = required_param('void_notes', PARAM_TEXT);
$sesskey = required_param('sesskey', PARAM_ALPHANUM);

if (!confirm_sesskey($sesskey)) {
    echo json_encode(['success' => false, 'message' => 'Invalid session key.']);
    exit;
}

if (!\enrol_course_tokens\local\lifecycle_service::void_token($tokenid, $void_notes)) {
    echo json_encode(['success' => false, 'message' => 'Token could not be voided safely.']);
    exit;
}

echo json_encode(['success' => true, 'message' => 'Token voided successfully.']);
exit;
