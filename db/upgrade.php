<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_local_enrollment_tokens_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2024120304) {
        // Define table enrollment_tokens to be created
        $table = new xmldb_table('enrollment_tokens');

        // Adding fields to table enrollment_tokens
        $table->addField(new xmldb_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null));
        $table->addField(new xmldb_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null));
        $table->addField(new xmldb_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null));
        $table->addField(new xmldb_field('code', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null));
        $table->addField(new xmldb_field('course_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null));
        $table->addField(new xmldb_field('voided', XMLDB_TYPE_BINARY, '1', null, XMLDB_NOTNULL, null, '0'));
        $table->addField(new xmldb_field('user_enrolments_id', XMLDB_TYPE_INTEGER, '10', null, null, null, null));
        $table->addField(new xmldb_field('extra_json', XMLDB_TYPE_TEXT, null, null, null, null, null));

        // Add keys to the table
        $table->addKey(new xmldb_key('primary', XMLDB_KEY_PRIMARY, array('id')));
        $table->addKey(new xmldb_key('course_id_fk', XMLDB_KEY_FOREIGN, array('course_id'), 'course', array('id')));
        $table->addKey(new xmldb_key('user_enrolments_id_fk', XMLDB_KEY_FOREIGN, array('user_enrolments_id'), 'user_enrolments', array('id')));

        // Create table
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Enrolltokens savepoint reached
        upgrade_plugin_savepoint(true, 2024120304, 'local', 'enrollment_tokens');
    }

    if ($oldversion < 2024120304) {
        $table = new xmldb_table('enrollment_tokens');
        
        // Adding fields if they don't exist
        if (!$dbman->field_exists($table, 'user_id')) {
            $table->addField(new xmldb_field('user_id', XMLDB_TYPE_INTEGER, '10', null, null, null, null));
            $dbman->add_field($table, new xmldb_field('user_id'));
        }

        if (!$dbman->field_exists($table, 'used_on')) {
            $table->addField(new xmldb_field('used_on', XMLDB_TYPE_INTEGER, '10', null, null, null, null));
            $dbman->add_field($table, new xmldb_field('used_on'));
        }

        // Adding foreign key if the field exists
        if ($dbman->field_exists($table, 'user_id')) {
            $table->addKey(new xmldb_key('user_id_fk', XMLDB_KEY_FOREIGN, array('user_id'), 'user', array('id')));
        }

        // Enrolltokens savepoint reached
        upgrade_plugin_savepoint(true, 2024120305, 'local', 'enrollment_tokens'); // This version needs to be one up.
    }

    if ($oldversion < 2024120304) { // Update for group_account and created_by
        $table = new xmldb_table('enrollment_tokens');

        // Adding new fields
        if (!$dbman->field_exists($table, 'group_account')) {
            $table->addField(new xmldb_field('group_account', XMLDB_TYPE_CHAR, '255', null, null, null, null)); // Corporate Account
            $dbman->add_field($table, new xmldb_field('group_account'));
        }

        if (!$dbman->field_exists($table, 'created_by')) {
            $table->addField(new xmldb_field('created_by', XMLDB_TYPE_INTEGER, '10', null, null, null, null)); // Created By
            $dbman->add_field($table, new xmldb_field('created_by'));
        }

        if (!$dbman->field_exists($table, 'used_by')) {
            $table->addField(new xmldb_field('used_by', XMLDB_TYPE_CHAR, '255', null, null, null, null)); // Used By
            $dbman->add_field($table, new xmldb_field('used_by'));
        }

        // Add keys for new fields
        $table->addKey(new xmldb_key('created_by_fk', XMLDB_KEY_FOREIGN, array('created_by'), 'user', array('id')));

        // Enrolltokens savepoint reached
        upgrade_plugin_savepoint(true, 2024120304, 'local', 'enrollment_tokens');
    }

    return true;
}
