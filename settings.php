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
 * Link to tool page from Admin settings.
 *
 * @package    tool
 * @subpackage kaltura_migration
 * @copyright  2022 SWITCH {@link http://switch.ch}
 */

defined('MOODLE_INTERNAL') || die;

if ($hassiteconfig) {
    $settings = new admin_settingpage('tool_kaltura_migration_settings', new lang_string('tool_kaltura_migration_settings', 'tool_kaltura_migration'));

    $adminsecret = get_config('local_kaltura', 'adminsecret');
    $settings->add(new admin_setting_configtext('tool_kaltura_migration/adminsecret',
    new lang_string('adminsecret', 'tool_kaltura_migration'),
    new lang_string('adminsecret_description', 'tool_kaltura_migration'),
    $adminsecret ? $adminsecret : '', PARAM_RAW_TRIMMED));

    $partner_id = get_config('local_kaltura', 'partner_id');
    $settings->add(new admin_setting_configtext('tool_kaltura_migration/partner_id',
    new lang_string('partner_id', 'tool_kaltura_migration'),
    new lang_string('partner_id_description', 'tool_kaltura_migration'),
    $partner_id ? $partner_id : '', PARAM_RAW_TRIMMED));

    $api_url = 'https://api.cast.switch.ch';
    $settings->add(new admin_setting_configtext('tool_kaltura_migration/api_url',
    new lang_string('api_url', 'tool_kaltura_migration'),
    '',
    $api_url, PARAM_RAW_TRIMMED));

    $kaf_uri = get_config('local_kaltura', 'kaf_uri');
    $settings->add(new admin_setting_configtext('tool_kaltura_migration/kaf_uri',
    new lang_string('kaf_uri', 'tool_kaltura_migration'),
    '',
    $kaf_uri ? $kaf_uri : '', PARAM_RAW_TRIMMED));

    $uiconf_id = '';
    $settings->add(new admin_setting_configtext('tool_kaltura_migration/uiconf_id',
    new lang_string('uiconf_id', 'tool_kaltura_migration'),
    new lang_string('uiconf_id_description', 'tool_kaltura_migration'),
    '', PARAM_RAW_TRIMMED));

    $mediaspace_url = 'https://mediaspace.cast.switch.ch';
    $settings->add(new admin_setting_configtext('tool_kaltura_migration/mediaspace_url',
    new lang_string('mediaspace_url', 'tool_kaltura_migration'),
    new lang_string('mediaspace_url_description', 'tool_kaltura_migration'),
    $mediaspace_url, PARAM_RAW_TRIMMED));

    $ADMIN->add('tools', $settings);

    $ADMIN->add('tools', new admin_externalpage('tool_kaltura_migration', get_string('pluginname', 'tool_kaltura_migration'), $CFG->wwwroot.'/'.$CFG->admin.'/tool/kaltura_migration/index.php', 'moodle/site:config'));
}
