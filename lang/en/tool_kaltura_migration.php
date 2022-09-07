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
 * Strings for component 'tool_kaltura_migration', language 'en'
 *
 * @package    tool
 * @subpackage kaltura_migration
 * @copyright  2022 SWITCH {@link http://switch.ch}
 */

$string['pluginname'] = 'Kaltura Migration';
$string['privacy:metadata'] = 'The Kaltura Migration plugin does not store any personal data.';
$string['pageheader'] = 'Search for SWITCHcast and SWITCHtube videos';
$string['excludedtables'] = 'Several tables are skipped from the video search. These include configuration, log, events, and session tables.';
$string['backupwarning'] = 'WARNING: Be sure to have a database backup before clicking the replace button!';
$string['thereareresults'] = 'There are {$a->numresults} saved results, {$a->numreplaced} of them already replaced.';
$string['clicksearch'] = 'Click the Search button to find all SWITCH videos in this Moodle database.';
$string['search'] = "Search!";
$string['downloadcsv'] = "Download CSV";
$string['deleterecords'] = "Delete records";
$string['replacevideos'] = "Replace videos";
$string['testreplacevideos'] = "Test replace videos";
$string['replacednvideos'] = 'Replaced {$a} videos.';
$string['testreplacemodules'] = 'Test replace SwitchCast activities';
$string['replacemodules'] = 'Replace SwitchCast activities';
$string['migrateembeddings'] = 'Migrate video embeddings and links';
$string['migratemodules'] = 'Migrate SwitchCast activities';
$string['therearemodules'] = 'There are {$a} SwitchCast activities.';
$string['therearenomodules'] = 'There are no SwitchCast activities.';
$string['thereareerrors'] = 'Found {$a} errors during the test phase. You can still do the migration for the elements without errors, but you\'ll need to manually check and fix the remaining cases one by one. We advise not to perform the migration until you know the source of the errors and always have a database backup.';
$string['noerrors'] = 'There were no errors during the test phase. You can safely proceed with the migration.';
$string['foundnvideos'] = 'Found {$a} videos from SwitchCast in this site, including embeddings and links.';
$string['results'] = 'Results';
