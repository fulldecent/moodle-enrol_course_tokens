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

namespace enrol_course_tokens;

use enrol_course_tokens\local\lifecycle_service;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../lib.php');

/**
 * Token lifecycle regression tests.
 *
 * @package enrol_course_tokens
 * @group enrol_course_tokens
 * @covers \enrol_course_tokens\local\lifecycle_service
 * @copyright 2026 Pacific Medical Training
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class lifecycle_service_test extends \advanced_testcase {
    /**
     * Create a token record with explicit lifecycle fields.
     *
     * @param int $courseid Course ID.
     * @param int $ownerid Purchaser ID.
     * @param int|null $learnerid Learner ID.
     * @param int|null $ueid Enrolment ID.
     * @param int|null $usedon Used timestamp.
     * @return \stdClass
     */
    private function create_token(
        int $courseid,
        int $ownerid,
        ?int $learnerid,
        ?int $ueid,
        ?int $usedon
    ): \stdClass {
        global $DB;

        $now = time();
        $token = (object) [
            'timecreated' => $now,
            'timemodified' => $now,
            'code' => uniqid('token-', true),
            'course_id' => $courseid,
            'voided' => 0,
            'voided_at' => null,
            'voided_notes' => null,
            'user_enrolments_id' => $ueid,
            'extra_json' => null,
            'user_id' => $ownerid,
            'used_by_user_id' => $learnerid,
            'used_on' => $usedon,
            'group_account' => null,
            'created_by' => $ownerid,
        ];
        $token->id = $DB->insert_record('course_tokens', $token);
        return $token;
    }

    /**
     * Create a live manual enrolment.
     *
     * @param \stdClass $course Course.
     * @param \stdClass $user Learner.
     * @return \stdClass User enrolment.
     */
    private function enrol_user(\stdClass $course, \stdClass $user): \stdClass {
        global $DB;

        $instance = $DB->get_record(
            'enrol',
            ['courseid' => $course->id, 'enrol' => 'manual'],
            '*',
            MUST_EXIST
        );
        $role = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);
        enrol_get_plugin('manual')->enrol_user($instance, $user->id, $role->id);
        return $DB->get_record(
            'user_enrolments',
            ['enrolid' => $instance->id, 'userid' => $user->id],
            '*',
            MUST_EXIST
        );
    }

    /**
     * Three renewals share one enrolment; only the latest can be refunded.
     */
    public function test_three_cycle_refund_preserves_history(): void {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $owner = $this->getDataGenerator()->create_user();
        $learner = $this->getDataGenerator()->create_user();
        $ue = $this->enrol_user($course, $learner);
        $t1 = $this->create_token($course->id, $owner->id, $learner->id, $ue->id, 100);
        $t2 = $this->create_token($course->id, $owner->id, $learner->id, $ue->id, 200);
        $t3 = $this->create_token($course->id, $owner->id, $learner->id, $ue->id, 300);

        $this->assertNull(lifecycle_service::get_refundable_enrolment($t1));
        $this->assertNull(lifecycle_service::get_refundable_enrolment($t2));
        $this->assertNotNull(lifecycle_service::get_refundable_enrolment($t3));
        $this->assertTrue(lifecycle_service::refund_and_unenrol($t3->id));

        $t1 = $DB->get_record('course_tokens', ['id' => $t1->id], '*', MUST_EXIST);
        $t2 = $DB->get_record('course_tokens', ['id' => $t2->id], '*', MUST_EXIST);
        $t3 = $DB->get_record('course_tokens', ['id' => $t3->id], '*', MUST_EXIST);
        $this->assertSame('100', (string) $t1->used_on);
        $this->assertSame('200', (string) $t2->used_on);
        $this->assertSame((string) $learner->id, (string) $t1->used_by_user_id);
        $this->assertSame((string) $learner->id, (string) $t2->used_by_user_id);
        $this->assertNull($t1->user_enrolments_id);
        $this->assertNull($t2->user_enrolments_id);
        $this->assertNull($t3->used_on);
        $this->assertNull($t3->used_by_user_id);
        $this->assertNull($t3->user_enrolments_id);
        $this->assertNull(lifecycle_service::get_refundable_enrolment($t1));
        $this->assertNull(lifecycle_service::get_refundable_enrolment($t2));
    }

    /**
     * Historical and repeat requests fail without changing state.
     */
    public function test_historical_and_repeat_refunds_are_rejected(): void {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $owner = $this->getDataGenerator()->create_user();
        $learner = $this->getDataGenerator()->create_user();
        $ue = $this->enrol_user($course, $learner);
        $old = $this->create_token($course->id, $owner->id, $learner->id, $ue->id, 100);
        $latest = $this->create_token($course->id, $owner->id, $learner->id, $ue->id, 200);

        $this->assertFalse(lifecycle_service::refund_and_unenrol($old->id));
        $this->assertTrue($DB->record_exists('user_enrolments', ['id' => $ue->id]));
        $this->assertSame('100', (string) $DB->get_field('course_tokens', 'used_on', ['id' => $old->id]));
        $this->assertTrue(lifecycle_service::refund_and_unenrol($latest->id));
        $this->assertFalse(lifecycle_service::refund_and_unenrol($latest->id));
    }

    /**
     * Detached and contradictory records are consumed but never inventory.
     */
    public function test_detached_consumed_token_fails_closed(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $owner = $this->getDataGenerator()->create_user();
        $learner = $this->getDataGenerator()->create_user();
        $token = $this->create_token($course->id, $owner->id, $learner->id, null, 0);

        $this->assertTrue(lifecycle_service::is_consumed($token));
        $this->assertFalse(lifecycle_service::is_available($token));
        $this->assertSame('assigned', enrol_course_tokens_get_generic_token_status($token, $learner->id));
        $this->assertFalse(lifecycle_service::refund_and_unenrol($token->id));

        $token->used_on = null;
        $this->assertFalse(lifecycle_service::is_available($token));
    }

    /**
     * The ID is the deterministic latest-token tie breaker.
     */
    public function test_latest_consumed_token_uses_id_tie_breaker(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $owner = $this->getDataGenerator()->create_user();
        $learner = $this->getDataGenerator()->create_user();
        $first = $this->create_token($course->id, $owner->id, $learner->id, null, 123);
        $second = $this->create_token($course->id, $owner->id, $learner->id, null, 123);

        $latest = lifecycle_service::get_latest_consumed_token($learner->id, $course->id);
        $this->assertSame($second->id, $latest->id);
        $this->assertGreaterThan($first->id, $latest->id);
    }

    /**
     * Consuming on behalf of someone else does not overwrite the purchaser.
     */
    public function test_consumed_fields_distinguish_owner_and_learner(): void {
        $owner = (object) ['id' => 11];
        $learner = (object) ['id' => 22];
        $token = (object) ['user_id' => $owner->id];
        lifecycle_service::set_consumed_fields($token, 33, $learner->id, 44);

        $this->assertSame(11, $token->user_id);
        $this->assertSame(22, $token->used_by_user_id);
        $this->assertSame(33, $token->user_enrolments_id);
        $this->assertSame(44, $token->used_on);
    }

    /**
     * A voided consumed token is never eligible for refund, even with a live link.
     */
    public function test_voided_consumed_token_is_not_refundable(): void {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $owner = $this->getDataGenerator()->create_user();
        $learner = $this->getDataGenerator()->create_user();
        $ue = $this->enrol_user($course, $learner);
        $token = $this->create_token($course->id, $owner->id, $learner->id, $ue->id, 100);
        $token->voided = 1;
        $token->voided_at = 101;
        $DB->update_record('course_tokens', $token);

        $this->assertNull(lifecycle_service::get_refundable_enrolment($token));
        $this->assertFalse(lifecycle_service::refund_and_unenrol($token->id));
        $this->assertTrue($DB->record_exists('user_enrolments', ['id' => $ue->id]));
        $this->assertSame('100', (string) $DB->get_field('course_tokens', 'used_on', ['id' => $token->id]));
    }

    /**
     * Voiding the active cycle unenrols it atomically but preserves older history.
     */
    public function test_voiding_active_token_refunds_only_active_cycle(): void {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $owner = $this->getDataGenerator()->create_user();
        $learner = $this->getDataGenerator()->create_user();
        $ue = $this->enrol_user($course, $learner);
        $old = $this->create_token($course->id, $owner->id, $learner->id, $ue->id, 100);
        $latest = $this->create_token($course->id, $owner->id, $learner->id, $ue->id, 200);

        $this->assertTrue(lifecycle_service::void_token($latest->id, 'active void'));
        $this->assertFalse($DB->record_exists('user_enrolments', ['id' => $ue->id]));

        $old = $DB->get_record('course_tokens', ['id' => $old->id], '*', MUST_EXIST);
        $latest = $DB->get_record('course_tokens', ['id' => $latest->id], '*', MUST_EXIST);
        $this->assertSame('100', (string) $old->used_on);
        $this->assertSame((string) $learner->id, (string) $old->used_by_user_id);
        $this->assertNull($old->user_enrolments_id);
        $this->assertNull($latest->used_on);
        $this->assertNull($latest->used_by_user_id);
        $this->assertNull($latest->user_enrolments_id);
        $this->assertSame('1', (string) $latest->voided);
        $this->assertNotNull($latest->voided_at);
        $this->assertNull(lifecycle_service::get_refundable_enrolment($old));
    }

    /**
     * Voiding history must not disturb the current live course cycle.
     */
    public function test_voiding_historical_token_preserves_current_enrolment(): void {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $owner = $this->getDataGenerator()->create_user();
        $learner = $this->getDataGenerator()->create_user();
        $ue = $this->enrol_user($course, $learner);
        $old = $this->create_token($course->id, $owner->id, $learner->id, $ue->id, 100);
        $latest = $this->create_token($course->id, $owner->id, $learner->id, $ue->id, 200);

        $this->assertTrue(lifecycle_service::void_token($old->id, 'historical audit'));
        $this->assertTrue($DB->record_exists('user_enrolments', ['id' => $ue->id]));
        $old = $DB->get_record('course_tokens', ['id' => $old->id], '*', MUST_EXIST);
        $latest = $DB->get_record('course_tokens', ['id' => $latest->id], '*', MUST_EXIST);
        $this->assertSame('1', (string) $old->voided);
        $this->assertSame('100', (string) $old->used_on);
        $this->assertSame((string) $learner->id, (string) $old->used_by_user_id);
        $this->assertSame((string) $ue->id, (string) $latest->user_enrolments_id);
        $old->voided = 0;
        $old->voided_at = null;
        $this->assertFalse(lifecycle_service::is_available($old));
    }
}
