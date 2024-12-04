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
