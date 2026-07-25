<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * The enrol plugin course_tokens is defined here.
 *
 * @package     enrol_course_tokens
 * @copyright   2024 Pacific Medical Training <support@pacificmedicaltraining.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// The base class 'enrol_plugin' can be found at lib/enrollib.php. Override
// methods as necessary.

/**
 * Class enrol_course_tokens_plugin.
 */
$component = 'enrol_course_tokens';
defined('MOODLE_INTERNAL') || die();

// This loads the language file for your plugin.
require_once($CFG->dirroot . '/enrol/course_tokens/lang/en/enrol_course_tokens.php');
class enrol_course_tokens_plugin extends enrol_plugin
{

    // Override the get_name method to return 'course_tokens'
    public function get_name()
    {
        return 'course_tokens';
    }

    /**
     * Does this plugin allow manual enrolments?
     *
     * All plugins allowing this must implement 'enrol/course_tokens:enrol' capability.
     *
     * @param stdClass $instance Course enrol instance.
     * @return bool True means user with 'enrol/course_tokens:enrol' may enrol others freely, false means nobody may add more enrolments manually.
     */
    public function allow_enrol($instance)
    {
        return true;
    }

    /**
     * Does this plugin allow manual unenrolment of all users?
     *
     * All plugins allowing this must implement 'enrol/course_tokens:unenrol' capability.
     *
     * @param stdClass $instance Course enrol instance.
     * @return bool True means user with 'enrol/course_tokens:unenrol' may unenrol others freely, false means nobody may touch user_enrolments.
     */
    public function allow_unenrol($instance)
    {
        return false;
    }

    /**
     * Use the standard interface for adding/editing the form.
     *
     * @since Moodle 3.1.
     * @return bool.
     */
    public function use_standard_editing_ui()
    {
        return true;
    }

    /**
     * Adds form elements to add/edit instance form.
     *
     * @since Moodle 3.1.
     * @param object $instance Enrol instance or null if does not exist yet.
     * @param MoodleQuickForm $mform.
     * @param context $context.
     * @return void
     */
    public function edit_instance_form($instance, MoodleQuickForm $mform, $context)
    {
        // Do nothing by default.
    }

    /**
     * Perform custom validation of the data used to edit the instance.
     *
     * @since Moodle 3.1.
     * @param array $data Array of ("fieldname"=>value) of submitted data.
     * @param array $files Array of uploaded files "element_name"=>tmp_file_path.
     * @param object $instance The instance data loaded from the DB.
     * @param context $context The context of the instance we are editing.
     * @return array Array of "element_name"=>"error_description" if there are errors, empty otherwise.
     */
    public function edit_instance_validation($data, $files, $instance, $context)
    {
        // No errors by default.
        debugging('enrol_plugin::edit_instance_validation() is missing. This plugin has no validation!', DEBUG_DEVELOPER);
        return array();
    }

    /**
     * Return whether or not, given the current state, it is possible to add a new instance
     * of this enrolment plugin to the course.
     *
     * @param int $courseid.
     * @return bool.
     */
    public function can_add_instance($courseid)
    {
        return true;
    }

    /**
     * Is it possible to delete enrol instance via standard UI?
     *
     * @param stdClass $instance
     * @return bool
     */
    public function can_delete_instance($instance)
    {
        $context = context_course::instance($instance->courseid);
        return has_capability('enrol/course_tokens:config', $context);
    }

    /**
     * Add new instance of enrol plugin.
     *
     * @param object $courseid The course object (stdClass).
     * @param array|null $fields Instance fields (optional)
     * @return int|bool The new instance id or false on error
     */
    public function add_instance($courseid, $fields = null)
    {
        global $DB;

        // Ensure courseid is an object and contains the 'id' property
        if (!isset($courseid->id)) {
            return false;
        }

        // Extract course id from the object
        $courseid = (int) $courseid->id;

        // Prepare minimal instance data.
        $instance = [
            'courseid' => $courseid,
            'enrol' => 'course_tokens', // Correct plugin name
            'status' => isset($fields['status']) ? (int) $fields['status'] : ENROL_INSTANCE_ENABLED, // Default to enabled
            'timecreated' => time(),
            'timemodified' => time(),
        ];

        // Insert the instance into the enrol table.
        try {
            $inserted = $DB->insert_record('enrol', $instance);

            return $inserted; // Return the instance ID on success or false on failure
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Deletes the enrol instance.
     *
     * @param object $instance The instance object.
     * @return bool True on success, false on failure.
     */
    public function delete_instance($instance)
    {
        global $DB;

        // Check if the instance exists
        if (!$instance) {
            return false;
        }

        // Delete the instance from the 'enrol' table
        try {
            $DB->delete_records('enrol', ['id' => $instance->id]);
            return true; // Return true on success
        } catch (Exception $e) {
            return false; // Return false on failure
        }
    }
}

/**
 * Returns the display label and CSS class for a token status code.
 *
 * @param string $statuscode One of the supported token status codes.
 * @return array{label:string,class:string}
 */
function enrol_course_tokens_get_status_display($statuscode)
{
    switch ($statuscode) {
        case 'available':
            return ['label' => 'Available', 'class' => 'bg-secondary'];
        case 'completed':
            return ['label' => 'Completed', 'class' => 'bg-primary text-white'];
        case 'failed':
            return ['label' => 'Failed', 'class' => 'bg-danger text-white'];
        case 'in_progress':
            return ['label' => 'In-progress', 'class' => 'bg-warning text-dark'];
        case 'assigned':
        default:
            return ['label' => 'Assigned', 'class' => 'bg-success text-white'];
    }
}

/**
 * Calculates a generic token status using only core Moodle progress data.
 *
 * @param stdClass $token Token record.
 * @param int|null $userid Enrolled user ID, if known.
 * @param int $windowstart Unix timestamp for the current token cycle.
 * @param int|null $windowend Optional upper bound for historical token cycles.
 * @return string Status code.
 */
function enrol_course_tokens_get_generic_token_status($token, $userid, $windowstart = 0, $windowend = null)
{
    global $DB;

    if (empty($token->used_on)) {
        return 'available';
    }

    if (empty($userid)) {
        return 'assigned';
    }

    $courseid = (int)$token->course_id;
    $windowstart = (int)$windowstart;
    $windowend = $windowend ? (int)$windowend : null;

    if ($DB->get_manager()->table_exists('course_completions')) {
        $completion = $DB->get_record(
            'course_completions',
            ['userid' => $userid, 'course' => $courseid],
            'timecompleted'
        );
        if ($completion && !empty($completion->timecompleted)
            && $completion->timecompleted >= $windowstart
            && ($windowend === null || $completion->timecompleted <= $windowend)) {
            return 'completed';
        }
    }

    if ($DB->get_manager()->table_exists('course_modules_completion')) {
        $params = [$userid, $courseid, $windowstart];
        $endclause = '';
        if ($windowend !== null) {
            $endclause = ' AND cmc.timemodified <= ?';
            $params[] = $windowend;
        }
        $hasmoduleprogress = $DB->record_exists_sql(
            "SELECT 1
               FROM {course_modules_completion} cmc
               JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
              WHERE cmc.userid = ?
                AND cm.course = ?
                AND cmc.completionstate > 0
                AND cmc.timemodified >= ?
                $endclause",
            $params
        );
        if ($hasmoduleprogress) {
            return 'in_progress';
        }
    }

    if ($DB->get_manager()->table_exists('logstore_standard_log')) {
        $params = ['\core\event\course_viewed', $courseid, $userid, $windowstart];
        $endclause = '';
        if ($windowend !== null) {
            $endclause = ' AND timecreated <= ?';
            $params[] = $windowend;
        }
        $hasviewed = $DB->record_exists_sql(
            "SELECT 1
               FROM {logstore_standard_log}
              WHERE eventname = ?
                AND contextinstanceid = ?
                AND userid = ?
                AND timecreated >= ?
                $endclause",
            $params
        );
        if ($hasviewed) {
            return 'in_progress';
        }
    }

    return 'assigned';
}

/**
 * Builds generic customcert eCard and forward buttons when customcert is available.
 *
 * @param int|null $userid Enrolled user ID, if known.
 * @param stdClass|null $course Course record.
 * @param string $coursename Course display name.
 * @param stdClass|null $user User record.
 * @param int $windowstart Unix timestamp for the current token cycle.
 * @param int|null $windowend Optional upper bound for historical token cycles.
 * @return array{ecard_html:string,forward_html:string}
 */
function enrol_course_tokens_get_generic_customcert_actions($userid, $course, $coursename, $user, $windowstart = 0, $windowend = null)
{
    global $CFG, $DB;

    $unavailable = html_writer::tag('span', 'No eCard available', ['class' => 'text-muted']);
    $result = [
        'ecard_html' => $unavailable,
        'forward_html' => $unavailable,
    ];

    if (empty($userid) || empty($course) || empty($course->id)) {
        return $result;
    }

    $customcertlib = $CFG->dirroot . '/mod/customcert/lib.php';
    if (file_exists($customcertlib)) {
        require_once($customcertlib);
    }

    if (!function_exists('generate_public_url_for_certificate')
        || !$DB->get_manager()->table_exists('customcert')
        || !$DB->get_manager()->table_exists('customcert_issues')) {
        return $result;
    }

    $params = [
        'userid' => $userid,
        'courseid' => $course->id,
        'certname' => '%' . $DB->sql_like_escape('ecard') . '%',
    ];
    $timeclause = '';
    if ($windowstart > 0) {
        $timeclause .= ' AND ci.timecreated >= :windowstart';
        $params['windowstart'] = (int)$windowstart;
    }
    if ($windowend !== null) {
        $timeclause .= ' AND ci.timecreated <= :windowend';
        $params['windowend'] = (int)$windowend;
    }

    $certificate = $DB->get_record_sql(
        "SELECT ci.id, ci.code, ci.customcertid, ci.userid, c.name AS certname
           FROM {customcert_issues} ci
           JOIN {customcert} c ON ci.customcertid = c.id
          WHERE ci.userid = :userid
            AND c.course = :courseid
            AND " . $DB->sql_like('c.name', ':certname', false) . "
            $timeclause
          ORDER BY ci.id DESC
          LIMIT 1",
        $params
    );

    if (!$certificate || empty($certificate->code)) {
        return $result;
    }

    $publicurl = generate_public_url_for_certificate($certificate->code);
    $result['ecard_html'] = html_writer::tag('a', 'View eCard', [
        'href' => $publicurl,
        'class' => 'btn btn-success',
        'target' => '_blank',
    ]);

    $firstname = $user ? $user->firstname : '';
    $lastname = $user ? $user->lastname : '';
    $subject = rawurlencode("Check out {$firstname} {$lastname}'s eCard for {$coursename}");
    $body = rawurlencode("eCard of {$firstname} {$lastname} for the course {$coursename} is available at:\n\n{$publicurl}");

    $result['forward_html'] = html_writer::tag('a', 'Forward eCard', [
        'href' => 'mailto:?subject=' . $subject . '&body=' . $body,
        'class' => 'btn btn-primary',
        'target' => '_blank',
    ]);

    return $result;
}

/**
 * Flattens Moodle callback discovery results into callable names.
 *
 * @param mixed $callbacks Return value from get_plugins_with_function().
 * @return array
 */
function enrol_course_tokens_flatten_token_display_callbacks($callbacks)
{
    $flatcallbacks = [];

    if (is_string($callbacks) || (is_array($callbacks) && is_callable($callbacks))) {
        return [$callbacks];
    }

    if (!is_array($callbacks)) {
        return $flatcallbacks;
    }

    foreach ($callbacks as $callback) {
        if (is_string($callback) || (is_array($callback) && is_callable($callback))) {
            $flatcallbacks[] = $callback;
            continue;
        }

        foreach (enrol_course_tokens_flatten_token_display_callbacks($callback) as $nestedcallback) {
            $flatcallbacks[] = $nestedcallback;
        }
    }

    return $flatcallbacks;
}

/**
 * Applies optional token display callbacks from other plugins.
 *
 * @param array $context Token display context.
 * @return array Merged display values.
 */
function enrol_course_tokens_apply_token_display_callbacks(array $context)
{
    $allowedstatuscodes = ['available', 'assigned', 'in_progress', 'completed', 'failed'];
    $display = [
        'status_code' => $context['default_status_code'],
        'status_label' => $context['default_status_label'],
        'status_class' => $context['default_status_class'],
        'ecard_html' => $context['ecard_html'],
        'forward_html' => $context['forward_html'],
    ];

    $callbacks = get_plugins_with_function('enrol_course_tokens_extend_token_display', 'lib.php');
    foreach (enrol_course_tokens_flatten_token_display_callbacks($callbacks) as $callback) {
        if (!is_callable($callback)) {
            continue;
        }

        $result = $callback($context);
        if (!is_array($result)) {
            continue;
        }

        if (array_key_exists('status_code', $result)) {
            if (empty($result['status_code']) || !in_array($result['status_code'], $allowedstatuscodes, true)) {
                unset($result['status_code'], $result['status_label'], $result['status_class']);
            } else {
                $display['status_code'] = $result['status_code'];
                $statusdisplay = enrol_course_tokens_get_status_display($display['status_code']);
                $display['status_label'] = $statusdisplay['label'];
                $display['status_class'] = $statusdisplay['class'];
            }
        }

        foreach (['status_label', 'status_class', 'ecard_html', 'forward_html'] as $key) {
            if (!empty($result[$key])) {
                $display[$key] = $result[$key];
            }
        }

        $context['default_status_code'] = $display['status_code'];
        $context['default_status_label'] = $display['status_label'];
        $context['default_status_class'] = $display['status_class'];
        $context['ecard_html'] = $display['ecard_html'];
        $context['forward_html'] = $display['forward_html'];
    }

    return $display;
}
