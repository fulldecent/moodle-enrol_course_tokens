<?php

defined('MOODLE_INTERNAL') || die();

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

        // SQL query to fetch tokens associated with the logged-in user
        $sql = "SELECT t.*, u.email as enrolled_user_email
                FROM {course_tokens} t
                LEFT JOIN {user_enrolments} ue ON t.user_enrolments_id = ue.id
                LEFT JOIN {user} u ON ue.userid = u.id
                WHERE t.user_id = ?";

        // Execute the SQL query and get the tokens
        $tokens = $DB->get_records_sql($sql, [$USER->id]);

        // Ensure that content is initialized
        if (empty($this->content)) {
            $this->content = new stdClass();
            $this->content->text = '';
            $this->content->footer = '';
        }

        // Initialize an array to store course data and token counts
        $course_data = [];

        foreach ($tokens as $token) {
            // Fetch course details
            $course = $DB->get_record('course', ['id' => $token->course_id], 'fullname');
            $course_name = $course ? $course->fullname : 'Unknown Course';

            // Initialize course data if not already set
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

            // Fetch the user ID based on the token's used_by field (email)
            $user = $DB->get_record('user', ['email' => $token->used_by], 'id');
            $user_id = $user ? $user->id : null;

            // Determine token status based on conditions
            if ($user_id) {
                // Check if the user has viewed the course
                $has_viewed_course = $DB->record_exists('logstore_standard_log', [
                    'eventname' => '\core\event\course_viewed',
                    'contextinstanceid' => $token->course_id,
                    'userid' => $user_id
                ]);

                // Fetch the completion record for the user and course
                $completion = $DB->get_record('course_completions', ['userid' => $user_id, 'course' => $token->course_id], 'timecompleted');

                // Fetch the exam grade (if any) for the user in the course
                $exam = $DB->get_record('quiz', ['course' => $token->course_id, 'name' => 'Exam'], 'id, grade');
                $exam_grade = null;
                if ($exam) {
                    $exam_grade = $DB->get_record('quiz_grades', ['quiz' => $exam->id, 'userid' => $user_id], 'grade');
                }

                // Update the course data based on status
                if ($completion && $completion->timecompleted) {
                    $course_data[$course_name]['completed']++;
                } elseif ($exam_grade && $exam_grade->grade < 0.84 * $exam->grade) {
                    $course_data[$course_name]['failed']++;
                } elseif ($has_viewed_course) {
                    $course_data[$course_name]['in_progress']++;
                } else {
                    $course_data[$course_name]['assigned']++;
                }
            } else {
                // If the token is not used (no user ID), mark as available
                $course_data[$course_name]['available']++;
            }
        }

        // Add the custom style to the block's content
        $this->content->text .= html_writer::tag('style', '
        .table th, .table td {
            vertical-align: middle; /* Correct way to vertically center content */
        }
        ');

        // Add the information message below the block title
        $this->content->text .= html_writer::tag('p', 
        'You can enroll yourself or somebody else from your available inventory of courses. Please click the ASSIGN button below.', 
        ['class' => 'alert alert-info']
        );
        // Output the data in the block
        $this->content->text .= html_writer::start_tag('table', array('class' => 'table table-striped table-hover'));

        // Table headers
        $this->content->text .= html_writer::start_tag('thead');
        $this->content->text .= html_writer::start_tag('tr');
        $this->content->text .= html_writer::tag('th', 'Course');
        $this->content->text .= html_writer::tag('th', 'Available inventory');
        $this->content->text .= html_writer::tag('th', 'Assigned');
        $this->content->text .= html_writer::tag('th', 'In progress');
        $this->content->text .= html_writer::tag('th', 'Completed');
        $this->content->text .= html_writer::tag('th', 'Failed');
        $this->content->text .= html_writer::end_tag('tr');
        $this->content->text .= html_writer::end_tag('thead');

        $this->content->text .= html_writer::start_tag('tbody');

        // Loop through course data and display counts
        foreach ($course_data as $course_name => $counts) {
            $this->content->text .= html_writer::start_tag('tr');
            $this->content->text .= html_writer::tag('td', format_string($course_name));

            // Available Inventory with an Assign Button
            if ($counts['available'] > 0) {
                $assign_button = html_writer::tag('button', 'Assign', [
                    'class' => 'btn btn-success ml-2',
                    'data-toggle' => 'modal',
                    'data-target' => '#assignModal' . $counts['course_id']
                ]);
            } else {
                $assign_button = '';
            }
            $this->content->text .= html_writer::tag('td', $counts['available'] . $assign_button);

            // Assigned column with bg-success and text-white
            $this->content->text .= html_writer::tag('td', $counts['assigned'], [
                'class' => 'bg-success text-white text-center font-weight-bold'
            ]);

            // In Progress column with bg-warning and text-white
            $this->content->text .= html_writer::tag('td', $counts['in_progress'], [
                'class' => 'bg-warning text-white text-center font-weight-bold'
            ]);

            // Completed column with bg-primary and text-white
            $this->content->text .= html_writer::tag('td', $counts['completed'], [
                'class' => 'bg-primary text-white text-center font-weight-bold'
            ]);

            // Failed column with bg-danger and text-white
            $this->content->text .= html_writer::tag('td', $counts['failed'], [
                'class' => 'bg-danger text-white text-center font-weight-bold'
            ]);

            $this->content->text .= html_writer::end_tag('tr');

            // Fetch available tokens for the specific course ID
            $sql = "SELECT t.code, c.fullname as course_name, t.course_id, t.id
                    FROM {course_tokens} t
                    JOIN {course} c ON t.course_id = c.id
                    WHERE t.user_id = ? AND t.user_enrolments_id IS NULL AND t.course_id = ?";
            $available_tokens = $DB->get_records_sql($sql, [$USER->id, $counts['course_id']]);

            // Get the first available token (if exists)
            $token = reset($available_tokens); // Get the first token in the list (if any)

            // Check if a token is available for the course
            if ($token) {
                // Generate the modal content with the available token
                $this->content->text .= '
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
            }
        }

        $this->content->text .= html_writer::end_tag('tbody');
        $this->content->text .= html_writer::end_tag('table');

        // Add link to view individual tokens
        $this->content->text .= html_writer::tag('a', 'View individual tokens', [
            'href' => '/enrol/course_tokens/view_tokens.php',
        ]);


        // Add the form and AJAX functionality
        $this->content->text .= '
        <script>
            function submitEnrollForm(tokenId) {
                var form = document.getElementById("enrollForm" + tokenId);
                if (!form.checkValidity()) {
                    alert("Please fill out all required fields.");
                    return;
                }
                var formData = new FormData(form);

                fetch("/enrol/course_tokens/use_token.php", {
                    method: "POST",
                    body: formData
                })
                .then(response => response.text())
                .then(data => {
                    alert("Enrollment successful");
                    location.reload();
                })
                .catch(error => {
                    alert("An error occurred while processing the enrollment.");
                });
            }
        </script>';

        return $this->content;
    }
}