<?php
// Buffer ALL output immediately — catches Moodle debug notices, PHP warnings,
// and any HTML Moodle injects during bootstrap before we can stop it.
ob_start();

require_once('../../config.php');
global $DB, $USER, $PAGE, $OUTPUT;

require_login();

$PAGE->set_url(new moodle_url('/enrol/course_tokens/use_token.php'));
$PAGE->set_context(context_user::instance($USER->id));
$PAGE->set_title('Use Token');
$PAGE->set_heading('Use Token');

/**
 * Discard any buffered output, set JSON header, emit payload, and exit.
 * Called for EVERY response so no PHP/Moodle noise can corrupt the JSON.
 */
function json_response(array $payload): void {
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit();
}

/**
 * Ensure optional name fields exist to avoid debug notices.
 */
function ensure_optional_name_fields(&$user): void {
    foreach (['firstnamephonetic', 'lastnamephonetic', 'middlename', 'alternatename'] as $field) {
        if (!property_exists($user, $field)) {
            $user->$field = '';
        }
    }
}

// ---------------------------------------------------------------------------
// VALIDATE TOKEN
// ---------------------------------------------------------------------------
$token_code = required_param('token_code', PARAM_TEXT);

$token = $DB->get_record_sql(
    "SELECT * FROM {course_tokens} WHERE " . $DB->sql_compare_text('code') . " = ? AND user_id = ?",
    [$token_code, $USER->id]
);

if (!$token) {
    json_response(['status' => 'error', 'message' => 'Invalid token or token not associated with your account.']);
}
if (!empty($token->voided)) {
    json_response(['status' => 'error', 'message' => 'This token has been voided and cannot be used.']);
}
if (!empty($token->user_enrolments_id)) {
    json_response(['status' => 'error', 'message' => 'This token has already been used.']);
}

// ---------------------------------------------------------------------------
// VALIDATE COURSE & ENROLMENT INSTANCE
// ---------------------------------------------------------------------------
$course = $DB->get_record('course', ['id' => $token->course_id]);
if (!$course) {
    json_response(['status' => 'error', 'message' => 'Course not found.']);
}

$enrolinstance = null;
foreach (enrol_get_instances($course->id, true) as $instance) {
    if ($instance->enrol === 'course_tokens') {
        $enrolinstance = $instance;
        break;
    }
}
if (!$enrolinstance) {
    json_response(['status' => 'error', 'message' => 'Course token enrolment method not enabled for this course.']);
}

// ---------------------------------------------------------------------------
// RESOLVE TARGET USER
// ---------------------------------------------------------------------------
$enrol_email     = optional_param('email',           null,  PARAM_EMAIL);
$first_name      = optional_param('first_name',      'New', PARAM_TEXT);
$last_name       = optional_param('last_name',       'User', PARAM_TEXT);
$confirm_renewal = optional_param('confirm_renewal', 0,     PARAM_INT);

$is_new_user = false;
$enrol_user  = null;

if ($enrol_email) {
    $enrol_user = $DB->get_record('user', ['email' => $enrol_email, 'deleted' => 0, 'suspended' => 0]);

    if (!$enrol_user) {
        $new_user               = new stdClass();
        $new_user->auth         = 'manual';
        $new_user->confirmed    = 1;
        $new_user->mnethostid   = $CFG->mnet_localhost_id;
        $new_user->username     = strtolower(explode('@', $enrol_email)[0]) . rand(1000, 9999);
        $new_user->password     = hash_internal_user_password('changeme');
        $new_user->email        = $enrol_email;
        $new_user->firstname    = $first_name;
        $new_user->lastname     = $last_name;
        $new_user->timecreated  = time();
        $new_user->timemodified = time();
        ensure_optional_name_fields($new_user);
        $new_user->id = $DB->insert_record('user', $new_user);
        $enrol_user   = $new_user;
        $is_new_user  = true;
    } else {
        ensure_optional_name_fields($enrol_user);
    }
} else {
    $enrol_user = clone $USER;
    ensure_optional_name_fields($enrol_user);
}

// ---------------------------------------------------------------------------
// VALIDATE PHONE NUMBER REQUIREMENT
// ---------------------------------------------------------------------------
// Extract phone number earlier for validation
$phone_number = isset($_REQUEST['phone_number']) ? optional_param('phone_number', '', PARAM_TEXT) : null;

// Require phone for CPRFAAED (13) and CPRFAAED-Spanish (15)
if (in_array($course->id, [13, 15])) {
    // Check if phone number is empty in the form AND the user's profile
    if (empty($phone_number) && empty($enrol_user->phone1)) {
        json_response(['status' => 'error', 'message' => 'A phone number is required to enroll in this course.']);
    }
}

// ---------------------------------------------------------------------------
// RECERTIFICATION GATE
//
// If the user is already enrolled we need their consent before wiping anything.
//
// First POST  (confirm_renewal = 0):
//   Return a warning JSON payload. JS shows a Bootstrap modal with "Cancel"
//   and "Yes, use token & reset progress". Clicking Yes re-POSTs with
//   confirm_renewal = 1.
//
// Second POST (confirm_renewal = 1):
//   Call manager::reset_user_course() to wipe all activity progress, then
//   mark the token as used. The user stays enrolled — no unenrol/re-enrol.
//   Moodle's own reset covers: quizzes, assignments, scheduler, SCORM, H5P,
//   lessons, completion records, and the completion cache.
//
// Cancelling in the modal does nothing — the token is never touched.
// ---------------------------------------------------------------------------
$enrolled_record = $DB->get_record_sql(
    "SELECT ue.id
       FROM {user_enrolments} ue
       JOIN {enrol} e ON ue.enrolid = e.id
      WHERE e.courseid = ? AND ue.userid = ?",
    [$course->id, $enrol_user->id]
);

$is_renewal = false;

if ($enrolled_record) {

    if (!$confirm_renewal) {
        // Build the appropriate warning and return it — do not touch the token.
        $completion     = $DB->get_record('course_completions',
                            ['userid' => $enrol_user->id, 'course' => $course->id]);
        $has_completion = $completion && !empty($completion->timecompleted);

        if ($has_completion) {
            $expiry_time       = strtotime('+2 years', $completion->timecompleted);
            $days_until_expiry = (int) floor(($expiry_time - time()) / 86400);
            $expiry_date_str   = userdate($expiry_time, get_string('strftimedate', 'langconfig'));

            if ($days_until_expiry > 90) {
                // Certificate still well within validity period (> 90 days).
                json_response([
                    'status'  => 'confirm_early_renewal',
                    'message' => "Your current certificate is still valid and does not expire until {$expiry_date_str} ({$days_until_expiry} days from now).\n\n"
                               . "It is unusually early to renew at this time. If you choose to proceed, your current progress and certificate will be securely archived, and you will start a new certification cycle from 0%.\n\n"
                               . "Are you sure you want to use your token and reset your progress?"
                ]);
            } elseif ($days_until_expiry >= 0) {
                // Certificate expiring soon (Between 0 and 90 days).
                json_response([
                    'status'  => 'confirm_renewal',
                    'message' => "Your current certificate expires soon, on {$expiry_date_str} ({$days_until_expiry} days from now).\n\n"
                               . "Using this token will safely archive your existing certificate, clear your course progress, and allow you to start your recertification cycle from 0%.\n\n"
                               . "Do you wish to proceed and use this token?"
                ]);
            }

            // If $days_until_expiry < 0 (It is expired), we do NOT send a json_response().
            // The script simply ignores the warnings and naturally falls down to the reset logic below!

        } else {
            // Enrolled but no completed certificate (in-progress or never started).
            json_response([
                'status'  => 'confirm_renewal',
                'message' => "You are already enrolled in this course, but have not completed it.\n\n"
                           . "Using this token will permanently clear your current progress "
                           . "so you can start the course fresh from 0%.\n\n"
                           . "Do you wish to continue?"
            ]);
        }
    }

    // -----------------------------------------------------------------------
    // User confirmed — wipe all activity progress using the mts_hacks manager.
    // The user stays enrolled; we are only clearing their progress data.
    // Pass $token->id so the archive record is linked to the correct token cycle.
    // -----------------------------------------------------------------------
    require_once($CFG->dirroot . '/local/mts_hacks/classes/archive/manager.php');
    \local_mts_hacks\archive\manager::reset_user_course($enrol_user->id, $course->id, $token->id);

    $is_renewal = true;
}

// ---------------------------------------------------------------------------
// ENROL USER  (only for brand new enrolments — renewals stay enrolled)
// ---------------------------------------------------------------------------
if (!$is_renewal) {
    $roleId      = $DB->get_record('role', ['shortname' => 'student'])->id;
    $enrolPlugin = enrol_get_plugin('course_tokens');
    $enrolPlugin->enrol_user($enrolinstance, $enrol_user->id, $roleId);
}

/**
 * AUTOMATIC GROUP ASSIGNMENT FOR AHA COURSES
 * As per GitHub Issue #375 — place every enrollee into the "FULL class" group
 * for AHA courses (ACLS=16, BLS=18, PALS=20) automatically.
 */
if (in_array($course->id, [16, 18, 20])) {
    require_once($CFG->dirroot . '/group/lib.php');
    $group_id = groups_get_group_by_name($course->id, 'FULL class');
    if (!$group_id) {
        $groupdata           = new stdClass();
        $groupdata->courseid = $course->id;
        $groupdata->name     = 'FULL class';
        $group_id            = groups_create_group($groupdata);
    }
    if (!groups_is_member($group_id, $enrol_user->id)) {
        groups_add_member($group_id, $enrol_user->id);
    }
}

// ---------------------------------------------------------------------------
// MARK TOKEN AS USED
// For renewals the existing user_enrolments row is still there (we didn't
// unenrol), so this lookup always succeeds in both cases.
// ---------------------------------------------------------------------------
$userEnrolment = $DB->get_record('user_enrolments',
                    ['userid' => $enrol_user->id, 'enrolid' => $enrolinstance->id]);
if ($userEnrolment) {
    $token->user_enrolments_id = $userEnrolment->id;
    $token->used_on            = time();
    $token->used_by            = $enrol_email ?: $USER->email;
    $DB->update_record('course_tokens', $token);
}

// ---------------------------------------------------------------------------
// NOTIFY TOKEN OWNER when someone else is enrolled using their token
// ---------------------------------------------------------------------------
if ($USER->id !== $enrol_user->id) {
    $token_owner = $DB->get_record('user', ['id' => $USER->id]);
    if ($token_owner) {
        ensure_optional_name_fields($token_owner);

        $from_user              = new stdClass();
        $from_user->email       = 'support@pacificmedicaltraining.com';
        $from_user->firstname   = 'PMT';
        $from_user->lastname    = 'instructor';
        $from_user->maildisplay = 1;
        ensure_optional_name_fields($from_user);

        email_to_user($token_owner, $from_user,
            "Your course token has been used",
            "Dear {$token_owner->firstname} {$token_owner->lastname},\n\n"
            . "Your token '{$token->code}' was used to enrol "
            . "{$enrol_user->firstname} {$enrol_user->lastname} ({$enrol_user->email})"
            . " in: {$course->fullname}.\n\nThank you,\nPMT Team"
        );
    }
}

// ---------------------------------------------------------------------------
// UPDATE PHONE / ADDRESS if provided
// ---------------------------------------------------------------------------
$address = isset($_REQUEST['address']) ? optional_param('address', '', PARAM_TEXT) : null;

if ($phone_number || $address) {
    $data     = new stdClass();
    $data->id = $enrol_user->id;
    if ($phone_number) $data->phone1  = $phone_number;
    if ($address)      $data->address = $address;
    $DB->update_record('user', $data);
}

// ---------------------------------------------------------------------------
// SEND CONFIRMATION EMAIL
// ---------------------------------------------------------------------------
$from_user              = new stdClass();
$from_user->email       = 'support@pacificmedicaltraining.com';
$from_user->firstname   = 'PMT';
$from_user->lastname    = 'instructor';
$from_user->maildisplay = 1;
ensure_optional_name_fields($from_user);

if ($is_renewal) {
    $subject = "Recertification Started: {$course->fullname}";
    $message = "Dear {$enrol_user->firstname} {$enrol_user->lastname},\n\n"
             . "Your recertification for {$course->fullname} has been processed. "
             . "All previous progress has been cleared and your course has been reset to 0%.\n\n"
             . "Log in to begin your new certification cycle:\n"
             . "https://learn.pacificmedicaltraining.com/login/\n\nThank you,\nPMT Team";
} elseif ($is_new_user) {
    $subject = "Welcome to the {$course->fullname} Course";
    $message = "Dear {$enrol_user->firstname} {$enrol_user->lastname},\n\n"
             . "Thank you for purchasing {$course->fullname}.\n"
             . "Log in at: https://learn.pacificmedicaltraining.com/login/\n\n"
             . "Username: {$enrol_user->username} | Default password: changeme "
             . "(you will be asked to change it on first login).\n\nThank you.";
} else {
    $subject = "Welcome to the {$course->fullname} Course";
    $message = "Dear {$enrol_user->firstname} {$enrol_user->lastname},\n\n"
             . "Welcome back! You have been successfully enrolled in {$course->fullname}.\n"
             . "Log in at: https://learn.pacificmedicaltraining.com/login/\n\nThank you.";
}

email_to_user($enrol_user, $from_user, $subject, $message);

// ---------------------------------------------------------------------------
// FINAL RESPONSE
// ---------------------------------------------------------------------------
if ($enrol_user->email === $USER->email) {

    // BEST PRACTICE: Hard-bind the absolute base URL to bypass any proxy routing quirks
    $redirect_url = $CFG->wwwroot . '/course/view.php?id=' . $course->id;

    json_response([
        'status'       => 'redirect',
        'redirect_url' => $redirect_url,
        'message'      => $is_renewal
            ? 'Your progress has been cleared and your recertification has started. Good luck!'
            : 'You have been successfully enrolled in the course.'
    ]);
} else {
    json_response([
        'status'  => 'success',
        'message' => $is_renewal
            ? 'The student\'s progress has been cleared and their recertification has started.'
            : 'User successfully enrolled in the course.'
    ]);
}