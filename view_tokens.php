<?php
require_once('../../config.php');
require_once($CFG->dirroot . '/enrol/course_tokens/lib.php');
global $DB, $USER, $PAGE, $OUTPUT;

// Ensure the user is logged in
require_login();

// Set the URL of the page
$PAGE->set_url(new moodle_url('/enrol/course_tokens/view_tokens.php'));
$PAGE->set_context(context_user::instance($USER->id));
$PAGE->set_title('My course tokens');
$PAGE->set_heading('My course tokens');

// Define the base URL for token operations outside the loop
$use_token_url = new moodle_url('/enrol/course_tokens/use_token.php');

// Fetch tokens associated with the logged-in user
$sql = "SELECT t.*, u.email as enrolled_user_email, u.id as student_id
        FROM {course_tokens} t
        LEFT JOIN {user_enrolments} ue ON t.user_enrolments_id = ue.id
        LEFT JOIN {user} u ON ue.userid = u.id
        WHERE t.user_id = ? AND t.voided_at IS NULL
        ORDER BY t.id DESC";

        $tokens = $DB->get_records_sql($sql, [$USER->id]);

        // --- NEW LOGIC: Determine Active vs Historical Tokens Timeline ---
        $token_timelines = [];
        if ($tokens) {
            foreach ($tokens as $t) {
                if (!empty($t->used_on) && !empty($t->student_id)) {
                    $key = $t->student_id . '_' . $t->course_id;
                    $token_timelines[$key][] = $t;
                }
            }
            // Sort each student's course tokens ascending by usage date
            foreach ($token_timelines as $key => $group) {
                usort($token_timelines[$key], function($a, $b) {
                    return $a->used_on <=> $b->used_on;
                });
            }
        }
        // -----------------------------------------------------------------

// Render the page header
echo $OUTPUT->header();

if (!empty($tokens)) {
    // Check if there is any "Available" token
    $has_available_token = false;
    foreach ($tokens as $token) {
        if (empty($token->used_on)) {
            $has_available_token = true;
            break;
        }
    }

    // Display alert if there is an available token
    if ($has_available_token) {
        echo html_writer::tag('div', 'Token will expire in 90 days after order if not used.', array('class' => 'alert alert-info'));
    }
    // Start a Bootstrap-styled table
    echo html_writer::start_tag('table', array('class' => 'table table-striped table-hover'));
    echo html_writer::start_tag('thead');
    echo html_writer::start_tag('tr');
    echo html_writer::tag('th', 'Token code');
    echo html_writer::tag('th', 'Course');
    echo html_writer::tag('th', 'Status');
    echo html_writer::tag('th', 'Used by');
    echo html_writer::tag('th', 'Name of student');
    echo html_writer::tag('th', 'Used on');
    echo html_writer::tag('th', 'Skills schedule date'); // <-- NEW COLUMN HEADER
    echo html_writer::tag('th', 'Enroll myself');
    echo html_writer::tag('th', 'Enroll somebody else');
    echo html_writer::tag('th', 'eCard');
    echo html_writer::tag('th', 'Forward eCard');
    echo html_writer::end_tag('tr');
    echo html_writer::end_tag('thead');
    echo html_writer::start_tag('tbody');

    foreach ($tokens as $token) {
        // Fetch course details
        $course = $DB->get_record('course', ['id' => $token->course_id], 'id, fullname');
        $course_name = $course ? $course->fullname : 'Unknown Course';

        $user = null;
        if (!empty($token->user_enrolments_id)) {
            $enrolment = $DB->get_record('user_enrolments', ['id' => $token->user_enrolments_id], 'userid');
            if ($enrolment) {
                $user = $DB->get_record('user', ['id' => $enrolment->userid],
                'id, email, firstname, lastname, firstnamephonetic, lastnamephonetic, middlename, alternatename, phone1, address');
            }
        }
        $user_id = $user ? $user->id : null;

        $is_active_token = true;
        $window_start = !empty($token->used_on) ? (int)$token->used_on : 0;
        $window_end = null;

        if ($user_id) {
            $key = $user_id . '_' . $token->course_id;
            if (isset($token_timelines[$key])) {
                $timeline = $token_timelines[$key];
                $latest_token = end($timeline);
                if ($token->id != $latest_token->id) {
                    $is_active_token = false;
                    foreach ($timeline as $index => $tt) {
                        if ($tt->id == $token->id && isset($timeline[$index + 1])) {
                            $window_end = (int)$timeline[$index + 1]->used_on;
                            break;
                        }
                    }
                }
            }
        }

        $status_code = enrol_course_tokens_get_generic_token_status($token, $user_id, $window_start, $window_end);
        $status_display = enrol_course_tokens_get_status_display($status_code);
        $status = $status_display['label'];
        $status_class = $status_display['class'];

        $no_ecard = html_writer::tag('span', 'No eCard available', ['class' => 'text-muted']);
        if ($user_id && $is_active_token) {
            $ecard_actions = enrol_course_tokens_get_generic_customcert_actions(
                $user_id,
                $course,
                $course_name,
                $user,
                $window_start,
                $window_end
            );
        } elseif ($user_id) {
            $ecard_actions = ['ecard_html' => $no_ecard, 'forward_html' => $no_ecard];
        } else {
            $ecard_actions = ['ecard_html' => '-', 'forward_html' => '-'];
        }
        $ecard_button = $ecard_actions['ecard_html'];
        $forward_button = $ecard_actions['forward_html'];

        $display_context = [
            'token' => $token,
            'course' => $course,
            'course_name' => $course_name,
            'user' => $user,
            'user_id' => $user_id,
            'is_active_token' => $is_active_token,
            'window_start' => $window_start,
            'window_end' => $window_end,
            'default_status_code' => $status_code,
            'default_status_label' => $status,
            'default_status_class' => $status_class,
            'ecard_html' => $ecard_button,
            'forward_html' => $forward_button,
        ];
        $display = enrol_course_tokens_apply_token_display_callbacks($display_context);
        $status_code = $display['status_code'];
        $status = $display['status_label'];
        $status_class = $display['status_class'];
        $ecard_button = $display['ecard_html'];
        $forward_button = $display['forward_html'];

        // Prepare "Used By" and "Used On" fields for display
        $used_by = $user ? $user->email : '-';
        $used_on = !empty($token->used_on) ? date('Y-m-d', $token->used_on) : '-';

        // Render table row
        echo html_writer::start_tag('tr');
        echo html_writer::tag('td', format_string($token->code));
        echo html_writer::tag('td', format_string($course_name));
        echo html_writer::tag('td', format_string($status), array('class' => $status_class));
        // Add "Used By" details if the token has been used with phone number and address
        if ($user_id) {
            // Fetch user's phone number and address
            $phone   = !empty($user->phone1)   ? $user->phone1   : 'N/A';
            $address = !empty($user->address)   ? $user->address  : 'N/A';

            // Render the clickable "Used by" text
            $modal_trigger = html_writer::tag('a', format_string($used_by), [
                'href' => '#',
                'data-bs-toggle' => 'modal',
                'data-bs-target' => '#userModal' . $user_id,
            ]);

            echo html_writer::tag('td', $modal_trigger);

            // Add the modal HTML
            echo '
            <div class="modal fade" id="userModal' . $user_id . '" tabindex="-1" role="dialog" aria-labelledby="userModalLabel' . $user_id . '" aria-hidden="true">
                <div class="modal-dialog" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="userModalLabel' . $user_id . '">User Details</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <p><strong>Name:</strong> ' . fullname($user) . '</p>
                            <p><strong>Email:</strong> ' . format_string($user->email) . '</p>
                            <p><strong>Phone Number:</strong> ' . format_string($phone) . '</p>
                            <p><strong>Address:</strong> ' . format_string($address) . '</p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        </div>
                    </div>
                </div>
            </div>';
        } else {
            echo html_writer::tag('td', '-');
        }

        // Add "Name of Student" column
        $student_name = $user ? fullname($user) : '-';
        echo html_writer::tag('td', $student_name);

        echo html_writer::tag('td', $used_on);

        // Fetch Skills schedule date
        $schedule_dates_output = '-';
        if ($user_id && !empty($token->course_id)) {
            $schedulersql = "SELECT ss.id, ss.starttime 
                             FROM {scheduler_appointment} sa
                             JOIN {scheduler_slots} ss ON sa.slotid = ss.id
                             JOIN {scheduler} s ON ss.schedulerid = s.id
                             WHERE s.course = ? AND sa.studentid = ?
                             ORDER BY ss.starttime ASC";
            
            $scheduled_slots = $DB->get_records_sql($schedulersql, [$token->course_id, $user_id]);
            
            if (!empty($scheduled_slots)) {
                $schedule_dates_array = [];
                foreach ($scheduled_slots as $slot) {
                    // Preserve the existing reporting timezone for scheduled skills sessions.
                    $schedule_dates_array[] = userdate($slot->starttime, '%A, %d %B %Y %I:%M %p', 'America/New_York');
                }
                $schedule_dates_output = implode('<br>', $schedule_dates_array);
            } else {
                $schedule_dates_output = 'Not scheduled';
            }
        }
        echo html_writer::tag('td', $schedule_dates_output);
        // ---------------------------------------------

        // Show "Enroll Myself" and "Enroll Somebody Else" buttons for available tokens
        if ($status_code === 'available') {
            $use_token_url = new moodle_url('/enrol/course_tokens/use_token.php');
            
            // --- NEW LOGIC: Phone Requirement ---
            $phone_mode = get_config('enrol_course_tokens', 'phone_required_mode');
            $is_phone_required_course = false;

            if ($phone_mode === 'all') {
                $is_phone_required_course = true;
            } elseif ($phone_mode === 'specific') {
                $phone_courses_setting = get_config('enrol_course_tokens', 'phone_required_courses');
                $phone_required_ids = $phone_courses_setting ? array_map('intval', array_map('trim', explode(',', $phone_courses_setting))) : [];
                $is_phone_required_course = in_array((int)$token->course_id, $phone_required_ids);
            }

            $phone_required_attr = $is_phone_required_course ? 'required' : '';
            $phone_label_asterisk = $is_phone_required_course ? ' <span class="text-danger">*</span>' : '';
            $needs_phone_for_myself = $is_phone_required_course && empty($USER->phone1);
            // ------------------------------------

            if ($needs_phone_for_myself) {
                echo '
                <td>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#enrollMyselfModal' . $token->id . '">Enroll Myself</button>

                    <div class="modal fade" id="enrollMyselfModal' . $token->id . '" tabindex="-1" role="dialog" aria-labelledby="enrollMyselfModalLabel' . $token->id . '" aria-hidden="true">
                        <div class="modal-dialog" role="document">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="enrollMyselfModalLabel' . $token->id . '">Phone Number Required</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <div class="modal-body">
                                    <form id="enrollMyselfForm' . $token->id . '">
                                        <p>Please provide your phone number to continue enrollment for this course.</p>
                                        <div class="form-group mb-2">
                                            <label for="myselfPhone' . $token->id . '">Phone number <span class="text-danger">*</span></label>
                                            <input type="tel" class="form-control" id="myselfPhone' . $token->id . '" name="phone_number" required>
                                        </div>
                                        <input type="hidden" name="token_code" value="' . $token->code . '">
                                    </form>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                    <button type="button" class="btn btn-primary" onclick="submitEnrollForm(' . $token->id . ', \'myself\')">Enroll</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </td>';
            } else {
                echo '
                <td>
                    <form id="enrollMyselfForm' . $token->id . '">
                        <input type="hidden" name="token_code" value="' . $token->code . '">
                        <button type="button" class="btn btn-primary" onclick="submitEnrollForm(' . $token->id . ', \'myself\')">Enroll Myself</button>
                    </form>
                </td>';
            }

            // Enroll Somebody Else Button
            $share_button = html_writer::tag('button', 'Enroll Somebody Else', array(
                'class'          => 'btn btn-secondary',
                'data-bs-toggle' => 'modal',
                'data-bs-target' => '#enrollModal' . $token->id
            ));
            echo html_writer::tag('td', $share_button);

            // Render the "Enroll Somebody Else" modal
            echo '
            <div class="modal fade" id="enrollModal' . $token->id . '" tabindex="-1" role="dialog" aria-labelledby="enrollModalLabel" aria-hidden="true">
                <div class="modal-dialog" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="enrollModalLabel">Enroll Somebody Else</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <form id="enrollForm' . $token->id . '">
                                <div class="form-group mb-2">
                                    <label for="firstName' . $token->id . '">First name</label>
                                    <input type="text" class="form-control" id="firstName' . $token->id . '" name="first_name" required>
                                </div>
                                <div class="form-group mb-2">
                                    <label for="lastName' . $token->id . '">Last name</label>
                                    <input type="text" class="form-control" id="lastName' . $token->id . '" name="last_name" required>
                                </div>
                                <div class="form-group mb-2">
                                    <label for="emailAddress' . $token->id . '">Email address</label>
                                    <input type="email" class="form-control" id="emailAddress' . $token->id . '" name="email" required>
                                </div>
                                <div class="form-group mb-2">
                                    <label for="address' . $token->id . '">Address</label>
                                    <input type="text" class="form-control" id="address' . $token->id . '" name="address">
                                </div>
                                <div class="form-group mb-2">
                                    <label for="phone' . $token->id . '">Phone number' . $phone_label_asterisk . '</label>
                                    <input type="tel" class="form-control" id="phone' . $token->id . '" name="phone_number" ' . $phone_required_attr . '>
                                </div>
                                <input type="hidden" name="token_code" value="' . $token->code . '">
                            </form>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            <button type="button" class="btn btn-primary" onclick="submitEnrollForm(' . $token->id . ')">Enroll</button>
                        </div>
                    </div>
                </div>
            </div>';
        } else {
            echo html_writer::tag('td', '-');
            echo html_writer::tag('td', '-');
        }

        echo html_writer::tag('td', $ecard_button);
        echo html_writer::tag('td', $forward_button);
        echo html_writer::end_tag('tr');
    }

    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');

} else {
    // No tokens available
    echo html_writer::tag('p', 'No tokens available.', array('class' => 'alert alert-info'));
}

// Render the page footer
echo $OUTPUT->footer();

// ---------------------------------------------------------------------------
// RECERTIFICATION WARNING MODAL (shared, single instance on the page)
// Injected once; JavaScript populates it dynamically before showing it.
// ---------------------------------------------------------------------------
echo '
<div class="modal fade" id="recertWarningModal" tabindex="-1" aria-labelledby="recertWarningModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header text-white" id="recertWarningModalHeader" style="border-radius:.375rem .375rem 0 0;">
                <h5 class="modal-title fw-bold" id="recertWarningModalLabel">
                    <span id="recertWarningIcon" class="me-2"></span>
                    <span id="recertWarningTitle"></span>
                </h5>
            </div>
            <div class="modal-body py-4 px-4">
                <p id="recertWarningMessage" class="mb-0" style="white-space:pre-line;line-height:1.6;"></p>
            </div>
            <div class="modal-footer border-top-0 pt-0">
                <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn px-4 fw-semibold" id="recertConfirmBtn"
                        style="background:#d9534f;color:#fff;">
                    Yes, use token &amp; reset progress
                </button>
            </div>
        </div>
    </div>
</div>';

// ---------------------------------------------------------------------------
// JAVASCRIPT — AJAX enrollment + recertification modal handling
// ---------------------------------------------------------------------------
$use_token_url_str = (new moodle_url('/enrol/course_tokens/use_token.php'))->out(false);
echo '
<script>
(function () {
    "use strict";

    // -----------------------------------------------------------------------
    // submitEnrollForm(tokenId, type, confirmRenewal)
    //   tokenId       — numeric token row ID (used to find the correct <form>)
    //   type          — "myself" | "other"
    //   confirmRenewal — 0 (default) | 1 (user confirmed the recert modal)
    // -----------------------------------------------------------------------
    window.submitEnrollForm = function (tokenId, type, confirmRenewal) {
        type          = type          || "other";
        confirmRenewal = confirmRenewal || 0;

        const formId = (type === "myself")
            ? "enrollMyselfForm" + tokenId
            : "enrollForm"       + tokenId;

        const form = document.getElementById(formId);
        if (!form) return;

        if (!form.checkValidity()) {
            if (typeof form.reportValidity === "function") {
                form.reportValidity(); // Highlights the specific missing field natively
            } else {
                showFormError("Please fill out all required fields.");
            }
            return;
        }

        const formData = new FormData(form);
        if (confirmRenewal) {
            formData.set("confirm_renewal", "1");
        }

        // BEST PRACTICE: Always prefix AJAX requests with M.cfg.wwwroot
        const targetUrl = M.cfg.wwwroot + "/enrol/course_tokens/use_token.php";

        fetch(targetUrl, {
            method : "POST",
            body   : formData
        })
        .then(r => r.text())
        .then(rawText => {
            console.log("[course_tokens] use_token.php raw response:", rawText);
            let data;
            try {
                data = JSON.parse(rawText);
            } catch (parseErr) {
                console.error("[course_tokens] JSON parse failed. Raw server output:", rawText);
                showFormError("Server returned an unexpected response. Check the browser console for details.");
                return;
            }
            handleResponse(data, tokenId, type);
        })
        .catch(err => {
            console.error("[course_tokens] fetch() network error:", err);
            showFormError("A network error occurred. Please check your connection and try again.");
        });
    };

    // -----------------------------------------------------------------------
    // handleResponse — routes the JSON reply from use_token.php
    // -----------------------------------------------------------------------
    function handleResponse(data, tokenId, type) {
        switch (data.status) {
            case "redirect":
                // Successful "Enroll Myself" — go straight to the course.
                showSuccessThen(data.message, function () {
                    window.location.href = data.redirect_url;
                });
                break;

            case "success":
                // Successful "Enroll Somebody Else"
                showSuccessThen(data.message || "Enrollment successful!", function () {
                    location.reload();
                });
                break;

            case "confirm_early_renewal":
                // ⚠ EARLY-RENEWAL WARNING (cert valid > 90 days)
                showRecertModal({
                    title        : "⚠ Early Renewal Warning",
                    message      : data.message,
                    headerColor  : "#f0ad4e",   // amber
                    icon         : "⚠",
                    tokenId      : tokenId,
                    enrollType   : type
                });
                break;

            case "confirm_renewal":
                // 🔄 STANDARD-RENEWAL WARNING (cert expiring soon or expired)
                showRecertModal({
                    title       : "Reset Progress & Recertify",
                    message     : data.message,
                    headerColor : "#d9534f",    // red
                    icon        : "🔄",
                    tokenId     : tokenId,
                    enrollType  : type
                });
                break;

            case "error":
            default:
                showFormError(data.message || "An unexpected error occurred.");
                location.reload();
                break;
        }
    }

    // -----------------------------------------------------------------------
    // Bootstrap modal helpers — compatible with BS5 global, BS4 via jQuery,
    // and Moodle themes that expose neither as a plain global.
    // -----------------------------------------------------------------------
    function courseTokensModalShow(el) {
        if (typeof bootstrap !== "undefined" && bootstrap.Modal) {
            new bootstrap.Modal(el, { backdrop: "static", keyboard: false }).show();
        } else if (typeof jQuery !== "undefined") {
            jQuery(el).modal({ backdrop: "static", keyboard: false });
            jQuery(el).modal("show");
        }
    }
    function courseTokensModalHide(el) {
        if (typeof bootstrap !== "undefined" && bootstrap.Modal) {
            const m = bootstrap.Modal.getInstance(el);
            if (m) m.hide();
        } else if (typeof jQuery !== "undefined") {
            jQuery(el).modal("hide");
        }
    }

    // -----------------------------------------------------------------------
    // showRecertModal — populates and opens the shared Bootstrap warning modal
    // -----------------------------------------------------------------------
    function showRecertModal(opts) {
        const modalEl    = document.getElementById("recertWarningModal");
        const headerEl   = document.getElementById("recertWarningModalHeader");
        const titleEl    = document.getElementById("recertWarningTitle");
        const iconEl     = document.getElementById("recertWarningIcon");
        const messageEl  = document.getElementById("recertWarningMessage");
        const confirmBtn = document.getElementById("recertConfirmBtn");

        headerEl.style.backgroundColor = opts.headerColor;
        iconEl.textContent             = opts.icon || "";
        titleEl.textContent            = opts.title;
        messageEl.textContent          = opts.message;

        // Wire the confirm button — remove any previous listener first
        const newBtn = confirmBtn.cloneNode(true);
        confirmBtn.parentNode.replaceChild(newBtn, confirmBtn);
        newBtn.addEventListener("click", function () {
            // Close this modal, then re-submit WITH confirmation flag
            courseTokensModalHide(modalEl);
            submitEnrollForm(opts.tokenId, opts.enrollType, 1);
        });

        // Open the modal
        courseTokensModalShow(modalEl);
    }

    // -----------------------------------------------------------------------
    // Small helpers
    // -----------------------------------------------------------------------
    function showFormError(msg) {
        let container = document.getElementById("course-tokens-alert-container");
        if (!container) {
            container = document.createElement("div");
            container.id = "course-tokens-alert-container";
            container.style.cssText = "position:fixed;top:1rem;right:1rem;z-index:9999;min-width:320px;";
            document.body.appendChild(container);
        }
        const alert = document.createElement("div");
        alert.className = "alert alert-danger alert-dismissible fade show shadow";
        alert.role      = "alert";
        alert.innerHTML = `<strong>Error:</strong> ${escHtml(msg)}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>`;
        container.appendChild(alert);
        setTimeout(() => alert.remove(), 8000);
    }

    function showSuccessThen(msg, callback) {
        let container = document.getElementById("course-tokens-alert-container");
        if (!container) {
            container = document.createElement("div");
            container.id = "course-tokens-alert-container";
            container.style.cssText = "position:fixed;top:1rem;right:1rem;z-index:9999;min-width:320px;";
            document.body.appendChild(container);
        }
        const alert = document.createElement("div");
        alert.className = "alert alert-success alert-dismissible fade show shadow";
        alert.role      = "alert";
        alert.innerHTML = `<strong>Success!</strong> ${escHtml(msg)}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>`;
        container.appendChild(alert);
        setTimeout(callback, 1800);
    }

    function escHtml(str) {
        const d = document.createElement("div");
        d.appendChild(document.createTextNode(str || ""));
        return d.innerHTML;
    }
}());
</script>
';
?>
