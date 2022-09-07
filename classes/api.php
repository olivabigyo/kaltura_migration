<?php
global $CFG;
require_once($CFG->dirroot . '/local/kaltura/API/KalturaClient.php');

class tool_kaltura_migration_api {
  protected $client;

  function __construct() {
    $this->client = self::buildClient();
  }

  protected static function buildClient() {
    global $USER;
    // Get some config from local_kaltura plugin.
    $adminsecret = get_config('local_kaltura', 'adminsecret');
    $partner_id = get_config('local_kaltura', 'partner_id');
    // Other required config fields. Should we make these configurable admin
    // fields?
    $url = 'https://api.cast.switch.ch';
    $user = $USER->email;

    $config = new KalturaConfiguration();
    $config->serviceUrl = $url;
    $config->format = KalturaClientBase::KALTURA_SERVICE_FORMAT_JSON;

    $client = new KalturaClient($config);
    $ks = $client->generateSession($adminsecret, $user, KalturaSessionType::ADMIN, $partner_id);
    $client->setKs($ks);

    return $client;
  }

  protected function singleOrFalseResult($result) {
    $count = count($result->objects);
    if ($count == 0) {
      // Entry not found!
      return false;
    } else if ($count > 1) {
      // Found more than one entry with this reference id!.
      return false;
    } else {
      return $result->objects[0];
    }
  }
  /**
   * @param array $referenceIds
   */
  public function getMediaByReferenceIds($referenceIds) {
    foreach ($referenceIds as $referenceId) {
      if (($entry = $this->getMediaByReferenceId($referenceId)) !== false) {
        return $entry;
      }
    }
  }
  /**
   * @param string $referenceId
   */
  public function getMediaByReferenceId($referenceId) {
    $filter = new KalturaMediaEntryFilter();
    $filter->referenceIdEqual = $referenceId;
    $pager = null;
    $result = $this->client->media->listAction($filter, $pager);
    return $this->singleOrFalseResult($result);
  }
  /**
   * Fetch Kaltura Category Given its reference id.
   * @param string $referenceId.
   * @return stdClass|bool category record or false if no or more than one category found.
   */
  public function getCategoryByReferenceId($referenceId) {
    $filter = new KalturaCategoryFilter();
    $filter->referenceIdEqual = $referenceId;
    $pager = null;
    $result = $this->client->category->listAction($filter, $pager);
    return $this->singleOrFalseResult($result);
  }

  /**
   * Rename Kaltura category
   * @param stdClass $category The category recird.
   * @param string $name the new name.
   */
  public function setCategoryName($category, $name) {
    if ($category->name != $name) {
      $update = new KalturaCategory();
      $update->name = $name;
      return $this->client->category->update($category->id, $update);
    }
    return $category;
  }
}
