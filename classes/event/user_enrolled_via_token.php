<?php
namespace enrol_course_tokens\event;

defined('MOODLE_INTERNAL') || die();

/**
 * Event fired when a user is enrolled via a token.
 */
class user_enrolled_via_token extends \core\event\base {

    /**
     * Init method.
     */
    protected function init() {
        $this->data['crud']      = 'c'; // Create - new enrolment
        $this->data['edulevel']  = self::LEVEL_PARTICIPATING;
        $this->data['objectid']  = 0; // Moodle requires this; populated at trigger time
    }

    public static function get_objectid_mapping() {
        // Maps the objectid to the course_tokens database table for privacy API compliance
        return ['db' => 'course_tokens', 'restore' => 'course_token'];
    }

    /**
     * Returns localised general event name.
     *
     * @return string
     */
    public static function get_name() {
        return 'User enrolled via course token';
    }

    /**
     * Returns non-localised event description with id's for admin use only.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->relateduserid}' enrolled in course '{$this->courseid}' using token id '" . $this->other['token_id'] . "'.";
    }

    /**
     * Returns relevant URL.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/course/view.php', ['id' => $this->courseid]);
    }
}