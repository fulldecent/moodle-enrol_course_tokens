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

// Get token details
$token = $DB->get_record('course_tokens', ['id' => $tokenid], '*', MUST_EXIST);

// Update the token as voided
$DB->update_record('course_tokens', [
    'id' => $tokenid,
    'voided' => 1,
    'voided_at' => time(),
    'voided_notes' => $void_notes
]);

echo json_encode(['success' => true, 'message' => 'Token voided successfully.']);
exit;
