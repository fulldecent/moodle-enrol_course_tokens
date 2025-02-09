<?php
require_once('../../config.php');
require_login();

$token_id = required_param('token_id', PARAM_INT);

$DB->execute("UPDATE {course_tokens} SET voided = 0, voided_at = NULL, voided_notes = NULL WHERE id = ?", [$token_id]);

redirect(new moodle_url('/enrol/course_tokens/'), 'Token successfully unvoided.', null, \core\output\notification::NOTIFY_SUCCESS);