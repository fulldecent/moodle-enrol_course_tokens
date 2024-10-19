<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_local_enrollment_tokens_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2024040801) {
        // Define table enrollment_tokens to be created
        $table = new xmldb_table('enrollment_tokens');

        // Adding fields to table enrollment_tokens
        $table->addField(new xmldb_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null));
        $table->addField(new xmldb_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null));
        $table->addField(new xmldb_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null));
        $table->addField(new xmldb_field('code', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, null));
        $table->addField(new xmldb_field('course_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null));
        $table->addField(new xmldb_field('voided', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0'));
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
        upgrade_plugin_savepoint(true, 2024040801, 'local', 'enrollment_tokens');
    }
    if($oldversion < 20240909){
        $table = new xmldb_table('enrollment_tokens');
        $table->addField(new xmldb_field('user_id', XMLDB_TYPE_INTEGER, '10', null, null, null, null));
        $table->addField(new xmldb_field('used_on', XMLDB_TYPE_INTEGER, '10', null, null, null, null));
        $table->addKey(new xmldb_key('user_id_fk', XMLDB_KEY_FOREIGN, array('user_id'), 'user', array('id')));
        // Enrolltokens savepoint reached
        upgrade_plugin_savepoint(true, 20240909, 'local', 'enrollment_tokens');
    }

    if ($oldversion < 2024101901) { // Use an appropriate version number for the next update
        $table = new xmldb_table('enrollment_tokens');

        // Adding new fields
        $table->addField(new xmldb_field('corporate_account', XMLDB_TYPE_TEXT, null, null, null, null, null)); // Corporate Account
        $table->addField(new xmldb_field('created_by', XMLDB_TYPE_INTEGER, '10', null, null, null, null)); // Created By

        // Add keys for new fields
        $table->addKey(new xmldb_key('created_by_fk', XMLDB_KEY_FOREIGN, array('created_by'), 'user', array('id')));

        // Update table with new fields
        $dbman->add_field($table, new xmldb_field('corporate_account'));
        $dbman->add_field($table, new xmldb_field('created_by'));

        // Enrolltokens savepoint reached
        upgrade_plugin_savepoint(true, 20240919, 'local', 'enrollment_tokens');
    }

    return true;
}
