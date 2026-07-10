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

namespace enrol_course_tokens\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Resolves the user ID that should be recorded as token creator.
 *
 * @package enrol_course_tokens
 */
class token_creator {
    /**
     * Resolve a valid token creator user ID.
     *
     * Resolution order:
     * 1. Explicit requested user ID, if provided and valid.
     * 2. Current logged-in user ID, if valid.
     * 3. Configured automated creator user ID.
     * 4. Throw a moodle_exception if no valid creator can be resolved.
     *
     * @param int|null $requesteduserid Optional requested user ID.
     * @return int
     * @throws \moodle_exception
     */
    public static function resolve(?int $requesteduserid = null): int {
        global $USER;

        if ($requesteduserid !== null) {
            if (self::is_valid_userid($requesteduserid)) {
                return $requesteduserid;
            }
            throw new \moodle_exception('error_invalid_token_creator_user', 'enrol_course_tokens');
        }

        if (!empty($USER) && !empty($USER->id)) {
            $userid = (int)$USER->id;
            if (self::is_valid_userid($userid)) {
                return $userid;
            }
        }

        $configureduserid = get_config('enrol_course_tokens', 'tokencreatoruserid');
        if ($configureduserid === false || $configureduserid === null || trim((string)$configureduserid) === '') {
            throw new \moodle_exception('error_missing_token_creator_user', 'enrol_course_tokens');
        }

        $configureduserid = (int)$configureduserid;
        if (!self::is_valid_userid($configureduserid)) {
            throw new \moodle_exception('error_invalid_token_creator_user', 'enrol_course_tokens');
        }

        return $configureduserid;
    }

    /**
     * Validate whether a user ID can be used as token creator.
     *
     * @param int $userid
     * @return bool
     */
    public static function is_valid_userid(int $userid): bool {
        global $CFG, $DB;

        if ($userid <= 0) {
            return false;
        }

        $user = $DB->get_record(
            'user',
            ['id' => $userid, 'deleted' => 0, 'suspended' => 0, 'confirmed' => 1],
            'id,username,deleted,suspended,confirmed'
        );

        if (!$user) {
            return false;
        }

        if (!empty($CFG->siteguest) && (int)$CFG->siteguest === (int)$userid) {
            return false;
        }

        if (isguestuser($user)) {
            return false;
        }

        return true;
    }
}
