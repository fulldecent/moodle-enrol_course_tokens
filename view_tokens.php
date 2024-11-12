<?php
require_once('../../config.php');
global $DB, $USER, $PAGE, $OUTPUT;

// Ensure the user is logged in
require_login();

// Set the URL of the page
$PAGE->set_url(new moodle_url('/local/enrollment_tokens/view_tokens.php'));
$PAGE->set_context(context_user::instance($USER->id));
$PAGE->set_title('My course tokens');
$PAGE->set_heading('My course tokens');

// Fetch tokens associated with the logged-in user
$sql = "SELECT t.*, u.email as enrolled_user_email
        FROM {enrollment_tokens} t
        LEFT JOIN {user_enrolments} ue ON t.user_enrolments_id = ue.id
        LEFT JOIN {user} u ON ue.userid = u.id
        WHERE t.user_id = ?";

$tokens = $DB->get_records_sql($sql, [$USER->id]);

echo $OUTPUT->header();

echo html_writer::tag('h3', 'My course tokens', array('class' => 'mb-3'));

if (!empty($tokens)) {
    // Start a Bootstrap table
    echo html_writer::start_tag('table', array('class' => 'table table-striped table-hover'));
    echo html_writer::start_tag('thead');
    echo html_writer::start_tag('tr');
    echo html_writer::tag('th', 'Token code');
    echo html_writer::tag('th', 'Course');
    echo html_writer::tag('th', 'Status');
    echo html_writer::tag('th', 'Used by');
    echo html_writer::tag('th', 'Used on');
    echo html_writer::tag('th', 'Enroll Myself');
    echo html_writer::tag('th', 'Enroll Somebody Else');
    echo html_writer::end_tag('tr');
    echo html_writer::end_tag('thead');
    echo html_writer::start_tag('tbody');

    foreach ($tokens as $token) {
        // Fetch course details
        $course = $DB->get_record('course', ['id' => $token->course_id], 'fullname');
        $course_name = $course ? $course->fullname : 'Unknown Course';
    
        // Determine token status
        if (!empty($token->used_on)) {
            $status = 'Used';
            // Get the email saved in the used_by field
            $used_by = !empty($token->used_by) ? $token->used_by : 'N/A';
            // Format the used_on date without leading zeros in the month and day
            $used_on = date('Y-n-j', $token->used_on);
        } else {
            $status = 'Available';
            $used_by = '-';
            $used_on = '-';
        }
    
        echo html_writer::start_tag('tr');
        echo html_writer::tag('td', format_string($token->code));
        echo html_writer::tag('td', format_string($course_name));
        echo html_writer::tag('td', $status);
        echo html_writer::tag('td', format_string($used_by));
        echo html_writer::tag('td', $used_on);
    
        // Show "Enroll Myself" button only for available tokens
        if ($status === 'Available') {
            $use_token_url = new moodle_url('/local/enrollment_tokens/use_token.php', ['token_code' => $token->code]);
            $use_button = html_writer::tag('a', 'Enroll Myself', array(
                'href' => $use_token_url->out(),
                'class' => 'btn btn-primary'
            ));
            echo html_writer::tag('td', $use_button);

            // Add "Enroll Somebody Else" button with a modal trigger
            $share_button = html_writer::tag('button', 'Enroll Somebody Else', array(
                'class' => 'btn btn-secondary',
                'data-toggle' => 'modal',
                'data-target' => '#enrollModal' . $token->id // Ensure the modal ID is unique for each token
            ));
            echo html_writer::tag('td', $share_button);

            // Add the modal markup
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
                                    <label for="firstName">First Name</label>
                                    <input type="text" class="form-control" id="firstName' . $token->id . '" name="first_name" required>
                                </div>
                                <div class="form-group">
                                    <label for="lastName">Last Name</label>
                                    <input type="text" class="form-control" id="lastName' . $token->id . '" name="last_name" required>
                                </div>
                                <div class="form-group">
                                    <label for="emailAddress">Email Address</label>
                                    <input type="email" class="form-control" id="emailAddress' . $token->id . '" name="email" required>
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
            </div>
            ';
        } else {
            echo html_writer::tag('td', '-');
            echo html_writer::tag('td', '-');
        }
    
        echo html_writer::end_tag('tr');
    }

    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');

} else {
    echo html_writer::tag('p', 'No tokens available.', array('class' => 'alert alert-info'));
}

echo $OUTPUT->footer();

// Add JavaScript to submit the form via AJAX
echo '
<script>
    function submitEnrollForm(tokenId) {
        var form = document.getElementById("enrollForm" + tokenId);
        
        // Check if form is valid before submitting
        if (!form.checkValidity()) {
            alert("Please fill out all required fields.");
            return; // Do not submit if the form is invalid
        }
        
        var formData = new FormData(form);

        // Send the form data via AJAX
        fetch("' . $use_token_url->out(false) . '", {
            method: "POST",
            body: formData
        })
        .then(response => response.text())
        .then(data => {
            // Display success or error message, or handle redirect
            alert("Enrollment successful");
            location.reload();
        })
        .catch(error => {
            console.error("Error:", error);
            alert("An error occurred while processing the enrollment.");
        });
    }
</script>
';
?>
