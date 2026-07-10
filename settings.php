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
 * Plugin administration pages are defined here.
 *
 * @package     enrol_course_tokens
 * @category    admin
 * @copyright   2024 Pacific Medical Training <support@pacificmedicaltraining.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('enrol_course_tokens_settings', new lang_string('pluginname', 'enrol_course_tokens'));

    if ($ADMIN->fulltree) {
        global $DB, $CFG, $SITE;

        // 1. Fetch available custom profile fields dynamically for the dropdown
        $profilefields = ['' => new lang_string('none', 'enrol_course_tokens')];
        // Wrap in a try-catch just in case the table isn't fully set up during initial install
        try {
            if ($fields = $DB->get_records('user_info_field', null, 'name ASC', 'id, shortname, name')) {
                foreach ($fields as $field) {
                    $profilefields[$field->shortname] = format_string($field->name) . ' (' . $field->shortname . ')';
                }
            }
        } catch (\Exception $e) {
            // Ignore during install/upgrade if the table doesn't exist yet
        }

        // 2. Sender Email
        $settings->add(new admin_setting_configtext(
            'enrol_course_tokens/sender_email',
            new lang_string('sender_email', 'enrol_course_tokens'),
            new lang_string('sender_email_desc', 'enrol_course_tokens'),
            isset($CFG->supportemail) ? $CFG->supportemail : 'noreply@' . $_SERVER['HTTP_HOST'],
            PARAM_EMAIL
        ));

        // 3. Sender Name
        $settings->add(new admin_setting_configtext(
            'enrol_course_tokens/sender_name',
            new lang_string('sender_name', 'enrol_course_tokens'),
            new lang_string('sender_name_desc', 'enrol_course_tokens'),
            isset($SITE->fullname) ? $SITE->fullname : 'Moodle',
            PARAM_TEXT
        ));

        // 4. Custom Login URL
        $settings->add(new admin_setting_configtext(
            'enrol_course_tokens/custom_login_url',
            new lang_string('custom_login_url', 'enrol_course_tokens'),
            new lang_string('custom_login_url_desc', 'enrol_course_tokens'),
            $CFG->wwwroot . '/login/',
            PARAM_URL
        ));

        // 5. Automated Token Creator User ID.
        $settings->add(new admin_setting_configtext(
            'enrol_course_tokens/tokencreatoruserid',
            new lang_string('tokencreatoruserid', 'enrol_course_tokens'),
            new lang_string('tokencreatoruserid_desc', 'enrol_course_tokens'),
            '',
            PARAM_INT
        ));

        // 6. Corporate/Group Profile Field Mapping
        $settings->add(new admin_setting_configselect(
            'enrol_course_tokens/customer_group_field',
            new lang_string('customer_group_field', 'enrol_course_tokens'),
            new lang_string('customer_group_field_desc', 'enrol_course_tokens'),
            '', // Default to none/empty
            $profilefields
        ));

        // 6. Phone Required Mode
        $phone_modes = [
            'none'     => new lang_string('phone_mode_none', 'enrol_course_tokens'),
            'all'      => new lang_string('phone_mode_all', 'enrol_course_tokens'),
            'specific' => new lang_string('phone_mode_specific', 'enrol_course_tokens')
        ];
        $settings->add(new admin_setting_configselect(
            'enrol_course_tokens/phone_required_mode',
            new lang_string('phone_required_mode', 'enrol_course_tokens'),
            new lang_string('phone_required_mode_desc', 'enrol_course_tokens'),
            'none', // Default to none
            $phone_modes
        ));

        // 7. Phone Required Course IDs
        $settings->add(new admin_setting_configtext(
            'enrol_course_tokens/phone_required_courses',
            new lang_string('phone_required_courses', 'enrol_course_tokens'),
            new lang_string('phone_required_courses_desc', 'enrol_course_tokens'),
            '', // Default empty
            PARAM_TEXT
        ));

        // Native Moodle logic to hide the text box unless the dropdown is set to 'specific'
        $settings->hide_if('enrol_course_tokens/phone_required_courses', 'enrol_course_tokens/phone_required_mode', 'neq', 'specific');

        // --- EMAIL TEMPLATES ---
        
        $settings->add(new admin_setting_heading(
            'enrol_course_tokens/email_heading',
            new lang_string('email_templates_heading', 'enrol_course_tokens'),
            new lang_string('email_templates_desc', 'enrol_course_tokens')
        ));

        // 6. Welcome Email Template
        $settings->add(new admin_setting_confightmleditor(
            'enrol_course_tokens/welcome_email_body',
            new lang_string('welcome_email', 'enrol_course_tokens'),
            new lang_string('welcome_email_desc', 'enrol_course_tokens'),
            new lang_string('welcome_email_default', 'enrol_course_tokens'),
            PARAM_RAW
        ));

        // 7. Token Delivery Email Template
        $settings->add(new admin_setting_confightmleditor(
            'enrol_course_tokens/token_email_body',
            new lang_string('token_email', 'enrol_course_tokens'),
            new lang_string('token_email_desc', 'enrol_course_tokens'),
            new lang_string('token_email_default', 'enrol_course_tokens'),
            PARAM_RAW
        ));

        // 8. Recertification Email Template
        $settings->add(new admin_setting_confightmleditor(
            'enrol_course_tokens/recert_email_body',
            new lang_string('recert_email', 'enrol_course_tokens'),
            new lang_string('recert_email_desc', 'enrol_course_tokens'),
            new lang_string('recert_email_default', 'enrol_course_tokens'),
            PARAM_RAW
        ));

        // 9. Standard Enrollment Email Template
        $settings->add(new admin_setting_confightmleditor(
            'enrol_course_tokens/enrolment_email_body',
            new lang_string('enrolment_email', 'enrol_course_tokens'),
            new lang_string('enrolment_email_desc', 'enrol_course_tokens'),
            new lang_string('enrolment_email_default', 'enrol_course_tokens'),
            PARAM_RAW
        ));
    }
}