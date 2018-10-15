<?php
	
	Class extension_db_sync extends Extension {
		
		public static $meta_written = FALSE;

        private static $dont_log_this_run = FALSE;

		public function getSubscribedDelegates() {
			return array(
				array(
					'page'		=> '/backend/',
					'delegate'	=> 'PostQueryExecution',
					'callback'	=> 'log'
				),
                array(
                    'page' => '/system/preferences/',
                    'delegate' => 'AddCustomPreferenceFieldsets',
                    'callback' => 'appendPreferences'
                )
			);
		}
		
		public function install() {
			Symphony::Configuration()->set('enabled', 'yes', 'db_sync');
			Symphony::Configuration()->set('enable_replay', 'no', 'db_sync');
            Symphony::Configuration()->set('track_authors', 'no', 'db_sync');
            Symphony::Configuration()->set('track_content', 'no', 'db_sync');
            Symphony::Configuration()->set('log_file', 'db_sync.sql', 'db_sync');
            Symphony::Configuration()->set('log_dir', '/workspace/struct/', 'db_sync');
            Symphony::Configuration()->set('mysql_bin', '/usr/local/bin/mysql', 'db_sync');
            Symphony::Configuration()->set('mysqldump_bin', '/usr/local/bin/mysqldump', 'db_sync');
            Symphony::Configuration()->set('gzip_bin', '/usr/bin/gzip', 'db_sync');
            Symphony::Configuration()->set('gunzip_bin', '/usr/bin/gunzip', 'db_sync');
			Symphony::Configuration()->write();
			return TRUE;
		}

		public function update($previousVersion = false) {
			if(version_compare($previousVersion, '1.1', '<')) {
                Symphony::Configuration()->set('enable_replay', 'no', 'db_sync');
                Symphony::Configuration()->set('track_authors', 'no', 'db_sync');
                Symphony::Configuration()->set('track_content', 'no', 'db_sync');
                Symphony::Configuration()->set('log_file', 'db_sync.sql', 'db_sync');
                Symphony::Configuration()->set('log_dir', '/manifest/', 'db_sync');
                Symphony::Configuration()->set('mysql_bin', '/usr/local/bin/mysql', 'db_sync');
                Symphony::Configuration()->set('mysqldump_bin', '/usr/local/bin/mysqldump', 'db_sync');
                Symphony::Configuration()->set('gzip_bin', '/usr/bin/gzip', 'db_sync');
                Symphony::Configuration()->set('gunzip_bin', '/usr/bin/gunzip', 'db_sync');
                Symphony::Configuration()->write();
                return TRUE;
            }
        }
		
		public function uninstall() {
			Symphony::Configuration()->remove('db_sync');
			Symphony::Configuration()->write();
		}

        public function appendPreferences($context) {

            // Create preference group
            $group = new XMLElement('fieldset');
            $group->setAttribute('class', 'settings');
            $group->appendChild(new XMLElement('legend', __('Database Synchroniser')));

            // create control frame
            $div = new XMLElement('div', NULL, array('id' => 'db_sync_control', 'class' => 'label'));
            $span = new XMLElement('span', NULL, array('class' => 'frame'));

            //append action button
			if(Symphony::Configuration()->get('enable_replay', 'db_sync') == 'yes') {
                $span->appendChild(new XMLElement('button', __('Synchronise Database from Query Log'), array('name' => 'action[db-sync-replay]', 'type' => 'submit')));
            }
            $span->appendChild(new XMLElement('button', __('Backup Database'), array('name' => 'action[db-sync-backup]', 'type' => 'submit')));
            $span->appendChild(new XMLElement('button', __('Restore Database from Last Backup'), array('name' => 'action[db-sync-restore]', 'type' => 'submit')));
            $div->appendChild($span);
            $group->appendChild($div);

            $results = '';

            $latest_backup = self::latestBackupFile();
            if ($latest_backup) {
                $results = "<strong>Lastest backup: </strong>" . $latest_backup;
            }

            if(isset($_POST['action']['db-sync-replay'])){
                $results = $this->replay();
            }
            else if(isset($_POST['action']['db-sync-backup'])){
                $results = $this->backup();
            }
            else if(isset($_POST['action']['db-sync-restore'])){
                $results = $this->restore();
            }
            
            if ($results) { // Append output results
                $group->appendChild(new XMLElement('p', __($results)), array());
            }

            // Append new preference group
            $context['wrapper']->appendChild($group);
        }
		
		public static function log($context) {
			if(Symphony::Configuration()->get('enabled', 'db_sync') == 'no') return;
			if(Symphony::Configuration()->get('enable_replay', 'db_sync') == 'yes') return;
            if(self::$dont_log_this_run) return;
			
			$query = $context['query'];

            // ensure one line per statement, no line breaks or extraneous whitespace
            $query = trim(preg_replace('/\s+/', ' ', $query)); 

			// append query delimeter if it doesn't exist
			if (!preg_match('/;$/', $query)) $query .= ";";


			$tbl_prefix = Symphony::Configuration()->get('tbl_prefix', 'database');

			/* FILTERS */
			// only structural changes, no SELECT statements
			if (!preg_match('/^(insert|update|delete|create|drop|alter|rename)/i', $query)) return;

			// un-tracked tables (sessions, cache)
			if (preg_match("/{$tbl_prefix}(cache|forgotpass|sessions)/i", $query)) return;

            // track, or not, authors
            // include as comments instead of ignoring completely if track_authors enabled
            if (preg_match("/{$tbl_prefix}(authors)/i", $query)) {
                // always ignore 'last seen' tracking since it's queried on every admin page load
                if (preg_match("/^UPDATE {$tbl_prefix}authors SET \`last_seen\`/", trim($query))) return; 

                if (Symphony::Configuration()->get('track_authors', 'db_sync') == 'no') {
                    return;
                }
                else if (Symphony::Configuration()->get('track_authors', 'db_sync') == 'comment') {
                    $query = '-- ' . $query; // commentify
                }
            }

			// content updates in tbl_entries (includes tbl_entries_fields_*)
            // include as comments instead of ignoring completely if track_content enabled
            if (preg_match('/^(insert|delete|update)/i', $query) && preg_match("/({$tbl_prefix}entries)/i", $query)) {
                if (Symphony::Configuration()->get('track_content', 'db_sync') == 'no') {
                    return;
                }
                else if (Symphony::Configuration()->get('track_content', 'db_sync') == 'comment') {
                    $query = '-- ' . $query; // commentify
                }
            }

			$line = '';

			if(self::$meta_written == FALSE) {

				$line .= "\n" . '-- ' . date('Y-m-d H:i:s', time());

				$author = Symphony::Engine()->Author();
				if (isset($author)) $line .= ', ' . $author->getFullName();

				$url = Administration::instance()->getCurrentPageURL();
				if (!is_null($url)) $line .= ', ' . $url;

				$line .= "\n";

				self::$meta_written = TRUE;

			}

            // separate each query log with a blank line
			$line .= $query . "\n";
			
            $handle = @fopen(self::logFile(), 'a');
			if ($handle) {
                fwrite($handle, $line);
                fclose($handle);
            }
		}

        private function replay() {
            $db = Symphony::Database();
            $log_file = self::logFile();
            if (file_exists($log_file)) {
                $logs = file_get_contents($log_file);
                $lines = explode("\n", $logs);
                $queries = [];
                foreach ($lines as $line) {
                    if ($line == '') continue; // skip blank lines
                    if (substr($line, 0, 2) == '--') continue; // skip comments
                    $queries[] = $line; // all other lines should be valid queries
                }
                if (!$queries) return "No valid queries found to replay in '" . $log_file ."'";

                self::$dont_log_this_run = TRUE; // ensure that the replay of queries aren't logged as new queries

                $count = 0;
                foreach ($queries as $query){
                    // if a query fails, fail the whole lot...
                    if (FALSE === $db->query($query)) return "Error replaying queries. Recommend restore DB from backup."; 
                    $count++;
                }
                if ($count > 0) {
                    $now = date('Y-m-d-H-i-s');
                    rename($log_file, $log_file . '.replayed-' . $now); // archive the replayed queries
                    return ($count . " queries replayed from '" . $log_file . "'");
                }
                return "Error: This point should never be reached. File a issue on Github.";
            }
            return "No database logs needing to be replayed. Searched in '" . $log_file . "'";

        }

        private function backup() {
            $mysqldump_bin = Symphony::Configuration()->get('mysqldump_bin', 'db_sync');
            $gzip_bin = Symphony::Configuration()->get('gzip_bin', 'db_sync');
            $db_host = Symphony::Configuration()->get('host', 'database');
            $db_port = Symphony::Configuration()->get('port', 'database');
            $db_name = Symphony::Configuration()->get('db', 'database');
            $db_user = Symphony::Configuration()->get('user', 'database');
            $db_password = Symphony::Configuration()->get('password', 'database');

            $backup_dir = Symphony::Configuration()->get('log_dir', 'db_sync');
            $backup_filename = $db_name . "-" . date("Y-m-d-H-i-s") . ".sql.gz";
            $backup_file = DOCROOT . $backup_dir . $backup_filename;

            $return_value = NULL;
            $result = array();
            $command = "{$mysqldump_bin} --host={$db_host} --port={$db_port} --user={$db_user} --password={$db_password} {$db_name} | {$gzip_bin} -9 > {$backup_file}";
            exec($command, $result, $return_value);
            if ($return_value) {
                $message = "<strong style='color:red'>ERROR </strong> Unable to backup database: ";
            }
            else {
                $message = "<strong style='color:green'>SUCCESS </strong> Database was backed up to: ";
            }
            return $message . "<strong>" . $backup_file . "</strong><br/><br/>" . "Executed command was <em>" . $command . "</em>";
        }

        private function restore() {
            $mysql_bin = Symphony::Configuration()->get('mysql_bin', 'db_sync');
            $gunzip_bin = Symphony::Configuration()->get('gunzip_bin', 'db_sync');
            $db_host = Symphony::Configuration()->get('host', 'database');
            $db_port = Symphony::Configuration()->get('port', 'database');
            $db_name = Symphony::Configuration()->get('db', 'database');
            $db_user = Symphony::Configuration()->get('user', 'database');
            $db_password = Symphony::Configuration()->get('password', 'database');

            $backup_file = self::latestBackupFile();

            $return_value = NULL;
            $result = array();
            $command = "{$gunzip_bin} < ${backup_file} | {$mysql_bin} --host={$db_host} --port={$db_port} --user={$db_user} --password={$db_password} {$db_name}";
            exec($command, $result, $return_value);

            if ($return_value) {
                $message = "<strong style='color:red'>ERROR </strong> Unable to restore database from: ";
            }
            else {
                $message = "<strong style='color:green'>SUCCESS </strong> Database was restored from backup: ";
            }
            return $message . "<strong>" . $backup_file . "</strong><br/><br/>" . "Executed command was <em>" . $command . "</em>";
        }

        private static function latestBackupFile() {
            $db_name = Symphony::Configuration()->get('db', 'database');
            $backup_dir = Symphony::Configuration()->get('log_dir', 'db_sync');

            $backups = [];
            foreach (glob(DOCROOT . $backup_dir . $db_name . "-*") as $backup) {
                $backups[] = $backup;
            }

            return array_pop($backups); // already sorted alphabetically by glob()
        }

        private static function logFile() {
            $log_file = Symphony::Configuration()->get('log_file', 'db_sync');
            $log_dir = Symphony::Configuration()->get('log_dir', 'db_sync');
            return DOCROOT . $log_dir . $log_file;
        }
	}
