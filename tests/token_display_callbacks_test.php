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

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../lib.php');

/**
 * Tests for token display callbacks.
 *
 * @package enrol_course_tokens
 * @group enrol_course_tokens
 * @covers ::enrol_course_tokens_order_token_display_callbacks
 * @covers ::enrol_course_tokens_apply_token_display_callbacks
 * @copyright 2026 Pacific Medical Training
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class token_display_callbacks_test extends \advanced_testcase {
    /**
     * Returns a token display context with an available token.
     *
     * @return array
     */
    private function get_context(): array {
        return [
            'default_status_code' => 'available',
            'default_status_label' => 'Available',
            'default_status_class' => 'badge-success',
            'ecard_html' => '<a>Default eCard</a>',
            'forward_html' => '<a>Default forward</a>',
            'token' => (object) [
                'used_on' => null,
                'used_by_user_id' => null,
                'user_enrolments_id' => null,
                'voided' => 0,
                'voided_at' => null,
            ],
        ];
    }

    /**
     * Calls the display callback merger with supplied discovery results.
     *
     * @param array $callbacks Nested callback discovery results.
     * @return array
     */
    private function apply_callbacks(array $callbacks): array {
        return \enrol_course_tokens_apply_token_display_callbacks($this->get_context(), $callbacks);
    }

    /**
     * No providers leave the default display unchanged.
     */
    public function test_no_providers_preserve_defaults(): void {
        $this->assertSame([
            'status_code' => 'available',
            'status_label' => 'Available',
            'status_class' => 'badge-success',
            'ecard_html' => '<a>Default eCard</a>',
            'forward_html' => '<a>Default forward</a>',
        ], $this->apply_callbacks([]));
    }

    /**
     * One provider can replace supported fields.
     */
    public function test_one_provider(): void {
        $display = $this->apply_callbacks([
            'local' => ['alpha' => [self::class, 'provide_one']],
        ]);

        $this->assertSame('in_progress', $display['status_code']);
        $this->assertSame('Provider status', $display['status_label']);
    }

    /**
     * The alphabetically later component wins a status conflict.
     */
    public function test_two_providers_returning_different_statuses(): void {
        $display = $this->apply_callbacks([
            'mod' => ['zeta' => [self::class, 'provide_completed_status']],
            'block' => ['alpha' => [self::class, 'provide_failed_status']],
        ]);

        $this->assertSame('completed', $display['status_code']);
    }

    /**
     * Values from separate providers are both retained.
     */
    public function test_two_providers_returning_independent_fields(): void {
        $display = $this->apply_callbacks([
            'local' => ['alpha' => [self::class, 'provide_status_label']],
            'mod' => ['beta' => [self::class, 'provide_ecard']],
        ]);

        $this->assertSame('Independent label', $display['status_label']);
        $this->assertSame('<a>Independent eCard</a>', $display['ecard_html']);
    }

    /**
     * Later HTML replaces earlier HTML rather than being appended.
     */
    public function test_conflicting_html_is_replaced(): void {
        $display = $this->apply_callbacks([
            'mod' => ['zeta' => [self::class, 'provide_later_html']],
            'block' => ['alpha' => [self::class, 'provide_earlier_html']],
        ]);

        $this->assertSame('<p>Later eCard</p>', $display['ecard_html']);
        $this->assertSame('<p>Later forward</p>', $display['forward_html']);
        $this->assertStringNotContainsString('Earlier', $display['ecard_html']);
        $this->assertStringNotContainsString('Earlier', $display['forward_html']);
    }

    /**
     * Discovery insertion order does not affect callback results.
     */
    public function test_discovery_order_does_not_affect_result(): void {
        $firstorder = [
            'mod' => ['zeta' => [self::class, 'provide_later_html']],
            'block' => ['alpha' => [self::class, 'provide_earlier_html']],
        ];
        $secondorder = [
            'block' => ['alpha' => [self::class, 'provide_earlier_html']],
            'mod' => ['zeta' => [self::class, 'provide_later_html']],
        ];

        $this->assertSame($this->apply_callbacks($firstorder), $this->apply_callbacks($secondorder));
    }

    /**
     * Invalid discovery entries are ignored by the ordering helper.
     */
    public function test_invalid_callbacks_are_ignored(): void {
        $callbacks = \enrol_course_tokens_order_token_display_callbacks([
            'local' => [
                'valid' => [self::class, 'provide_one'],
                'invalid' => 'function_that_does_not_exist',
            ],
            'invalid' => 'not a plugin list',
        ]);

        $this->assertSame([[self::class, 'provide_one']], $callbacks);
    }

    /**
     * Provides a status replacement.
     *
     * @param array $context Current display context.
     * @return array
     */
    public static function provide_one(array $context): array {
        return [
            'status_code' => 'in_progress',
            'status_label' => 'Provider status',
        ];
    }

    /**
     * Provides a failed status.
     *
     * @param array $context Current display context.
     * @return array
     */
    public static function provide_failed_status(array $context): array {
        return ['status_code' => 'failed'];
    }

    /**
     * Provides a completed status.
     *
     * @param array $context Current display context.
     * @return array
     */
    public static function provide_completed_status(array $context): array {
        return ['status_code' => 'completed'];
    }

    /**
     * Provides a label.
     *
     * @param array $context Current display context.
     * @return array
     */
    public static function provide_status_label(array $context): array {
        return ['status_label' => 'Independent label'];
    }

    /**
     * Provides eCard HTML.
     *
     * @param array $context Current display context.
     * @return array
     */
    public static function provide_ecard(array $context): array {
        if ($context['default_status_label'] !== 'Independent label') {
            return [];
        }

        return ['ecard_html' => '<a>Independent eCard</a>'];
    }

    /**
     * Provides HTML from the alphabetically earlier component.
     *
     * @param array $context Current display context.
     * @return array
     */
    public static function provide_earlier_html(array $context): array {
        return [
            'ecard_html' => '<p>Earlier eCard</p>',
            'forward_html' => '<p>Earlier forward</p>',
        ];
    }

    /**
     * Provides HTML from the alphabetically later component.
     *
     * @param array $context Current display context.
     * @return array
     */
    public static function provide_later_html(array $context): array {
        return [
            'ecard_html' => '<p>Later eCard</p>',
            'forward_html' => '<p>Later forward</p>',
        ];
    }
}
