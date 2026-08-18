<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_enrol_course_tokens_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    // Upgrade to version 2024120305: Add voided_at and voided_notes fields.
    if ($oldversion < 2024120305) {
        $table = new xmldb_table('course_tokens');

        // Add voided_at field if it does not exist.
        if (!$dbman->field_exists($table, 'voided_at')) {
            $voidedAtField = new xmldb_field('voided_at', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $dbman->add_field($table, $voidedAtField);
        }

        // Add voided_notes field if it does not exist.
        if (!$dbman->field_exists($table, 'voided_notes')) {
            $voidedNotesField = new xmldb_field('voided_notes', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $dbman->add_field($table, $voidedNotesField);
        }

        // Upgrade savepoint.
        upgrade_plugin_savepoint(true, 2024120305, 'enrol', 'course_tokens');
    }

    // Upgrade to version 2024120306: Change voided from longblob to tinyint(1).
    if ($oldversion < 2024120306) {
        $table = new xmldb_table('course_tokens');

        // Check if voided is of incorrect type and change it.
        $voidedField = new xmldb_field('voided', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $dbman->change_field_type($table, $voidedField);

        // Upgrade savepoint.
        upgrade_plugin_savepoint(true, 2024120306, 'enrol', 'course_tokens');
    }

    // Upgrade to version 2024120307: Drop the 'used_by' field.
        if ($oldversion < 2024120307) {
            $table = new xmldb_table('course_tokens');

            // Drop the field if it exists.
            if ($dbman->field_exists($table, 'used_by')) {
                $field = new xmldb_field('used_by');
                $dbman->drop_field($table, $field);
            }

            // Upgrade savepoint.
            upgrade_plugin_savepoint(true, 2024120307, 'enrol', 'course_tokens');
        }

        // Upgrade: Add index for timecreated to optimize pagination.
        if ($oldversion < 2026050201) {
            $table = new xmldb_table('course_tokens');
            $index = new xmldb_index('timecreated', XMLDB_INDEX_NOTUNIQUE, ['timecreated']);

            // Conditionally launch add index timecreated
            if (!$dbman->index_exists($table, $index)) {
                $dbman->add_index($table, $index);
            }

            // Upgrade savepoint.
            upgrade_plugin_savepoint(true, 2026050201, 'enrol', 'course_tokens');
        }

        // Add permanent learner identity and conservatively backfill consumed tokens.
        if ($oldversion < 2026081700) {
            $table = new xmldb_table('course_tokens');
            $field = new xmldb_field(
                'used_by_user_id',
                XMLDB_TYPE_INTEGER,
                '10',
                null,
                null,
                null,
                null,
                'user_id'
            );
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }

            // Prefer the still-live Moodle enrolment link.
            $tokens = $DB->get_recordset_select(
                'course_tokens',
                'used_on IS NOT NULL AND used_by_user_id IS NULL AND user_enrolments_id IS NOT NULL',
                [],
                '',
                'id, course_id, user_enrolments_id'
            );
            foreach ($tokens as $token) {
                $userid = $DB->get_field_sql(
                    "SELECT ue.userid
                       FROM {user_enrolments} ue
                       JOIN {enrol} e ON e.id = ue.enrolid
                      WHERE ue.id = :ueid
                        AND e.courseid = :courseid",
                    [
                        'ueid' => $token->user_enrolments_id,
                        'courseid' => $token->course_id,
                    ]
                );
                if ($userid !== false) {
                    $DB->set_field('course_tokens', 'used_by_user_id', (int) $userid, ['id' => $token->id]);
                }
            }
            $tokens->close();

            // Detached legacy cycles are only attributable when exact-time plugin
            // events unanimously identify one existing learner.
            if ($dbman->table_exists('logstore_standard_log')) {
                $tokens = $DB->get_recordset_select(
                    'course_tokens',
                    'used_on IS NOT NULL AND used_by_user_id IS NULL',
                    [],
                    '',
                    'id, course_id, used_on'
                );
                foreach ($tokens as $token) {
                    $userids = $DB->get_fieldset_sql(
                        "SELECT DISTINCT relateduserid
                           FROM {logstore_standard_log}
                          WHERE component = :component
                            AND objecttable = :objecttable
                            AND objectid = :objectid
                            AND timecreated = :usedon
                            AND courseid = :courseid
                            AND (eventname = :enrollevent OR eventname = :renewalevent)
                            AND relateduserid IS NOT NULL
                            AND relateduserid > 0",
                        [
                            'component' => 'enrol_course_tokens',
                            'objecttable' => 'course_tokens',
                            'objectid' => $token->id,
                            'usedon' => $token->used_on,
                            'courseid' => $token->course_id,
                            'enrollevent' => '\\enrol_course_tokens\\event\\user_enrolled_via_token',
                            'renewalevent' => '\\enrol_course_tokens\\event\\token_renewal_confirmed',
                        ]
                    );
                    if (count($userids) === 1 && $DB->record_exists('user', ['id' => (int) reset($userids)])) {
                        $DB->set_field(
                            'course_tokens',
                            'used_by_user_id',
                            (int) reset($userids),
                            ['id' => $token->id]
                        );
                    }
                }
                $tokens->close();
            }

            $key = new xmldb_key(
                'used_by_user_id',
                XMLDB_KEY_FOREIGN,
                ['used_by_user_id'],
                'user',
                ['id']
            );
            $dbman->add_key($table, $key);
            $index = new xmldb_index(
                'learner_course_used',
                XMLDB_INDEX_NOTUNIQUE,
                ['used_by_user_id', 'course_id', 'used_on']
            );
            if (!$dbman->index_exists($table, $index)) {
                $dbman->add_index($table, $index);
            }

            upgrade_plugin_savepoint(true, 2026081700, 'enrol', 'course_tokens');
        }

    return true;
}
