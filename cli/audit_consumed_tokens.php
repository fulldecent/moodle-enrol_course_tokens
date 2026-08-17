<?php
// This file is part of Moodle - https://moodle.org/.
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
 * Reports consumed tokens whose permanent learner could not be determined.
 *
 * @package enrol_course_tokens
 * @copyright 2026 Pacific Medical Training
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

$sql = "SELECT t.id, t.code, t.course_id, c.fullname AS coursename,
               t.user_enrolments_id, t.used_on
          FROM {course_tokens} t
     LEFT JOIN {course} c ON c.id = t.course_id
         WHERE t.used_on IS NOT NULL
           AND t.used_by_user_id IS NULL
      ORDER BY t.course_id, t.used_on, t.id";

$tokens = $DB->get_recordset_sql($sql);
$count = 0;
cli_writeln("Consumed course tokens missing a permanent learner ID:\n");
foreach ($tokens as $token) {
    $count++;
    $formatted = userdate((int) $token->used_on, get_string('strftimedatetime', 'langconfig'));
    cli_writeln(sprintf(
        'token=%d code=%s course=%d (%s) user_enrolments_id=%s used_on=%d (%s)',
        $token->id,
        $token->code,
        $token->course_id,
        $token->coursename ?? 'missing course',
        $token->user_enrolments_id === null ? 'NULL' : $token->user_enrolments_id,
        $token->used_on,
        $formatted
    ));
}
$tokens->close();

cli_writeln("\nTotal: {$count}");
exit(0);
