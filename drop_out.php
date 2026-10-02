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
 * Administrator confirmation page for dropping out a course token.
 *
 * @package enrol_course_tokens
 * @copyright 2026 Pacific Medical Training
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once('../../config.php');
require_once(__DIR__ . '/lib.php');
require_login();
require_capability('moodle/site:config', context_system::instance());

$tokenid = required_param('tokenid', PARAM_INT);
$returnurl = new moodle_url('/enrol/course_tokens/index.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/enrol/course_tokens/drop_out.php', ['tokenid' => $tokenid]));
$PAGE->set_title(get_string('markdroppedout', 'enrol_course_tokens'));
$PAGE->set_heading(get_string('markdroppedout', 'enrol_course_tokens'));

$form = new \enrol_course_tokens\form\drop_out_form(null, ['tokenid' => $tokenid]);
if ($form->is_cancelled()) {
    redirect($returnurl);
}
$token = $DB->get_record('course_tokens', ['id' => $tokenid], '*', MUST_EXIST);
if ($data = $form->get_data()) {
    // Moodle forms validate sesskey; also explicitly require POST and sesskey here.
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new moodle_exception('invalidaccess');
    }
    require_sesskey();
    if (\enrol_course_tokens\local\lifecycle_service::drop_out($tokenid, $data->reason, (int) $USER->id)) {
        redirect($returnurl, get_string('dropoutsuccess', 'enrol_course_tokens'), null,
            \core\output\notification::NOTIFY_SUCCESS);
    }
    redirect($returnurl, get_string('dropoutfailed', 'enrol_course_tokens'), null,
        \core\output\notification::NOTIFY_ERROR);
}

echo $OUTPUT->header();
if (!\enrol_course_tokens\local\lifecycle_service::can_drop_out($token)) {
    echo $OUTPUT->notification(get_string('dropoutfailed', 'enrol_course_tokens'));
    echo $OUTPUT->continue_button($returnurl);
} else {
    $course = $DB->get_record('course', ['id' => $token->course_id], '*', MUST_EXIST);
    echo $OUTPUT->heading(format_string($course->fullname) . ' — ' . s($token->code), 3);
    $learnerid = \enrol_course_tokens\local\lifecycle_service::get_learner_id($token);
    if ($learnerid) {
        $learner = $DB->get_record('user', ['id' => $learnerid], '*', MUST_EXIST);
        echo html_writer::div(s(fullname($learner) . ' (' . $learner->email . ')'));
    } else {
        echo $OUTPUT->notification(get_string('dropoutunusedwarning', 'enrol_course_tokens'),
            \core\output\notification::NOTIFY_INFO);
    }
    echo $OUTPUT->notification(get_string('dropoutconfirm', 'enrol_course_tokens'),
        \core\output\notification::NOTIFY_WARNING);
    $form->display();
}
echo $OUTPUT->footer();
