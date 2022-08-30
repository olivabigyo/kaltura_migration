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
          // Extract urls and prepare records for db insertion.
          $records = [];
          foreach ($result as $id => $field) {
            $urls  = $this->extractUrls($field);
            foreach ($urls as $url) {
              $records[] = array(
                'tblname' => $table,
                'colname' => $column->name,
                'resid' => $id,
                'url' => $url,
                'replaced' => false,
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
   * Replace switch video embeds by kaltura embeds.
   */
  function replace($test = false) {
    global $DB;
    $records = $DB->get_records('tool_kaltura_migration_urls');
    if ($test) {
      echo '<table border="1">';
    }
    $i = 1;
    $replaced = 0;
    foreach ($records as $record) {
      $table = $record->tblname;
      $column = $record->colname;
      $id = $record->resid;
      $url = $record->url;
      if (!$record->replaced) {
        if ($test) {
          echo '<tr><td>' . $i++ . ' ('. $table . ' ' . $id . ')</td>';
        }
        if ($this->replaceVideo($table, $column, $id, $url, $test)) {
          $record->replaced = true;
          $DB->update_record('tool_kaltura_migration_urls', $record);
          $replaced++;
        }
        if ($test) {
          echo '</tr>';
        }
      }
    }
    if ($test) {
      echo '</table>';
    }
    return $replaced;
  }

  /**
   * Replaces a single video embedding from a DB text field.
   */
  function replaceVideo($table, $column, $id, $url, $test = false) {
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
    $content = preg_replace_callback([$iframe_reg, $iframe2_reg, $video_reg, $video2_reg], function($matches) use ($url) {
      $width = count($matches) > 2 ? $matches[1] : null;
      $height = count($matches) > 2 ? $matches[2] : null;
      return $this->getKalturaEmbedCode($url, $width, $height);
    }, $content);
    // replace video links and other references (not embeddings)
    $content = str_replace($url, $this->getKalturaVideoUrl($url), $content);
    if ($test) {
      echo '<td>' . $content . '</td>';
      return false;
    } else {
      return $DB->set_field($table, $column, $content, ['id' => $id]);
    }
  }

  function getKalturaVideoUrl($url) {
    // see https://knowledge.kaltura.com/help/how-to-retrieve-the-download-or-streaming-url-using-api-calls
    $serviceUrl = "https://api.cast.switch.ch";
    $YourPartnerId = "105";
    $YourEntryId = "0_d920p5hf";
    $VideoFlavorId = '0_yx52xqhg';
    $StreamingFormat = 'url';
    // unused params.
    $Protocol = 'https';
    $ks = '';
    $extra = "/protocol/${Protocol}/ks/${ks}";
    // end unused params.
    $ext = 'mp4';
    return "{$serviceUrl}/p/{$YourPartnerId}/sp/10500/playManifest/entryId/{$YourEntryId}/flavorParamId/{$VideoFlavorId}/format/{$StreamingFormat}/video.{$ext}";
  }

  function getKalturaEmbedCode($url, $width = null, $height = null) {
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
  "entry_id": "0_d920p5hf"
});
</script>
EOD;
  }
}
