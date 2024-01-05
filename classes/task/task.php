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
 * Adhoc task
 *
 * @package    tool_kaltura_migration
 * @copyright  2023 lukc@zhaw.ch
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_kaltura_migration\task;

defined('MOODLE_INTERNAL') || die();

class task extends \core\task\adhoc_task
{
    public function execute()
    {
        $migration = new \tool_kaltura_migration_controller();

        $migration->setTaskStatus('running');
        $status = 'failed';

        try {
            $progress = new \tool_kaltura_migration\task\progress();
            $task = $migration->getCurrentTask();
            if ($task == 'search') {
                $migration->execute($progress, true);
            } elseif ($task == 'replaceall') {
                $migration->replaceAll($progress);
            } elseif ($task == 'replaceallmodules') {
                $migration->replaceAllModules($progress);
            } else {
                throw new \Exception('Unknown task: ' . $task);
            }
            $status = 'completed';
        } catch (\Exception $e) {
            $migration->setTaskProgress($e->getMessage());
        }

        $migration->setTaskStatus($status);
    }
}
