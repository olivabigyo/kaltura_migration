# kaltura_migration
Moodle admin tool featuring Kaltura migration for SWITCH users

## Requisites
 - *Kaltura* Moodle plugin (https://github.com/kaltura/moodle_plugin or https://github.com/estevebadia/kaltura_moodle_plugin).
 - *Switch Config* LTI source plugin installed and configured (https://github.com/estevebadia/switch_config).
 - *Video Gallery* External tool properly configured.
## Install
Copy the source code to the `/admin/tool` folder:
```bash
cd admin/tool
git clone https://github.com/estevebadia/kaltura_migration.git
```
Login to your Moodle with admin rights, go to *Site Administration* and install the new plugin. Fill the required admin settings.

If you've already the plugin installed, you need to *Uninstall* it first so the system properly rebuilds the database, as this plugin does not provide the update script.

## Use
 - Go to *Site Administration > Plugins > Admin tools > Kaltura migration*.
 ### Replace embeddings and video urls
 - Press the button "Search" to search the whole database for SWITCH video URLs and also video URLs migrated with previous versions of this plugin.
 - Press the button "Test replace videos" to attempt replacing the videos from a course or from the whole site, but don't actually change the database. You can choose either 
   - Replace with generic javascript embedding code. See Dynamic Embed from [kaltura player docs](https://kaltura.github.io/kaltura-player-js/docs/embed-types.html).
   - Replace with links filterable by the Kaltura Moodle plugin. It creates links similar than the ones produced by the Kaltura button in the content editor. These links are then converted to embedding code by the Kaltura Moodle filter. Note that you won't see the links in the preview but the final result since it already uses the filtering.
 - After the tests, you'll see a new button "Replace Videos". Click it to perform the migration of embedded and linked videos.
 ### Replace SwitchCast activities
 - Choose whether you want to 
   1. Replace SwitchCast activities by *Video gallery* external tools, keeping the same course structure or
   2. Delete all SwitchCast activities and add the media from all these acrivities to the standard Kaltura *Course Gallery*.
 - Press the button "Test replace SwitchCast activities" to attempt replacing the SwitchCast activities, without actually doing it.
 - After the test, you'll see a new button "Replace SwitchCast activities" that actually performs the migration.
 - The migration script will care about all required operations with Kaltura (renaming categories, moving categories, adding new categories, adding content to new categories...). However you may want to do that in a more reliable environment. In this case just get the operations to be done from the test log (see next section), apply the operations on Kaltura, and run the test again to see that these log lines have disappeared. Then finally perform the migration.
 ### Download logs
The last section of the page comes with two buttons to download different logs.
 - *Download video urls* button will output a file CSV with all video urls found in this Moodle site. The file will include the table name, column, record id, url and the course.
 - *Download logs* button will output a CSV file with all logs both from testing and real operations. records include a timespan, an execution identifier, whether or not the execution was testing, an entry identifier, a log level (1=info, 2=operation, 3=warning, 4=error) and a message. Log lines with level = operation are related to operations done -if real execution- or to be done -if testing execution- to the Kaltura API. These lines include a machine readable code and two identifiers that are sufficient to reproduce this operation.
- Note that if you uninstall the plugin the logs will be lost.
## Extra features
  ### Fix Kaltura URLs
Fix Kaltura video URLs created with this plugin (version <= 0.9) to add the video quality parameter. Go to `<YOUR_MOODLE>/admin/tool/kaltura_migration/extra/addflavors.php` and use first the "Test replace" to check the script before modifying the DB and then the "Real replace" button to actually perform the search and replace.
## Moodle version
 - Tested in Moodle 3.11 and Moodle 4.02
