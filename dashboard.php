<?php
// Include the Moodle configuration file
require_once('../../config.php');
global $DB, $USER, $PAGE, $OUTPUT;

// Ensure the user is logged in
require_login();

// Set the URL of the page and page properties
$PAGE->set_url(new moodle_url('/enrol/course_tokens/dashboard.php'));
$PAGE->set_context(context_user::instance($USER->id));
$PAGE->set_title('Course tokens dashboard');
$PAGE->set_heading('Course tokens dashboard');

// SQL query to fetch tokens associated with the logged-in user
$sql = "SELECT t.*, u.email as enrolled_user_email
        FROM {course_tokens} t
        LEFT JOIN {user_enrolments} ue ON t.user_enrolments_id = ue.id
        LEFT JOIN {user} u ON ue.userid = u.id
        WHERE t.user_id = ?";

// Execute the SQL query and get the tokens
$tokens = $DB->get_records_sql($sql, [$USER->id]);

// Initialize an array to group tokens by course and count statuses
$course_data = [];
foreach ($tokens as $token) {
    // Fetch course details for each token
    $course = $DB->get_record('course', ['id' => $token->course_id], 'fullname');
    $course_name = $course ? $course->fullname : 'Unknown Course';

    // Initialize the course data if not already set
    if (!isset($course_data[$course_name])) {
        $course_data[$course_name] = [
            'available' => 0,
            'assigned' => 0,
            'in_progress' => 0,
            'completed' => 0,
            'failed' => 0,
            'course_id' => $token->course_id // Track course ID for modals
        ];
    }

    // Get the user ID based on the email from the token
    $user = $DB->get_record('user', ['email' => $token->used_by], 'id');
    $user_id = $user ? $user->id : null;

    // Determine token status based on various conditions
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

        // Update course data based on completion, exam grade, or course view status
        if (!empty($completion) && !empty($completion->timecompleted) && $completion->timecompleted > 0) {
            $course_data[$course_name]['completed']++;
        } elseif ($exam_grade && $exam_grade->grade < 0.84 * $exam->grade) {
            $course_data[$course_name]['failed']++;
        } elseif ($has_viewed_course) {
            $course_data[$course_name]['in_progress']++;
        } else {
            $course_data[$course_name]['assigned']++;
        }
    } elseif (!empty($token->used_on)) {
        // If token is used but no user ID, mark as assigned
        $course_data[$course_name]['assigned']++;
    } else {
        // If token is not used, mark as available
        $course_data[$course_name]['available']++;
    }
}

// Render the page header
echo $OUTPUT->header();

echo html_writer::tag(
    'p', 
    'You can enroll yourself or somebody else from your available inventory of courses. Please click the ASSIGN button below.', 
    ['class' => 'alert alert-info']
);

// Display the data in a table if course data is available
if (!empty($course_data)) {
    // Start a Bootstrap-styled table
    echo html_writer::start_tag('table', array('class' => 'table table-striped table-hover table-bordered'));
    echo html_writer::start_tag('thead');
    echo html_writer::start_tag('tr');
    echo html_writer::tag('th', 'Course', ['class' => 'col-2 text-center']);
    echo html_writer::tag('th', 'Available inventory', ['class' => 'col-2 text-center']);
    echo html_writer::tag('th', 'Assigned', ['class' => 'col-2 text-center']);
    echo html_writer::tag('th', 'In-progress', ['class' => 'col-2 text-center']);
    echo html_writer::tag('th', 'Completed', ['class' => 'col-2 text-center']);
    echo html_writer::tag('th', 'Failed', ['class' => 'col-2 text-center']);
    echo html_writer::end_tag('tr');
    echo html_writer::end_tag('thead');
    echo html_writer::start_tag('tbody');

    // Loop through each course and display its status
    foreach ($course_data as $course_name => $counts) {
        echo html_writer::start_tag('tr');
        echo html_writer::tag('td', format_string($course_name), ['class' => 'text-center col-2']);

        // Available Inventory with an Assign Button
        $assign_button = '';
        if ($counts['available'] > 0) {
            $assign_button = html_writer::tag('button', 'Assign', [
                'class' => 'btn btn-success',
                'data-toggle' => 'modal',
                'data-target' => '#assignModal' . $counts['course_id']
            ]);
        }
        echo html_writer::tag('td', $counts['available'] . ' ' . $assign_button, ['class' => 'text-center col-2']);

        // Display other course status counts
        echo html_writer::tag('td', $counts['assigned'], ['class' => 'bg-success text-white text-center font-weight-bold col-2']);
        echo html_writer::tag('td', $counts['in_progress'], ['class' => 'bg-warning text-white text-center font-weight-bold col-2']);
        echo html_writer::tag('td', $counts['completed'], ['class' => 'bg-primary text-white text-center font-weight-bold col-2']);
        echo html_writer::tag('td', $counts['failed'], ['class' => 'bg-danger text-white text-center font-weight-bold col-2']);
        echo html_writer::end_tag('tr');

        // Fetch available tokens for the specific course ID
        $sql = "SELECT t.code, c.fullname as course_name, t.course_id, t.id
        FROM {course_tokens} t
        JOIN {course} c ON t.course_id = c.id
        WHERE t.user_id = ? AND t.user_enrolments_id IS NULL AND t.course_id = ?"; // Filter by course ID
        $available_tokens = $DB->get_records_sql($sql, [$USER->id, $counts['course_id']]);

        // Get the first available token (if exists)
        $token = reset($available_tokens); // Get the first token in the list (if any)

        // Check if a token is available for the course
        if ($token) {
        // Generate the modal content with the available token
        echo '
        <div class="modal fade" id="assignModal' . $counts['course_id'] . '" tabindex="-1" role="dialog" aria-labelledby="assignModalLabel' . $counts['course_id'] . '" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="assignModalLabel' . $counts['course_id'] . '">Use Token</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <form id="enrollForm' . $token->id . '" action="/enrol/course_tokens/use_token.php" method="POST">
                        <div class="form-group">
                            <label for="firstName' . $token->id . '">First Name</label>
                            <input type="text" class="form-control" id="firstName' . $token->id . '" name="first_name" required>
                        </div>
                        <div class="form-group">
                            <label for="lastName' . $token->id . '">Last Name</label>
                            <input type="text" class="form-control" id="lastName' . $token->id . '" name="last_name" required>
                        </div>
                        <div class="form-group">
                            <label for="emailAddress' . $token->id . '">Email Address</label>
                            <input type="email" class="form-control" id="emailAddress' . $token->id . '" name="email" required>
                        </div>
                        <div class="form-group">
                            <label for="address<?php echo $token->id; ?>">Address</label>
                            <input type="text" class="form-control" id="address<?php echo $token->id; ?>" name="address" required>
                        </div>
                        <div class="form-group">
                            <label for="phone<?php echo $token->id; ?>">Phone Number</label>
                            <input type="tel" class="form-control" id="phone<?php echo $token->id; ?>" name="phone_number" required>
                        </div>
                        <input type="hidden" name="token_code" value="' . $token->code . '">
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-success" onclick="submitEnrollForm(' . $token->id . ')">Use Token for ' . $token->course_name . '</button>
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
        </div>';

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
            fetch("/enrol/course_tokens/use_token.php", {
                method: "POST",
                body: formData
            })
            .then(response => {
                // Ensure we have a successful HTTP status before processing the JSON
                if (!response.ok) {
                    throw new Error("Network response was not ok");
                }
                return response.json(); // Parse the JSON response
            })
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
        </script>';
        }
    }

    // Close the table tags
    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');
    // Add link to view individual tokens
    echo html_writer::tag('a', 'View individual tokens', [
        'href' => '/enrol/course_tokens/view_tokens.php',
    ]);
} else {
    // Display a message if there is no course data available
    echo html_writer::tag('div', 'No tokens available for this user.', array('class' => 'alert alert-warning'));
}

// Render the page footer
echo $OUTPUT->footer();
?>
