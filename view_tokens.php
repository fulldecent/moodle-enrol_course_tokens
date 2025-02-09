<?php
require_once('../../config.php');
global $DB, $USER, $PAGE, $OUTPUT;

// Ensure the user is logged in
require_login();

// Set the URL of the page
$PAGE->set_url(new moodle_url('/enrol/course_tokens/view_tokens.php'));
$PAGE->set_context(context_user::instance($USER->id));
$PAGE->set_title('My course tokens');
$PAGE->set_heading('My course tokens');

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
    // Start a Bootstrap-styled table
    echo html_writer::start_tag('table', array('class' => 'table table-striped table-hover'));
    echo html_writer::start_tag('thead');
    echo html_writer::start_tag('tr');
    echo html_writer::tag('th', 'Token code');
    echo html_writer::tag('th', 'Course');
    echo html_writer::tag('th', 'Status');
    echo html_writer::tag('th', 'Used by');
    echo html_writer::tag('th', 'Used on');
    echo html_writer::tag('th', 'Enroll myself');
    echo html_writer::tag('th', 'Enroll somebody else');
    echo html_writer::tag('th', 'eCard');
    echo html_writer::end_tag('tr');
    echo html_writer::end_tag('thead');
    echo html_writer::start_tag('tbody');

    foreach ($tokens as $token) {
        // Fetch course details
        $course = $DB->get_record('course', ['id' => $token->course_id], 'fullname');
        $course_name = $course ? $course->fullname : 'Unknown Course';
    
        // Get the user ID based on the email from the token
        $user = $DB->get_record('user', ['email' => $token->used_by], 'id');
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
        $used_by = !empty($token->used_by) ? $token->used_by : '-';
        $used_on = !empty($token->used_on) ? date('Y-n-j', $token->used_on) : '-';

        // Render table row
        echo html_writer::start_tag('tr');
        echo html_writer::tag('td', format_string($token->code));
        echo html_writer::tag('td', format_string($course_name));
        echo html_writer::tag('td', format_string($status), array('class' => $status_class)); // Apply Bootstrap class for status
        // Add "Used By" details if the token has been used with phone number and address
        if ($user_id) {
            // Fetch user's phone number and address
            $user_details = $DB->get_record('user', ['id' => $user_id], 'phone1, address');
            $phone = !empty($user_details->phone1) ? $user_details->phone1 : 'N/A';
            $address = !empty($user_details->address) ? $user_details->address : 'N/A';
        
            // Render the clickable "Used by" text
            $modal_trigger = html_writer::tag('a', format_string($used_by), [
                'href' => '#',
                'data-toggle' => 'modal',
                'data-target' => '#userModal' . $user_id,
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
        echo html_writer::tag('td', $used_on);

        // Show "Enroll Myself" and "Enroll Somebody Else" buttons for available tokens
        if ($status === 'Available') {
            $use_token_url = new moodle_url('/enrol/course_tokens/use_token.php', ['token_code' => $token->code]);
            $use_button = html_writer::tag('a', 'Enroll Myself', array(
                'href' => $use_token_url->out(),
                'class' => 'btn btn-primary'
            ));
            echo html_writer::tag('td', $use_button);

            // Add "Enroll Somebody Else" button with modal trigger
            $share_button = html_writer::tag('button', 'Enroll Somebody Else', array(
                'class' => 'btn btn-secondary',
                'data-toggle' => 'modal',
                'data-target' => '#enrollModal' . $token->id
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
                                    <input type="text" class="form-control" id="address<?php echo $token->id; ?>" name="address" required>
                                </div>
                                <div class="form-group">
                                    <label for="phone<?php echo $token->id; ?>">Phone number</label>
                                    <input type="tel" class="form-control" id="phone<?php echo $token->id; ?>" name="phone_number" required>
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
            // Get the latest certificate (eCard) issue for this user in this course
            $certificate = $DB->get_record_sql("
                SELECT ci.id, ci.code, ci.customcertid, ci.userid
                FROM {customcert_issues} ci
                JOIN {customcert} c ON ci.customcertid = c.id
                WHERE ci.userid = :userid AND c.course = :courseid AND (c.name = 'Completion eCard' OR c.name = 'Cognitive eCard')
                ORDER BY ci.id DESC
                LIMIT 1",
                ['userid' => $user_id, 'courseid' => $token->course_id]
            );
        
            if ($certificate && !empty($certificate->code)) {
                // Generate the verification URL using the 'code' field
                $certificate_url = new moodle_url('/mod/customcert/verify_certificate.php', ['code' => $certificate->code]);
        
                // Convert to string and ensure & is used instead of &amp;
                $url_string = str_replace('&amp;', '&', $certificate_url->out());
        
                // Create the eCard button
                $ecard_button = html_writer::tag('a', 'View eCard', [
                    'href' => $url_string,  // Use the modified URL string
                    'class' => 'btn btn-success',
                    'target' => '_blank'
                ]);
            } else {
                $ecard_button = html_writer::tag('span', 'No eCard available', ['class' => 'text-muted']);
            }
        
            echo html_writer::tag('td', $ecard_button);
            echo html_writer::end_tag('tr');
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
    const submitEnrollForm = (tokenId) => {
    const form = document.getElementById(`enrollForm${tokenId}`);
    
    // Check if form is valid before submitting
    if (!form.checkValidity()) {
        alert("Please fill out all required fields.");
        return; // Do not submit if the form is invalid
    }
    
    const formData = new FormData(form);

    // Send the form data via AJAX
    fetch("' . $use_token_url->out(false) . '", {
        method: "POST",
        body: formData
    })
    .then(response => response.json()) // Parse JSON response
    .then(data => {
        // Check if the response status is success
        if (data.status === "success") {
            alert("Enrollment successful");
            location.reload(); // Reload page to reflect changes
        } else {
            // Handle error message from the server
            alert(data.message || "An error occurred during enrollment.");
            location.reload(); // Reload page after error alert
        }
    })
    .catch(error => {
        console.error("Error:", error);
        alert("An error occurred while processing the enrollment.");
        location.reload(); // Reload page after error alert
    });
};
</script>
';
?>
