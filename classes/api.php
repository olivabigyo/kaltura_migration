<?php
global $CFG;
require_once($CFG->dirroot . '/local/kaltura/API/KalturaClient.php');

class tool_kaltura_migration_api {
  protected $client;
  /**
   * @var tool_kaltura_migration_logger
   */
  protected $logger;

  function __construct($logger) {
    $this->client = self::buildClient();
    $this->logger = $logger;
  }

  protected static function buildClient() {
    global $USER;
    // Get some config from local_kaltura plugin.
    $adminsecret = get_config('tool_kaltura_migration', 'adminsecret');
    $partner_id = get_config('tool_kaltura_migration', 'partner_id');
    $url = get_config('tool_kaltura_migration', 'api_url');

    $user = isset($USER) && isset($USER->email) ? $USER->email : '';

    $config = new \KalturaConfiguration();
    $config->serviceUrl = $url;
    $config->format = \KalturaClientBase::KALTURA_SERVICE_FORMAT_JSON;

    $client = new \KalturaClient($config);
    $ks = $client->generateSession($adminsecret, $user, \KalturaSessionType::ADMIN, $partner_id);
    $client->setKs($ks);

    return $client;
  }

  protected function singleOrFalseResult($result) {
    $count = count($result->objects);
    if ($count == 0) {
      // Entry not found!
      return false;
    }
    $entry = $result->objects[0];
    if ($count > 1) {
      // Found more than one entry with this reference id!.
      $reference = $entry->referenceId;
      $id = $entry->id;
      $this->logger->warning("There are $count entries with reference id $reference, taking the first one with id $id.");
    }
    return $entry;
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
    $filter = new \KalturaMediaEntryFilter();
    $filter->referenceIdEqual = $referenceId;
    $pager = null;
    $result = $this->client->media->listAction($filter, $pager);
    return $this->singleOrFalseResult($result);
  }
  /**
   * @param string $entryId
   */
  public function getMediaByEntryId($entryId) {
    $filter = new \KalturaMediaEntryFilter();
    $filter->idEqual = $entryId;
    $pager = null;
    $result = $this->client->media->listAction($filter, $pager);
    return $this->singleOrFalseResult($result);
  }
  /**
   * @param array $referenceIds
   */
  public function getCategoryByReferenceIds($referenceIds) {
    foreach ($referenceIds as $referenceId) {
      $entries = $this->getCategoriesByReferenceId($referenceId);
      if (!empty($entries)) {
        return $entries[0];
      }
    }
    return false;
  }
  /**
   * Fetch Kaltura Category Given its reference id.
   * @param string $referenceId.
   * @return stdClass|bool category record or false if no or more than one category found.
   */
  public function getCategoryByReferenceId($referenceId) {
    $filter = new \KalturaCategoryFilter();
    $filter->referenceIdEqual = $referenceId;
    $pager = null;
    $result = $this->client->category->listAction($filter, $pager);
    return $this->singleOrFalseResult($result);
  }

   /**
   * Fetch Kaltura Categories Given its reference id.
   * @param string $referenceId.
   * @return array category array.
   */
  public function getCategoriesByReferenceId($referenceId) {
    $filter = new \KalturaCategoryFilter();
    $filter->referenceIdEqual = $referenceId;
    $pager = null;
    $result = $this->client->category->listAction($filter, $pager);
    return $result->objects;
  }

  /**
   * Search for a category that shares the parent category with the given parent
   * and with given name.
   *
   * @param object $parentCategory The parent category.
   * @param string $name The name of the category to be found.
   * @return object|bool The found category or false.
   */
  public function getCategoryByParentAndName($parentCategory, $name) {
    $fullName = $parentCategory->fullName . '>' . $name;
    return $this->getCategoryByFullName($fullName);
  }

  /**
   * Fetches a category given its full name.
   * Eg: "Moodle>site>channels>2-50"
   */
  public function getCategoryByFullName($fullName) {
    $filter = new \KalturaCategoryFilter();
    $filter->fullNameEqual = $fullName;
    $pager = null;
    $result = $this->client->category->listAction($filter, $pager);
    return $this->singleOrFalseResult($result);
  }

  /**
   * Move (or just rename) Kaltura category
   * @param stdClass $category The category to be moved/renamed.
   * @param object $parent The destination parent category
   * @param string $name the new name.
   */
  public function moveCategory($category, $parent, $name, $retry = 0) {
    $update = new \KalturaCategory();
    $needupdate = false;
    if ($category->name != $name) {
      $update->name = $name;
      $needupdate = true;
    }
    if ($category->parentId != $parent->id) {
      $update->parentId = $parent->id;
      $needupdate = true;
    }
    if ($needupdate) {
      try {
        return $this->client->category->update($category->id, $update);
      } catch (\Exception $e) {
        // It happens that when moving a category, Kaltura server does this operation
        // asynchronously (it looks like they have to rebuild some internal index) and
        // they block further operations on categories until the first operation is
        // completed. That's why we spend up to 7 seconds to wait for that.
        if ($e->getCode() == 'CATEGORIES_LOCKED') {
          if ($retry < 3) {
            sleep(2^$retry);
            $retry++;
            return $this->moveCategory($category, $parent, $name, $retry);
          } else {
            $this->logger->error("Could not move category {$category->id} from {$category->parentId} to {$parent->id} after 3 tries. Please try again or do the move my other means");
            return false;
          }
        }
        // Prevent pausing migration.
        $this->logger->error($e->getMessage());
        return false;
      }
    }
    return $category;
  }

  public function createCategory($category) {
    // Build new category object
    $fields = ['name','parentId', 'description', 'tags', 'privacy',
    'inheritanceType', 'defaultPermissionLevel', 'owner', 'referenceId',
    'contributionPolicy', 'privacyContext', 'partnerSortValue', 'partnerData',
    'defaultOrderBy', 'moderation', 'isAggregationCategory', 'aggregationCategories'];
    $newcategory = new \KalturaCategory();
    foreach($fields as $field) {
      $newcategory->{$field} = $category->{$field};
    }
    // Create new empty category.
    try {
      $newcategory = $this->client->category->add($newcategory);
      return $newcategory;
    } catch (\Exception $e) {
      // Prevent pausing execution if cant create new category.
      $this->logger->error("Could not create category. " . $e->getMessage());
      return false;
    }
  }

  /**
   * Copy the given category to a new one with given name.
   * @param object $category the category object to be copied.
   * @param object $parent the parent category of the destination category.
   * @param string $newname the name of the new category
   * @return object|bool the new category or false.
   */
  public function copyCategory($category, $parent, $newname) {
    $model = clone($category);
    $model->name = $newname;
    $model->parentId = $parent->id;

    if (($newcategory = $this->createCategory($model)) === false) {
      return false;
    }

    $this->copyMedia($category, $newcategory);
    return $newcategory;
  }

  public function copyMedia($fromcategory, $tocategory) {
    $filter = new \KalturaCategoryEntryFilter();
    $filter->categoryIdEqual = $fromcategory->id;

    // Add all media from old category to new category.
    $result = $this->client->categoryEntry->listAction($filter, null);
    $entryids = array_map(function($object) { return $object->entryId; }, $result->objects);
    // Check which entries are already in target category.
    $filter->categoryIdEqual = $tocategory->id;
    $filter->entryIdIn = implode(',', $entryids);
    $existing = $this->client->categoryEntry->listAction($filter, null);
    $existingids = array_map(function($object) { return $object->entryId; }, $existing->objects);

    foreach ($entryids as $id) {
      if (in_array($id, $existingids)) {
        $this->logger->info("Entry id {$id} already in category, no need to add");
      } else {
        $entry = new \KalturaCategoryEntry();
        $entry->categoryId = $tocategory->id;
        $entry->entryId = $id;
        try {
          $this->client->categoryEntry->add($entry);
          $this->logger->op(tool_kaltura_migration_logger::CODE_OP_ADD_MEDIA_TO_CATEGORY, $id, $tocategory->id);
        } catch (\Exception $e) {
          // Don't pause execution.
          $this->logger->error("Error adding entry {$id} to category {$tocategory->id}. Should be fixed manually!" . $e->getMessage());
        }
      }
    }
  }

  /**
   * Fetches the list of uiconf objects from kaltura api service.
   * @return array of uiconf objects.
   */
  public function getUiConfs() {
    $result = $this->client->uiConf->listAction(null, null);
    return $result->objects;
  }
}
