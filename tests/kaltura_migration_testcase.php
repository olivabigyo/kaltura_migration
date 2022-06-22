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
 * Unit tests
 *
 * @package    tool
 * @subpackage kaltura_migration
 * @copyright  2022 SWITCH {@link http://switch.ch}
 */

 require_once(__DIR__ . '/fixtures/testable_controller.php');

class kaltura_migration_testcase extends advanced_testcase {
   public function test_extract_urls() {
      $text = 'XXX aaa <a href="https://tube.switch.ch/video/1234567890?embed=true">video link</a>' . "\n"
         . 'http://cast.switch.ch/casts/123 http://cast.switch.com/casts/123';
      $migration = new testable_kaltura_migration_controller();
      $urls = $migration->extractUrls($text);
      $this->assertEquals(count($urls), 2);
      $this->assertEquals($urls[0], 'https://tube.switch.ch/video/1234567890?embed=true');
      $this->assertEquals($urls[1], 'http://cast.switch.ch/casts/123');
   }
}
