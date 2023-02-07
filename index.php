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

// Increase server limits for this migration script.
core_php_time_limit::raise();
raise_memory_limit(MEMORY_EXTRA);
// Increase other limits found by ZHAW client, they should not be necessary in
// general, but probably required in their particular server configuration.
session_cache_expire(600);
ini_set('opcache.force_restart_timeout', 600);

admin_externalpage_setup('tool_kaltura_migration');

$migration = new tool_kaltura_migration_controller();

$op = false;
$course = false;

$coursesreplacevideos = optional_param('coursesreplacevideos', -2, PARAM_INT);
$coursesreplacemodules = optional_param('coursesreplacemodules', -2, PARAM_INT);

if($opsearch = optional_param('opsearch', 0, PARAM_BOOL)) {
  $op = 'opsearch';
}
if ($opdelete = optional_param('opdelete', 0, PARAM_BOOL)) {
  $op = 'opdelete';
}
if ($optestreplacevideos = optional_param('optestreplacevideos', 0, PARAM_BOOL)) {
  $op = 'optestreplacevideos';
}
if ($opreplacevideos = optional_param('opreplacevideos', 0, PARAM_BOOL)) {
  $op = 'opreplacevideos';
}
if ($optestreplacemodules = optional_param('optestreplacemodules', 0, PARAM_BOOL)) {
  $op = 'optestreplacemodules';
}
if ($opreplacemodules = optional_param('opreplacemodules', 0, PARAM_BOOL)) {
  $op = 'opreplacemodules';
}
if ($opdownloadurls = optional_param('opdownloadurls', 0, PARAM_BOOL)) {
  $op = 'opdownloadurls';
}
if ($opdownloadlogs = optional_param('opdownloadlogs', 0, PARAM_BOOL)) {
  $op = 'opdownloadlogs';
}

if ($opdownloadurls) {
  $migration->downloadCSV('tool_kaltura_migration_urls');
  exit(0);
} else if ($opdownloadlogs) {
  $migration->downloadCSV('tool_kaltura_migration_logs');
  exit(0);
}

if ($optestreplacevideos || $opreplacevideos) {
  $course = $coursesreplacevideos;
} else if ($optestreplacemodules || $opreplacemodules) {
  $course = $coursesreplacemodules;
}

$modulestocoursemedia = optional_param('modulestocoursemedia', 0, PARAM_BOOL);

echo $OUTPUT->header();

echo $OUTPUT->heading(get_string('pageheader', 'tool_kaltura_migration'));

echo $OUTPUT->box_start();
echo $OUTPUT->notification(get_string('excludedtables', 'tool_kaltura_migration'), \core\output\notification::NOTIFY_INFO);
echo $OUTPUT->notification(get_string('backupwarning', 'tool_kaltura_migration'), \core\output\notification::NOTIFY_WARNING);
$hasvideogallerylti = !empty($migration->getVideoGalleryLTIType());
if (!$hasvideogallerylti) {
  echo $OUTPUT->notification(get_string('nomediagallery', 'tool_kaltura_migration'), \core\output\notification::NOTIFY_WARNING);
}
if (!$migration->checkKalturaAPIConnection()) {
  echo $OUTPUT->notification(get_string('nokalturaapiconnection', 'tool_kaltura_migration'), \core\output\notification::NOTIFY_ERROR);
} else if (get_config('tool_kaltura_migration', 'uiconf_id') != $migration->getUIConfId()) {
  $uiconfid = $migration->getUIConfId();
  echo $OUTPUT->notification(get_string('nouiconfid', 'tool_kaltura_migration', $uiconfid), \core\output\notification::NOTIFY_WARNING);
}
echo $OUTPUT->box_end();

$errors = false;
if ($op) {
  echo $OUTPUT->box_start();
  echo $OUTPUT->heading(get_string('results', 'tool_kaltura_migration'), 3);
  if ($opsearch) {
    $progress = new \core\progress\display_if_slow();
    $migration->execute($progress);
  } else if ($opdelete) {
    $migration->deleteResults();
  } else if ($optestreplacevideos) {
    $errors = $migration->replace($course, true);
  } else if ($opreplacevideos) {
    $errors = $migration->replace($course);
  } else if ($optestreplacemodules) {
    $errors = $migration->replaceModules($course, $modulestocoursemedia, true);
  } else if ($opreplacemodules) {
    $errors = $migration->replaceModules($course, $modulestocoursemedia);
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
  'course' => $course,
  'modulestocoursemedia' => $modulestocoursemedia,
  'hasvideogallerylti' => $hasvideogallerylti,
]);

// Display form. It will depend on the current status.
$form->display();

echo $OUTPUT->footer();
