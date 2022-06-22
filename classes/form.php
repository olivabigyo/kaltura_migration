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
 * Site wide kaltura migration form.
 *
 * @package    tool
 * @subpackage kaltura_migration
 * @copyright  2022 SWITCH {@link http://switch.ch}
 */

defined('MOODLE_INTERNAL') || die();

require_once("$CFG->libdir/formslib.php");

/**
 * Site wide kaltura migration form.
 */
class tool_kaltura_migration_form extends moodleform
{
    function definition()
    {
        $mform = $this->_form;

        $numresults = $this->_customdata['numresults'];
        $hasresults = $numresults > 0;
        $message = $hasresults ? get_string('thereareresults', 'tool_kaltura_migration', $numresults) :
            get_string('clicksearch', 'tool_kaltura_migration');

        $mform->addElement('static', 'description', '', $message);

        $buttonarray = [];
        if ($hasresults) {
            $buttonarray[] = $mform->createElement('submit', 'op', get_string('downloadcsv', 'tool_kaltura_migration'));
            $buttonarray[] = $mform->createElement('submit', 'op', get_string('deleterecords', 'tool_kaltura_migration'));
        }
        $buttonarray[] = $mform->createElement('submit', 'op', get_string('search', 'tool_kaltura_migration'));

        $mform->addGroup($buttonarray, 'buttonar', '', ' ', false);

    }
}
