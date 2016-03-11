# Database Synchroniser

## Installation

1. Add the `db_sync` folder to your Symphony `extensions` folder.
2. Go to `Symphony Admin` > `System` > `Preferences`
3. Enable the extension by selecting `Database Synchroniser` in the list and choose `Enable` from the with-selected menu, then click `Apply`.

## Warning

As of version 0.7, queries are stored (only by default, see `Version 1.1.0` notes below) in a file named `db_sync.sql` in your `/manifest` folder. This file is visible to anyone,  therefore I strongly advise that this extension only be enabled on development environments. Don't deploy it to production, or disable it entirely by looking for `db_sync` in Symphony's config file. See additional notes re: Version 1.1.0 for options to change the Query Log location.

## Disclaimer

While this extension has worked well for my own projects, I can't guarantee its stability for your own. My workflow when using a development/staging/production environment is to install this extension on the development server only. When making a release I pull the production database back to staging where I apply the db_sync SQL file. If all goes well after testing, I back up production and run the same db_sync file there. The file is then removed locally and I can continue developing towards another release.

Please, please, please back up your production database before applying any structural changes.

## Version 1.1.0 (additions not by the original author)

**Please Note**: All the new features of v1.1.0 are **Opt-In**, by default there's no change to how the extension functioned in previous versions. (aside from the bug fixes).

 - **Fixes**: Compatibility with Order Entries extension (thanks to Jonathan Mifsud)

 - **Fixes**: Compatibility with Symphony 2.4+ API for adding Author name to Query Log comments

 - **New Feature**: Customise the Query Log location via `config.php`, now relative to project root (remains by default as `/manifest/db_sync.sql`).

 - **New Feature**: Preferences panel button to replay all queries in the current Query Log. When enabled it *disables* Query Logging, thus it is designed for use on non-development deployments (i.e. when using dual-manifest setup) to allow easy DB synchronisation from a development's uploaded Query Log. After synchronisation the Query log is then archived as e.g. `/manifest/db_sync.sql.replayed.2016-03-05-12-35-01` to prevent duplicate replay (but keeping a record of past synchronisations). Disabled by default, enable it via `config.php` by setting `enable_replay` to `yes` for any relevant non-development deployments (e.g. testing, production, etc). See **An Example Setup & Workflow** section below for step-by-step guide.

 - **New Feature**: Backup and Restore your Symphony Database with buttons on the Preferences page. Uses `mysqldump` and `gzip` behind the scenes to make a full, zipped copy of your current database. Restoring the database is likewise handled behind the scenes by `mysql` and `gunzip`. The exact location of all four binaries are easily edited in `config.php`.

 - **New Feature**: Track content changes, either as Commented Queries (non-replayed, simply logged as comments for manual review) or as Full Queries (replayed like any other tracked query). Disabled by default, enable it via `config.php` by setting `track_content` to `comment` (for commented only) or `yes` (for full tracking).

 - **New Feature**: Track Author changes, either as Commented Queries (non-replayed, simply logged as comments for manual review) or as Full Queries (replayed like any other tracked query). Disabled by default, enable it via `config.php` by setting `track_authors` to `comment` (for commented only) or `yes` (for full tracking).  
**Please Note**: This feature even when enabled **ignores** updates to the Author's `last_seen` column since an `UPDATE` is made to that column on each page load in the Admin, thus including it in the Query Log generates an large number of useless entries.

### An Example Setup & Workflow

**Prerequisite**: Dual/Symlinked Manifest folder setup, expects a different `config.php` for development and production environments.

1. Install the extension.

2. Customise both `config.php` files to set `'db_sync'` with `'log_dir' => '/sql/'` (note both slashes).

3. In your production `config.php` set `'db_sync'` with `'enable_replay' => 'yes'`

4. Add `RewriteRule ^sql/(.*)$ - [F]` to `.htaccess` (or Apache config) to prevent access to `/sql` directory in production.

5. Track `/sql` directory under version control.

6. Develop as usual on the your development version.

7. Upload (via version control, or however else, FTP etc) your changes (including `/sql/` dir) to your production deployment.

8. In `Symphony > Preferences` backup your current production deployment with the `Backup Database` button.

9. Replay your development Query Log in production with `Synchronise Database from Logs` button.

10. Check everything worked as expected, if something went wrong, restore from backup with the `Restore Database from Last Backup` button. 

If something did go wrong during synchronisation you'll need to manually investigate which queries caused the problems, look in the archived Query Log in e.g. `/sql/db_sync.sql.replayed.2016-03-05-12-35-01`

