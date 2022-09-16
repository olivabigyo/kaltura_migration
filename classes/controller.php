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
 * Kaltura migration loginc implementation.
 *
 * @package    tool
 * @subpackage kaltura_migration
 * @copyright  2022 SWITCH {@link http://switch.ch}
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;
// db_should_replace function.
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/mod/lti/locallib.php');
require_once($CFG->dirroot . '/mod/lti/edit_form.php');

require_once(__DIR__ . '/api.php');

class tool_kaltura_migration_controller {
  /** @var \core\progress\base Progress bar for the current operation. */
  protected $progress;
  /** @var array video URL subsctrings to search for*/
  protected $hosts = ['tube.switch.ch', 'cast.switch.ch', 'download.cast.switch.ch'];

  /** @var array map of category updates during a testing session. */
  protected $testing_updates = [];
  /** @var array of "course-cmid" for new modules to be created during a testing session. */
  protected $testing_created_modules = [];

  /**
   * @param \core\progress\base $progress Progress bar object, not started.
   */
  function execute($progress) {

    $this->progress = $progress;
    $this->deleteResults();
    $this->search();

    global $OUTPUT;
    echo $OUTPUT->notification(get_string('foundnvideos', 'tool_kaltura_migration', $this->countResults()), \core\output\notification::NOTIFY_SUCCESS);
  }
  /**
   * Search for video URLs in the specified table and column.
   * @param string $table Name of the table.
   * @param database_column_info $column Column object.
   * @return array of matching full column values indexed by id field (if exists).
   */
  protected function search_table_column($table, $column) {
    global $DB;
    // Build where clause: (column LIKE %pattern1%) OR (column LIKE %patttern2%)
    $colname = $DB->get_manager()->generator->getEncQuoted($column->name);
    $searchsql = $DB->sql_like($colname, '?');
    $params = [];
    $where = '';
    foreach ($this->hosts as $host) {
      // That's the longest string that can used from the available info.
      $pattern = '://' . $host . '/';
      if ($where != '') {
        $where .= ' OR';
      }
      $where .= ' (' . $searchsql . ')';
      $params[] = '%' . $DB->sql_like_escape($pattern) . '%';
    }

    if ($this->tableHasId($table)) {
      $result = $DB->get_records_select_menu($table, $where, $params, '', 'id, ' . $column->name);
    } else {
      $result = $DB->get_fieldset_select($table, $column->name, $where);
    }
    return $result;
  }
  /**
   * @param string table The table name.
   * @return boolean If the table has id field.
   */
  protected function tableHasId($table) {
    global $DB;
    $columns = $DB->get_columns($table);
    return isset($columns['id']);
  }
  /**
   * @param string text The text to extract the video urls from.
   * @return array of urls.
   */
  protected function extractUrls($text) {
    $hosts = implode("|", $this->hosts);
    $chars = "[a-zA-Z0-9:;&\#@=_~%\?\/\.\,\+\-]";
    $pattern = '#https?://(' . $hosts . ')/' . $chars . '+#';
    $urls = [];
    if (preg_match_all($pattern, $text, $matches)) {
      foreach ($matches[0] as $url) {
        $urls[] = $url;
      }
    }
    return $urls;
  }

  protected function shouldSearch($table, $column = '') {
    return $table !== 'tool_kaltura_migration_urls' && db_should_replace($table, $column);
  }

  /**
   * @param string $table DB table name
   * @return int the course id related to the record $id from table $table, or -1
   * if unknown or not applicable.
   */
  protected function getRecordCourse($table, $columns, $id) {
    global $DB;
    if (!isset($columns['id'])) {
      return -1;
    }
    try {
      $course = false;
      if (isset($columns['course'])) {
        $course = $DB->get_field($table, 'course', ['id' => $id]);
      }
      if ($table == 'question') {
        if (isset($columns['category'])) {
          // Moodle 3
          $course = $DB->get_field_sql(
            'SELECT c.instanceid FROM {context} c
            JOIN {question_categories} qc ON qc.contextid = c.id
            JOIN {question} q ON q.category = qc.id
            WHERE c.contextlevel = 50 AND q.id = ?',
            [$id]);
        } else {
          // Moodle 4
          $course = $DB->get_field_sql(
            'SELECT c.instanceid FROM {context} c
              JOIN {question_categories} qc ON qc.contextid = c.id
              JOIN {question_bank_entries} qbe ON qbe.questioncategoryid = qc.id
              JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
              JOIN {question} q ON q.id = qv.questionid
              WHERE c.contextlevel = 50 AND q.id = ?',
              [$id]);
        }
      }
      if ($table == 'wiki_pages') {
        $course = $DB->get_field_sql(
          'SELECT w.course FROM {wiki} w
          JOIN {wiki_subwikis} s ON s.wikiid = w.id
          JOIN {wiki_pages} p ON p.subwikiid = s.id
          WHERE p.id = ?',
          [$id]);
      }
      if ($table == 'wiki_versions') {
        $course = $DB->get_field_sql(
          'SELECT w.course FROM {wiki} w
          JOIN {wiki_subwikis} s ON s.wikiid = w.id
          JOIN {wiki_pages} p ON p.subwikiid = s.id
          JOIN {wiki_versions} v ON v.pageid = p.id
          WHERE v.id = ?',
          [$id]);
      }
      return ($course === false) ? -1 : $course;
    } catch (Exception $e) {
      return -1;
    }
  }
  /**
   * Search video URLs in the whole database.
   *
  */
  protected function search() {
    global $DB;
    if (!$tables = $DB->get_tables()) {    // No tables yet at all.
      return false;
    }
    \core_php_time_limit::raise();

    $this->progress->start_progress('Searching video urls across all database.', count($tables));
    purge_all_caches();
    $progress = 0;
    // Iterate over all DB tables.
    foreach ($tables as $table) {
      // Ignore some tables.
      if (!$this->shouldSearch($table)) {
        continue;
      }
      // Iterate over table columns.
      if ($columns = $DB->get_columns($table)) {
        foreach ($columns as $column) {
          // Skip non-text columns
            if ($column->meta_type != 'X' && $column->meta_type != 'C') {
            continue;
          }
          // Skip some other columns.
          if (!$this->shouldSearch($table, $column->name)) {
            continue;
          }
          // Get matching fields.
          $result = $this->search_table_column($table, $column);
          // Extract urls and prepare records for db insertion.
          $records = [];
          foreach ($result as $id => $field) {
            $urls  = $this->extractUrls($field);
            $course = $this->getRecordCourse($table, $columns, $id);

            foreach ($urls as $url) {
              $records[] = array(
                'tblname' => $table,
                'colname' => $column->name,
                'resid' => $id,
                'url' => $url,
                'replaced' => false,
                'course' => $course
              );
            }
          }
          // Save results into DB.
          $DB->insert_records('tool_kaltura_migration_urls', $records);
        }
      }
      $progress++;
      $this->progress->progress($progress);
    }
    $this->progress->end_progress();
  }

  /**
   * Delete all saved records from a previous execute() invocation.
   */
  function deleteResults() {
    global $DB;
    $DB->delete_records('tool_kaltura_migration_urls');
  }
  /**
   * Determine the number of saved results from a previoyus execute() invocation.
   *
   * @return int The number of saved search results.
   */
  function countResults() {
    global $DB;
    return $DB->count_records('tool_kaltura_migration_urls');
  }

  function countReplaced() {
    global $DB;
    return $DB->count_records('tool_kaltura_migration_urls', ['replaced' => true]);
  }

  protected function getCSV() {
    global $DB;
    $csv = '';
    $columns = $DB->get_columns('tool_kaltura_migration_urls');
    // Headers row.
    $csv .= implode(',', array_map(function($column) {return $column->name; }, $columns)) . "\n";
    $records = $DB->get_records('tool_kaltura_migration_urls');
    foreach ($records as $record) {
      $csv .= implode(',', (array) $record) . "\n";
    }
    return $csv;
  }

  /**
   * Send a CSV file for download.
   */
  function downloadCSV() {
    $fs = get_file_storage();
    $context = context_system::instance();
    $fileinfo = array(
      'contextid' => $context->id,
      'component' => 'tool_kaltura_migration',
      'filearea' => 'tool_kaltura_migration',
      'itemid' => 0,
      'filepath' => '/',
      'filename' => 'switch_video_urls.csv'
    );
    // Delete file if already exists.
    $file = $fs->get_file($fileinfo['contextid'], $fileinfo['component'],
      $fileinfo['filearea'], $fileinfo['itemid'], $fileinfo['filepath'], $fileinfo['filename']);
    if ($file) {
      $file->delete();
    }
    $file = $fs->create_file_from_string($fileinfo, $this->getCSV());
    send_stored_file($file, 0, 0, true);
  }

  /**
   * @return array
  */
  protected function getReferenceIdsFromUrl($url) {
    if (preg_match('#https?://[a-zA-z0-9\._\-/]*?/([a-f0-9\-]{36})/([a-f0-9\-]{36})/.*#', $url, $matches)) {
      return [$matches[1], $matches[2]];
    }
    if (preg_match('#https?://[a-zA-z0-9\._\-/]*/([a-zA-Z0-9]{8,10})(\?|\#|$)#', $url, $matches)) {
      return [$matches[1]];
    }
    return false;
  }

  /**
   * Replace switch video embeds by kaltura embeds.
   * @param string|int $course id, if nonnegative, content not in any course if -1 and all courses if -2.
   * @return true if no errors, array of error strings if errors.
   */
  public function replace($course = -2, $test = false) {
    global $DB;
    $errors = [];
    $conditions = [];
    if (intval($course) > -2) {
      $conditions['course'] = $course;
    }
    $records = $DB->get_records('tool_kaltura_migration_urls', $conditions);

    $api = new tool_kaltura_migration_api();
    echo '<table border="1">';
    $i = 1;
    $replaced = 0;
    foreach ($records as $record) {
      $table = $record->tblname;
      $id = $record->resid;
      if (!$record->replaced) {
        echo '<tr><td>' . $i++ . ' ('. $table . ' ' . $id . ')</td>';
        $column = $record->colname;
        $url = $record->url;
        $referenceIds = $this->getReferenceIdsFromUrl($url);
        if (!$referenceIds) {
          $error = 'Error: could not get refid from url ' . $url;
          echo "<td>$error</td>";
          $errors[] = $error;
        } else {
          $entry = $api->getMediaByReferenceIds($referenceIds);
          if (!$entry) {
            $error = 'Error: could not get Kaltura media with refid ' . implode(',', $referenceIds);
            echo "<td>$error</td>";
            $errors[] = $error;
          } else if ($this->replaceVideo($table, $column, $id, $url, $entry, $test)) {
            $record->replaced = true;
            $DB->update_record('tool_kaltura_migration_urls', $record);
            $replaced++;
            echo '<td>Video replaced with refid ' . implode(',', $referenceIds) . '</td>';
          } else if (!$test) {
            $error = 'Error: Could not replace html content.';
            echo "<td>$error</td>";
            $errors[] = $error;
          }
        }
        echo '</tr>';
      }
    }
    echo '</table>';

    if (!$test) {
      global $OUTPUT;
      echo $OUTPUT->notification(get_string('replacednvideos', 'tool_kaltura_migration', $replaced), \core\output\notification::NOTIFY_SUCCESS);
    }

    return count($errors) == 0 ? true : $errors;
  }

  /**
   * Replaces a single video embedding from a DB text field.
   */
  function replaceVideo($table, $column, $id, $url, $entry, $test = false) {
    $iframe_reg = '/<iframe\s[^>]*src\s*=\s*"' . preg_quote($url, "/") . '"[^>]*width="(\d+)"\s+height="(\d+)"[^>]*><\/iframe>/';
    $iframe2_reg = '/<iframe\s[^>]*width="(\d+)"\s+height="(\d+)"[^>]*src\s*=\s*"' . preg_quote($url, "/") . '"[^>]*><\/iframe>/';
    $video_reg = '/<video\s[^>]*width="(\d+)"\s+height="(\d+)"[^>]*><source\ssrc="'. preg_quote($url, "/") .'">[^<]*<\/video>/';
    $video2_reg = '/<video\s[^>]*><source\s[^>]*src="' . preg_quote($url, "/") . '"[^>]*>.*?<\/video>/';

    global $DB;
    $content = $DB->get_field($table, $column, ['id' => $id]);
    if ($test) {
      echo '<td>' . $content . '</td>';
    }
    // replace video embeddings
    $content = preg_replace_callback([$iframe_reg, $iframe2_reg, $video_reg, $video2_reg], function($matches) use ($entry) {
      $width = count($matches) > 2 ? $matches[1] : null;
      $height = count($matches) > 2 ? $matches[2] : null;
      return $this->getKalturaEmbedCode($entry, $width, $height);
    }, $content);
    // replace video links and other references (not embeddings)
    $content = str_replace($url, $this->getKalturaVideoUrl($entry), $content);
    if ($test) {
      echo '<td>' . $content . '</td>';
      return false;
    } else {
      return $DB->set_field($table, $column, $content, ['id' => $id]);
    }
  }

  function getKalturaVideoUrl($entry) {
    return $entry->dataUrl;
  }

  function getKalturaEmbedCode($entry, $width = null, $height = null) {
    $style  = '';
    if ($width !== null && $height !== null) {
      $style = "style=\"width: {$width}px; height: {$height}px;\"";
    }
    $hash = mt_rand();
    return <<<EOD
<script src="https://api.cast.switch.ch/p/105/sp/10500/embedIframeJs/uiconf_id/23448506/partner_id/105"></script>
<div id="kaltura_player_{$hash}" {$style}></div>
<script>
kWidget.embed({
  "targetId": "kaltura_player_{$hash}",
  "wid": "_105",
  "uiconf_id": 23448506,
  "flashvars": {},
  "entry_id": "{$entry->id}"
});
</script>
EOD;
  }

  /**
   * @return int the number of opencast modules.
   */
  function countModules() {
    global $DB;
    $id = $DB->get_field('modules', 'id', ['name' =>'opencast']);
    return $DB->count_records('course_modules', ['module'=> $id]);
  }

  /**
   * $course >= 0.
   */
  protected function replaceModulesToCourseMediaSingle($api, $course, $testing = false) {
    global $DB;
    $cms = $this->getAllSwitchCastModules($course);

    $category = $this->getCourseCategory($api, $course, $testing);
    if ($category === false) {
      $error = $this->swithCastModuleErrorMessage($cm, "Error creating course category. Can't migrate.");
      echo $error;
    }
    echo '<table border="1">';
    $i = 1;
    $replaced = 0;
    foreach ($cms as $cm) {
      echo "<tr><td>$i</td><td>";
      $instance = $this->getSwitchCastInstance($cm);
      $cmcategories = $api->getCategoriesByReferenceId($instance->ext_id);
      if (count($cmcategories) == 0) {
        $error = $this->swithCastModuleErrorMessage($cm, "Kaltura category with reference id {$instance->ext_id} not found.");
        echo $error;
      } else {
        $cmcategory = $cmcategories[0];
        if (count($cmcategories) > 1) {
          echo "<p>Warning: more than one category found with reference id {$instance->ext_id}. Taking the one with id {$cmcategory->id}.</p>";
        }
        if ($testing) {
          echo "<p>Module '{$instance->name}' ready to migrate!</p>";
        } else {
          // copy all media from module category to course category.
          $api->copyMedia($cmcategory, $category);
          // Remove the switchcast cm.
            course_delete_module($cm->id);
          echo "<p>Media successfully migrated from module name '{$instance->name}' to Course Media Gallery.</p>";
          $replaced++;
        }
      }
      echo '</td></tr>' . "\n";
      $i++;
    }
    echo '</table>';
    if (!$testing) {
      global $OUTPUT;
      echo $OUTPUT->notification(get_string('replacednmodules', 'tool_kaltura_migration', $replaced), \core\output\notification::NOTIFY_SUCCESS);
    }
  }
  /**
   * Delete all switchcast activities and send all media from Switchcast modules
   * to the Course Media Gallery category.
   * */
  function replaceModulesToCourseMedia($course = -1, $testing = false) {
    global $DB;
    $api = new tool_kaltura_migration_api();
    if ($course >= 0) {
      $this->replaceModulesToCourseMediaSingle($api, $course, $testing);
    } else {
      // Courses with opencast modules.
      $course_ids = $DB->get_fieldset_sql('SELECT DISTINCT course FROM {opencast}');
      foreach ($course_ids as $course_id) {
        $this->replaceModulesToCourseMediaSingle($api, $course_id, $testing);
      }
    }
  }

  /**
   * Replace every course module of type opencast by a LTI external tool of type
   * "Media Gallery".
   * @param int $course The course to migrate, negative for all courses.
   * @param bool $testing
   *
   * @return mixed boolean TRUE if no errors, an array of error messages if any error.
   */
  function replaceModulesToLTI($course = -2, $testing = false) {
    // Get all switchcast modules.
    $cms = $this->getAllSwitchCastModules($course);
    $errors = [];
    $api = new tool_kaltura_migration_api();

    echo '<table border="1">';
    $i = 1;
    $replaced = 0;
    foreach ($cms as $cm) {
      echo "<tr><td>$i</td><td>";
      $i++;
      $instance = $this->getSwitchCastInstance($cm);
      $modinfo = $this->getLTIModuleInfoFromSwitchCast($cm, $instance);
      if (!$modinfo) {
          $error = $this->swithCastModuleErrorMessage($cm, "Create first the Video Gallery LTI type. Can't migrate!");
          echo $error;
          $errors[] = $error;
      } else {
        $category = $this->getModuleCategory($api, $cm, $instance, $testing);
        if ($category === false) {
          // Category not found. Report error!
          $error = $this->swithCastModuleErrorMessage($cm, "Could not associate kaltura category. Can't migrate!");
          echo $error;
          $errors[] = $error;
        } else {
          if ($testing) {
            echo '<p>Ready to migrate!</p>';
            $this->testing_created_modules[] = $category->name;
          } else {
            $this->createModule($cm, $modinfo);
            // Remove the switchcast cm.
            course_delete_module($cm->id);
            $replaced++;
            echo "<p>Successfully migrated <em>{$instance->name}</em>!</p>";
          }
        }
      }
      echo '</td></tr>' . "\n";
    }
    echo '</table>';

    if (!$testing) {
      global $OUTPUT;
      echo $OUTPUT->notification(get_string('replacednmodules', 'tool_kaltura_migration', $replaced), \core\output\notification::NOTIFY_SUCCESS);
    }

    return (count($errors) == 0) ? true : $errors;
  }

  /**
   * Migrate switchcast modules either to the kaltura course media gallery or to
   * external tool activities.
  */
  function replaceModules($course = -2, $modulestocoursemedia = false, $testing = false) {
    if ($modulestocoursemedia) {
      return $this->replaceModulesToCourseMedia($course, $testing);
    } else {
      return $this->replaceModulesToLTI($course, $testing);
    }
  }

  /**
   *  This (most probably) the new id of the module that we are about to create.
   */
  protected function guessNextModuleId($testing = false) {
    global $DB;
    $newid = $DB->get_field_sql("SELECT MAX(id) FROM {course_modules}") + 1;
    if ($testing) {
      $newid += count($this->testing_created_modules);
    }
    return $newid;
  }

  /** @return string "Moodle>site>channels" */
  protected function getParentCategoryFullName() {
    return 'Moodle>site>channels';
  }

  /**
   * Fetches the category identified by fullname getParentCategoryFullName. This
   * function caches the result between calls on same request.
   */
  protected function getParentCategory($api) {
    static $parent = null; // Cache.
    if ($parent == null) {
      $fullname = $this->getParentCategoryFullName();
      $parent = $api->getCategoryByFullName($fullname);
    }
    return $parent;
  }

  /**
   * Creates (or tests creation) the Kaltura category for the given course.
   */
  protected function createCourseCategory($api, $courseid, $testing = false) {
    $fullname = $this->getParentCategoryFullName();
    $parent = $this->getParentCategory($api);
    if ($parent === false) {
      echo "Error: Could not find parent category {$fullname}.";
      return false;
    }
    $category = (object)[
      'name' => $courseid,
      'parentId' => $parent->id,
      'description' => null,
      'tags' => null,
      'privacy' => 1,
      'inheritanceType' => 2,
      'defaultPermissionLevel' => 3,
      'owner' => '',
      'referenceId' => '',
      'contributionPolicy' => 1,
      'privacyContext' => '',
      'partnerSortValue' => 0,
      'partnerData' => null,
      'defaultOrderBy' => null,
      'moderation' => true,
      'isAggregationCategory' => false,
      'aggregationCategories' => ''
    ];
    if ($testing) {
      return $category;
    } else {
      $category = $api->createCategory($category);
      echo "Created course category id {$category->id} name {$category->name}.";
      return $category;
    }
  }
  /**
   * Fetches or creates the Kaltura category for the given course.
   */
  protected function getCourseCategory($api, $courseid, $testing = false) {
    $fullname = $this->getParentCategoryFullName() . '>' . $courseid;
    $category = $api->getCategoryByFullName($fullname);
    if ($category == false) {
      if ($testing) {
        echo "Warning: Kaltura category not found for course {$courseid}. The
        migration process will create a new one. You can also manually visit the
        course media gallery in order to create the categories in Kaltura.";
      }
      // No Media gallery exists for this course. We'll create a new one.
      // Note that this function still has the testing parameter.
      $category = $this->createCourseCategory($api, $courseid, $testing);
    }
    return $category;
  }

  /**
   * From the given array of categories, pick one such that the name of the
   * category is NOT the string "course-cmid" for any LTI module.
   */
  protected function getFreeCategory($categories, $testing) {
    global $DB;
        // Search for a free category.
    $category = false;
    $ltimodule = $DB->get_field('modules', 'id', ['name'=>'lti']);
    foreach ($categories as $candidate) {
      if (preg_match('/(\d+)\-(\d+)/', $candidate->name, $matches)) {
        $courseid = intval($matches[1]);
        $cmid = intval($matches[2]);
        if (!$DB->count_records('course_modules', ['id' => intval($cmid), 'course' => intval($courseid), 'module' => $ltimodule])) {
          if ($testing) {
            // Take in count not created modules when testing!
            if (in_array($candidate->name, $this->testing_created_modules)) {
              continue;
            }
          }
          // There not exists any module with the id based on the category name.
          $category = $candidate;
        }
      } else {
        $category = $candidate;
      }
    }
    return $category;
  }

  /**
   * Retrieves or creates a category for the provided swtchcast module. If no
   * category is found for this module, returns false.
   */
  protected function getModuleCategory($api, $cm, $instance, $testing = false) {
    global $DB;

    $categories = $api->getCategoriesByReferenceId($instance->ext_id);
    if (count($categories) == 0) {
      echo "<p>Error: There is no kaltura category with reference id {$instance->ext_id}.</p>";
      return false;
    }
    if ($testing) {
      echo "<p>Found kaltura category with reference id {$instance->ext_id} for Switchcast module id {$cm->id} name '{$instance->name}'.</p>";
      // Simulate previous updates.
      foreach ($categories as $index => $category) {
        if (isset($this->testing_updates[$category->id])) {
          $categories[$index] = $this->testing_updates[$category->id];
        }
      }
    }

    $parent = $this->getParentCategory($api);
    // Categories with the right parent.
    $mcategories = array_filter($categories, function($cat) use ($parent) {
      return $cat->parentId == $parent->id;
    });
    // Categories with other (wrong) parents
    $ocategories = array_filter($categories, function($cat) use ($parent) {
      return $cat->parentId != $parent->id;
    });


    $newid = $this->guessNextModuleId($testing);
    $category_name = $cm->course . '-' . $newid;
    // If there's a category with the right reference id, we'll provide a result
    // either (a) directly, (b) by renaming or (c) by copying this category.

    foreach ($mcategories as $category) {
      if ($category->name == $category_name) {
        // (a) we found the matching category for this module!
        return $category;
      }
    }

    // At this point there is no category with the right reference id and name,
    // so we'll need to rename an existing category or create a new category with
    // the proper name. However that may clash with an existing category (with
    // different refid). We check this possibility here:
    $existing = $api->getCategoryByParentAndName($parent, $category_name);
    if ($existing) {
      echo "<p>Error: there already exists another category with name {$category_name}
      but with different reference id {$existing->referenceId} so it's not possible to
      rename the related category found. That requires manual fix: either delete,
      rename or change the reference Id of this category to {$instance->ext_id}.<p>";
      return false;
    }

    // Check if there is an available category with the right parent.
    $category = $this->getFreeCategory($mcategories, $testing);

    // Otherwise, check if there is a category with the wrong parent. Any category
    // with wrong parent is automatically free, since LTI modules only search in
    // cartegories within the right parent.
    if ($category === false && (count($ocategories) > 0)) {
      $category = array_shift($ocategories);
    }

    if ($category) {
      // (b) We have a category for our module, but we need to move/rename it.
      if ($testing) {
        if ($category->parentId !== $parent->id) {
          $oldparentname = substr($category->fullName, 0, strrpos($category->fullName, '>'));
          echo "<p>Warning: Kaltura category {$category->id} will be moved from '{$oldparentname}' to '{$parent->fullName}'</p>";
        }
        if ($category->name !== $category_name) {
          echo "<p>Warning: Kaltura category {$category->id} will be renamed from '{$category->name}' to '$category_name'</p>";
        }
        // simulate move in testing execution.
        $category->name = $category_name;
        $category->parentId = $parent->id;
        $category->fullName = $parent->fullName . '>' . $category->name;
        $this->testing_updates[$category->id] = $category;
      } else {
        $old_name = $category->fullName;
        $category = $api->moveCategory($category, $parent, $category_name);
        if ($category === false) {
          $new_name = $parent->fullName . '>' . $category_name;
          echo $this->swithCastModuleErrorMessage($cm, "Error moving category id {$category->id} refid {$instance->ext_id} from '{$old_name}' to '{$new_name}'.");
          return false;
        } else {
          echo "<p>Moved category {$category->id} from '{$old_name}' to '{$category->fullName}'.<p>";
        }
      }
    } else {
      // (c) Didn't found any free category for our module. Let's create a new one.
      $model = array_shift($categories);
      if ($testing) {
        echo "<p>Warning: There is another SwitchCast module pointing to the same category. " .
             "A new category will be created for this module and all media in this module will be added to the new category.</p>";
        $category = $model;
      } else {
        $category = $api->copyCategory($model, $parent, $category_name);
        if ($category === false) {
          $fullname = $parent->fullName . '>' . $category_name;
          echo $this->swithCastModuleErrorMessage($cm, "Error copying category id {$model->id} refid {$instance->ext_id} to {$fullname}");
          return false;
        } else {
          echo "<p>Created new kaltura category {$category->id} name '{$category->name}'</p>";
        }
      }
    }
    return $category;
  }

  /**
   * @param object $instance The instance to test.
   * @param object $instances Instances array given by getSwitchCastInstances function.
   * @return true if given instance is not the first one with that reference id.
   *
  */
  protected function checkRepeatedReferenceId($instance, $allinstances) {
    foreach ($allinstances as $id => $item) {
      if ($item->ext_id == $instance->ext_id) {
        return $id < $instance->id;
      }
    }
    // Function always returns before loop ends.
    return false;
  }

  /**
   * @param object $cm old switchcast course module.
   * @param object $modinfo LTI modinfo object.
   * @return object modinfo.
   */
  protected function createModule($cm, $modinfo) {
    global $DB;
    //Create Module.
    $modinfo = create_module($modinfo);
    // Put module in the right spot within the section.
    $section = $DB->get_record('course_sections', ['course' => $modinfo->course, 'section' => $modinfo->section]);
    $newsequence = $this->moveInSequence($section->sequence, $modinfo->coursemodule, $cm->id);
    $DB->set_field("course_sections", "sequence", $newsequence, array("id" => $section->id));
    return $modinfo;
  }

  public function moveInSequence($sequence, $id, $before) {
    $elems = explode(',', $sequence);
    if (($pos = array_search($id, $elems))!==false) {
      array_splice($elems, $pos, 1);
    }
    if (($pos = array_search($before, $elems))!==false) {
      array_splice($elems, $pos, 0, [$id]);
    }
    $newsequence = implode(',', $elems);
    return $newsequence;
  }

  /**
   * @return array all the course modules of type opencast.
   */
  protected function getAllSwitchCastModules($course = -2) {
    global $DB;
    $sql = "SELECT cm.*, m.name as modname
      FROM {modules} m, {course_modules} cm
      WHERE cm.module = m.id AND m.name = 'opencast'";
    $params = [];
    if ($course >= 0) {
      $sql .= ' AND cm.course = ?';
      $params[] = $course;
    }
    return $DB->get_records_sql($sql, $params);
  }
  protected function getSwitchCastInstances($cms) {
    if (count($cms) == 0) {
      return [];
    }
    global $DB;
    $ids = array_map(function($cm) {
      return $cm->instance;
    }, $cms);
    list($in, $params) = $DB->get_in_or_equal($ids);
    $instances = $DB->get_records_select('opencast', 'id ' . $in, $params, 'id');
    return $instances;
  }
  /**
   * @param object $cm course_module record.
   * @return object the opencast instance.
   */
  protected function getSwitchCastInstance($cm) {
    global $DB;
    $instance = $DB->get_record('opencast', ['id' => $cm->instance]);
    return $instance;
  }

  /**
   * Build error message referring a particular opencast course module instance.
   * @param object $cm course module record.
   * @param string $msg error detail message
  */
  protected function swithCastModuleErrorMessage($cm, $msg) {
    return '<p class="alert-danger">' . "Error in opencast course module id {$cm->id} from course id {$cm->course}. " . $msg . '</p>';
  }

  public function getVideoGalleryLTIType() {
    global $DB;
    return $DB->get_record_select('lti_types', "baseurl LIKE '%/hosted/index/course-gallery'");
  }

  /**
   * Build the moduleinfo data object for a new LTI module replacing the switcast
   * module given by the two parameters.
   *
   * @param stdClass $cm SwitchCast course module.
   * @param stdClass $instance SwitchCast instance.
   */
  protected function getLTIModuleInfoFromSwitchCast($cm, $instance) {
    global $DB;
    $course = get_course($cm->course);
    $lti_module = $DB->get_record('modules', ['name' => 'lti']);
    // Get switchcast data
    list($cm, $context, $module, $data, $cw) = get_moduleinfo_data($cm, $course);
    // Change some fields
    $data->module = $lti_module->id;
    $data->modulename = 'lti';
    $data->instance = 0;
    $data->add = 'lti';
    // Get video gallery type id.
    $type = $this->getVideoGalleryLTIType();
    if (!$type) {
      return false;
    }
    $lti = [
      'name' => $instance->name,
      'intro' => $instance->intro,
      'introformat' => $instance->introformat,
      'timecreated' => isset($instance->timecreated) ? $instance->timecreated : time(),
      'timemodified' => time(),
      'typeid' => $type->id,
      'toolurl' => null,
      'securetoolurl' => null,
      'instructorchoicesendname' => 1,
      'instructorchoicesendemailaddr' => 1,
      'instructorchoiceallowroster' => null,
      'instructorchoiceallowsetting' => null,
      'instructorcustomparameters' => '',
      'instructorchoiceacceptgrades' => 0,
      'grade' => 0,
      'launchcontainer' => 1,
      'resourcekey' => null,
      'password' => null,
      'debuglaunch' => 0,
      'showtitlelaunch' => 1,
      'showdescriptionlaunch' => 0,
      'servicesalt' => null,
      'icon' => null,
      'secureicon' => null
    ];
    $data = (object)array_merge((array)$data, $lti);

    return $data;
  }

}
