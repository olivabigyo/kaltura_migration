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
 * Video services migration to kaltura by SWITCH, main page.
 *
 * @package    tool
 * @subpackage kaltura_migration
 * @copyright  2022 SWITCH {@link http://switch.ch}
 */

define('NO_OUTPUT_BUFFERING', true);

require_once('../../../config.php');
require_once($CFG->dirroot.'/course/lib.php');
require_once($CFG->libdir.'/adminlib.php');

admin_externalpage_setup('tool_kaltura_migration');

$migration = new tool_kaltura_migration_controller();

$form = new tool_kaltura_migration_form(null, [
  'numresults' => $migration->countResults(),
  'numreplaced' => $migration->countReplaced(),
  'nummodules' => $migration->countModules()
]);

if ($data = $form->get_data()) {
  if ($data->op == get_string('downloadcsv', 'tool_kaltura_migration')) {
    $migration->downloadCSV();
    // downloadCSV ends execution.
  }
}

echo $OUTPUT->header();

echo $OUTPUT->heading(get_string('pageheader', 'tool_kaltura_migration'));

echo $OUTPUT->box_start();
echo $OUTPUT->notification(get_string('excludedtables', 'tool_kaltura_migration'), \core\output\notification::NOTIFY_INFO);
echo $OUTPUT->notification(get_string('backupwarning', 'tool_kaltura_migration'), \core\output\notification::NOTIFY_WARNING);
echo $OUTPUT->box_end();

$errors = false;
$course = -1;
$op = false;
if ($data) {
  echo $OUTPUT->box_start();
  echo $OUTPUT->heading(get_string('results', 'tool_kaltura_migration'), 3);
  if (!empty($data->opsearch)) {
    $op = 'opsearch';
    $progress = new \core\progress\display_if_slow();
    $migration->execute($progress);
  } else if (!empty($data->opdelete)) {
    $op = 'opdelete';
    $migration->deleteResults();
  } else if (!empty($data->optestreplacevideos)) {
    $op = 'optestreplacevideos';
    $course = $data->coursesreplacevideos;
    $errors = $migration->replace($course, true);
  } else if (!empty($data->opreplacevideos)) {
    $op = 'opreplacevideos';
    $course = $data->coursesreplacevideos;
    $errors = $migration->replace($course);
  } else if (!empty($data->opreplacemodules)) {
    $op = 'opreplacemodules';
    $course = $data->coursesreplacemodules;
    $errors = $migration->replaceModules($course);
  } else if (!empty($data->optestreplacemodules)) {
    $op = 'optestreplacemodules';
    $course = $data->coursesreplacemodules;
    $errors = $migration->replaceModules($course, true);
  }
  echo $OUTPUT->box_end();
}


// Recreate form after operation.
$form = new tool_kaltura_migration_form(null, [
  'numresults' => $migration->countResults(),
  'numreplaced' => $migration->countReplaced(),
  'nummodules' => $migration->countModules(),
  'numerrors' => is_array($errors) ? count($errors) : 0,
  'op' => $op,
  'course' => $course
]);

// Display form. It will depend on the current status.
$form->display();

echo $OUTPUT->footer();
