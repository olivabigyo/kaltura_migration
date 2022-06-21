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
 * Video services migration to kaltura by SWITCH.
 *
 * @package    tool_kaltura_migration
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('NO_OUTPUT_BUFFERING', true);

require_once('../../../config.php');
require_once($CFG->dirroot.'/course/lib.php');
require_once($CFG->libdir.'/adminlib.php');

admin_externalpage_setup('tool_kaltura_migration');



$migration = new tool_kaltura_migration_controller();

$form = new tool_kaltura_migration_form(null, ['numresults' => $migration->countResults()]);

if ($data = $form->get_data()) {
  if ($data->op == get_string('search', 'tool_kaltura_migration')) {
    $progress = new \core\progress\display();
    $migration->execute($progress);
  } else if ($data->op == get_string('deleterecords', 'tool_kaltura_migration')) {
    $migration->deleteResults();
  } else if ($data->op == get_string('downloadcsv', 'tool_kaltura_migration')) {
    $migration->downloadCSV();
  }
}

echo $OUTPUT->header();

echo $OUTPUT->heading(get_string('pageheader', 'tool_kaltura_migration'));

echo $OUTPUT->box_start();
echo $OUTPUT->notification(get_string('excludedtables', 'tool_kaltura_migration'), \core\output\notification::NOTIFY_INFO);
echo $OUTPUT->notification(get_string('searchdeletes', 'tool_kaltura_migration'), \core\output\notification::NOTIFY_INFO);
echo $OUTPUT->box_end();

// Recreate form after operation.
$form = new tool_kaltura_migration_form(null, ['numresults' => $migration->countResults()]);

// Display form. It will depend on the current status.
$form->display();

echo $OUTPUT->footer();
