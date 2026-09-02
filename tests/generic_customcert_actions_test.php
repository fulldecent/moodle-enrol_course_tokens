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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <https://www.gnu.org/licenses/>.

namespace enrol_course_tokens;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../lib.php');

/**
 * Tests for generic Custom Certificate actions.
 *
 * @package enrol_course_tokens
 * @group enrol_course_tokens
 * @covers ::enrol_course_tokens_get_tagged_customcert_ids
 * @covers ::enrol_course_tokens_get_generic_customcert_actions
 * @copyright 2026 Pacific Medical Training
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class generic_customcert_actions_test extends \advanced_testcase {
    /**
     * Prepare the optional Custom Certificate integration.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $customcertdir = \core_component::get_plugin_directory('mod', 'customcert');
        if ($customcertdir === null) {
            $this->markTestSkipped('mod_customcert is not installed.');
        }

        require_once($customcertdir . '/lib.php');
        if (!function_exists('generate_public_url_for_certificate')) {
            $this->markTestSkipped('The Custom Certificate public URL integration is unavailable.');
        }
    }

    /**
     * Create a Custom Certificate activity and optionally tag it.
     *
     * @param \stdClass $course Course record.
     * @param string $name Certificate activity name.
     * @param string|null $tagname Optional course-module tag.
     * @return array{certificate:\stdClass,cm:\stdClass,tagid:int|null}
     */
    private function create_certificate(\stdClass $course, string $name, ?string $tagname): array {
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_customcert');
        $certificate = $generator->create_instance([
            'course' => $course->id,
            'name' => $name,
        ]);
        $cm = get_coursemodule_from_instance('customcert', $certificate->id, $course->id, false, MUST_EXIST);
        $tagid = null;

        if ($tagname !== null) {
            \core_tag_tag::set_item_tags(
                'core',
                'course_modules',
                $cm->id,
                \context_module::instance($cm->id),
                [$tagname]
            );
            $tags = \core_tag_tag::get_item_tags('core', 'course_modules', $cm->id);
            $tag = reset($tags);
            $tagid = (int)$tag->id;
        }

        return [
            'certificate' => $certificate,
            'cm' => $cm,
            'tagid' => $tagid,
        ];
    }

    /**
     * Create an issued certificate record.
     *
     * @param int $customcertid Custom Certificate instance ID.
     * @param int $userid User ID.
     * @param int $timecreated Issue timestamp.
     * @return \stdClass Issue record.
     */
    private function create_issue(int $customcertid, int $userid, int $timecreated): \stdClass {
        global $DB;

        $issue = (object) [
            'customcertid' => $customcertid,
            'userid' => $userid,
            'code' => sha1($customcertid . '-' . $userid . '-' . $timecreated . '-' . microtime(true)),
            'emailed' => 0,
            'timecreated' => $timecreated,
        ];
        $issue->id = $DB->insert_record('customcert_issues', $issue);
        return $issue;
    }

    /**
     * Build the generic actions for a learner and course.
     *
     * @param \stdClass $user Learner.
     * @param \stdClass $course Course.
     * @param int $windowstart Cycle start.
     * @param int|null $windowend Cycle end.
     * @return array
     */
    private function get_actions(
        \stdClass $user,
        \stdClass $course,
        int $windowstart = 0,
        ?int $windowend = null
    ): array {
        return enrol_course_tokens_get_generic_customcert_actions(
            $user->id,
            $course,
            $course->fullname,
            $user,
            $windowstart,
            $windowend
        );
    }

    /**
     * Tagged certificates are selected independently of their display name or language.
     */
    public function test_tagged_certificates_do_not_depend_on_name(): void {
        $user = $this->getDataGenerator()->create_user();
        $names = [
            'eCard',
            'Course achievement certificate',
            'Certificado de finalización',
        ];

        foreach ($names as $index => $name) {
            $course = $this->getDataGenerator()->create_course();
            $activity = $this->create_certificate($course, $name, 'course-token-certificate');
            $issue = $this->create_issue($activity['certificate']->id, $user->id, 100 + $index);
            set_config('customcerttagid', $activity['tagid'], 'enrol_course_tokens');

            $actions = $this->get_actions($user, $course);
            $this->assertStringContainsString($name, $actions['ecard_html']);
            $this->assertStringContainsString($issue->code, $actions['ecard_html']);
        }
    }

    /**
     * A certificate named eCard is ignored when it is not tagged.
     */
    public function test_untagged_ecard_is_not_selected(): void {
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $marker = $this->create_certificate($course, 'Configured certificate', 'course-token-certificate');
        $untagged = $this->create_certificate($course, 'eCard', null);
        $this->create_issue($untagged['certificate']->id, $user->id, 100);
        set_config('customcerttagid', $marker['tagid'], 'enrol_course_tokens');

        $actions = $this->get_actions($user, $course);
        $this->assertStringContainsString('No eCard available', $actions['ecard_html']);
    }

    /**
     * The newest issue is selected when several tagged activities match.
     */
    public function test_newest_tagged_issue_is_selected(): void {
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $older = $this->create_certificate($course, 'Cognitive certificate', 'course-token-certificate');
        $newer = $this->create_certificate($course, 'Completion certificate', 'course-token-certificate');
        $this->create_issue($older['certificate']->id, $user->id, 100);
        $newissue = $this->create_issue($newer['certificate']->id, $user->id, 200);
        set_config('customcerttagid', $older['tagid'], 'enrol_course_tokens');

        $actions = $this->get_actions($user, $course);
        $this->assertStringContainsString('Completion certificate', $actions['ecard_html']);
        $this->assertStringContainsString($newissue->code, $actions['ecard_html']);
        $this->assertStringNotContainsString('Cognitive certificate', $actions['ecard_html']);
    }

    /**
     * Issues outside the token-cycle window are unavailable.
     */
    public function test_issue_outside_cycle_window_is_not_selected(): void {
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $activity = $this->create_certificate($course, 'Completion certificate', 'course-token-certificate');
        $this->create_issue($activity['certificate']->id, $user->id, 100);
        set_config('customcerttagid', $activity['tagid'], 'enrol_course_tokens');

        $actions = $this->get_actions($user, $course, 200);
        $this->assertStringContainsString('No eCard available', $actions['ecard_html']);
    }

    /**
     * Missing configuration or a missing issue returns the unavailable state.
     */
    public function test_missing_configuration_or_issue_is_unavailable(): void {
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $activity = $this->create_certificate($course, 'Completion certificate', 'course-token-certificate');

        unset_config('customcerttagid', 'enrol_course_tokens');
        $actions = $this->get_actions($user, $course);
        $this->assertStringContainsString('No eCard available', $actions['ecard_html']);

        set_config('customcerttagid', $activity['tagid'], 'enrol_course_tokens');
        $actions = $this->get_actions($user, $course);
        $this->assertStringContainsString('No eCard available', $actions['ecard_html']);
    }
}
