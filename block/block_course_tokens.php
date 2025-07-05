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

        // SQL query to fetch tokens and course names
        $sql = "SELECT t.*, u.email as enrolled_user_email, c.fullname as course_name
                FROM {course_tokens} t
                LEFT JOIN {user_enrolments} ue ON t.user_enrolments_id = ue.id
                LEFT JOIN {user} u ON ue.userid = u.id
                JOIN {course} c ON t.course_id = c.id
                WHERE t.user_id = ? AND t.voided_at IS NULL
                ORDER BY t.id DESC";

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
            $course_name = $token->course_name ?: 'Unknown Course'; // Use course_name from query

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

            // Get the user ID based on the email from the token
            $user = null;
            if (!empty($token->user_enrolments_id)) {
                $enrolment = $DB->get_record('user_enrolments', ['id' => $token->user_enrolments_id], 'userid');
                if ($enrolment) {
                    // 👇 include email in the SELECT fields
                    $user = $DB->get_record('user', ['id' => $enrolment->userid], 'id, email, firstname, lastname, phone1, address');
                }
            }
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

        // Initial alert message
        $alert_message = 'You can enroll yourself or somebody else from your available inventory of courses. Please click the ASSIGN button below.';

        // Check if there are any available tokens
        $has_available_tokens = false;
        foreach ($course_data as $counts) {
            if ($counts['available'] > 0) {
                $has_available_tokens = true;
                break;
            }
        }

        // Append expiration notice if there are available tokens
        if ($has_available_tokens) {
            $alert_message .= ' Token will expire in 90 days after order if not used.';
        }

        // Add the combined alert to the block content
        $this->content->text .= html_writer::tag('p', $alert_message, ['class' => 'alert alert-info']);

        // Flag to determine if user has any in-progress courses older than the defined limit
        $has_old_in_progress = false;

        // Define the age threshold (in days) for considering a course as "old in-progress"
        $days_limit = 60;

        // Calculate the timestamp that is 60 days before now
        $old_timestamp = time() - ($days_limit * 24 * 60 * 60);

        // Loop through each course to check for old in-progress enrollments
        foreach ($course_data as $course_name => $counts) {
            // Only proceed if there are in-progress courses
            if ($counts['in_progress'] > 0) {
                // SQL query to find user enrolments older than 60 days and not voided
                $sql = "SELECT ue.id
                        FROM {user_enrolments} ue
                        JOIN {enrol} e ON e.id = ue.enrolid
                        JOIN {course_tokens} t ON t.user_enrolments_id = ue.id
                        WHERE e.courseid = ?
                        AND t.user_id = ?
                        AND ue.timecreated < ?
                        AND t.voided_at IS NULL";

                $params = [$counts['course_id'], $USER->id, $old_timestamp];

                // If at least one old in-progress course is found, set the flag and stop further checking
                if ($DB->record_exists_sql($sql, $params)) {
                    $has_old_in_progress = true;
                    break;
                }
            }
        }

        // Show alert only if the user has old in-progress courses
        if ($has_old_in_progress) {
            $this->content->text .= html_writer::tag(
                'p',
                'All courses expire 90 days after enrollment if not completed.',
                ['class' => 'alert alert-warning']
            );
        }

        // Output the data in the block
        $this->content->text .= html_writer::start_tag('table', array('class' => 'table table-striped table-hover table-bordered'));

        // Table headers
        $this->content->text .= html_writer::start_tag('thead');
        $this->content->text .= html_writer::start_tag('tr');
        $this->content->text .= html_writer::tag('th', 'Course',['class' => 'text-center col-2']);
        $this->content->text .= html_writer::tag('th', 'Available inventory',['class' => 'text-center col-2']);
        $this->content->text .= html_writer::tag('th', 'Assigned',['class' => 'text-center col-2']);
        $this->content->text .= html_writer::tag('th', 'In-progress',['class' => 'text-center col-2']);
        $this->content->text .= html_writer::tag('th', 'Completed',['class' => 'text-center col-2']);
        $this->content->text .= html_writer::tag('th', 'Failed',['class' => 'text-center col-2']);
        $this->content->text .= html_writer::end_tag('tr');
        $this->content->text .= html_writer::end_tag('thead');

        $this->content->text .= html_writer::start_tag('tbody');

        // Loop through course data and display counts
        foreach ($course_data as $course_name => $counts) {
            $this->content->text .= html_writer::start_tag('tr');
            $this->content->text .= html_writer::tag('td', format_string($course_name), ['class' => 'text-center col-2']);

            // Filter available tokens for this course from the $tokens array
            $available_tokens = array_filter($tokens, function($token) use ($counts) {
                return $token->course_id == $counts['course_id'] && $token->user_enrolments_id === null;
            });
            $token = reset($available_tokens); // Get the first available token

            if ($counts['available'] > 0 && $token) {
                $assign_button = html_writer::tag('button', 'Assign', [
                    'class' => 'btn btn-success ml-2 btn-sm',
                    'data-toggle' => 'modal',
                    'data-target' => '#assignModal' . $counts['course_id']
                ]);
            } else {
                $assign_button = '';
            }
            $this->content->text .= html_writer::tag('td', $counts['available'] . $assign_button, ['class' => 'text-center col-2']);

            // Assigned column with bg-success and text-white
            $this->content->text .= html_writer::tag('td', $counts['assigned'], [
                'class' => 'bg-success text-white text-center font-weight-bold col-2'
            ]);

            // In Progress column with bg-warning and text-white
            $this->content->text .= html_writer::tag('td', $counts['in_progress'], [
                'class' => 'bg-warning text-white text-center font-weight-bold col-2'
            ]);

            // Completed column with bg-primary and text-white
            $this->content->text .= html_writer::tag('td', $counts['completed'], [
                'class' => 'bg-primary text-white text-center font-weight-bold col-2'
            ]);

            // Failed column with bg-danger and text-white
            $this->content->text .= html_writer::tag('td', $counts['failed'], [
                'class' => 'bg-danger text-white text-center font-weight-bold col-2'
            ]);

            $this->content->text .= html_writer::end_tag('tr');

            // Generate modal if a token is available
            if ($token) {
                // Generate the modal content with the available token
                $this->content->text .= '
                <div class="modal fade" id="assignModal' . $counts['course_id'] . '" tabindex="-1" role="dialog" aria-labelledby="assignModalLabel' . $counts['course_id'] . '" aria-hidden="true">
                    <div class="modal-dialog" role="document">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="assignModalLabel' . $counts['course_id'] . '">Use token for ' . ucwords(strtolower($token->course_name)) . '</h5>
                                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                    <span aria-hidden="true">×</span>
                                </button>
                            </div>
                            <div class="modal-body">
                                <div id="initialOptions' . $token->id . '">
                                    <div class="d-flex justify-content-between">
                                        <button type="button" class="btn btn-primary" onclick="enrollMyself(' . $token->id . ')">Enroll Yourself</button>
                                        <button type="button" class="btn btn-success" onclick="showEnrollForm(' . $token->id . ')">Enroll Somebody Else</button>
                                    </div>
                                </div>
                                <!-- Form for enrolling somebody else -->
                                <form id="enrollForm' . $token->id . '" action="/enrol/course_tokens/use_token.php" method="POST">
                                    <div id="firstNameGroup' . $token->id . '" class="form-group d-none">
                                        <label for="firstName' . $token->id . '">First name</label>
                                        <input type="text" class="form-control" id="firstName' . $token->id . '" name="first_name" required>
                                    </div>
                                    <div id="lastNameGroup' . $token->id . '" class="form-group d-none">
                                        <label for="lastName' . $token->id . '">Last name</label>
                                        <input type="text" class="form-control" id="lastName' . $token->id . '" name="last_name" required>
                                    </div>
                                    <div id="emailGroup' . $token->id . '" class="form-group d-none">
                                        <label for="emailAddress' . $token->id . '">Email address</label>
                                        <input type="email" class="form-control" id="emailAddress' . $token->id . '" name="email" required>
                                    </div>
                                    <div id="addressGroup' . $token->id . '" class="form-group d-none">
                                        <label for="address' . $token->id . '">Address</label>
                                        <input type="text" class="form-control" id="address' . $token->id . '" name="address">
                                    </div>
                                    <div id="phoneGroup' . $token->id . '" class="form-group d-none">
                                        <label for="phone' . $token->id . '">Phone number</label>
                                        <input type="tel" class="form-control" id="phone' . $token->id . '" name="phone_number">
                                    </div>
                                    <input type="hidden" name="token_code" value="' . $token->code . '">
                                </form>

                                <!-- Hidden form for enrolling myself -->
                                <form id="enrollMyselfForm' . $token->id . '" class="d-none">
                                    <input type="hidden" name="token_code" value="' . $token->code . '">
                                </form>
                            </div>
                            <div class="modal-footer">
                                <div id="initialFooter' . $token->id . '">
                                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                                </div>
                                <div id="enrollFormFooter' . $token->id . '" class="d-none">
                                    <button type="button" class="btn btn-success" onclick="submitEnrollForm(' . $token->id . ', \'other\')">Enroll</button>
                                    <button type="button" class="btn btn-secondary" onclick="cancelEnrollForm(' . $token->id . ')">Cancel</button>
                                </div>
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
            const enrollMyself = (tokenId) => submitEnrollForm(tokenId, "myself");

            const toggleElementVisibility = (elementId, hide = true) => {
                const element = document.getElementById(elementId);
                if (element) {
                    element.classList.toggle("d-none", hide);
                }
            };

            const showEnrollForm = (tokenId) => {
                // Hide initial elements
                toggleElementVisibility(`initialOptions${tokenId}`);
                toggleElementVisibility(`initialFooter${tokenId}`);

                // Form group IDs
                const formGroupIds = [
                    "firstNameGroup",
                    "lastNameGroup",
                    "emailGroup",
                    "addressGroup",
                    "phoneGroup"
                ].map(prefix => `${prefix}${tokenId}`);

                // Show form groups
                formGroupIds.forEach(groupId => toggleElementVisibility(groupId, false));

                // Show enroll footer
                toggleElementVisibility(`enrollFormFooter${tokenId}`, false);
            };

            const cancelEnrollForm = (tokenId) => {
                // Show initial elements
                toggleElementVisibility(`initialOptions${tokenId}`, false);
                toggleElementVisibility(`initialFooter${tokenId}`, false);

                // Form group IDs
                const formGroupIds = [
                    "firstNameGroup",
                    "lastNameGroup",
                    "emailGroup",
                    "addressGroup",
                    "phoneGroup"
                ].map(prefix => `${prefix}${tokenId}`);

                // Hide form groups
                formGroupIds.forEach(groupId => toggleElementVisibility(groupId));

                // Hide enroll footer
                toggleElementVisibility(`enrollFormFooter${tokenId}`);
            };

            const submitEnrollForm = async (tokenId, type) => {
                const formId = type === "myself" ? `enrollMyselfForm${tokenId}` : `enrollForm${tokenId}`;
                const form = document.getElementById(formId);

                if (type === "other" && !form.checkValidity()) {
                    alert("Please fill out all required fields.");
                    return;
                }

                const formData = new FormData(form);

                try {
                    const response = await fetch("/enrol/course_tokens/use_token.php", {
                        method: "POST",
                        body: formData
                    });

                    const text = await response.text();
                    let data;

                    try {
                        data = JSON.parse(text);
                    } catch (error) {
                        alert("Unexpected response from server. Please contact at support@pacificmedicaltraining.com");
                        location.reload();
                        return;
                    }

                    if (data.status === "error" && data.message) {
                        alert(data.message);
                        location.reload();
                    } else if (data.status === "redirect" && data.redirect_url) {
                        alert(data.message);
                        window.location.href = data.redirect_url;
                    } else {
                        alert("Enrollment successful!");
                        location.reload();
                    }
                } catch (error) {
                    alert("An error occurred while processing the enrollment.");
                    location.reload();
                }
            };
        </script>';

        return $this->content;
    }
}
