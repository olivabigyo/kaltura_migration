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
 * Helper class for adhoc task progress reporting.
 *
 * @package    tool_kaltura_migration
 * @copyright  2023 lukc@zhaw.ch
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_kaltura_migration\task;

defined('MOODLE_INTERNAL') || die();

class progress {
    private int $totalsteps = 0;

    public function start_progress(string $description, int $totalsteps) {
        $this->totalsteps = $totalsteps;
        \tool_kaltura_migration_controller::setTaskProgress($description);
    }

    public function progress(int $step) {
        \tool_kaltura_migration_controller::setTaskProgress($step . ' / ' . $this->totalsteps);
    }

    public function end_progress() {
        \tool_kaltura_migration_controller::setTaskProgress($this->totalsteps . ' / ' . $this->totalsteps);
    }
}
