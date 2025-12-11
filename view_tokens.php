<?php
require_once('../../config.php');
// Required for generating public URL for certificates
require_once($CFG->dirroot . '/mod/customcert/lib.php');
require_once($CFG->dirroot . '/local/mts_hacks/lib.php');
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
$sql = "SELECT t.*, u.email as enrolled_user_email
        FROM {course_tokens} t
        LEFT JOIN {user_enrolments} ue ON t.user_enrolments_id = ue.id
        LEFT JOIN {user} u ON ue.userid = u.id
        WHERE t.user_id = ? AND t.voided_at IS NULL
        ORDER BY t.id DESC";

$tokens = $DB->get_records_sql($sql, [$USER->id]);

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
    echo html_writer::tag('th', 'Enroll myself');
    echo html_writer::tag('th', 'Enroll somebody else');
    echo html_writer::tag('th', 'eCard');
    echo html_writer::tag('th', 'Forward eCard');
    echo html_writer::end_tag('tr');
    echo html_writer::end_tag('thead');
    echo html_writer::start_tag('tbody');

    foreach ($tokens as $token) {
        // Fetch course details
        $course = $DB->get_record('course', ['id' => $token->course_id], 'fullname');
        $course_name = $course ? $course->fullname : 'Unknown Course';

        $user = null;
        if (!empty($token->user_enrolments_id)) {
            $enrolment = $DB->get_record('user_enrolments', ['id' => $token->user_enrolments_id], 'userid');
            if ($enrolment) {
                // 👇 include email in the SELECT fields
                $user = $DB->get_record('user', ['id' => $enrolment->userid],
                'id, email, firstname, lastname, firstnamephonetic, lastnamephonetic, middlename, alternatename, phone1, address');
            }
        }
        $user_id = $user ? $user->id : null;

        // Determine token status
        if ($user_id) {
            // Check if the user has viewed the course
            $has_viewed_course = $DB->record_exists('logstore_standard_log', [
                'eventname' => '\core\event\course_viewed',
                'contextinstanceid' => $token->course_id,
                'userid' => $user_id
            ]);

            // Fetch course completion details
            $completion = $DB->get_record('course_completions', ['userid' => $user_id, 'course' => $token->course_id], 'timecompleted');

            // Fetch the Exam for the course
            $exam = $DB->get_record('quiz', ['course' => $token->course_id, 'name' => 'Exam'], 'id, grade');
            $exam_grade = null;
            if ($exam) {
                // Fetch the user's grade for the Exam
                $exam_grade = $DB->get_record('quiz_grades', ['quiz' => $exam->id, 'userid' => $user_id], 'grade');
            }

            if (!empty($completion) && !empty($completion->timecompleted) && $completion->timecompleted > 0) {
                $status = 'Completed';
                $status_class = 'bg-primary text-white';
            } elseif ($exam_grade && $exam_grade->grade < 0.84 * $exam->grade) {
                $status = 'Failed';
                $status_class = 'bg-danger text-white';
            } elseif ($has_viewed_course) {
                $status = 'In-progress';
                $status_class = 'bg-warning text-dark';
            } else {
                $status = 'Assigned';
                $status_class = 'bg-success text-white';
            }
        } elseif (!empty($token->used_on)) {
            $status = 'Assigned';
            $status_class = 'bg-success text-white';
        } else {
            $status = 'Available';
            $status_class = 'bg-secondary';
        }

        // Prepare "Used By" and "Used On" fields for display
        $used_by = $user ? $user->email : '-';
        $used_on = !empty($token->used_on) ? date('Y-m-d', $token->used_on) : '-';

        // Render table row
        echo html_writer::start_tag('tr');
        echo html_writer::tag('td', format_string($token->code));
        echo html_writer::tag('td', format_string($course_name));
        echo html_writer::tag('td', format_string($status), array('class' => $status_class)); // Apply Bootstrap class for status
        // Add "Used By" details if the token has been used with phone number and address
        if ($user_id) {
            // Fetch user's phone number and address
            $phone = !empty($user->phone1) ? $user->phone1 : 'N/A';
            $address = !empty($user->address) ? $user->address : 'N/A';

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
                            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                        <div class="modal-body">
                            <p><strong>Name:</strong> ' . fullname($user) . '</p>
                            <p><strong>Email:</strong> ' . format_string($user->email) . '</p>
                            <p><strong>Phone Number:</strong> ' . format_string($phone) . '</p>
                            <p><strong>Address:</strong> ' . format_string($address) . '</p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
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

        // Show "Enroll Myself" and "Enroll Somebody Else" buttons for available tokens
        if ($status === 'Available') {
            // Enroll Myself Form and Button
            $use_token_url = new moodle_url('/enrol/course_tokens/use_token.php');
            echo '
            <td>
                <form id="enrollMyselfForm' . $token->id . '">
                    <input type="hidden" name="token_code" value="' . $token->code . '">
                    <button type="button" class="btn btn-primary" onclick="submitEnrollForm(' . $token->id . ', \'myself\')">Enroll Myself</button>
                </form>
            </td>';

            // Enroll Somebody Else Button (modified to use same function)
            $share_button = html_writer::tag('button', 'Enroll Somebody Else', array(
                'class' => 'btn btn-secondary',
                'data-bs-toggle' => 'modal',
                'data-bs-target' => '#enrollModal' . $token->id
            ));
            echo html_writer::tag('td', $share_button);

            // Render the modal
            echo '
            <div class="modal fade" id="enrollModal' . $token->id . '" tabindex="-1" role="dialog" aria-labelledby="enrollModalLabel" aria-hidden="true">
                <div class="modal-dialog" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="enrollModalLabel">Enroll Somebody Else</h5>
                            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                        <div class="modal-body">
                            <form id="enrollForm' . $token->id . '">
                                <div class="form-group">
                                    <label for="firstName">First name</label>
                                    <input type="text" class="form-control" id="firstName' . $token->id . '" name="first_name" required>
                                </div>
                                <div class="form-group">
                                    <label for="lastName">Last name</label>
                                    <input type="text" class="form-control" id="lastName' . $token->id . '" name="last_name" required>
                                </div>
                                <div class="form-group">
                                    <label for="emailAddress">Email address</label>
                                    <input type="email" class="form-control" id="emailAddress' . $token->id . '" name="email" required>
                                </div>
                                <div class="form-group">
                                    <label for="address<?php echo $token->id; ?>">Address</label>
                                    <input type="text" class="form-control" id="address<?php echo $token->id; ?>" name="address">
                                </div>
                                <div class="form-group">
                                    <label for="phone<?php echo $token->id; ?>">Phone number</label>
                                    <input type="tel" class="form-control" id="phone<?php echo $token->id; ?>" name="phone_number">
                                </div>
                                <input type="hidden" name="token_code" value="' . $token->code . '">
                            </form>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                            <button type="button" class="btn btn-primary" onclick="submitEnrollForm(' . $token->id . ')">Enroll</button>
                        </div>
                    </div>
                </div>
            </div>';
        } else {
            echo html_writer::tag('td', '-');
            echo html_writer::tag('td', '-');
        }
        if ($user_id) {
            // Get course name
            $course_name = $course ? $course->fullname : 'Course';
            $ecard_button = null;
            $forward_button = null;
            $public_url = null;

            // Check if course name starts with "AHA" for special handling
            if ($course && strpos($course_name, 'AHA') === 0) {
                // Special handling for AHA courses - check for specific assignment submissions

                // Get all assignment instances in the course
                $assignments = $DB->get_records('assign', ['course' => $token->course_id]);
                $file_found = false;
                $file_id = null;
                $submission_id = null;

                // First priority: Check for "Upload AHA provider eCard" submissions
                foreach ($assignments as $assignment) {
                    if ($assignment->name === 'Upload AHA provider eCard') {
                        // Get the submission for this assignment
                        $submission = $DB->get_record('assign_submission', [
                            'assignment' => $assignment->id,
                            'userid' => $user_id,
                            'status' => 'submitted'
                        ]);

                        if ($submission) {
                            // Check if there are files attached to this submission
                            $file_submission = $DB->get_record('assignsubmission_file', ['submission' => $submission->id]);

                            if ($file_submission && $file_submission->numfiles > 0) {
                                // Get the context of this assignment
                                $cm = get_coursemodule_from_instance('assign', $assignment->id, $assignment->course);
                                $context = context_module::instance($cm->id);

                                // Get the files from this submission
                                $fs = get_file_storage();
                                $files = $fs->get_area_files(
                                    $context->id,
                                    'assignsubmission_file',
                                    'submission_files',
                                    $submission->id,
                                    'filename',
                                    false
                                );

                                if (!empty($files)) {
                                    // Get the first file
                                    $file = reset($files);
                                    $file_id = $file->get_id();
                                    $submission_id = $submission->id;
                                    $file_found = true;
                                    break;
                                }
                            }
                        }
                    }
                }

                // Second priority: If no "Upload AHA provider eCard" file found, check for "Upload AHA online part 1"
                if (!$file_found) {
                    foreach ($assignments as $assignment) {
                        if ($assignment->name === 'Upload AHA online part 1') {
                            // Get the submission for this assignment
                            $submission = $DB->get_record('assign_submission', [
                                'assignment' => $assignment->id,
                                'userid' => $user_id,
                                'status' => 'submitted'
                            ]);

                            if ($submission) {
                                // Check if there are files attached to this submission
                                $file_submission = $DB->get_record('assignsubmission_file', ['submission' => $submission->id]);

                                if ($file_submission && $file_submission->numfiles > 0) {
                                    // Get the context of this assignment
                                    $cm = get_coursemodule_from_instance('assign', $assignment->id, $assignment->course);
                                    $context = context_module::instance($cm->id);

                                    // Get the files from this submission
                                    $fs = get_file_storage();
                                    $files = $fs->get_area_files(
                                        $context->id,
                                        'assignsubmission_file',
                                        'submission_files',
                                        $submission->id,
                                        'filename',
                                        false
                                    );

                                    if (!empty($files)) {
                                        // Get the first file
                                        $file = reset($files);
                                        $file_id = $file->get_id();
                                        $submission_id = $submission->id;
                                        $file_found = true;
                                        break;
                                    }
                                }
                            }
                        }
                    }
                }

                // If we found a file, generate a public URL for it
                if ($file_found && $file_id && $submission_id) {
                    // Check if our function to generate public URL exists
                    if (function_exists('local_mts_hacks_calculate_submission_file_signature')) {
                        require_once($CFG->dirroot . '/local/mts_hacks/lib.php');

                        // Generate public URL for the file
                        $token = local_mts_hacks_calculate_submission_file_signature($file_id, $submission_id);
                        $public_url = $CFG->wwwroot . '/local/mts_hacks/view_submission_file/view_submission_file.php?' .
                            'file_id=' . urlencode($file_id) .
                            '&submission_id=' . urlencode($submission_id) .
                            '&token=' . urlencode($token);

                        // Create the eCard button
                        $is_aha_online = ($assignment->name === 'Upload AHA online part 1');
                        $view_button_text = $is_aha_online ? 'View AHA online part 1' : 'View eCard';
                        $forward_button_text = $is_aha_online ? 'Forward AHA online part 1' : 'Forward eCard';
                        $ecard_button = html_writer::tag('a', $view_button_text, [
                            'href' => $public_url,
                            'class' => 'btn btn-success',
                            'target' => '_blank'
                        ]);

                        // Get user's first and last name
                        $user_fulldetails = $DB->get_record('user', ['id' => $user_id],
                        'firstname, lastname, firstnamephonetic, lastnamephonetic, middlename, alternatename');
                        $first_name = $user_fulldetails ? $user_fulldetails->firstname : '';
                        $last_name = $user_fulldetails ? $user_fulldetails->lastname : '';

                        // Construct the subject line
                        $subject = rawurlencode("Check out " . $first_name . " " . $last_name . "'s eCard for " . $course_name);

                        // Construct the body with the actual link
                        $body = rawurlencode("eCard of " . $first_name . " " . $last_name . " for the course " . $course_name . " is available at:\n\n" . $public_url);

                        // Generate the mailto link
                        $mailto_link = 'mailto:?subject=' . $subject . '&body=' . $body;

                        // Create the "Forward eCard" button
                        $forward_button = html_writer::tag('a', $forward_button_text, [
                            'href' => $mailto_link,
                            'class' => 'btn btn-primary',
                            'target' => '_blank'
                        ]);
                    } else {
                        $ecard_button = html_writer::tag('span', 'eCard feature not properly configured', ['class' => 'text-warning']);
                        $forward_button = html_writer::tag('span', 'eCard feature not properly configured', ['class' => 'text-warning']);
                    }
                } else {
                    $ecard_button = html_writer::tag('span', 'No eCard available', ['class' => 'text-muted']);
                    $forward_button = html_writer::tag('span', 'No eCard available', ['class' => 'text-muted']);
                }
            } else {
                // Standard handling for non-AHA courses (existing code)
                // Check if mod_customcert is installed before querying
                if ($DB->get_manager()->table_exists('customcert_issues')) {
                    // Get the latest certificate (eCard) issue for this user in this course
                    $certificate = $DB->get_record_sql("
                        SELECT ci.id, ci.code, ci.customcertid, ci.userid
                        FROM {customcert_issues} ci
                        JOIN {customcert} c ON ci.customcertid = c.id
                        WHERE ci.userid = :userid
                            AND c.course = :courseid
                            AND (c.name = 'Completion eCard' OR c.name = 'Cognitive eCard')
                        ORDER BY ci.id DESC
                        LIMIT 1",
                        ['userid' => $user_id, 'courseid' => $token->course_id]
                    );

                    if ($certificate && !empty($certificate->code) && function_exists('generate_public_url_for_certificate')) {
                        // Generate the public eCard URL
                        $public_url = generate_public_url_for_certificate($certificate->code);

                        // Create the eCard button
                        $ecard_button = html_writer::tag('a', 'View eCard', [
                            'href' => $public_url,
                            'class' => 'btn btn-success',
                            'target' => '_blank'
                        ]);

                        // Get user's first and last name
                        $user_fulldetails = $DB->get_record('user', ['id' => $user_id], 'firstname, lastname');
                        $first_name = $user_fulldetails ? $user_fulldetails->firstname : '';
                        $last_name = $user_fulldetails ? $user_fulldetails->lastname : '';

                        // Construct the subject line
                        $subject = rawurlencode("Check out " . $first_name . " " . $last_name . "'s eCard for " . $course_name);

                        // Construct the body with the actual link
                        $body = rawurlencode("eCard of " . $first_name . " " . $last_name . " for the course " . $course_name . " is available at:\n\n" . $public_url);

                        // Generate the mailto link
                        $mailto_link = 'mailto:?subject=' . $subject . '&body=' . $body;

                        // Create the "Forward eCard" button
                        $forward_button = html_writer::tag('a', 'Forward eCard', [
                            'href' => $mailto_link,
                            'class' => 'btn btn-primary',
                            'target' => '_blank'
                        ]);
                    } else {
                        // If the certificate is missing, or the function doesn't exist, display a placeholder
                        $ecard_button = html_writer::tag('span', 'No eCard available', ['class' => 'text-muted']);
                        $forward_button = html_writer::tag('span', 'No eCard available', ['class' => 'text-muted']);
                    }
                } else {
                    $ecard_button = html_writer::tag('span', 'eCard feature not available', ['class' => 'text-warning']);
                    $forward_button = html_writer::tag('span', 'eCard feature not available', ['class' => 'text-warning']);
                }
            }

            // Output the buttons in separate columns
            echo html_writer::tag('td', $ecard_button);
            echo html_writer::tag('td', $forward_button);
        }
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

// Add JavaScript to submit the form via AJAX
echo '
<script>
    const submitEnrollForm = (tokenId, type = "other") => {
        let form;
        if (type === "myself") {
            form = document.getElementById(`enrollMyselfForm${tokenId}`);
        } else {
            form = document.getElementById(`enrollForm${tokenId}`);

            // Check form validity for "Enroll Somebody Else"
            if (!form.checkValidity()) {
                alert("Please fill out all required fields.");
                return;
            }
        }

        const formData = new FormData(form);

        fetch("' . $use_token_url->out(false) . '", {
            method: "POST",
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === "redirect") {
                // Handle redirect for "Enroll Myself"
                alert(data.message || "You have been successfully enrolled in the course.");
                window.location.href = data.redirect_url;
            } else if (data.status === "success") {
                // Handle success for "Enroll Somebody Else"
                alert(data.message || "User successfully enrolled in the course.");
                location.reload();
            } else {
                // Handle any error case
                alert(data.message || "An error occurred during enrollment.");
                location.reload();
            }
        })
        .catch(error => {
            console.error("Error:", error);
            alert("An error occurred while processing the enrollment.");
            location.reload();
        });
    };
</script>
';
?>
