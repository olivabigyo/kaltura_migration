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
 * Simple logger implementation for the Kaltura migration class at controller.php.
 *
 * The standard Moodle logging system with events is too sophisticated for our
 * use case. This logger just outputs the event to the screen as a HTML table and
 * also records the line in a DB table.
 *
 * @package    tool
 * @subpackage kaltura_migration
 * @copyright  2022 SWITCH {@link http://switch.ch}
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;

class tool_kaltura_migration_logger {
  public const LEVEL_INFO = 1; // General information.
  public const LEVEL_OPERATION = 2; // Operation done in kaltura server.
  public const LEVEL_WARNING = 3; // Something possibly dangerous.
  public const LEVEL_ERROR = 4; // Error happened.

  public const CODE_OP_CREATE_CATEGORY = 'CREATE_CATEGORY';
  public const CODE_OP_RENAME_CATEGORY = 'RENAME_CATEGORY';
  public const CODE_OP_MOVE_CATEGORY = 'MOVE_CATEGORY';
  public const CODE_OP_ADD_MEDIA_TO_CATEGORY = 'ADD_MEDIA_TO_CATEGORY';
  public const CODE_OP_COPY_CATEGORY = 'COPY_CATEGORY';


  private $currentEntry = 0;
  private $execution;
  private $testing;
  private $errors;

  public function __construct() {

  }

  public function start($testing = false) {
    echo '<table border="1">';
    $this->currentEntry = 0;
    global $DB;
    $max = $DB->get_field_sql('SELECT MAX(execution) FROM {tool_kaltura_migration_logs}');
    $this->execution = intval($max) + 1;
    $this->testing = $testing;
    $this->errors = [];
  }
  public function end() {
    if ($this->currentEntry > 0) {
      echo '</td></tr>';
    }
    echo '</table>';
  }
  public function entry($name = false) {
    if ($this->currentEntry > 0) {
      echo '</td></tr>';
    }
    $this->currentEntry++;
    echo '<tr><td>' . $this->currentEntry . ($name ? '(' . $name . ')' : '') . '</td><td>';
  }
  public function content($content) {
    echo $content . '</td><td>';
  }
  public function codeContent($content) {
    $content = '<div style="white-space: pre-wrap; font-family: monospace; max-width: 500px; max-height: 500px; overflow: scroll;">' . $content . "</div>";
    $this->content($content);
  }
  public function htmlContent($content) {
    $content = format_text($content, FORMAT_HTML, ['noclean' => true]);
    $this->content($content);
  }
  /**
   * @param int $level Use level constans from this class.
   * @param string $message The message string.
   * @param string $code Optional. The operation code, use code constants from this class
   * @param string $id1 Optional. The first parameter of the operation.
   * @param string $id2 Optional. The second parametet of the operation.
   */
  public function log($level, $message, $code = '', $id1 = '', $id2 = '') {
    global $DB;
    $DB->insert_record('tool_kaltura_migration_logs', (object) [
      'execution' => $this->execution,
      'testing' => $this->testing,
      'level' => $level,
      'time' => time(),
      'entry' => $this->currentEntry,
      'level' => $level,
      'message' => $message,
      'code' => $code,
      'id1' => $id1,
      'id2' => $id2
    ]);
    if ($level == self::LEVEL_ERROR) {
      echo '<p class="alert-danger">ERROR: ';
      $this->errors[] = $message;
    } else {
      echo '<p>';
      if ($level == self::LEVEL_INFO) {
        echo 'INFO: ';
      } else if ($level == self::LEVEL_OPERATION) {
        echo 'OPERATION: ';
      } else if ($level == self::LEVEL_WARNING) {
        echo 'WARNING: ';
      }
    }
    echo htmlspecialchars($message) . '</p>';
  }

  public function errorCM($cm, $message) {
    $msg =  "In opencast course module id {$cm->id} from course id {$cm->course}. " . $message;
    $this->log(self::LEVEL_ERROR, $msg);
  }

  public function error($msg) {
    $this->log(self::LEVEL_ERROR, $msg);
  }
  public function warning($msg) {
    $this->log(self::LEVEL_WARNING, $msg);
  }
  public function info($msg) {
    $this->log(self::LEVEL_INFO, $msg);
  }
  public function op($code, $id1 = '', $id2 = '') {
    $infix = $this->testing ? 'will be ' : '';
    if ($code == self::CODE_OP_CREATE_CATEGORY) {
      $msg = "Category $id1 {$infix}created with name $id2";
    } else if ($code == self::CODE_OP_RENAME_CATEGORY) {
      $msg = "Category $id1 {$infix}renamed to $id2";
    } else if ($code == self::CODE_OP_MOVE_CATEGORY) {
      $msg = "Category $id1 {$infix}moved to $id2";
    } else if ($code == self::CODE_OP_ADD_MEDIA_TO_CATEGORY) {
      $msg = "Media $id1 {$infix}added to category $id2";
    } else if ($code == self::CODE_OP_COPY_CATEGORY) {
      $msg = "Category $id1 {$infix}copied to name $id2 with all its entries";
    }
    $this->log(self::LEVEL_OPERATION, $msg, $code, $id1, $id2);
  }
  /**
   * Return array of error messages from last execution.
   */
  public function getErrors() {
    return $this->errors;
  }

}
