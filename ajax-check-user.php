<?php
define('AJAX_SCRIPT', true);
require_once('../../config.php');
require_sesskey();

$email = required_param('email', PARAM_EMAIL);

// Check if the user exists
$user = $DB->get_record('user', array('email' => $email, 'deleted' => 0, 'suspended' => 0));

$response = new stdClass();
if ($user) {
    $response->exists = true;
    $response->firstname = $user->firstname;
    $response->lastname = $user->lastname;
} else {
    $response->exists = false;
}

echo json_encode($response);
