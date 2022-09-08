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
 - Press the button "Search" to search the whole database for SWITCH video URLs.
 - Press the button "Test replace videos" to attempt replacing the videos from a course or from the whole site, but don't actually change the database.
 - After the tests, you'll see a new button "Replace Videos". Click it to perform the migration of embedded and linked videos.
 ### Replace SwitchCast activities
 - Press the button "Test replace SwitchCast activities" to attempt replacing teh SwitchCast activities, without actually doing it.
 - After the test, you'll see a new button "Replace SwitchCast activities" that actually performs the migration.
## Moodle version
 - Tested in Moodle 3.11
