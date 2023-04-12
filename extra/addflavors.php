<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Add flavorParamIds parameter to kaltura video urls.
 *
 * @package    tool
 * @subpackage kaltura_migration
 * @copyright  2022 SWITCH {@link http://switch.ch}
 */

define('NO_OUTPUT_BUFFERING', true);

// Increase other limits found by ZHAW client, they should not be necessary in
// general, but probably required in their particular server configuration.
session_cache_expire(600);
ini_set('opcache.force_restart_timeout', 600);

require_once('../../../../config.php');
require_once($CFG->dirroot.'/course/lib.php');
require_once("$CFG->libdir/formslib.php");


class tool_kaltura_flavors_form extends moodleform {
  function definition() {
    $mform = $this->_form;
    $mform->addElement('header', 'addflavorsheader', get_string('addflavorsheader', 'tool_kaltura_migration'));
    $mform->setExpanded('addflavorsheader');
    $message = get_string('addflavorsdescription', 'tool_kaltura_migration');
    $mform->addElement('static', 'description', '', $message);

    $buttonarray = [];
    $buttonarray[] = $mform->createElement('submit', 'testflavors', get_string('testflavors', 'tool_kaltura_migration'));
    $buttonarray[] = $mform->createElement('submit', 'addflavors', get_string('addflavors', 'tool_kaltura_migration'));
    $mform->addGroup($buttonarray, 'buttonar', '', ' ', false);
  }
}

// Increase server limits for this migration script.
core_php_time_limit::raise();
raise_memory_limit(MEMORY_EXTRA);

$PAGE->set_url(new moodle_url('/admin/tool/kaltura_migration/extra/addflavors.php'));
$context = context_system::instance();
$PAGE->set_context($context);
$title = get_string('addflavorspageheader', 'tool_kaltura_migration');
$PAGE->set_title($title);
$PAGE->set_heading($title);

require_capability('moodle/site:config', context_system::instance());

echo $OUTPUT->header();

$addflavors = optional_param('addflavors', 0, PARAM_BOOL);
$testflavors = optional_param('testflavors', 0, PARAM_BOOL);

if ($addflavors || $testflavors) {
  $migration = new tool_kaltura_migration_controller();
  $progress = new \core\progress\display_if_slow();
  if ($addflavors) {
    $migration->addFlavorsToKalturaUrls($progress);
  } else if($testflavors) {
    $migration->addFlavorsToKalturaUrls($progress, true);
  }
}

echo $OUTPUT->box_start();
echo $OUTPUT->notification(get_string('backupwarning', 'tool_kaltura_migration'), \core\output\notification::NOTIFY_WARNING);
echo $OUTPUT->box_end();

// Display form.
$form = new tool_kaltura_flavors_form();
$form->display();

echo $OUTPUT->footer();



