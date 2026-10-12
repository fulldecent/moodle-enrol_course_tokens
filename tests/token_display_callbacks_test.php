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
 * @covers ::enrol_course_tokens_validate_token_display_overrides
 * @covers ::enrol_course_tokens_apply_token_display_callbacks
 * @covers ::enrol_course_tokens_render_token_status
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

    /** Non-array callback results leave all defaults intact. */
    public function test_non_array_results_preserve_defaults(): void {
        $expected = $this->apply_callbacks([]);
        foreach ([null, false, true, 1, 1.5, 'completed', (object)['status_code' => 'completed']] as $result) {
            $this->assertSame($expected, $this->apply_callbacks([
                'local' => ['alpha' => static fn(array $context) => $result],
            ]));
        }
    }

    /** Even stringable values must not be coerced into display strings. */
    public function test_non_string_fields_preserve_defaults(): void {
        $stringable = new class implements \Stringable {
            public function __toString(): string {
                throw new \RuntimeException('Display validation must not coerce objects.');
            }
        };
        $expected = $this->apply_callbacks([]);
        foreach (array_keys($expected) as $field) {
            foreach ([false, true, 0, 1, 1.5, [], ['completed'], new \stdClass(), $stringable] as $value) {
                $this->assertSame($expected, $this->apply_callbacks([
                    'local' => ['alpha' => static fn(array $context): array => [$field => $value]],
                ]), 'Invalid type for ' . $field);
            }
        }
    }

    /** Missing, null, empty and ASCII-whitespace-only fields do not clear defaults. */
    public function test_blank_fields_preserve_defaults(): void {
        $expected = $this->apply_callbacks([]);
        $this->assertSame($expected, $this->apply_callbacks([
            'local' => ['alpha' => static fn(array $context): array => []],
        ]));
        foreach (array_keys($expected) as $field) {
            foreach ([null, '', ' ', "\t\r\n\f\v"] as $value) {
                $this->assertSame($expected, $this->apply_callbacks([
                    'local' => ['alpha' => static fn(array $context): array => [$field => $value]],
                ]), 'Blank field ' . $field);
            }
        }
    }

    /** The string zero is content, while numeric zero is an invalid type. */
    public function test_zero_string_is_valid_display_content(): void {
        $overrides = array_fill_keys(['status_label', 'status_class', 'ecard_html', 'forward_html'], '0');
        $display = $this->apply_callbacks([
            'local' => ['alpha' => static fn(array $context): array => $overrides],
        ]);
        foreach ($overrides as $field => $value) {
            $this->assertSame($value, $display[$field]);
        }
        $this->assertSame('<td class="0">0</td>', \enrol_course_tokens_render_token_status($display));
    }

    /** Unsupported fields are not included in display or forwarded context. */
    public function test_unknown_keys_are_ignored(): void {
        $observed = null;
        $context = $this->get_context();
        $display = \enrol_course_tokens_apply_token_display_callbacks($context, [
            'local' => [
                'alpha' => static fn(array $context): array => ['unknown' => '<script>bad()</script>'],
                'beta' => static function(array $context) use (&$observed): array {
                    $observed = $context;
                    return [];
                },
            ],
        ]);
        $this->assertSame($this->apply_callbacks([]), $display);
        $this->assertSame($context, $observed);
    }

    /** An explicit invalid status suppresses companions but not independent HTML. */
    public function test_invalid_status_codes_suppress_label_and_class(): void {
        foreach ([null, '', ' ', "\t\n", '0', 'unknown', 'Completed', ' completed ', 'dropped_out', 1, ['completed']] as $code) {
            $display = $this->apply_callbacks([
                'local' => ['alpha' => static fn(array $context): array => [
                    'status_code' => $code,
                    'status_label' => 'Misleading label',
                    'status_class' => 'bg-danger text-white',
                    'ecard_html' => '<a>Independent eCard</a>',
                    'forward_html' => '<a>Independent forward</a>',
                ]],
            ]);
            $this->assertSame('available', $display['status_code']);
            $this->assertSame('Available', $display['status_label']);
            $this->assertSame('badge-success', $display['status_class']);
            $this->assertSame('<a>Independent eCard</a>', $display['ecard_html']);
            $this->assertSame('<a>Independent forward</a>', $display['forward_html']);
        }
    }

    /** Accepted codes reset presentation, with invalid companions leaving canonical defaults. */
    public function test_valid_status_codes_use_canonical_defaults(): void {
        $statuses = [
            'available' => ['Available', 'bg-secondary'],
            'assigned' => ['Assigned', 'bg-success text-white'],
            'in_progress' => ['In-progress', 'bg-warning text-dark'],
            'completed' => ['Completed', 'bg-primary text-white'],
            'failed' => ['Failed', 'bg-danger text-white'],
        ];
        foreach ($statuses as $code => [$label, $class]) {
            $display = $this->apply_callbacks([
                'local' => [
                    'alpha' => static fn(array $context): array => [
                        'status_label' => 'Earlier custom label', 'status_class' => 'earlier-class',
                    ],
                    'beta' => static fn(array $context): array => [
                        'status_code' => $code, 'status_label' => [], 'status_class' => 'bad.class',
                    ],
                ],
            ]);
            $this->assertSame($code, $display['status_code']);
            $this->assertSame($label, $display['status_label']);
            $this->assertSame($class, $display['status_class']);
        }
    }

    /** Audited classes and multiple tokens are preserved byte for byte. */
    public function test_valid_css_class_lists_are_preserved(): void {
        foreach (['badge-success', 'bg-primary text-white', 'bg-warning text-dark', 'bg-secondary text-white',
                'bg-success text-white', 'bg-danger text-white', 'course_token-2  _custom -custom 0'] as $class) {
            $display = $this->apply_callbacks([
                'local' => ['alpha' => static fn(array $context): array => ['status_class' => $class]],
            ]);
            $this->assertSame($class, $display['status_class']);
        }
    }

    /** Malformed class lists are rejected as a whole, without partial repair. */
    public function test_malformed_css_class_lists_are_rejected(): void {
        foreach ([' bg-primary', 'bg-primary ', "bg-primary\ttext-white", "bg-primary\ntext-white",
                'bg-primary bad.class', 'bg-primary,bg-danger', 'bg-primary;display:none',
                'bg-primary" onclick="bad()', '<b>bg-primary</b>', "bg-primary\0"] as $class) {
            $display = $this->apply_callbacks([
                'local' => ['alpha' => static fn(array $context): array => ['status_class' => $class]],
            ]);
            $this->assertSame('badge-success', $display['status_class']);
        }
    }

    /** Validation happens before later providers receive the merged context. */
    public function test_invalid_later_overrides_preserve_valid_values_and_context(): void {
        $valid = [
            'status_code' => 'completed', 'status_label' => 'A & B', 'status_class' => 'bg-primary text-white',
            'ecard_html' => '<a href="https://example.com/card">Card</a>',
            'forward_html' => '<a href="mailto:?subject=Card&amp;body=URL">Forward</a>',
        ];
        foreach ([
            ['status_code' => 'unknown', 'status_label' => 'Bad', 'status_class' => 'bad',
                'ecard_html' => [], 'forward_html' => new \stdClass()],
            ['status_label' => [], 'status_class' => 'bad.class', 'ecard_html' => false, 'forward_html' => 1],
            array_fill_keys(array_keys($valid), null),
            array_fill_keys(array_keys($valid), ''),
            array_fill_keys(array_keys($valid), " \t\n"),
            [],
            false,
            'not an array',
        ] as $invalid) {
            $observed = null;
            $display = $this->apply_callbacks([
                'local' => [
                    'alpha' => static fn(array $context): array => $valid,
                    'beta' => static fn(array $context) => $invalid,
                    'gamma' => static function(array $context) use (&$observed): array {
                        $observed = $context;
                        return [];
                    },
                ],
            ]);
            $this->assertSame($valid, $display);
            $expectedcontext = $this->get_context();
            foreach (['status_code', 'status_label', 'status_class'] as $field) {
                $expectedcontext['default_' . $field] = $valid[$field];
            }
            $expectedcontext['ecard_html'] = $valid['ecard_html'];
            $expectedcontext['forward_html'] = $valid['forward_html'];
            $this->assertEquals($expectedcontext, $observed);
        }
    }

    /** The production status-cell renderer escapes raw labels only at output. */
    public function test_status_labels_render_as_literal_text(): void {
        $labels = [
            '<img src=x onerror=alert(1)> A&B "quoted"' =>
                '&lt;img src=x onerror=alert(1)&gt; A&amp;B &quot;quoted&quot;',
            'A &amp; B' => 'A &amp;amp; B',
            '&#60;b&#62;label&#60;/b&#62;' => '&amp;#60;b&amp;#62;label&amp;#60;/b&amp;#62;',
            "Learner's status" => 'Learner&#039;s status',
        ];
        foreach ($labels as $label => $escaped) {
            $observed = null;
            $display = $this->apply_callbacks([
                'local' => [
                    'alpha' => static fn(array $context): array => ['status_label' => $label],
                    'beta' => static function(array $context) use (&$observed): array {
                        $observed = $context['default_status_label'];
                        return [];
                    },
                ],
            ]);
            $this->assertSame($label, $observed);
            $this->assertSame($label, $display['status_label']);
            $this->assertSame('<td class="badge-success">' . $escaped . '</td>',
                \enrol_course_tokens_render_token_status($display));
        }
    }

    /** Trusted HTML preserves links, attributes, modal triggers and email forwarding. */
    public function test_trusted_action_html_is_unchanged(): void {
        $actions = [
            'ecard_html' => ' <a href="https://example.com/card?code=a&amp;token=b" class="btn btn-success"'
                . ' target="_blank" rel="noopener noreferrer" data-bs-toggle="modal" data-bs-target="#cardModal">'
                . '<span>View &amp; share</span></a> ',
            'forward_html' => '<a class="btn btn-primary" href="mailto:?subject=A%20%26%20B&amp;body=https%3A%2F%2Fexample.com">'
                . 'Forward eCard</a>',
        ];
        $display = $this->apply_callbacks([
            'local' => ['alpha' => static fn(array $context): array => $actions],
        ]);
        foreach ($actions as $field => $html) {
            $this->assertSame($html, $display[$field]);
            $this->assertSame('<td>' . $html . '</td>', \html_writer::tag('td', $display[$field]));
        }
    }

    /** Display extensions cannot make non-inventory tokens available. */
    public function test_availability_protection_is_preserved(): void {
        foreach ([['used_on' => 0], ['used_by_user_id' => 1], ['user_enrolments_id' => 1],
                ['voided' => 1], ['voided_at' => 1], ['dropped_out_at' => 1]] as $fields) {
            $context = $this->get_context();
            foreach ($fields as $field => $value) {
                $context['token']->$field = $value;
            }
            $display = \enrol_course_tokens_apply_token_display_callbacks($context, [
                'local' => ['alpha' => static fn(array $context): array => [
                    'status_code' => 'available', 'status_label' => 'Misleading', 'status_class' => 'custom',
                ]],
            ]);
            $this->assertSame('assigned', $display['status_code']);
            $this->assertSame('Assigned', $display['status_label']);
            $this->assertSame('bg-success text-white', $display['status_class']);
        }
    }

    /** Dropout defaults survive without expanding or locking the callback status contract. */
    public function test_dropped_out_defaults_and_override_semantics_are_preserved(): void {
        $context = $this->get_context();
        $context['token']->dropped_out_at = 1;
        $context['default_status_code'] = 'dropped_out';
        $context['default_status_label'] = 'DROPPED OUT';
        $context['default_status_class'] = 'bg-secondary text-white';
        $this->assertSame('dropped_out', \enrol_course_tokens_get_generic_token_status($context['token'], null));
        $expected = \enrol_course_tokens_apply_token_display_callbacks($context, []);
        $this->assertSame('dropped_out', $expected['status_code']);
        $this->assertSame($expected, \enrol_course_tokens_apply_token_display_callbacks($context, [
            'local' => ['alpha' => static fn(array $context): array => [
                'status_code' => 'dropped_out', 'status_label' => 'Changed', 'status_class' => 'changed',
            ]],
        ]));
        $display = \enrol_course_tokens_apply_token_display_callbacks($context, [
            'local' => ['alpha' => [self::class, 'provide_completed_status']],
        ]);
        $this->assertSame('completed', $display['status_code']);
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
