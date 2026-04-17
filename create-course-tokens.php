<?php

/**
 * Dedicated Course Token Creation Form
 *
 * PURPOSE & HISTORY:
 * Originally, the token creation form and the historical table of all issued tokens 
 * were combined on a single page (index.php). As Pacific Medical Training scaled 
 * and issued thousands of tokens, index.php became a severe performance bottleneck. 
 * The heavy database load caused significant page rendering delays, which blocked 
 * administrators from being able to issue new tokens immediately—especially over 
 * slower internet connections.
 *
 * ARCHITECTURAL DECISION:
 * This file was created to decouple the token generation workflow from the historical 
 * data reporting workflow. By isolating the creation form here, we bypass the heavy 
 * N+1 database queries associated with rendering thousands of past tokens and their 
 * related user data. 
 *
 * PERFORMANCE:
 * This page executes only a single, lightweight query to fetch the allowed courses 
 * and renders instantly, ensuring the immediate operational goal of issuing a token 
 * is never delayed by historical data processing.
 *
 * @package    enrol_course_tokens
 * @copyright  Pacific Medical Training
 */

require_once('../../config.php');
require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_context(context_system::instance());
// Assuming you name this file create.php
$PAGE->set_url(new moodle_url('/enrol/course_tokens/create.php'));
$PAGE->set_title(get_string('pluginname', 'enrol_course_tokens') . ' - Create Tokens');
$PAGE->set_heading(get_string('pluginname', 'enrol_course_tokens'));

// Only fetch the courses needed for the dropdown menu
$sql = "
    SELECT c.id, c.fullname
    FROM {course} c
    JOIN {enrol} e ON e.courseid = c.id
    WHERE e.enrol = 'course_tokens'
";
$courses = $DB->get_records_sql_menu($sql, []);

// Start output
echo $OUTPUT->header();

// Optional: Add a button to go back to the token list if you separated them
echo '<div class="mb-3"><a href="index.php" class="btn btn-secondary">&larr; View Existing Tokens</a></div>';

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

// Add JavaScript for AJAX user lookup request
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