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
        $numreplaced = $this->_customdata['numreplaced'];
        $nummodules = $this->_customdata['nummodules'];
        $numerrors = isset($this->_customdata['numerrors']) ? $this->_customdata['numerrors'] : 0;
        $op = isset($this->_customdata['op']) ? $this->_customdata['op'] : false;

        $hasresults = $numresults > 0;
        $hasreplacements = $numresults > $numreplaced;
        $hasmodules = $nummodules > 0;

        $a = new stdClass;
        $a->numresults = $numresults;
        $a->numreplaced = $numreplaced;

        $mform->addElement('header', 'migrateembeddingsheader', get_string('migrateembeddings', 'tool_kaltura_migration'));
        $mform->setExpanded('migrateembeddingsheader');

        $message = $hasresults ? get_string('thereareresults', 'tool_kaltura_migration', $a) :
            get_string('clicksearch', 'tool_kaltura_migration');
        $mform->addElement('static', 'description', '', $message);

        $buttonarray = [];
        if ($hasresults) {
            $buttonarray[] = $mform->createElement('submit', 'op', get_string('deleterecords', 'tool_kaltura_migration'));
        } else {
            $buttonarray[] = $mform->createElement('submit', 'op', get_string('search', 'tool_kaltura_migration'));
        }

        if ($hasreplacements) {
            $buttonarray[] = $mform->createElement('submit', 'op', get_string('testreplacevideos', 'tool_kaltura_migration'));
            if ($op == get_string('testreplacevideos', 'tool_kaltura_migration')) {
                $message = $numerrors > 0 ? get_string('thereareerrors', 'tool_kaltura_migration', $numerrors) : get_string('noerrors', 'tool_kaltura_migration');
                $mform->addElement('static', 'videoerrors', '', $message);
                $buttonarray[] = $mform->createElement('submit', 'op', get_string('replacevideos', 'tool_kaltura_migration'));
            }
        }
        $mform->addGroup($buttonarray, 'buttonar', '', ' ', false);

        $mform->addElement('header', 'migratemodulesheader', get_string('migratemodules', 'tool_kaltura_migration'));
        $buttonarray = [];
        $mform->setExpanded('migratemodulesheader');
        if ($hasmodules) {
            $mform->addElement('static', 'message', '', get_string('therearemodules', 'tool_kaltura_migration', $nummodules));
            $buttonarray[] = $mform->createElement('submit', 'op', get_string('testreplacemodules', 'tool_kaltura_migration'));

            if ($op == get_string('testreplacemodules', 'tool_kaltura_migration')) {
                $message = $numerrors > 0 ? get_string('thereareerrors', 'tool_kaltura_migration', $numerrors) : get_string('noerrors', 'tool_kaltura_migration');
                $mform->addElement('static', 'videoerrors', '', $message);
                $buttonarray[] = $mform->createElement('submit', 'op', get_string('replacemodules', 'tool_kaltura_migration'));
            }
            $mform->addGroup($buttonarray, 'buttonar2', '', ' ', false);
        } else {
            $mform->addElement('static', 'message', '', get_string('therearenomodules', 'tool_kaltura_migration'));
        }
    }
}
