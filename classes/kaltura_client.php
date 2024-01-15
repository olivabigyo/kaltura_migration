<?php

global $CFG;

// Load Kaltura API from /KalturaAPI folder but take extra care not to clash with
// local_kaltura plugin.
if (!class_exists('\KalturaClient')) {
  require_once($CFG->dirroot . '/mod/lti/source/switch_config/KalturaAPI/KalturaClient.php');
}

class RetryKalturaClient extends \KalturaClient {
  public function __construct($config) {
    parent::__construct($config);
  }

  protected function doHttpRequest($url, $params = array(), $files = array()) {
    // Call the super function and retry up to 3 times if it fails.
    $tries = 0;
    while ($tries < 3) {
      try {
        // parent class can either throw an exception or return false on error.
        list($result, $curlError) = parent::doHttpRequest($url, $params, $files);
        if ($result === false) {
          throw new \Exception($curlError);
        }
        return array($result, $curlError);
      } catch (\Exception $e) {
        $tries++;
        if ($tries >= 3) {
          throw $e;
        } else {
          sleep(1);
        }
      }
    }
  }
}
