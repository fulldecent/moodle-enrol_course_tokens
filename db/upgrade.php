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

    return true;
}
