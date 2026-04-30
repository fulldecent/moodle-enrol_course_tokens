<?php
namespace enrol_course_tokens\event;

defined('MOODLE_INTERNAL') || die();

/**
 * Event fired when a user confirms token renewal (recertification).
 */
class token_renewal_confirmed extends \core\event\base {

    /**
     * Init method.
     */
    protected function init() {
        $this->data['crud']        = 'u'; // Use 'c' for create, 'u' for update
        $this->data['edulevel']    = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'course_tokens';
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
        return 'Course token used for renewal';
    }

    /**
     * Returns non-localised event description with id's for admin use only.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->relateduserid}' used token id '" . $this->other['token_id'] . "' to renew course '{$this->courseid}'.";
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