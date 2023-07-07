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
$string['replacednchannels'] = 'Replaced {$a} channels.';
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
$string['allcourses'] = 'All courses';
$string['contentnotincourse'] = 'Content not in courses';
$string['replacednmodules'] = 'Replaced {$a} SwitchCast activities';
$string['adminsecret'] = 'Admin secret';
$string['adminsecret_description'] = 'Kaltura API admin secret';
$string['partner_id'] = 'Partner id';
$string['partner_id_description'] = 'Kaltura API partner number, ex: 105';
$string['api_url'] = 'Kaltura API URL';
$string['tool_kaltura_migration_settings'] = 'Kaltura Migration Settings';
$string['nomediagallery'] = 'Create a LTI type Media Gallery in order to replace SwitchCast modules by Media Gallery external tools.';
$string['modulestolti'] = 'Replace each SwitchCast module by a Media Gallery external tool';
$string['modulestocoursemedia'] = 'Move all content from SwitchCast modules to the single course Media Gallery';
$string['logs'] = 'Download logs';
$string['downloadurls'] = 'Download video urls';
$string['downloadlogs'] = 'Download execution logs';
$string['uiconf_id'] = 'UI Conf ID';
$string['uiconf_id_description'] = 'References the player configuration. To get the UI Conf ID login to KMC, go to Studio > Universal Studio and search for Player ID.';
$string['nokalturaapiconnection'] = 'Could not connect to Kaltura API. Check the url, secret and partner id config settings at "Kaltura Migration Settings" page before starting the migration.';
$string['nouiconfid'] = 'UI Conf ID (Player id) not configured or missing. Using id {$a} by default. It is recommended to set a proven player configuration at "Kaltura Migration Settings" page before starting the migration.';
$string['mediaspace_url'] = 'MediaSpace URL';
$string['mediaspace_url_description'] = 'The MediaSpace URL for your organization. This is used to migrate links to channels in you content.';
$string['addflavorspageheader'] = 'Fix kaltura URLs';
$string['addflavorsheader'] = 'Add quality parameter to media urls';
$string['addflavorsdescription'] = 'This script will search the whole database for Kaltura media URLs and will add the media quality parameter (technically called flavorParamIds) to these urls so that they point to the video with high quality. The test button searches the database and logs all changes, without actually modifying your data. Use it first to check that the script runs without errors.';
$string['testflavors'] = 'Test replace';
$string['addflavors'] = 'Real replace';
$string['replaceasembeddings'] = 'Replace with generic javascript embedding code.';
$string['replaceasfilterablelinks'] = 'Replace with link filterable by Kaltura Moodle plugin.';
$string['kaf_uri'] = 'KAF URI';
$string['tasknotfinished'] = 'New task can not be started, previous task has not finished yet.';
