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
  protected array $hosts = ['tube.switch.ch', 'cast.switch.ch', 'download.cast.switch.ch'];

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
    $course = false;
    if (isset($columns['course'])) {
      $course = $DB->get_field($table, 'course', ['id' => $id]);
    }
    if ($table == 'question') {
      $course = $DB->get_field_sql(
        'SELECT c.instanceid FROM {context} c
        JOIN {question_categories} qc ON qc.contextid = c.id
        JOIN {question} q ON q.category = qc.id
        WHERE c.contextlevel = 50 AND q.id = ?',
        [$id]);
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
   * Replace every course module of type opencast by a LTI external tool of type
   * "Media Gallery".
   * @param int $course The course to migrate, negative for all courses.
   * @param bool $testing
   *
   * @return mixed boolean TRUE if no errors, an array of error messages if any error.
   */
  function replaceModules($course = -2, $testing = false) {
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
      // Fetch category.
      $category = $api->getCategoryByReferenceId($instance->ext_id);
      if ($category !== false) {
        // Create a new Media Gallery LTI cm replacing switchcast.
        $modinfo = $this->getLTIModuleInfoFromSwitchCast($cm, $instance);
        if (!$modinfo) {
          echo "<p>Error: Create first the Media Gallery LTI type.</p>";
        } else if ($testing) {
          echo "<p>Found kaltura category for Switchcast module id {$cm->id} name {$instance->name}.</p>";
          echo '<p>Ready to migrate!</p>';
        } else {
          $modinfo = $this->createModule($cm, $modinfo);
          // Change the name of the category so that the Media Gallery will access this category.
          $category_name = $cm->course . '-' . $modinfo->coursemodule;
          $api->setCategoryName($category, $category_name);
          // Remove the switchcast cm.
          course_delete_module($cm->id);
          $replaced++;
          echo "<p>Successfully migrated <em>{$instance->name}</em>!</p>";
        }
      }
      else {
        // Category not found. Report error!
        $error = $this->swithCastModuleErrorMessage($cm, "Category not found or duplicate in kaltura server with reference id {$instance->ext_id}.");
        echo $error;
        $errors[] = $error;
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
    return "Error in opencast course module id {$cm->id} from course id {$cm->course}. " . $msg;
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
