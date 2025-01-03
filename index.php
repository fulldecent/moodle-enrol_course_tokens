<?php
require_once('../../config.php');
require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/enrol/course_tokens/index.php'));
$PAGE->set_title(get_string('pluginname', 'enrol_course_tokens'));
$PAGE->set_heading(get_string('pluginname', 'enrol_course_tokens'));

// Load from databases, order tokens by creation time (newer first)
$tokens = $DB->get_records('course_tokens', null, 'timecreated DESC');
$sql = "
    SELECT c.id, c.fullname
    FROM {course} c
    JOIN {enrol} e ON e.courseid = c.id
    WHERE e.enrol = 'course_tokens'
";
$courses = $DB->get_records_sql_menu($sql, []);

// Start output
echo $OUTPUT->header();
echo '<p>' . s(get_string('introduction', 'enrol_course_tokens')) . '</p>';

echo '<form id="createTokenForm" action="do-create-token.php" method="post">';
echo '<div class="form-item row mb-3">';
// Select a course
echo '<div class="form-label col-sm-3 text-sm-right">';
echo '<label for="course_id">' . s(get_string('coursename', 'enrol_course_tokens')) . '</label>';
echo '</div>';
echo '<div class="form-setting col-sm-9">';
echo html_writer::select($courses, 'course_id', '', get_string('coursename', 'enrol_course_tokens'));
echo '</div>';
echo '</div>';
// Email
echo '<div class="form-item row mb-3">';
echo '<div class="form-label col-sm-3 text-sm-right">';
echo '<label for="email">' . s(get_string('email', 'enrol_course_tokens')) . '</label>';
echo '</div>';
echo '<div class="form-setting col-sm-9">';
echo '<input type="email" class="form-control" id="email" name="email" required>';
echo '<div id="emailError" class="text-danger small mt-1"></div>';
echo '</div>';
echo '</div>';
// First Name
echo '<div class="form-item row mb-3">';
echo '<div class="form-label col-sm-3 text-sm-right">';
echo '<label for="firstname">' . s(get_string('firstname', 'enrol_course_tokens')) . '</label>';
echo '</div>';
echo '<div class="form-setting col-sm-9">';
echo '<input type="text" class="form-control" id="firstname" name="firstname" required>';
echo '</div>';
echo '</div>';
// Last Name
echo '<div class="form-item row mb-3">';
echo '<div class="form-label col-sm-3 text-sm-right">';
echo '<label for="lastname">' . s(get_string('lastname', 'enrol_course_tokens')) . '</label>';
echo '</div>';
echo '<div class="form-setting col-sm-9">';
echo '<input type="text" class="form-control" id="lastname" name="lastname" required>';
echo '</div>';
echo '</div>';
// Corporate Account (optional)
echo '<div class="form-item row mb-3">';
echo '<div class="form-label col-sm-3 text-sm-right">';
echo '<label for="group_account">' . s(get_string('corporateaccount', 'enrol_course_tokens')) . '</label>';
echo '</div>';
echo '<div class="form-setting col-sm-9">';
echo '<input type="text" class="form-control" id="group_account" name="group_account">';
echo '</div>';
echo '</div>';
// Extra JSON
echo '<div class="form-item row mb-3">';
echo '<div class="form-label col-sm-3 text-sm-right">';
echo '<label for="extra_json">' . s(get_string('extrajson', 'enrol_course_tokens')) . '</label>';
echo '</div>';
echo '<div class="form-setting col-sm-9">';
echo '<textarea class="form-control" id="extra_json" name="extra_json" placeholder="Enter the order number here like \'1004\'"></textarea>';
echo '</div>';
echo '</div>';
// Quantity
echo '<div class="form-item row mb-3">';
echo '<div class="form-label col-sm-3 text-sm-right">';
echo '<label for="quantity">' . s(get_string('quantity', 'enrol_course_tokens')) . '</label>';
echo '</div>';
echo '<div class="form-setting col-sm-9">';
echo '<input type="number" class="form-control" id="quantity" name="quantity" min="1" required>';
echo '</div>';
echo '</div>';
// Submit
echo '<div class="form-item row mb-3">';
echo '<div class="form-label col-sm-3 text-sm-right"></div>';
echo '<div class="form-setting col-sm-9">';
echo '<input type="submit" class="btn btn-primary" value="' . s(get_string('createtokens', 'enrol_course_tokens')) . '">';
echo '</div>';
echo '</div>';
// CSRF token
echo '<input type="hidden" name="sesskey" value="' . sesskey() . '"/>';
echo '</form>';

// Show existing tokens
echo '<h2 class="my-3">' . s(get_string('existingtokens', 'enrol_course_tokens')) . '</h2>';
echo '<table class="table">';
echo '<tr>';
echo '  <th>' . s(get_string('token', 'enrol_course_tokens')) . '</th>';
echo '  <th>' . s(get_string('course', 'enrol_course_tokens')) . '</th>';
echo '  <th>' . s(get_string('createdby', 'enrol_course_tokens')) . '</th>';
echo '  <th>' . s(get_string('createdat', 'enrol_course_tokens')) . '</th>';
echo '  <th>' . s(get_string('purchaser', 'enrol_course_tokens')) . '</th>';
echo '<th>' . s(get_string('extrajson', 'enrol_course_tokens')) . '</th>';
echo '  <th>' . s(get_string('corporateaccount', 'enrol_course_tokens')) . '</th>';
echo '  <th>' . s(get_string('usedby', 'enrol_course_tokens')) . '</th>';
echo '  <th>' . s(get_string('usedat', 'enrol_course_tokens')) . '</th>';
echo '</tr>';
foreach ($tokens as $token) {
    echo '<tr>';
    echo '<td>' . s($token->code) . '</td>';
    echo '<td>' . s($courses[$token->course_id]) . '</td>';

    // Fetch user who created the token
    $creator = $DB->get_record('user', array('id' => $token->created_by), 'email');
    $created_by = $creator ? s($creator->email) : 'none';
    echo '<td>' . $created_by . '</td>';

    // Format "Created at" in ISO date format
    $created_at = date('Y-m-d', $token->timecreated);
    echo '<td>' . $created_at . '</td>';

    // Fetch purchaser (assigned to)
    $purchaser_email = $DB->get_field('user', 'email', array('id' => $token->user_id));
    echo '<td>' . s($purchaser_email) . '</td>';

    // Display Extra JSON (formatted or raw)
    $extra_json = !empty($token->extra_json) ? s($token->extra_json) : '-';
    echo '<td><pre>' . $extra_json . '</pre></td>';

    // Display the Corporate Account if available
    $group_account = !empty($token->group_account) ? s($token->group_account) : '-';
    echo '<td>' . $group_account . '</td>';

    // Fetch used by and used at
    if (!empty($token->user_enrolments_id)) {
        // Fetch the user linked to the enrollment
        $enrollment = $DB->get_record('user_enrolments', array('id' => $token->user_enrolments_id));
        $used_by_user = $DB->get_record('user', array('id' => $enrollment->userid), 'email');
        $used_by = $used_by_user ? s($used_by_user->email) : 'none';
        $used_at = date('Y-m-d', $token->used_on);
    } else {
        $used_by = '-';
        $used_at = '-';
    }
    echo '<td>' . s($used_by) . '</td>';
    echo '<td>' . s($used_at) . '</td>';
    echo '</tr>';
}
echo '</table>';

// Add JavaScript for AJAX request
echo '<script>
    document.getElementById("email").addEventListener("blur", function() {
        var email = this.value;
        if (email) {
            var xhr = new XMLHttpRequest();
            xhr.open("POST", "ajax-check-user.php", true);
            xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
            xhr.onreadystatechange = function() {
                if (xhr.readyState == 4 && xhr.status == 200) {
                    var response = JSON.parse(xhr.responseText);
                    var firstNameField = document.getElementById("firstname");
                    var lastNameField = document.getElementById("lastname");
                    var emailError = document.getElementById("emailError");

                    if (response.exists) {
                        firstNameField.value = response.firstname;
                        lastNameField.value = response.lastname;
                        firstNameField.style.borderColor = "";
                        lastNameField.style.borderColor = "";
                        emailError.textContent = "";
                    } else {
                        firstNameField.value = "New";
                        lastNameField.value = "User";
                        firstNameField.style.borderColor = "red";
                        lastNameField.style.borderColor = "red";
                        emailError.textContent = "User does not exist. Please enter first and last name.";
                    }
                }
            };
            xhr.send("email=" + encodeURIComponent(email) + "&sesskey=" + encodeURIComponent(M.cfg.sesskey));
        }
    });
</script>';

echo $OUTPUT->footer();
