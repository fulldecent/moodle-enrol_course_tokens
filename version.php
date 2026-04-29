<?php
defined('MOODLE_INTERNAL') || die();

$plugin->component = 'enrol_course_tokens';
$plugin->requires  = 2020061500;  // Requires this Moodle version
$plugin->maturity  = MATURITY_STABLE;
$plugin->version   = 2026042901;  // New version for dropping used_by column
$plugin->release   = '2.0.0';