<?php
require_once('../../config.php');
require_login();
require_capability('moodle/site:config', context_system::instance());

$email = required_param('email', PARAM_EMAIL);
$token = required_param('token', PARAM_TEXT);

$context = context_system::instance();
require_sesskey();

$user = $DB->get_record('user', ['email' => $email, 'deleted' => 0, 'suspended' => 0]);
if (!$user) {
    echo json_encode(['success' => false, 'error' => 'User not found.']);
    exit;
}

// Prepare email details
$message = "
Dear {$user->firstname} {$user->lastname},

Your new account has been created at Pacific Medical Training. 
Here are your login details:

Username: {$user->username}
Password: changeme  (You will be prompted to change this on first login)
Token: {$token}

Please login at https://learn.pacificmedicaltraining.com/login/index.php.

Thank you.
";

$subject = "Resent: Your account from Pacific Medical Training";
$sender = new stdClass();
$sender->firstname = "PMT";
$sender->lastname = "Instructor";
$sender->email = "support@pacificmedicaltraining.com";

if (email_to_user($user, $sender, $subject, $message)) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Failed to send email.']);
}
