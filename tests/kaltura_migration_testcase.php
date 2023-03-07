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
         . 'http://cast.switch.ch/casts/123 http://cast.switch.com/casts/123' ."\n"
         . '<iframe src="https://download.cast.switch.ch/ethz-ch/switchcast-player/a6d933d9-8513-4a18-8eea-d36ebb2f1357/463fd0cc-0b82-4aba-9d5c-840667b4e7fd/Learning_goals.mp4"></iframe>' . "\n"
         .  'And finally look at the channel <a href="https://tube.switch.ch/channels/bF7N6sNLse">https://tube.switch.ch/channels/bF7N6sNLse</a>'
         . 'And the external url: {"url": "https://tube.switch.ch/external/u1KUHLZp7h"}' ;
      $migration = new testable_kaltura_migration_controller();
      $urls = $migration->extractUrls($text);
      $this->assertEquals(count($urls), 5);
      $this->assertEquals($urls[0], 'https://tube.switch.ch/video/1234567890?embed=true');
      $this->assertEquals($urls[1], 'http://cast.switch.ch/casts/123');
      $this->assertEquals($urls[2], 'https://download.cast.switch.ch/ethz-ch/switchcast-player/a6d933d9-8513-4a18-8eea-d36ebb2f1357/463fd0cc-0b82-4aba-9d5c-840667b4e7fd/Learning_goals.mp4');
      $this->assertEquals($urls[3], 'https://tube.switch.ch/channels/bF7N6sNLse',
      $this->assertEquals($urls[4], 'https://tube.switch.ch/external/u1KUHLZp7h')
      );
   }
   public function test_extract_refids() {
      $urls = [
         'https://tube.switch.ch/video/KIYKnxzVr3?embed=true',
         'https://cast.switch.ch/casts/gjuqKSPL24',
         'https://download.cast.switch.ch/ethz-ch/switchcast-player/a6d933d9-8513-4a18-8eea-d36ebb2f1357/463fd0cc-0b82-4aba-9d5c-840667b4e7fd/Learning_goals.mp4',
         'https://tube.switch.ch/channels/bF7N6sNLse',
         'https://tube.switch.ch/external/u1KUHLZp7h'
         ];
      $expected = ['KIYKnxzVr3', 'gjuqKSPL24', 'a6d933d9-8513-4a18-8eea-d36ebb2f1357,463fd0cc-0b82-4aba-9d5c-840667b4e7fd', 'bF7N6sNLse', 'u1KUHLZp7h'];
      $migration = new testable_kaltura_migration_controller();
      foreach($urls as $i => $url) {
         $refids = $migration->getReferenceIdsFromUrl($url);
         $refids = is_array($refids) ? implode(',', $refids) : false;
         $this->assertEquals($refids, $expected[$i]);
      }
   }
}
