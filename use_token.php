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
// VALIDATE REQUEST
// ---------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response([
        'status' => 'error',
        'message' => 'Invalid request method.',
    ]);
}

try {
    require_sesskey();
} catch (\moodle_exception $e) {
    json_response([
        'status' => 'error',
        'message' => 'Your session has expired. Please refresh the page and try again.',
    ]);
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
    json_response(['status' => 'error', 'message' => 'Course token enrollment method not enabled for this course.']);
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

// Validation for courses requiring phone number (configurable via admin settings)
$phone_mode = get_config('enrol_course_tokens', 'phone_required_mode');
$is_phone_required = false;

if ($phone_mode === 'all') {
    $is_phone_required = true;
} elseif ($phone_mode === 'specific') {
    $phone_courses_setting = get_config('enrol_course_tokens', 'phone_required_courses');
    $phone_required_ids = $phone_courses_setting ? array_map('intval', array_map('trim', explode(',', $phone_courses_setting))) : [];
    $is_phone_required = in_array((int)$course->id, $phone_required_ids);
}

if ($is_phone_required) {
    if (empty($phone_number) && empty($enrol_user->phone1)) {
        json_response([
            'status' => 'error',
            'message' => 'A phone number is required to enroll in this course.'
        ]);
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
//   Run the required synchronous pre-renewal reset callback, verify it, and
//   mark the token used in the same transaction. The user stays enrolled.
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
    // User confirmed — perform the renewal as one atomic operation.
    //
    // Required reset/archive work is synchronous and runs BEFORE token use.
    // The token update is committed in the same transaction. If any required
    // step throws, the database rollback restores the learner's previous state
    // and leaves the token unused. A Moodle lock prevents concurrent requests
    // from resetting the same learner/course cycle at the same time.
    // -----------------------------------------------------------------------
    require_once($CFG->dirroot . '/enrol/course_tokens/lib.php');

    // Keep the public plugin generic: use Moodle's configured support contact.
    $supportcontact = !empty($CFG->supportemail)
        ? trim((string) $CFG->supportemail)
        : 'site support';

    try {
        $lockfactory = \core\lock\lock_config::get_lock_factory('enrol_course_tokens_renewal');
        $lockresource = 'user:' . (int) $enrol_user->id . ':course:' . (int) $course->id;
        $lock = $lockfactory->get_lock($lockresource, 5);
    } catch (\Throwable $e) {
        error_log(
            'enrol_course_tokens: could not acquire renewal lock for token '
                . (int) $token->id . ': ' . $e->getMessage()
        );
        json_response([
            'status' => 'renewal_retry',
            'message' => 'We could not safely start the renewal. Nothing was changed and the token was not used. '
                . 'Please try again. If the problem continues, contact ' . $supportcontact . ' for assistance.',
        ]);
    }

    if (!$lock) {
        json_response([
            'status' => 'renewal_retry',
            'message' => 'Another renewal request is currently processing for this course. '
                . 'Nothing was changed and the token was not used. Please try again in a few seconds. '
                . 'If the problem continues, contact ' . $supportcontact . ' for assistance.',
        ]);
    }

    $renewalerror = null;
    $resettime = time();
    $transaction = null;
    $commitattempted = false;
    $commitstate = 'not_attempted';

    try {
        // Re-read mutable records after acquiring the lock. This closes the
        // double-click/race window between initial validation and consumption.
        $fresh_token = $DB->get_record('course_tokens', ['id' => $token->id], '*', MUST_EXIST);

        if ((int) $fresh_token->user_id !== (int) $USER->id) {
            throw new \RuntimeException('The token owner changed during renewal.');
        }
        if (!empty($fresh_token->voided)) {
            throw new \RuntimeException('The token was voided before renewal could complete.');
        }
        if (!empty($fresh_token->user_enrolments_id) || !empty($fresh_token->used_on)) {
            throw new \RuntimeException('The token has already been used.');
        }

        $fresh_enrolled_record = $DB->get_record_sql(
            "SELECT ue.id
               FROM {user_enrolments} ue
               JOIN {enrol} e ON ue.enrolid = e.id
              WHERE e.courseid = ? AND ue.userid = ?",
            [$course->id, $enrol_user->id],
            IGNORE_MULTIPLE
        );

        if (!$fresh_enrolled_record) {
            throw new \RuntimeException(
                'The existing enrolment disappeared before renewal could complete.'
            );
        }

        $transaction = $DB->start_delegated_transaction();

        enrol_course_tokens_run_before_renewal_callbacks([
            'userid' => (int) $enrol_user->id,
            'courseid' => (int) $course->id,
            'tokenid' => (int) $fresh_token->id,
            'resettime' => $resettime,
            // The caller owns the transaction so the reset and token use are atomic.
            'caller_manages_transaction' => true,
        ]);

        $fresh_token->user_enrolments_id = (int) $fresh_enrolled_record->id;
        $fresh_token->used_on = $resettime;
        $fresh_token->timemodified = time();
        $DB->update_record('course_tokens', $fresh_token);

        // Postconditions inside the transaction: do not commit unless the exact
        // token is now linked to the expected existing enrolment.
        $usedcheck = $DB->get_record(
            'course_tokens',
            ['id' => $fresh_token->id],
            'id, user_enrolments_id, used_on',
            MUST_EXIST
        );
        if ((int) $usedcheck->user_enrolments_id !== (int) $fresh_enrolled_record->id
                || (int) $usedcheck->used_on !== $resettime) {
            throw new \RuntimeException('Token consumption verification failed.');
        }

        $commitattempted = true;
        $transaction->allow_commit();
        $transaction = null;
        $commitstate = 'committed';

        $token = $fresh_token;
        $enrolled_record = $fresh_enrolled_record;
        $is_renewal = true;

    } catch (\Throwable $e) {
        $renewalerror = $e;

        if ($transaction) {
            try {
                $transaction->rollback($e);
            } catch (\Throwable $rollbackexception) {
                // rollback() normally rethrows the supplied exception. Keep the
                // rollback exception only for diagnostics; the user sees a safe
                // generic message below.
                $renewalerror = $rollbackexception;
            }
            $transaction = null;
        }
    } finally {
        $lock->release();
    }

    if ($renewalerror) {
        error_log(
            'enrol_course_tokens: atomic renewal failed for token ' . (int) $token->id
                . ', user ' . (int) $enrol_user->id
                . ', course ' . (int) $course->id
                . ': ' . $renewalerror->getMessage()
        );

        // A failure before allow_commit() is a normal rollback case: the token and
        // reset DML were in one transaction and remain unchanged. If allow_commit()
        // itself threw (for example, the DB connection dropped during COMMIT), the
        // outcome can be indeterminate. Re-read authoritative token state when
        // possible instead of incorrectly promising that the token is unused.
        if ($commitattempted) {
            try {
                $statecheck = $DB->get_record(
                    'course_tokens',
                    ['id' => $token->id],
                    'id, user_enrolments_id, used_on',
                    MUST_EXIST
                );

                if ((int) $statecheck->user_enrolments_id === (int) $fresh_enrolled_record->id
                        && (int) $statecheck->used_on === $resettime) {
                    // The commit actually completed despite the connection/error path.
                    $token = $DB->get_record('course_tokens', ['id' => $token->id], '*', MUST_EXIST);
                    $enrolled_record = $fresh_enrolled_record;
                    $is_renewal = true;
                    $renewalerror = null;
                    $commitstate = 'committed_after_recheck';
                } elseif (empty($statecheck->user_enrolments_id) && empty($statecheck->used_on)) {
                    $commitstate = 'rolled_back';
                } else {
                    $commitstate = 'indeterminate';
                }
            } catch (\Throwable $stateexception) {
                $commitstate = 'indeterminate';
                error_log(
                    'enrol_course_tokens: could not determine renewal commit outcome for token '
                        . (int) $token->id . ': ' . $stateexception->getMessage()
                );
            }
        } else {
            $commitstate = 'rolled_back';
        }

        if ($renewalerror && $commitstate === 'indeterminate') {
            json_response([
                'status' => 'renewal_check',
                'message' => 'We could not confirm the final renewal status. Please refresh your token dashboard '
                    . 'before trying again. If the token still appears available, you may retry it. If it appears used '
                    . 'or the problem continues, contact ' . $supportcontact . ' for assistance.',
            ]);
        }

        if ($renewalerror) {
            json_response([
                'status' => 'renewal_retry',
                'message' => 'We could not safely complete the renewal. Your previous course progress was preserved '
                    . 'and the token was not used. Please try again. If the problem continues, contact '
                    . $supportcontact . ' for assistance.',
            ]);
        }
    }

    // The atomic reset + token consumption has committed. The event is now
    // informational/post-commit only; it must never be responsible for the
    // destructive reset itself.
    try {
        if (class_exists('\enrol_course_tokens\event\token_renewal_confirmed')) {
            $event = \enrol_course_tokens\event\token_renewal_confirmed::create([
                'objectid' => $token->id,
                'context' => context_course::instance($course->id),
                'relateduserid' => $enrol_user->id,
                'other' => [
                    'token_id' => $token->id,
                    'course_id' => $course->id,
                ],
            ]);
            $event->trigger();
        }
    } catch (\Throwable $e) {
        error_log(
            'enrol_course_tokens: post-renewal event failed after successful atomic renewal for token '
                . (int) $token->id . ': ' . $e->getMessage()
        );
    }

    // Best-effort post-commit cache warm-up. The required cache purge is part
    // of the reset callback; failure to warm here does not invalidate the DB state.
    try {
        get_fast_modinfo((int) $course->id, 0, true);
    } catch (\Throwable $e) {
        error_log(
            'enrol_course_tokens: post-renewal modinfo refresh failed for course '
                . (int) $course->id . ': ' . $e->getMessage()
        );
    }
}

// ---------------------------------------------------------------------------
// ENROL USER  (only for brand new enrolments — renewals stay enrolled)
// ---------------------------------------------------------------------------
if (!$is_renewal) {
    $roleId      = $DB->get_record('role', ['shortname' => 'student'])->id;
    $enrolPlugin = enrol_get_plugin('course_tokens');
    $enrolPlugin->enrol_user($enrolinstance, $enrol_user->id, $roleId);
}

// Trigger generic enrolment event for optional integrations.
// Fired for new enrolments only. Renewals stay enrolled and don't need re-adding to groups.
if (!$is_renewal && class_exists('\enrol_course_tokens\event\user_enrolled_via_token')) {
    $event = \enrol_course_tokens\event\user_enrolled_via_token::create([
        'objectid'      => $token->id,
        'context'       => context_course::instance($course->id),
        'relateduserid' => $enrol_user->id,
        'other'         => [
            'course_id' => $course->id,
            'token_id'  => $token->id
        ]
    ]);
    $event->trigger();
}

// ---------------------------------------------------------------------------
// MARK TOKEN AS USED
// For renewals the existing user_enrolments row is still there (we didn't
// unenrol), so this lookup always succeeds in both cases.
// ---------------------------------------------------------------------------
$userEnrolment = $DB->get_record('user_enrolments',
                    ['userid' => $enrol_user->id, 'enrolid' => $enrolinstance->id], '*', IGNORE_MULTIPLE);

// Fallback: If enrolled via another method (e.g., manual), use that existing enrolment ID
if (!$userEnrolment && $enrolled_record) {
    $userEnrolment = $enrolled_record;
}

if ($userEnrolment && !$is_renewal) {
    $token->user_enrolments_id = $userEnrolment->id;
    $token->used_on            = time();
    $token->timemodified       = time();
    $DB->update_record('course_tokens', $token);
}

// ---------------------------------------------------------------------------
// NOTIFY TOKEN OWNER when someone else is enrolled using their token
// ---------------------------------------------------------------------------
if ($USER->id !== $enrol_user->id) {
    $token_owner = $DB->get_record('user', ['id' => $USER->id]);
    if ($token_owner) {
        ensure_optional_name_fields($token_owner);

        $sender_email = get_config('enrol_course_tokens', 'sender_email') ?: $CFG->supportemail;
        $sender_name  = get_config('enrol_course_tokens', 'sender_name') ?: (isset($SITE) ? $SITE->fullname : 'Moodle');

        $from_user              = new stdClass();
        $from_user->email       = $sender_email;
        $from_user->firstname   = $sender_name;
        $from_user->lastname    = '';
        $from_user->maildisplay = 1;
        ensure_optional_name_fields($from_user);

        email_to_user($token_owner, $from_user,
            "Your course token has been used",
            "Dear {$token_owner->firstname} {$token_owner->lastname},\n\n"
            . "Your token '{$token->code}' was used to enroll "
            . "{$enrol_user->firstname} {$enrol_user->lastname} ({$enrol_user->email})"
            . " in: {$course->fullname}."
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
$sender_email = get_config('enrol_course_tokens', 'sender_email') ?: $CFG->supportemail;
$sender_name  = get_config('enrol_course_tokens', 'sender_name') ?: (isset($SITE) ? $SITE->fullname : 'Moodle');
$login_url    = get_config('enrol_course_tokens', 'custom_login_url') ?: $CFG->wwwroot . '/login/';

$from_user              = new stdClass();
$from_user->email       = $sender_email;
$from_user->firstname   = $sender_name;
$from_user->lastname    = '';
$from_user->maildisplay = 1;
ensure_optional_name_fields($from_user);

$html_content = '';

if ($is_renewal) {
    $subject = "Recertification Started: {$course->fullname}";
    $html_content = get_config('enrol_course_tokens', 'recert_email_body') ?: '';
    $replacements = [
        '{{firstname}}'   => $enrol_user->firstname,
        '{{lastname}}'    => $enrol_user->lastname,
        '{{course_name}}' => $course->fullname,
        '{{login_url}}'   => $login_url
    ];
    $html_content = str_replace(array_keys($replacements), array_values($replacements), $html_content);

} elseif ($is_new_user) {
    $subject = get_string('welcome_email_subject', 'enrol_course_tokens');
    $html_content = get_config('enrol_course_tokens', 'welcome_email_body') ?: '';
    $replacements = [
        '{{firstname}}'   => $enrol_user->firstname,
        '{{lastname}}'    => $enrol_user->lastname,
        '{{email}}'       => $enrol_user->email, // FIXED: Using email instead of username
        '{{password}}'    => 'changeme',
        '{{login_url}}'   => $login_url
    ];
    $html_content = str_replace(array_keys($replacements), array_values($replacements), $html_content);

} else {
    $subject = get_string('enrolment_email_subject', 'enrol_course_tokens');
    $html_content = get_config('enrol_course_tokens', 'enrolment_email_body') ?: '';
    $replacements = [
        '{{firstname}}'   => $enrol_user->firstname,
        '{{lastname}}'    => $enrol_user->lastname,
        '{{course_name}}' => $course->fullname,
        '{{login_url}}'   => $login_url
    ];
    $html_content = str_replace(array_keys($replacements), array_values($replacements), $html_content);
}

$plain_content = strip_tags($html_content);

email_to_user($enrol_user, $from_user, $subject, $plain_content, $html_content);

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