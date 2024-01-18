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
require_once(__DIR__ . '/logger.php');

class tool_kaltura_migration_controller {
  /** @var \core\progress\base Progress bar for the current operation. */
  protected $progress;
  /** @var array video URL subsctrings to search for*/
  protected $hosts;

  /** @var array map of category updates during a testing session. */
  protected $testing_updates = [];
  /** @var array of "course-cmid" for new modules to be created during a testing session. */
  protected $testing_created_modules = [];

  protected $logger;

  function __construct() {
    $this->hosts = [
      'tube.switch.ch',
      'cast.switch.ch',
      'download.cast.switch.ch',
      self::getHostFromURL(get_config('tool_kaltura_migration', 'api_url')),
      //self::getHostFromURL(get_config('tool_kaltura_migration', 'kaf_uri'))
    ];
  }

  private static function getHostFromURL($url) {
    return parse_url($url, PHP_URL_HOST);
  }

  /**
   * @param \core\progress\base $progress Progress bar object, not started.
   */
  function execute($progress, $backgroundtask = false) {
    $this->progress = $progress;
    $this->deleteResults();
    $this->search();

    $feedback = get_string('foundnvideos', 'tool_kaltura_migration', $this->countResults());
    if ($backgroundtask) {
      $this->setTaskProgress($feedback);
    } else {
      global $OUTPUT;
      echo $OUTPUT->notification($feedback, \core\output\notification::NOTIFY_SUCCESS);
    }
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
      // That's the longest string that can used from the available info. Note that
      // we want to catch either http and https and unescaped and escaped forward slashes.
      $pattern = '/' . $host;
      if ($where != '') {
        $where .= ' OR';
      }
      $where .= ' (' . $searchsql . ')';
      $params[] = '%' . $DB->sql_like_escape($pattern) . '%';
    }

    if ($this->tableHasId($table)) {
      $result = $DB->get_records_select_menu($table, $where, $params, '', 'id, ' . $column->name);
    } else {
      $result = $DB->get_fieldset_select($table, $column->name, $where, $params);
    }
    if ($this->isJsonField($table, $column->name)) {
      $result = array_map(function($content) {
        // Unescape forward slashes.
        return str_replace('\\/', '/', $content);
      }, $result);
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
   * @param string $table. The table name.
   * @param string $column. The column name.
   * @return boolean true if the field contains json-encoded data.
   */
  protected function isJsonField($table, $column) {
    // tables and fields with json-encoded content.
    $json = [
      'hvp' => ['json_content', 'filtered'],
      'h5p' => ['jsoncontent', 'filtered'],
    ];
    return isset($json[$table]) && in_array($column, $json[$table]);
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
        // Remove trailing HTML entities.
        $url = preg_replace('/(&[a-z]+;)+$/', '', $url);
        if (!in_array($url, $urls)) {
          $urls[] = $url;
        }
      }
    }
    // The urls for the script embeddings don't contain the entry id, so they are
    // not sufficient for replacing the content. Get it from subsequent content and
    // append to the url as a query param.
    $api_url = rtrim(get_config('tool_kaltura_migration', 'api_url'), ' /');
    $kaf_uri = rtrim(get_config('tool_kaltura_migration', 'kaf_uri'), ' /');
    foreach ($urls as $index => $url) {
      if (strpos($url, $api_url) !== FALSE) {
        if (preg_match(
          "#<script src=\"${url}\"></script>\s*<div[^>]*></div>\s*<script>[^<]*\"entry_id\":\s*\"([^\"]+)\"[^<]*</script>#",
          $text, $matches)) {
            $urls[$index] = $url . '?entry_id=' . $matches[1];
        } else {
          // There are places where the api url is used without being an embedding.
          // we use the same regexp to discard these.
          unset($urls[$index]);
        }
      }
      // Kaf uri is used in other contexts than embedded links. This check should
      // discard these other cases.
      if (strpos($url, $kaf_uri) !== FALSE) {
        if (strpos($url, $kaf_uri . "/browseandembed/index/media/entryid/") === FALSE) {
          unset($urls[$index]);
        }
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
  protected function getRecordCourse($table, $id) {
    global $DB;
    // We have asked for table columns already but moodle caches the response so
    // it is not really useful to propagate the result here.
    $columns = $DB->get_columns($table);
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
   * @param callable $callback is a function taking two parameters ($table, $column)
   * to be called for each text column in the database.
   */
  protected function foreachDBColumn($callback) {
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
          // Call provided callback
          $callback($table, $column);
        }
      }
      $progress++;
      $this->progress->progress($progress);
    }
    $this->progress->end_progress();
  }

  /**
   * Search video URLs in the whole database.
   */
  protected function search() {
    // Passing directly $this->searchCallback is not allowed, but an anonymous
    /// function is.
    $this->foreachDBColumn(function($table, $column) {
      $this->searchCallback($table, $column);
    });
  }

  protected function searchCallback($table, $column) {
    global $DB;
    // Get matching fields.
    $result = $this->search_table_column($table, $column);
    // Extract urls and prepare records for db insertion.
    $records = [];
    foreach ($result as $id => $field) {
      $urls  = $this->extractUrls($field);
      $course = $this->getRecordCourse($table, $id);

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

  protected function array2csv($fields) {
    $buffer = fopen('php://temp', 'r+');
    fputcsv($buffer, $fields);
    rewind($buffer);
    $csv = fgets($buffer);
    fclose($buffer);
    return $csv;
  }

  protected function getCSV($table) {
    global $DB;
    $csv = '';
    $columns = $DB->get_columns($table);
    // Headers row.
    $csv .= $this->array2csv(array_map(function($column) {return $column->name; }, $columns));
    $records = $DB->get_records($table);
    foreach ($records as $record) {
      $csv .= $this->array2csv((array)$record);
    }
    return $csv;
  }

  /**
   * Send a CSV file for download.
   */
  function downloadCSV($table) {
    $fs = get_file_storage();
    $context = context_system::instance();
    $fileinfo = array(
      'contextid' => $context->id,
      'component' => 'tool_kaltura_migration',
      'filearea' => 'tool_kaltura_migration',
      'itemid' => 0,
      'filepath' => '/',
      'filename' => $table . '.csv'
    );
    // Delete file if already exists.
    $file = $fs->get_file($fileinfo['contextid'], $fileinfo['component'],
      $fileinfo['filearea'], $fileinfo['itemid'], $fileinfo['filepath'], $fileinfo['filename']);
    if ($file) {
      $file->delete();
    }
    $file = $fs->create_file_from_string($fileinfo, $this->getCSV($table));
    send_stored_file($file, 0, 0, true);
  }

  /**
   * @return array
  */
  protected function getReferenceIdsFromUrl($url) {
    if (preg_match('#https?://[a-zA-z0-9\._\-/]*?/([a-f0-9\-]{36})/([a-f0-9\-]{36})/.*#', $url, $matches)) {
      return [$matches[1], $matches[2]];
    }
    if (preg_match('#https?://[a-zA-z0-9\._\-/]*?/([a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})#', $url, $matches)) {
      return [$matches[1]];
    }
    if (preg_match('#https?://[a-zA-z0-9\._\-/]*/([a-zA-Z0-9]{8,10})(\?|\#|$)#', $url, $matches)) {
      return [$matches[1]];
    }

    return false;
  }

  protected function getEntryIdFromUrl($url) {
    if (preg_match('#https?://[^\?]*\?entry_id=(.*)$#', $url, $matches)) {
      return $matches[1];
    }
    if (preg_match('#https?://[^/]+/browseandembed/index/media/entryid/([^/]+)/.*#', $url, $matches)) {
      return $matches[1];
    }
    return false;
  }

  /**
   * Replace switch video embeds by kaltura embeds.
   * @param string|int $course id, if nonnegative, content not in any course if -1 and all courses if -2.
   * @return mixed true if no errors, array of error strings if errors.
   */
  public function replace($course = -2, $filterablelinks = false, $test = false, $limit = 0, $offset = 0) {
    global $DB;
    $conditions = [];
    if (intval($course) > -2) {
      $conditions['course'] = $course;
    }
    $records = $DB->get_records('tool_kaltura_migration_urls', $conditions, 'id', '*', $offset, $limit);
    $this->logger = new tool_kaltura_migration_logger();
    $this->logger->start($test);

    $api = new tool_kaltura_migration_api($this->logger);


    $replaced = 0;
    $channels = 0;
    $processed = 0;
    foreach ($records as $record) {
      $table = $record->tblname;
      $id = $record->resid;
      if (!$record->replaced) {
        $this->logger->entry($table . ' ' . $id);

        $column = $record->colname;
        $url = $record->url;
        $referenceIds = $this->getReferenceIdsFromUrl($url);
        $entryId = $this->getEntryIdFromUrl($url);
        $logid = "[{$record->id}, {$url}] ";
        if (!$referenceIds && !$entryId) {
          $this->logger->error($logid . 'Could not get refid from url ' . $url);
        } else if ((strpos($url, '/channels/') !== FALSE) || (strpos($url, '/channel/') !== FALSE)) {
          // Replace channel links.
          $category = $api->getCategoryByReferenceIds($referenceIds);
          if (!$category) {
             $this->logger->error($logid . 'Could not get Kaltura channel category with refid ' . implode(',', $referenceIds));
          } else if ($this->replaceChannel($table, $column, $id, $url, $category, $test)) {
            $record->replaced = true;
            $DB->update_record('tool_kaltura_migration_urls', $record);
            $channels++;
            $this->logger->info($logid . 'Channel replaced in table ' . $table . ' column ' . $column . ' record id ' . $id . ' with refid ' . implode(',', $referenceIds));
          } else if (!$test) {
            $this->logger->error($logid . 'Could not replace html content in table ' . $table . ' column ' . $column . ' record id ' . $id);
          }
        } else {
          // Replace media embeds or links.
          if ($referenceIds) {
            $entry = $api->getMediaByReferenceIds($referenceIds);
          } else if ($entryId) {
            $entry = $api->getMediaByEntryId($entryId);
          }

          if (!$entry) {
            $this->logger->error($logid . 'Could not get Kaltura media with ' . ($referenceIds ? 'refid ' . implode(',', $referenceIds) :  'id ' . $entryId));
          } else if ($this->replaceVideo($table, $column, $id, $url, $entry, $filterablelinks, $test)) {
            $record->replaced = true;
            $DB->update_record('tool_kaltura_migration_urls', $record);
            $replaced++;

            $this->logger->info($logid . 'Video replaced in table ' . $table . ' column ' . $column . ' record id ' . $id . ' with ' . ($referenceIds ? 'refid ' . implode(',', $referenceIds) : 'entryid ' . $entryId));
          } else if (!$test) {
            $this->logger->error($logid . 'Could not replace html content in table ' . $table . ' column ' . $column . ' record id ' . $id);
          }
        }
      }
      $processed++;
      if ($this->progress && $processed % 200 == 0) {
        $this->progress->progress($processed);
      }
    }

    $this->logger->end();
    if (!$test) {
      global $OUTPUT;
      echo $OUTPUT->notification(get_string('replacednvideos', 'tool_kaltura_migration', $replaced), \core\output\notification::NOTIFY_SUCCESS);
      if ($channels > 0) {
        echo $OUTPUT->notification(get_string('replacednchannels', 'tool_kaltura_migration', $channels), \core\output\notification::NOTIFY_SUCCESS);
      }
    }
    $errors = $this->logger->getErrors();
    return count($errors) == 0 ? true : $errors;
  }

  public function replaceAll($progress) {
    $this->progress = $progress;
    $progress->start_progress('Replacing all video urls', $this->countResults());

    $errors = $this->replace(-2, true);

    $progress->end_progress();
    if (is_countable($errors) && count($errors) > 0) {
      $this->setTaskProgress('Encountered ' . count($errors) . ' errors. See log for details.');
    }
  }

  /**
   * Replaces a single channel link from a DB text field.
   */
  function replaceChannel($table, $column, $id, $url, $category, $test = false) {
    global $DB;
    $content = $DB->get_field($table, $column, ['id' => $id]);
    if ($test) {
      $this->logger->content($content);
    }
    $content = str_replace($url, $this->getKalturaChannelUrl($category), $content);
    if ($test) {
      $this->logger->content($content);
      return false;
    } else {
      return $DB->set_field($table, $column, $content, ['id' => $id]);
    }
  }

  /**
   * Builds the MediaSpace URL for a channel category.
   */
  function getKalturaChannelUrl($category) {
    $base_url = get_config('tool_kaltura_migration', 'mediaspace_url');
    $base_url = rtrim($base_url, '/'); // Just in user added the trailing slash.
    $name = str_replace("/", "_", $category->name);
    $url = $base_url . '/channel/' . urlencode($name) . '/' . urlencode($category->id);
    return $url;
  }

  /**
   * Replaces a single video embedding from a DB text field.
   */
  function replaceVideo($table, $column, $id, $url, $entry, $filterablelinks, $test = false) {
    $is_json = $this->isJsonField($table, $column);

    global $DB;
    $content = $DB->get_field($table, $column, ['id' => $id]);
    if ($test) {
      if ($is_json) {
        $this->logger->codeContent($content);
      } else {
        $this->logger->htmlContent($content);
      }
    }
    $script_reg = "#<script src=\"".preg_quote($url, "#")."\"></script>\s*<div[^>]*width:\s*(\d+)px;\s*height:\s*(\d+)px[^>]*></div>\s*<script>[^<]*</script>#";
    $anchor_reg = "#<a href=\"" . preg_quote($url, "#") . "\">tinymce-kalturamedia-embed\|\|[^|]*\|\|(\d+)\|\|(\d+)</a>#";

    if (!$is_json) {
      $iframe_reg = '/<iframe\s[^>]*src\s*=\s*"' . preg_quote($url, "/") . '"[^>]*width="(\d+)"\s+height="(\d+)"[^>]*><\/iframe>/';
      $iframe2_reg = '/<iframe\s[^>]*width="(\d+)"\s+height="(\d+)"[^>]*src\s*=\s*"' . preg_quote($url, "/") . '"[^>]*><\/iframe>/';
      $iframe3_reg = '/<iframe\s[^>]*src\s*=\s*"' . preg_quote($url, "/") . '"[^>]*><\/iframe>/';
      $video_reg = '/<video\s[^>]*width="(\d+)"\s+height="(\d+)"[^>]*><source\ssrc="'. preg_quote($url, "/") .'">[^<]*<\/video>/';
      $video2_reg = '/<video\s[^>]*><source\s[^>]*src="' . preg_quote($url, "/") . '"[^>]*>.*?<\/video>/';
      if (($pos = strrpos($url, '?entry_id=')) !== FALSE) {
        $url = substr($url, 0, $pos);
      }
      $script_reg = "#<script src=\"".preg_quote($url, "#")."\"></script>\s*<div[^>]*width:\s*(\d+)px;\s*height:\s*(\d+)px[^>]*></div>\s*<script>[^<]*</script>#";
      $anchor_reg = "#<a href=\"" . preg_quote($url, "#") . "\">tinymce-kalturamedia-embed\|\|[^|]*\|\|(\d+)\|\|(\d+)</a>#";

      $regexs = [$iframe_reg, $iframe2_reg, $iframe3_reg, $video_reg, $video2_reg];
      // Only replace the new embedding forms that are not the way we want the embedding to be done.
      if ($filterablelinks) {
        $regexs[] = $script_reg;
      } else {
        $regexs[] = $anchor_reg;
      }

      // Replace video embeddings
      $content = preg_replace_callback($regexs, function($matches) use ($entry, $filterablelinks) {
        if (count($matches) > 2) {
          $width = $matches[1];
          $height = $matches[2];
        } else {
          // If no defined size, we use the aspect ratio from the video metadata
          // provided by the kaltura API and a width that is the intrinsic video
          // width (if less than 608), or 608 otherwise. The 608 is an arbitrary
          // constat that is a default value for the kaltura player and that's why
          // we use it.
          $width = min($entry->width, 608);
          $height = round($entry->height * $width / $entry->width);
        }
        return $this->getKalturaEmbedCode($entry, $width, $height, $filterablelinks);
      }, $content);
    }

    // Replace video links and other references (not embeddings)
    if (!preg_match($script_reg, $content) && !preg_match($anchor_reg, $content)) {
      $kaltura_url = $this->getKalturaVideoUrl($entry);
      $content = str_replace($url, $kaltura_url, $content);
    }

    // Also try with escaped slashes for json fields.
    if ($is_json) {
      $url = str_replace('/', '\\/', $url);
      $kaltura_url = str_replace('/', '\\/', $kaltura_url);
      $content = str_replace($url, $kaltura_url, $content);
    }

    if ($test) {
      if ($is_json) {
        $this->logger->codeContent($content);
      } else {
        $this->logger->htmlContent($content);
      }
      return false;
    } else {
      return $DB->set_field($table, $column, $content, ['id' => $id]);
    }
  }

  function getKalturaVideoUrl($entry) {
    // See: https://help.switch.ch/cast/faq/#collapse-f96b010a-5682-11ed-9939-5254009dc73c-2
    // See also: https://knowledge.kaltura.com/help/how-to-download-a-raw-file
    $url = rtrim(get_config('tool_kaltura_migration', 'api_url'), ' /');
    $partnerid = get_config('tool_kaltura_migration', 'partner_id');
    $entryid = $entry->id;

    // 6 and 7 are flavors for high quality videos ready to be played by browser.
    // Other flavors (including base flavor 0) may return lower quality videos or
    // uncommon formats.
    $flavorParamIds = "6,7";

    return "${url}/p/${partnerid}/sp/${partnerid}00/playManifest/entryId/${entryid}/format/url/protocol/https/flavorParamIds/${flavorParamIds}/video.mp4";
  }

  function getKalturaEmbedCode($entry, $width, $height, $filterablelinks) {
    if ($filterablelinks) {
      return $this->getKalturaEmbedCodeLink($entry, $width, $height);
    } else {
      return $this->getKalturaEmbedCodeJS($entry, $width, $height);
    }
  }

  function getKalturaEmbedCodeJS($entry, $width, $height) {
    $style = "style=\"width: {$width}px; height: {$height}px;\"";
    $url = rtrim(get_config('tool_kaltura_migration', 'api_url'), ' /');
    $hash = mt_rand();
    $uiconfid = $this->getUIConfId();
    $partnerid = get_config('tool_kaltura_migration', 'partner_id');
    return <<<EOD
<script src="${url}/p/${partnerid}/sp/${partnerid}00/embedIframeJs/uiconf_id/{$uiconfid}/partner_id/{$partnerid}"></script>
<div id="kaltura_player_{$hash}" {$style}></div>
<script>
kWidget.embed({
  "targetId": "kaltura_player_{$hash}",
  "wid": "_{$partnerid}",
  "uiconf_id": {$uiconfid},
  "flashvars": {},
  "entry_id": "{$entry->id}"
});
</script>
EOD;
  }

  function formatDuration($seconds) {
    $now = new DateTime();
    $after = clone($now);
    $after->add(new DateInterval("PT{$seconds}S"));
    $interval = $after->diff($now);
    return $interval->format("%I:%S");
  }

  function getKalturaEmbedCodeLink($entry, $width, $height) {
    // Kaltura embedding links need to have a w and h such that the height is 30px
    // more than a height that corresponds to the aspect ratio of the video given
    // the width. Otherwise it shows an ugly scroll bar.
    if ($entry->width) {
      $height = ceil(($entry->height * $width) / $entry->width) + 30;
    }

    $uiconfid = $this->getUIConfId();
    $url = rtrim(get_config('tool_kaltura_migration', 'kaf_uri'), ' /');
    $url = "$url/browseandembed/index/media/entryid/{$entry->id}"
      . "/showDescription/false/showTitle/false/showTags/false/showDuration/false"
      . "/showOwner/false/showUploadDate/false/playerSize/{$width}x{$height}"
      . "/playerSkin/{$uiconfid}/";
    $title = htmlspecialchars($entry->name);
    $duration = $this->formatDuration($entry->duration);
    return "<a href=\"$url\">tinymce-kalturamedia-embed||{$title} ($duration)||$width||$height</a>";
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
      $this->logger->error("Error creating course category.");
      return false;
    }
    $replaced = 0;
    foreach ($cms as $cm) {
      $this->logger->entry();
      $instance = $this->getSwitchCastInstance($cm);
      $cmcategories = $api->getCategoriesByReferenceId($instance->ext_id);
      if (count($cmcategories) == 0) {
        $this->logger->errorCM($cm, "Kaltura category with reference id {$instance->ext_id} not found.");
      } else {
        $cmcategory = $cmcategories[0];
        if (count($cmcategories) > 1) {
          $this->logger->warning("More than one category found with reference id {$instance->ext_id}. Taking the one with id {$cmcategory->id}.");
        }
        if ($testing) {
          $this->logger->info("Module '{$instance->name}' ready to migrate!");
        } else {
          // copy all media from module category to course category.
          $api->copyMedia($cmcategory, $category);
          // Remove the switchcast cm.
          course_delete_module($cm->id);
          $this->logger->info("Media successfully migrated from module name '{$instance->name}' to Course Media Gallery.");
          $replaced++;
        }
      }
    }
    if (!$testing) {
      global $OUTPUT;
      echo $OUTPUT->notification(get_string('replacednmodules', 'tool_kaltura_migration', $replaced), \core\output\notification::NOTIFY_SUCCESS);
    }
  }
  /**
   * Delete all switchcast activities and send all media from Switchcast modules
   * to the Course Media Gallery category.
   * */
  protected function replaceModulesToCourseMedia($course = -1, $testing = false) {
    global $DB;
    $api = new tool_kaltura_migration_api($this->logger);
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
   */
  protected function replaceModulesToLTI($course = -2, $testing = false) {
    // Get all switchcast modules.
    $cms = $this->getAllSwitchCastModules($course);
    $api = new tool_kaltura_migration_api($this->logger);
    $replaced = 0;
    $processed = 0;
    foreach ($cms as $cm) {
      $this->logger->entry();
      $instance = $this->getSwitchCastInstance($cm);
      $modinfo = $this->getLTIModuleInfoFromSwitchCast($cm, $instance);
      if (!$modinfo) {
          $this->logger->errorCM($cm, "Create first the Video Gallery LTI type. Can't migrate!");
      } else {
        $category = $this->getModuleCategory($api, $cm, $instance, $testing);
        if ($category === false) {
          // Category not found. Report error!
          $this->logger->errorCM($cm, "Could not associate kaltura category. Can't migrate!");
        } else {
          if ($testing) {
            $this->logger->info('Ready to migrate!');
            $this->testing_created_modules[] = $category->name;
          } else {
            $this->createModule($cm, $modinfo);
            // Remove the switchcast cm.
            course_delete_module($cm->id);
            $replaced++;
            $this->logger->info("Successfully migrated '{$instance->name}'");
          }
        }
      }
      $processed++;
      if ($this->progress && $processed % 50 == 0) {
        $this->progress->progress($processed);
      }
    }

    if (!$testing) {
      global $OUTPUT;
      echo $OUTPUT->notification(get_string('replacednmodules', 'tool_kaltura_migration', $replaced), \core\output\notification::NOTIFY_SUCCESS);
    }
  }

  /**
   * Migrate switchcast modules either to the kaltura course media gallery or to
   * external tool activities.
   * @return bool|array true if no errors and the error array if errors.
  */
  function replaceModules($course = -2, $modulestocoursemedia = false, $testing = false) {
    $this->logger = new tool_kaltura_migration_logger();
    $this->logger->start($testing);
    if ($modulestocoursemedia) {
      $this->replaceModulesToCourseMedia($course, $testing);
    } else {
      $this->replaceModulesToLTI($course, $testing);
    }
    $this->logger->end();
    $errors = $this->logger->getErrors();
    return count($errors) == 0 ? true : $errors;
  }

  public function replaceAllModules($progress) {
    $this->progress = $progress;
    $progress->start_progress('Replacing all SwithCast modules', $this->countModules());

    $errors = $this->replaceModules(-2, false, false);

    $progress->end_progress();
    if (is_countable($errors) && count($errors) > 0) {
      $this->setTaskProgress('Encountered ' . count($errors) . ' errors. See log for details.');
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

  /**
   * Return the root category for this site media gallery. This is configured in
   * the plugin settings and must be the same setting as in
   * Kaltura Administration Site >
   *  Configuration Management > Global > Categories > rootCategory
   */
  protected function getRootCategoryName() {
    return get_config('tool_kaltura_migration', 'kaltura_root_category');
  }

  /**
   * Return the category name "Moodle>site>channels".
   * */
  protected function getParentCategoryFullName() {
    $root = $this->getRootCategoryName();
    return "$root>site>channels";
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
    $parent = $this->getParentCategory($api);
    if ($parent === false) {
      $fullname = $this->getParentCategoryFullName();
      $this->logger->error("Could not find parent category {$fullname}.");
      return false;
    }
    $category = (object)[
      'name' => $courseid,
      'parentId' => $parent->id,
      'description' => null,
      'tags' => null,
      'referenceId' => ''
    ];
    if (!$testing) {
      $category = $api->createCategory($category);
      $id = $category->id;
      $fullName = $category->fullName;
    } else {
      $id = '';
      $fullName = $category->name;
    }
    $this->logger->op(tool_kaltura_migration_logger::CODE_OP_CREATE_CATEGORY, $id, $fullName);
    return $category;
  }
  /**
   * Fetches or creates the Kaltura category for the given course.
   */
  protected function getCourseCategory($api, $courseid, $testing = false) {
    $fullname = $this->getParentCategoryFullName() . '>' . $courseid;
    $category = $api->getCategoryByFullName($fullname);
    if ($category == false) {
      if ($testing) {
        $this->logger->warning("Kaltura category not found for course {$courseid}. The
        migration process will create a new one. You can also manually visit the
        course media gallery in order to create the categories in Kaltura.");
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
   *
   * Since several Moodle instances can point to the same Kaltura Instance,
   * this script assumes the category is in use if it has the form
   * "*>site>channels>xxx-yyy"
   */
  protected function getFreeCategory($categories, $testing) {
    // Search for a free category.
    foreach ($categories as $candidate) {
      if (!preg_match('/^.+>site>channels>\d+\-\d+$/', $candidate->fullName)) {
        return $candidate;
      }
    }
    return false;
  }

  /**
   * Retrieves or creates a category for the provided swtchcast module. If no
   * category is found for this module, returns false.
   */
  protected function getModuleCategory($api, $cm, $instance, $testing = false) {
    global $DB;

    $categories = $api->getCategoriesByReferenceId($instance->ext_id);
    if (count($categories) == 0) {
      $this->logger->error("There is no kaltura category with reference id {$instance->ext_id}");
      return false;
    }
    if ($testing) {
      $this->logger->info("Found kaltura category with reference id {$instance->ext_id} for Switchcast module id {$cm->id} name '{$instance->name}'");
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
    // so we'll need to  create a new category with the proper name. However
    // that may clash with an existing category (with different refid). We check
    // this possibility here:
    $existing = $api->getCategoryByParentAndName($parent, $category_name);
    if ($testing){
      if ($existing && isset($this->testing_updates[$existing->id])) {
        $updated = $this->testing_updates[$existing->id];
        if ($updated->name != $category_name) {
          $existing = false;
        }
      } else if (!$existing) {
        foreach ($this->testing_updates as $candidate) {
          if ($candidate->name == $category_name && $candidate->parentId == $parent->id) {
            $existing = $candidate;
          }
        }
      }
    }
    if ($existing) {
      $this->logger->error("There already exists another category with name {$category_name}
      but with different reference id {$existing->referenceId} so it's not possible to
      rename the related category found. That requires manual fix: either delete,
      rename or change the reference Id of this category to {$instance->ext_id}.");
      return false;
    }

    // Check if there is an available category not used by any LTI module.
    $category = $this->getFreeCategory($categories, $testing);

    if ($category) {
      // (b) We have a category for our module, but we need to move/rename it.
      if ($testing) {
        if ($category->parentId == $parent->id && $category->name !== $category_name) {
          $this->logger->op(tool_kaltura_migration_logger::CODE_OP_RENAME_CATEGORY, $category->id, $category_name);
        }
        if ($category->parentId !== $parent->id) {
          $this->logger->op(tool_kaltura_migration_logger::CODE_OP_COPY_CATEGORY, $category->id, "$parent->fullName>$category_name");
          $this->logger->op(tool_kaltura_migration_logger::CODE_OP_DELETE_CATEGORY, $category->id);
        }
        // simulate move in testing execution.
        $category->name = $category_name;
        $category->parentId = $parent->id;
        $category->fullName = $parent->fullName . '>' . $category->name;
        $this->testing_updates[$category->id] = $category;
      } else {
        $old_name = $category->fullName;
        $oldcategory = $category;
        $category = $api->moveCategory($category, $parent, $category_name);
        if ($category === false) {
          $new_name = $parent->fullName . '>' . $category_name;
          $this->logger->errorCM($cm, "Error moving category id {$category->id} refid {$instance->ext_id} from '{$old_name}' to '{$new_name}'.");
          return false;
        } else {
          if (strpos($old_name, $parent->fullName) !== false) {
            $this->logger->op(tool_kaltura_migration_logger::CODE_OP_RENAME_CATEGORY, $category->id, $category_name);
          } else {
            $this->logger->op(tool_kaltura_migration_logger::CODE_OP_CREATE_CATEGORY, $category->id, $category->fullName);
            $this->logger->op(tool_kaltura_migration_logger::CODE_OP_DELETE_CATEGORY, $oldcategory->id);
          }
        }
      }
    } else {
      // (c) Didn't found any free category for our module. Let's create a new one.
      $model = array_shift($categories);
      if ($testing) {
        $this->logger->warning("There is another SwitchCast module pointing to the same category. "
        . "A new category will need to be created and all media from the existing category added also to the new category.");
        $this->logger->op(tool_kaltura_migration_logger::CODE_OP_COPY_CATEGORY, $model->id, $category_name);
        $category = $model;
      } else {
        $category = $api->copyCategory($model, $parent, $category_name);
        if ($category === false) {
          $fullname = $parent->fullName . '>' . $category_name;
          $this->logger->errorCM($cm, "Error copying category id {$model->id} refid {$instance->ext_id} to {$fullname}");
          return false;
        } else {
          $this->logger->op(tool_kaltura_migration_logger::CODE_OP_CREATE_CATEGORY, $category->id, $category->fullName);
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

  /**
   * Test the kaltura api connection with configured url and credentials.
   * @return bool true on success.
   */
  public function checkKalturaAPIConnection() {
    try {
      $confid = $this->getUIConfId();
      return true;
    } catch (Exception $error) {
      return false;
    }
  }

  /**
   * Checks if the config value uiconf_id is actually a uiconf in kaltura, and
   * in this case returns it. Otherwise it returns the first available uiconfid
   * in kaltura.
   * @return string an usable uiconfid.
   */
  public function getUIConfId() {
    static $uiconfid = null; // Static cache.
    if ($uiconfid == null) {
      $configured = get_config('tool_kaltura_migration', 'uiconf_id');
      $api = new tool_kaltura_migration_api($this->logger);
      $uiconfs = $api->getUiConfs();
      foreach ($uiconfs as $uiconf) {
        if ($uiconf->id == $configured) {
          $uiconfid = $configured;
          break;
        }
      }
      if ($uiconfid == null && count($uiconfs) > 0) {
        $uiconfid = $uiconfs[0]->id;
      }
    }
    return $uiconfid;
  }

  /**
   * Run the "add flavors" script.
   */
  public function addFlavorsToKalturaUrls($progress, $test = false) {
    // Configure class.
    $this->progress = $progress;
    $this->logger = new tool_kaltura_migration_logger();
    $this->logger->start($test);
    $suffix = $test ? ' in TEST mode' : '';
    $this->logger->info("Starting process ${suffix}.");

    $this->foreachDBColumn(function($table, $column) use ($test) {
      $this->addFlavorsCallback($table, $column, $test);
    });

    $this->logger->info("Process end ${suffix}.");
  }

  protected function addFlavorsCallback($table, $column, $test) {
    global $DB;
    // Only handle tables with id (most of them!).
    if (!$this->tableHasId($table)) {
      return;
    }
    // We can't search for a more concrete string since slaches will be encoded
    // in json columns.
    $url = '/api.cast.switch.ch';
    $pattern = '%' . $DB->sql_like_escape($url) . '%';

    // Build where clause: (column LIKE %pattern%))
    $colname = $DB->get_manager()->generator->getEncQuoted($column->name);
    $where = $DB->sql_like($colname, '?');
    $params = [$pattern];

    $result = $DB->get_records_select_menu($table, $where, $params, '', 'id, ' . $column->name);

    $isjson = $this->isJsonField($table, $column->name);

    foreach ($result as $id => $content) {
      if ($isjson) {
        $content = str_replace('\\/', '/', $content);
      }
      $partnerid = get_config('tool_kaltura_migration', 'partner_id');
      $pattern = "#(https://api.cast.switch.ch/p/${partnerid}/sp/${partnerid}00/playManifest/entryId/([a-zA-Z0-9_]+)/format/url/protocol/https)([^/]|$)#";

      $newcontent = preg_replace_callback($pattern, function($matches) use ($test, $table, $id, $colname) {
        $url = $matches[1];
        $mediaid = $matches[2];
        $prefix = $test ? '[TEST] ' : '';
        $this->logger->info("${prefix}Added flavors to media $mediaid in $table $id ($colname).");
        // Add the flavor parameter.
        $url = "$url/flavorParamIds/6,7/video.mp4";
        // Add the last character matched by pattern but not part of url.
        $replace =  $url . substr($matches[0], strlen($matches[1]));
        return $replace;
      }, $content);

      if (!$test && ($content != $newcontent)) {
        if ($isjson) {
          $newcontent = str_replace("/", "\\/", $newcontent);
        }
        $DB->set_field($table, $column->name, $newcontent, ['id' => $id]);
      }
    }

  }

  // Adhoc task related functions.

  public static function getTaskLastUpdate() {
    return get_config('tool_kaltura_migration', 'task_last_update');
  }

  public static function updateTaskLastUpdate() {
    set_config('task_last_update', time(), 'tool_kaltura_migration');
  }

  public static function getCurrentTask() {
    return get_config('tool_kaltura_migration', 'current_task');
  }

  public static function setCurrentTask($task) {
    set_config('current_task', $task, 'tool_kaltura_migration');
    self::updateTaskLastUpdate();
  }

  public static function getTaskStatus() {
    return get_config('tool_kaltura_migration', 'task_status');
  }

  public static function setTaskStatus($status) {
    set_config('task_status', $status, 'tool_kaltura_migration');
    self::updateTaskLastUpdate();
  }

  public static function getTaskProgress() {
    return get_config('tool_kaltura_migration', 'task_progress');
  }

  public static function setTaskProgress($progress) {
    set_config('task_progress', $progress, 'tool_kaltura_migration');
    self::updateTaskLastUpdate();
  }

  public function scheduleTask($taskname) {
    $status = $this->getTaskStatus();
    if(!in_array($status, ['', 'completed' , 'failed'])) {
      print_error('tasknotfinished', 'tool_kaltura_migration');
    }

    $this->setCurrentTask($taskname);
    $this->setTaskStatus('scheduled');
    $this->setTaskProgress('');

    $task = new \tool_kaltura_migration\task\task();
    \core\task\manager::queue_adhoc_task($task);
  }
}
