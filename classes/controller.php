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

// db_should_replace function.
require_once($CFG->libdir . '/adminlib.php');

class tool_kaltura_migration_controller {
  /** @var \core\progress\base Progress bar for the current operation. */
  protected $progress;
  /** @var array video URL subsctrings to search for*/
  protected array $hosts = ['tube.switch.ch', 'cast.switch.ch'];

  /**
   * @param \core\progress\base $progress Progress bar object, not started.
   */
  function execute($progress) {
    $this->progress = $progress;
    $this->deleteResults();
    $this->search();
  }
  /**
   * Search for video URLs in the specified table and column.
   * @param string $table Name of the table.
   * @param database_column_info $column Column object.
   * @return array of matching full column values.
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

    $result = $DB->get_fieldset_select($table, $column->name, $where, $params);
    return $result;
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
          // Extract urls.
          $urls = [];
          foreach ($result as $field) {
            $urls = array_merge($urls, $this->extractUrls($field));
          }
          // Save results into DB.
          $records = array_map(function($url) use ($table, $column) {
            return array(
              'tblname' => $table,
              'colname' => $column->name,
              'url' => $url
            );
          }, $urls);

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
}
