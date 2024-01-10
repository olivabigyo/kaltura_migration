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
 * Version details.
 *
 * @package    tool
 * @subpackage kaltura_migration
 * @copyright  2022 SWITCH {@link http://switch.ch}
 */

defined('MOODLE_INTERNAL') || die();

$plugin->version   = 2024011000; // The current plugin version (Date: YYYYMMDDXX)
$plugin->requires  = 2020060900; // Requires this Moodle version
$plugin->component = 'tool_kaltura_migration'; // Full name of the plugin (used for diagnostics)

$plugin->maturity  = MATURITY_RC; // this version's maturity level
$plugin->release   = 'v0.12';

$plugin->dependencies = array(
    'local_kaltura' => 2020121539,
    'ltisource_switch_config' => 2020061500
);
