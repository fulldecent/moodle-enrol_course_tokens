<?php
defined('MOODLE_INTERNAL') || die();

$plugin->component = 'enrol_course_tokens';
$plugin->requires  = 2025041400; // Moodle 5.0 or later.
$plugin->supported = [500, 502]; // Moodle 5.0 through Moodle 5.2.
$plugin->maturity  = MATURITY_STABLE;
$plugin->version   = 2026100100;
$plugin->release   = '2.2.0';