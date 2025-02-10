<?php
require_once('../../config.php');
require_login();

$token_id = required_param('token_id', PARAM_INT);

// Update the database to unvoid the token
$DB->execute("UPDATE {course_tokens} SET voided = 0, voided_at = NULL, voided_notes = NULL WHERE id = ?", [$token_id]);

// Return JSON response instead of redirecting
echo json_encode(["success" => true, "message" => "Token successfully unvoided."]);
exit; // Ensure no extra output
