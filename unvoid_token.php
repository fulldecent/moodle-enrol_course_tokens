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

$token_id = required_param('token_id', PARAM_INT);
$sesskey = required_param('sesskey', PARAM_ALPHANUM);
if (!confirm_sesskey($sesskey)) {
    echo json_encode(['success' => false, 'message' => 'Invalid session key.']);
    exit;
}

try {
    $lock = \enrol_course_tokens\local\lifecycle_service::acquire_token_lock($token_id);
    $token = $DB->get_record('course_tokens', ['id' => $token_id], '*', MUST_EXIST);
    if (\enrol_course_tokens\local\lifecycle_service::is_dropped_out($token)) {
        throw new \moodle_exception('dropoutcannotunvoid', 'enrol_course_tokens');
    }
    $DB->update_record('course_tokens', [
        'id' => $token->id,
        'voided' => 0,
        'voided_at' => null,
        'voided_notes' => null,
        'timemodified' => time(),
    ]);
    $lock->release();
} catch (\Throwable $e) {
    if (!empty($lock)) {
        $lock->release();
    }
    error_log('enrol_course_tokens: unvoid failed for token ' . $token_id . ': ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Token could not be unvoided safely.']);
    exit;
}

// Return JSON response instead of redirecting
echo json_encode(["success" => true, "message" => "Token successfully unvoided."]);
exit; // Ensure no extra output
