<?php

require_once('../../config.php');
require_login();
require_capability('moodle/site:config', context_system::instance());

$tokenid = required_param('tokenid', PARAM_INT);
$void_notes = required_param('void_notes', PARAM_TEXT);
$sesskey = required_param('sesskey', PARAM_ALPHANUM);

if (!confirm_sesskey($sesskey)) {
    echo json_encode(['success' => false, 'message' => 'Invalid session key.']);
    exit;
}

// Get current timestamp
$voided_at = time();

// Get token details
$token = $DB->get_record('course_tokens', ['id' => $tokenid], '*', MUST_EXIST);

if ($token->used_on) { // If token is used, unenroll the user
    require_once(__DIR__ . '/unenroll.php');

    if (!unenroll_user_by_token($tokenid)) {
        echo json_encode(['success' => false, 'message' => 'Failed to unenroll the user.']);
        exit;
    }
}

// Update the token as voided
$DB->update_record('course_tokens', [
    'id' => $tokenid,
    'voided' => 1,
    'voided_at' => $voided_at,
    'voided_notes' => $void_notes
]);

echo json_encode(['success' => true, 'message' => 'Token voided successfully.']);
exit;
