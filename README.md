# kaltura_migration
Moodle admin tool featuring Kaltura migration for SWITCH users

## Install
Copy the source code to the `/admin/tool` folder:
```bash
cd admin/tool
git clone https://github.com/estevebadia/kaltura_migration.git
```
Login to your Moodle with admin rights, go to *Site Administration* and install the new plugin. It will create a new empty database table.
If you've already the plugin installed, you need to *Uninstall* it first so the system properly rebuilds the database, as this plugin does not provide update script.

## Use
 - Go to *Site Administration > Plugins > Admin tools > Kaltura migration*.
 - Press the button "Search" to search the whole database for SWITCH video URLs.
 - All found video URLs are stored in a database table.
 - Download the URLs table by pressing the button "Download CSV".
 - You can clean the database table with "Delete records" button.
 - Pressing the "Search" again will reset the URLs table.

## Moodle version
 - Tested in Moodle 3.11
