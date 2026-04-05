<?php

// Ensure the file is executed within the Moodle environment
defined('MOODLE_INTERNAL') || die();

// Include Moodle libraries
require_once($CFG->dirroot . '/lib/moodlelib.php');
require_once($CFG->dirroot . '/lib/blocklib.php');

require_once($CFG->dirroot . '/config.php');
require_once($CFG->dirroot . '/blocks/moodleblock.class.php');
require_once($CFG->libdir  . '/weblib.php');
require_once($CFG->dirroot . '/local/mts_hacks/lib.php');

class block_course_tokens extends block_base
{
    public function init()
    {
        $this->title = get_string('pluginname', 'block_course_tokens');
    }

    public function get_content()
    {
        global $DB, $USER, $OUTPUT;

        if ($this->content !== null) {
            return $this->content;
        }

        // Define the base URL for token operations at the beginning of get_content
        $use_token_url = new moodle_url('/enrol/course_tokens/use_token.php');

        // SQL query to fetch tokens and course names
        $sql = "SELECT t.*, u.email as enrolled_user_email, u.id as student_id, c.fullname as course_name
                FROM {course_tokens} t
                LEFT JOIN {user_enrolments} ue ON t.user_enrolments_id = ue.id
                LEFT JOIN {user} u ON ue.userid = u.id
                JOIN {course} c ON t.course_id = c.id
                WHERE t.user_id = ? AND t.voided_at IS NULL
                ORDER BY t.id DESC";

        $tokens = $DB->get_records_sql($sql, [$USER->id]);

        // --- NEW LOGIC: Build timeline to isolate active vs historical ---
        $token_timelines = [];
        if ($tokens) {
            foreach ($tokens as $t) {
                if (!empty($t->used_on) && !empty($t->student_id)) {
                    $key = $t->student_id . '_' . $t->course_id;
                    $token_timelines[$key][] = $t;
                }
            }
            foreach ($token_timelines as $key => $group) {
                usort($token_timelines[$key], function($a, $b) {
                    return $a->used_on <=> $b->used_on;
                });
            }
        }
        // -----------------------------------------------------------------

        // Ensure that content is initialized
        if (empty($this->content)) {
            $this->content         = new stdClass();
            $this->content->text   = '';
            $this->content->footer = '';
        }

        // Initialize an array to store course data and token counts
        $course_data = [];

        foreach ($tokens as $token) {
            $course_name = $token->course_name ?: 'Unknown Course';

            if (!isset($course_data[$course_name])) {
                $course_data[$course_name] = [
                    'available'   => 0,
                    'assigned'    => 0,
                    'in_progress' => 0,
                    'completed'   => 0,
                    'failed'      => 0,
                    'course_id'   => $token->course_id
                ];
            }

            $user    = null;
            $user_id = null;
            if (!empty($token->user_enrolments_id)) {
                $enrolment = $DB->get_record('user_enrolments', ['id' => $token->user_enrolments_id], 'userid');
                if ($enrolment) {
                    $user    = $DB->get_record('user', ['id' => $enrolment->userid], 'id, email, firstname, lastname, phone1, address');
                    $user_id = $user ? $user->id : null;
                }
            }

            if ($user_id) {
                // Timeline Check
                $is_active_token = true;
                $next_used_on    = time();
                $key             = $user_id . '_' . $token->course_id;

                if (isset($token_timelines[$key])) {
                    $timeline     = $token_timelines[$key];
                    $latest_token = end($timeline);
                    if ($token->id != $latest_token->id) {
                        $is_active_token = false;
                        foreach ($timeline as $index => $tt) {
                            if ($tt->id == $token->id && isset($timeline[$index + 1])) {
                                $next_used_on = $timeline[$index + 1]->used_on;
                                break;
                            }
                        }
                    }
                }

                if (!$is_active_token) {
                    // HISTORICAL TOKEN
                    $archived_record = $DB->get_record_sql("
                        SELECT id, final_status
                          FROM {local_mts_hacks_archive}
                         WHERE userid = ? AND courseid = ?
                           AND timeissued >= ? AND timeissued <= ?
                         ORDER BY timeissued DESC
                         LIMIT 1
                    ", [$user_id, $token->course_id, $token->used_on, $next_used_on]);

                    if ($archived_record) {
                        switch ($archived_record->final_status) {
                            case 'completed':
                                $course_data[$course_name]['completed']++;
                                break;
                            case 'failed':
                                $course_data[$course_name]['failed']++;
                                break;
                            case 'in_progress':
                                $course_data[$course_name]['in_progress']++;
                                break;
                            case 'assigned':
                                $course_data[$course_name]['assigned']++;
                                break;
                            default:
                                $course_data[$course_name]['completed']++;
                        }
                    } else {
                        $course_data[$course_name]['failed']++;
                    }

                } else {
                    // ACTIVE TOKEN
                    $raw_status = local_mts_hacks_get_course_status($user_id, $token->course_id, $course_name, (int)$token->used_on);
                    switch ($raw_status) {
                        case 'completed':
                            $course_data[$course_name]['completed']++;
                            break;
                        case 'failed':
                            $course_data[$course_name]['failed']++;
                            break;
                        case 'in_progress':
                            $course_data[$course_name]['in_progress']++;
                            break;
                        default: // 'assigned'
                            $course_data[$course_name]['assigned']++;
                    }
                }
            } else {
                $course_data[$course_name]['available']++;
            }
        }

        // Custom table styles
        $this->content->text .= html_writer::tag('style', '
        .table th, .table td { vertical-align: middle; }
        ');

        // Info alert
        $alert_message = 'You can enroll yourself or somebody else from your available inventory of courses. Please click the ASSIGN button below.';

        $has_available_tokens = false;
        foreach ($course_data as $counts) {
            if ($counts['available'] > 0) {
                $has_available_tokens = true;
                break;
            }
        }

        if ($has_available_tokens) {
            $alert_message .= ' Token will expire in 90 days after order if not used.';
        }

        $this->content->text .= html_writer::tag('p', $alert_message, ['class' => 'alert alert-info']);

        // Check for old in-progress enrolments (>60 days)
        $has_old_in_progress = false;
        $days_limit          = 60;
        $old_timestamp       = time() - ($days_limit * 24 * 60 * 60);

        foreach ($course_data as $course_name => $counts) {
            if ($counts['in_progress'] > 0) {
                $sql    = "SELECT ue.id
                        FROM {user_enrolments} ue
                        JOIN {enrol} e ON e.id = ue.enrolid
                        JOIN {course_tokens} t ON t.user_enrolments_id = ue.id
                        WHERE e.courseid = ?
                        AND t.user_id = ?
                        AND ue.timecreated < ?
                        AND t.voided_at IS NULL";
                $params = [$counts['course_id'], $USER->id, $old_timestamp];

                if ($DB->record_exists_sql($sql, $params)) {
                    $has_old_in_progress = true;
                    break;
                }
            }
        }

        if ($has_old_in_progress) {
            $this->content->text .= html_writer::tag(
                'p',
                'All courses expire 90 days after enrollment if not completed.',
                ['class' => 'alert alert-warning']
            );
        }

        // Summary table
        $this->content->text .= html_writer::start_tag('table', array('class' => 'table table-striped table-hover table-bordered'));

        $this->content->text .= html_writer::start_tag('thead');
        $this->content->text .= html_writer::start_tag('tr');
        $this->content->text .= html_writer::tag('th', 'Course',              ['class' => 'text-center col-2']);
        $this->content->text .= html_writer::tag('th', 'Available inventory', ['class' => 'text-center col-2']);
        $this->content->text .= html_writer::tag('th', 'Assigned',            ['class' => 'text-center col-2']);
        $this->content->text .= html_writer::tag('th', 'In-progress',         ['class' => 'text-center col-2']);
        $this->content->text .= html_writer::tag('th', 'Completed',           ['class' => 'text-center col-2']);
        $this->content->text .= html_writer::tag('th', 'Failed',              ['class' => 'text-center col-2']);
        $this->content->text .= html_writer::end_tag('tr');
        $this->content->text .= html_writer::end_tag('thead');
        $this->content->text .= html_writer::start_tag('tbody');

        // IMPORTANT: Buffer the modals here so they are rendered OUTSIDE the table HTML
        // This prevents browsers from instantly stripping/auto-closing the <form> tags.
        $modals_html = '';

        foreach ($course_data as $course_name => $counts) {
            $this->content->text .= html_writer::start_tag('tr');
            $this->content->text .= html_writer::tag('td', format_string($course_name), ['class' => 'text-center col-2']);

            $available_tokens = array_filter($tokens, function($token) use ($counts) {
                return $token->course_id == $counts['course_id'] && $token->user_enrolments_id === null;
            });
            $token = reset($available_tokens);

            if ($counts['available'] > 0 && $token) {
                $assign_button = html_writer::tag('button', 'Assign', [
                    'class'          => 'btn btn-success ml-2 btn-sm',
                    'data-bs-toggle' => 'modal',
                    'data-bs-target' => '#assignModal' . $counts['course_id'],
                ]);
            } else {
                $assign_button = '';
            }
            $this->content->text .= html_writer::tag('td', $counts['available'] . $assign_button, ['class' => 'text-center col-2']);

            $this->content->text .= html_writer::tag('td', $counts['assigned'],    ['class' => 'bg-success text-white text-center font-weight-bold col-2']);
            $this->content->text .= html_writer::tag('td', $counts['in_progress'], ['class' => 'bg-warning text-white text-center font-weight-bold col-2']);
            $this->content->text .= html_writer::tag('td', $counts['completed'],   ['class' => 'bg-primary text-white text-center font-weight-bold col-2']);
            $this->content->text .= html_writer::tag('td', $counts['failed'],      ['class' => 'bg-danger  text-white text-center font-weight-bold col-2']);
            $this->content->text .= html_writer::end_tag('tr');

            // ---------------------------------------------------------------
            // ASSIGN MODAL — Building the string to append LATER
            // ---------------------------------------------------------------
            if ($token) {

                // --- NEW LOGIC: Phone Requirement ---
                $is_phone_required_course = in_array($counts['course_id'], [13, 15]);
                $phone_required_attr = $is_phone_required_course ? 'required' : '';
                $phone_label_asterisk = $is_phone_required_course ? ' <span class="text-danger">*</span>' : '';
                $needs_phone_for_myself = $is_phone_required_course && empty($USER->phone1);

                if ($needs_phone_for_myself) {
                    $enroll_myself_action = "showEnrollMyselfForm({$token->id})";
                    $myself_phone_field = '
                        <div id="myselfPhoneGroup' . $token->id . '" class="form-group mb-2 mt-3 text-start">
                            <p>Please provide your phone number to continue enrollment for this course.</p>
                            <label for="myselfPhone' . $token->id . '" class="fw-bold">Phone number <span class="text-danger">*</span></label>
                            <input type="tel" class="form-control" id="myselfPhone' . $token->id . '" name="phone_number" required>
                        </div>';
                } else {
                    $enroll_myself_action = "enrollMyself({$token->id})";
                    $myself_phone_field = '';
                }
                // ------------------------------------

                $modals_html .= '
                <div class="modal fade" id="assignModal' . $counts['course_id'] . '" tabindex="-1" role="dialog"
                     aria-labelledby="assignModalLabel' . $counts['course_id'] . '" aria-hidden="true">
                    <div class="modal-dialog" role="document">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="assignModalLabel' . $counts['course_id'] . '">
                                    Use token for ' . ucwords(strtolower($token->course_name)) . '
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <div id="initialOptions' . $token->id . '">
                                    <div class="d-flex justify-content-between">
                                        <button type="button" class="btn btn-primary"
                                                onclick="' . $enroll_myself_action . '">Enroll Yourself</button>
                                        <button type="button" class="btn btn-success"
                                                onclick="showEnrollForm(' . $token->id . ')">Enroll Somebody Else</button>
                                    </div>
                                </div>

                                <form id="enrollForm' . $token->id . '" action="' . $use_token_url->out() . '" method="POST">
                                    <div id="firstNameGroup' . $token->id . '" class="form-group mb-2 d-none text-start">
                                        <label for="firstName' . $token->id . '" class="fw-bold">First name</label>
                                        <input type="text" class="form-control" id="firstName' . $token->id . '" name="first_name" required>
                                    </div>
                                    <div id="lastNameGroup' . $token->id . '" class="form-group mb-2 d-none text-start">
                                        <label for="lastName' . $token->id . '" class="fw-bold">Last name</label>
                                        <input type="text" class="form-control" id="lastName' . $token->id . '" name="last_name" required>
                                    </div>
                                    <div id="emailGroup' . $token->id . '" class="form-group mb-2 d-none text-start">
                                        <label for="emailAddress' . $token->id . '" class="fw-bold">Email address</label>
                                        <input type="email" class="form-control" id="emailAddress' . $token->id . '" name="email" required>
                                    </div>
                                    <div id="addressGroup' . $token->id . '" class="form-group mb-2 d-none text-start">
                                        <label for="address' . $token->id . '" class="fw-bold">Address</label>
                                        <input type="text" class="form-control" id="address' . $token->id . '" name="address">
                                    </div>
                                    <div id="phoneGroup' . $token->id . '" class="form-group mb-2 d-none text-start">
                                        <label for="phone' . $token->id . '" class="fw-bold">Phone number' . $phone_label_asterisk . '</label>
                                        <input type="tel" class="form-control" id="phone' . $token->id . '" name="phone_number" ' . $phone_required_attr . '>
                                    </div>
                                    <input type="hidden" name="token_code" value="' . $token->code . '">
                                </form>

                                <form id="enrollMyselfForm' . $token->id . '" class="d-none">
                                    <input type="hidden" name="token_code" value="' . $token->code . '">
                                    ' . $myself_phone_field . '
                                </form>
                            </div>
                            <div class="modal-footer">
                                <div id="initialFooter' . $token->id . '">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                </div>
                                <div id="enrollFormFooter' . $token->id . '" class="d-none">
                                    <button type="button" class="btn btn-success"
                                            onclick="submitEnrollForm(' . $token->id . ', \'other\')">Enroll</button>
                                    <button type="button" class="btn btn-secondary"
                                            onclick="cancelEnrollForm(' . $token->id . ')">Cancel</button>
                                </div>
                                <div id="enrollMyselfFooter' . $token->id . '" class="d-none">
                                    <button type="button" class="btn btn-success"
                                            onclick="submitEnrollForm(' . $token->id . ', \'myself\')">Enroll</button>
                                    <button type="button" class="btn btn-secondary"
                                            onclick="cancelEnrollMyselfForm(' . $token->id . ')">Cancel</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>';
            }
        }

        $this->content->text .= html_writer::end_tag('tbody');
        $this->content->text .= html_writer::end_tag('table');

        // APPEND MODALS HERE (Outside the table boundaries)
        $this->content->text .= $modals_html;

        // Link to the full token list
        $this->content->text .= html_writer::tag('a', 'View individual tokens', [
            'href' => (new moodle_url('/enrol/course_tokens/view_tokens.php'))->out(),
        ]);

        // -------------------------------------------------------------------
        // RECERTIFICATION WARNING MODAL (shared, single instance per page)
        // JavaScript populates it with the correct message before showing it.
        // -------------------------------------------------------------------
        $this->content->text .= '
        <div class="modal fade" id="recertWarningModal" tabindex="-1" aria-labelledby="recertWarningModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-0 shadow-lg">
                    <div class="modal-header text-white" id="recertWarningModalHeader"
                         style="border-radius:.375rem .375rem 0 0;">
                        <h5 class="modal-title fw-bold" id="recertWarningModalLabel">
                            <span id="recertWarningIcon" class="me-2"></span>
                            <span id="recertWarningTitle"></span>
                        </h5>
                    </div>
                    <div class="modal-body py-4 px-4">
                        <p id="recertWarningMessage" class="mb-0"
                           style="white-space:pre-line;line-height:1.6;"></p>
                    </div>
                    <div class="modal-footer border-top-0 pt-0">
                        <button type="button" class="btn btn-outline-secondary px-4"
                                data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn px-4 fw-semibold" id="recertConfirmBtn"
                                style="background:#d9534f;color:#fff;">
                            Yes, use token &amp; reset progress
                        </button>
                    </div>
                </div>
            </div>
        </div>';

        // -------------------------------------------------------------------
        // JAVASCRIPT
        // -------------------------------------------------------------------
        $use_token_url_str = $use_token_url->out(false);

        $this->content->text .= '
        <script>
        (function () {
            "use strict";

            // ---------------------------------------------------------------
            // UI toggle helpers (show/hide forms)
            // ---------------------------------------------------------------
            window.enrollMyself = (tokenId) => submitEnrollForm(tokenId, "myself");

            window.toggleElementVisibility = (id, hide = true) => {
                const el = document.getElementById(id);
                if (el) el.classList.toggle("d-none", hide);
            };

            // "Enroll Somebody Else" 
            window.showEnrollForm = (tokenId) => {
                toggleElementVisibility("initialOptions"   + tokenId);
                toggleElementVisibility("initialFooter"    + tokenId);
                ["firstNameGroup","lastNameGroup","emailGroup","addressGroup","phoneGroup"]
                    .forEach(p => toggleElementVisibility(p + tokenId, false));
                toggleElementVisibility("enrollFormFooter" + tokenId, false);
            };

            window.cancelEnrollForm = (tokenId) => {
                toggleElementVisibility("initialOptions"   + tokenId, false);
                toggleElementVisibility("initialFooter"    + tokenId, false);
                ["firstNameGroup","lastNameGroup","emailGroup","addressGroup","phoneGroup"]
                    .forEach(p => toggleElementVisibility(p + tokenId));
                toggleElementVisibility("enrollFormFooter" + tokenId);
            };

            // "Enroll Myself" (When Phone Input is Required)
            window.showEnrollMyselfForm = (tokenId) => {
                toggleElementVisibility("initialOptions"     + tokenId);
                toggleElementVisibility("initialFooter"      + tokenId);
                toggleElementVisibility("enrollMyselfForm"   + tokenId, false);
                toggleElementVisibility("enrollMyselfFooter" + tokenId, false);
            };

            window.cancelEnrollMyselfForm = (tokenId) => {
                toggleElementVisibility("initialOptions"     + tokenId, false);
                toggleElementVisibility("initialFooter"      + tokenId, false);
                toggleElementVisibility("enrollMyselfForm"   + tokenId);
                toggleElementVisibility("enrollMyselfFooter" + tokenId);
            };

            // ---------------------------------------------------------------
            // submitEnrollForm(tokenId, type, confirmRenewal)
            //   tokenId        — numeric token row ID
            //   type           — "myself" | "other"
            //   confirmRenewal — 0 (default) | 1 (user confirmed recert modal)
            // ---------------------------------------------------------------
            window.submitEnrollForm = async function (tokenId, type, confirmRenewal) {
                type           = type           || "other";
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
                        showAlertBanner("Please fill out all required fields.", "danger");
                    }
                    return;
                }

                const formData = new FormData(form);
                if (confirmRenewal) {
                    formData.set("confirm_renewal", "1");
                }

                // BEST PRACTICE: Always prefix AJAX requests with M.cfg.wwwroot
                const targetUrl = M.cfg.wwwroot + "/enrol/course_tokens/use_token.php";

                try {
                    const resp = await fetch(targetUrl, {
                        method : "POST",
                        body   : formData
                    });
                    const text = await resp.text();
                    console.log("[course_tokens] use_token.php raw response:", text);
                    let data;
                    try {
                        data = JSON.parse(text);
                    } catch (parseErr) {
                        console.error("[course_tokens] JSON parse failed. Raw server output:", text);
                        showAlertBanner("Server returned an unexpected response. Check the browser console (F12) for details.", "danger");
                        return;
                    }
                    handleResponse(data, tokenId, type);
                } catch (networkErr) {
                    console.error("[course_tokens] fetch() network error:", networkErr);
                    showAlertBanner("A network error occurred. Please check your connection and try again.", "danger");
                }
            };

            // ---------------------------------------------------------------
            // handleResponse — routes JSON reply from use_token.php
            // ---------------------------------------------------------------
            function handleResponse(data, tokenId, type) {
                switch (data.status) {
                    case "redirect":
                        showAlertBanner(data.message || "Enrolment successful!", "success");
                        setTimeout(() => { window.location.href = data.redirect_url; }, 1800);
                        break;

                    case "success":
                        showAlertBanner(data.message || "Enrolment successful!", "success");
                        setTimeout(() => location.reload(), 1800);
                        break;

                    case "confirm_early_renewal":
                        // Amber warning: certificate still valid > 90 days
                        showRecertModal({
                            title       : "⚠ Early Renewal Warning",
                            message     : data.message,
                            headerColor : "#f0ad4e",
                            tokenId     : tokenId,
                            enrollType  : type
                        });
                        break;

                    case "confirm_renewal":
                        // Red warning: certificate expiring soon or already expired
                        showRecertModal({
                            title       : "🔄 Reset Progress & Recertify",
                            message     : data.message,
                            headerColor : "#d9534f",
                            tokenId     : tokenId,
                            enrollType  : type
                        });
                        break;

                    case "error":
                    default:
                        showAlertBanner(data.message || "An unexpected error occurred.", "danger");
                        setTimeout(() => location.reload(), 3000);
                        break;
                }
            }

            // ---------------------------------------------------------------
            // Bootstrap modal helpers — compatible with BS5 global, BS4/jQuery,
            // and Moodle themes that expose neither as a plain global.
            // ---------------------------------------------------------------
            function pmtModalShow(el) {
                if (typeof bootstrap !== "undefined" && bootstrap.Modal) {
                    new bootstrap.Modal(el, { backdrop: "static", keyboard: false }).show();
                } else if (typeof jQuery !== "undefined") {
                    jQuery(el).modal({ backdrop: "static", keyboard: false });
                    jQuery(el).modal("show");
                }
            }
            function pmtModalHide(el) {
                if (typeof bootstrap !== "undefined" && bootstrap.Modal) {
                    const m = bootstrap.Modal.getInstance(el);
                    if (m) m.hide();
                } else if (typeof jQuery !== "undefined") {
                    jQuery(el).modal("hide");
                }
            }

            // ---------------------------------------------------------------
            // showRecertModal — populates & opens the shared Bootstrap modal
            // ---------------------------------------------------------------
            function showRecertModal(opts) {
                const modalEl    = document.getElementById("recertWarningModal");
                const headerEl   = document.getElementById("recertWarningModalHeader");
                const titleEl    = document.getElementById("recertWarningTitle");
                const iconEl     = document.getElementById("recertWarningIcon");
                const messageEl  = document.getElementById("recertWarningMessage");
                const confirmBtn = document.getElementById("recertConfirmBtn");

                if (!modalEl) return;

                headerEl.style.backgroundColor = opts.headerColor || "#d9534f";
                iconEl.textContent             = "";              // icon already in title string
                titleEl.textContent            = opts.title;
                messageEl.textContent          = opts.message;

                // Replace button to avoid stacking event listeners
                const newBtn = confirmBtn.cloneNode(true);
                confirmBtn.parentNode.replaceChild(newBtn, confirmBtn);
                newBtn.addEventListener("click", function () {
                    pmtModalHide(modalEl);
                    submitEnrollForm(opts.tokenId, opts.enrollType, 1);
                });

                pmtModalShow(modalEl);
            }

            // ---------------------------------------------------------------
            // showAlertBanner — non-blocking in-page notification
            // ---------------------------------------------------------------
            function showAlertBanner(msg, type) {
                let container = document.getElementById("pmt-block-alert-container");
                if (!container) {
                    container = document.createElement("div");
                    container.id = "pmt-block-alert-container";
                    container.style.cssText = "position:fixed;top:1rem;right:1rem;z-index:9999;min-width:300px;";
                    document.body.appendChild(container);
                }
                const alertDiv = document.createElement("div");
                alertDiv.className = `alert alert-${type} alert-dismissible fade show shadow`;
                alertDiv.role      = "alert";
                alertDiv.innerHTML = escHtml(msg) +
                    `<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>`;
                container.appendChild(alertDiv);
                setTimeout(() => alertDiv.remove(), 6000);
            }

            function escHtml(str) {
                const d = document.createElement("div");
                d.appendChild(document.createTextNode(str || ""));
                return d.innerHTML;
            }
        }());
        </script>';

        return $this->content;
    }
}