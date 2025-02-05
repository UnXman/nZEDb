<?php
/**
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program (see LICENSE.txt in the base directory.  If
 * not, see:
 *
 * @link      <http://www.gnu.org/licenses/>.
 * @author    niel
 * @copyright 2014 nZEDb
 */
namespace nzedb\db;

use app\models\Settings as SettingsTable;
use nzedb\ColorCLI;
use nzedb\Install;
use nzedb\db\DB;
use nzedb\utility\Git;
use nzedb\utility\Misc;
use nzedb\utility\Text;


class DbUpdate
{
	public $backedup;

	/**
	 * @var \nzedb\db\Settings    Instance variable for DB object.
	 */
	public $pdo;

	/**
	 * @var \nzedb\utility\Git instance
	 */
	public $git;

	/**
	 * @var object    Instance variable for logging object. Currently only ColorCLI supported,
	 * but expanding for full logging with agnostic API planned.
	 */
	public $log;

	/**
	 * @var object    Instance object for sites/settings class.
	 */
	public $settings;

	protected $_DbSystem;

	/**
	 * @var bool    Has the Db been backed up?
	 */
	private $backedUp = false;

	/**
	 * @var bool    Should we perform a backup?
	 */
	private $backup = false;

	public function __construct(array $options = [])
	{
		$options += [
			'backup' => true,
			'db'     => null,
			'git'    => new Git(),
			'logger' => new ColorCLI(),
		];

		$this->backup = $options['backup'];
		$this->git    = $options['git'];
		$this->log    = $options['logger'];
		// Must be DB not Settings because the Settings table may not exist yet.
		$this->pdo = (($options['db'] instanceof DB) ? $options['db'] : new DB());
		$this->_DbSystem = strtolower($this->pdo->dbSystem());
	}

	/**
	 * @param array $options
	 *
	 * @return void
	 */
	public function loadTables(array $options = []): void
	{
		$defaults = [
			'enclosedby'	=> null,
			'ext'			=> 'tsv',
			'files'			=> [],
			'path'			=> nZEDb_RES . 'db' . DS . 'schema' . DS . 'data',
			'regex'			=> '#^' . Misc::PATH_REGEX . '(?P<order>\d+)-(?P<table>\w+)\.tsv$#',
		];
		$options += $defaults;

		$show = (Misc::isCLI() || nZEDb_DEBUG);

		$files = empty($options['files']) ? Misc::getDirFiles($options) : $options['files'];
		natsort($files);
		$local = $this->pdo->isLocalDb() ? '' : 'LOCAL ';
		$enclosedby = empty($options['enclosedby']) ? '' : 'OPTIONALLY ENCLOSED BY "' .
			$options['enclosedby'] . '"';
		$sql = 'LOAD DATA ' . $local . 'INFILE "%s" IGNORE INTO TABLE `%s` FIELDS TERMINATED BY "\t" ' .
			$enclosedby . ' LINES TERMINATED BY "\r\n" IGNORE 1 LINES (%s)';
		foreach ($files as $file) {
			if ($show === true)
			{
				echo "File: $file\n";
			}

			$fileTarget = '/tmp/' . pathinfo($file, PATHINFO_BASENAME);
			if (\copy($file, $fileTarget)) // Copy to a directory accessible to all (for mysql user)
			{
				$file = $fileTarget;
				\chmod($file, 0775);
			} else {
				echo 'Failed to copy file: ' . $file . '</br>' . \PHP_EOL;
				//throw new \Exception('Copying file to /tmp failed!');
			}

			if (is_readable($file)) {
				if (preg_match($options['regex'], $file, $matches))
				{
					$table = $matches['table'];
					// Get the first line of the file which holds the columns used.
					$handle = @fopen($file, 'r');
					if (is_resource($handle))
					{
						$line = fgets($handle);
						fclose($handle);
						if ($line === false)
						{
							echo "FAILED reading first line of '$file'\n";
							continue;
						}
						$fields = trim($line);

						if ($show === true)
						{
							echo "Inserting data into table: '$table'\n";
						}
						if (Misc::isWin())
						{
							$file = str_replace("\\", '\/', $file);
						}
						$this->pdo->exec(sprintf($sql, $file, $table, $fields));
					} else {
						exit("Failed to open file: '$file'\n");
					}
				} else {
					echo "Incorrectly formatted filename '$file' (should match " .
						 str_replace('#', '', $options['regex']) . "\n";
				}
			} else {
				echo $this->log->error("  Unable to read file: '$file'");
			}
		}
	}

	/**
	 * Takes new files in the correct format from the patches directory and turns them into proper patches.
	 *
	 * The files should be name as '+x~<table>.sql' where x is a number starting at 1 for your first
	 * patch. <table> should be the name of the primary table affected. If you have to modify more
	 * than one table, consider splitting into multiple patches using different patch modifier
	 * numbers to order them. i.e. +1~settings.sql, +2~predb.sql, etc.
	 *
	 * @param array $options
	 */
	public function newPatches(array $options = []): void
	{
		$defaults = [
			'data'	=> nZEDb_RES . 'db' . DS . 'schema' . DS . 'data' . DS,
			'ext'	=> 'sql',
			'path'	=> nZEDb_RES . 'db' . DS . 'patches' . DS . $this->_DbSystem,
			'regex'	=> '#^' . Misc::PATH_REGEX . '\+(?P<order>\d+)~(?P<table>\w+)\.sql$#',
			'safe'	=> true,
		];
		$options += $defaults;

		$this->processPatches(['safe' => $options['safe']]); // Make sure we are completely up to date!

		echo $this->log->primaryOver('Looking for new patches...');
		$files = Misc::getDirFiles($options);

		$count = count($files);
		echo $this->log->header(" $count found");
		if ($count > 0) {
			echo $this->log->header('Processing...');
			natsort($files);
			$local = $this->pdo->isLocalDb() ? '' : 'LOCAL ';

			foreach ($files as $file) {
				if (!preg_match($options['regex'], $file, $matches)) {
					$this->log->error("$file does not match the pattern {$options['regex']}\nPlease fix this before continuing");
				} else {
					echo $this->log->header('Processing patch file: ' . $file);
					$this->splitSQL($file, ['local' => $local, 'data' => $options['data']]);
					$current = (integer)SettingsTable::value('..sqlpatch');
					$current++;
					$this->pdo->queryExec("UPDATE settings SET value = '$current' WHERE setting = 'sqlpatch';");
					$newName = $matches['drive'] . $matches['path'] .
							   str_pad($current, 4, '0', STR_PAD_LEFT) . '~' .
							   $matches['table'] . '.sql';
					rename($matches[0], $newName);
					$this->git->add($newName);
					if ($this->git->isCommited($this->git->getBranch() . ':' . str_replace(nZEDb_ROOT, '', $matches[0]))) {
						$this->git->add(" -u {$matches[0]}"); // remove old filename from the index.
					}
				}
			}
		}
	}

	/**
	 * @param array $options
	 *
	 * @return int
	 */
	public function processPatches(array $options = []): int
	{
		$patched = 0;
		$defaults = [
			'data'	=> nZEDb_RES . 'db' . DS . 'schema' . DS . 'data' . DS,
			'ext'	=> 'sql',
			'path'	=> nZEDb_RES . 'db' . DS . 'patches' . DS . $this->_DbSystem,
			'regex'	=> '#^' . Misc::PATH_REGEX . '(?P<patch>\d{4})~(?P<table>\w+)\.sql$#',
			'safe'	=> true,
		];
		$options += $defaults;

		$currentVersion = SettingsTable::value('..sqlpatch');
		if (!is_numeric($currentVersion)) {
			exit("Bad sqlpatch value: '$currentVersion'\n");
		}

		$files = empty($options['files']) ? Misc::getDirFiles($options) : $options['files'];

		if (count($files)) {
			natsort($files);
			$local = $this->pdo->isLocalDb() ? '' : 'LOCAL ';
			$data  = $options['data'];
			echo $this->log->primary('Looking for unprocessed patches...');
			foreach ($files as $file) {
				$setPatch = false;
				$fp = fopen($file, 'r');
				$patch = fread($fp, filesize($file));

				if (preg_match($options['regex'], str_replace('\\', '/', $file), $matches)) {
					$patch = (integer)$matches['patch'];
					$setPatch = true;
				} else if (preg_match(
					'/UPDATE `?site`? SET `?value`? = \'?(?P<patch>\d+)\'? WHERE `?setting`? = \'sqlpatch\'/i',
					$patch,
					$matches)
				) {
					$patch = (integer)$matches['patch'];
				} else {
					throw new \RuntimeException('No patch information available, stopping!!');
				}
				if ($patch > $currentVersion) {
					echo $this->log->header('Processing patch file: ' . $file);
					if (!$this->backedUp && $options['safe']) {
						$this->backupDb();
					}
					$this->splitSQL($file, ['local' => $local, 'data' => $data]);
					if ($setPatch) {
						$this->pdo->queryExec("UPDATE settings SET value = '$patch' WHERE setting = 'sqlpatch';");
					}
					$patched++;
				}
			}
		} else {
			exit($this->log->error("\nHave you changed the path to the patches folder, or do you have the right permissions?\n"));
		}

		if ($patched === 0) {
			echo $this->log->info("Nothing to patch, you are already on version $currentVersion");
		}
		return $patched;
	}

	/**
	 * @param array $options
	 *
	 * @return void
	 */
	public function processSQLFile(array $options = []): void
	{
		$defaults = [
			'filepath' => nZEDb_RES . 'db' . DS . 'schema' . DS . $this->_DbSystem . '-ddl.sql',
		];
		$options += $defaults;

		$sql = file_get_contents($options['filepath']);
		$sql = str_replace(['DELIMITER $$', 'DELIMITER ;', '$$'], '', $sql);
		$this->pdo->exec($sql);
	}

	/**
	 * Executes an SQL source file via the mysql client.
	 *
	 * @param array $options
	 *
	 * @return void
	 */
	public function sourceSQL(array $options): void
	{
		$default = [
			'file' => null,
			'name' => null,
			'pass' => null,
			'user' => null,
		];
		$options += $default;

		$cmd = "mysql -u {$options['user']} -p{$options['pass']} --default-character-set=utf8 {$options['name']} < {$options['file']}";
		\exec(
			$cmd,
			$response,
			$status
		);

		//if ($status !== 0) {
			trigger_error(
				"DbUpdate::sourceSQL() output.\nStatus: $status\n" .
				\implode("\n", $response),
				E_USER_NOTICE);
		//}
	}

	/**
	 * @param       $file
	 * @param array $options
	 *
	 * @return void
	 */
	public function splitSQL($file, array $options = []): void
	{
		$defaults = [
			'data'		=> null,
			'delimiter'	=> ';',
			'local'		=> null,
		];
		$options += $defaults;

		if (!empty($options['vars'])) {
			extract($options['vars'], \EXTR_OVERWRITE);
		}

		set_time_limit(0);

		if (is_file($file)) {
			$file = fopen($file, 'r');

			if (is_resource($file)) {
				$query = [];

				$delimiter = $options['delimiter'];
				while (!feof($file)) {
					$line = fgets($file);

					if ($line === false) {
						continue;
					}

					// Skip comments.
					if (preg_match('!^\s*(#|--|//)\s*(.+?)\s*$!', $line, $matches)) {
						echo $this->pdo->log->info("COMMENT: " . $matches[2]);
						continue;
					}

					// Check for non default delimiters ($$ for example).
					if (preg_match('#^\s*DELIMITER\s+(?P<delimiter>.+)\s*$#i', $line, $matches)) {
						$delimiter = $matches['delimiter'];
						if (nZEDb_DEBUG) {
							echo $this->pdo->log->debug("DEBUG: Delimiter switched to $delimiter");
						}
						if ($delimiter != $options['delimiter']) {
							continue;
						}
					}

					// Check if the line has delimiter that is non default ($$ for example).
					if ($delimiter != $options['delimiter'] && preg_match('#^(.+?)' . preg_quote($delimiter) . '\s*$#', $line, $matches)) {
						// Check if the line has also the default delimiter (;), remove it.
						if (preg_match('#^(.+?)' . preg_quote($options['delimiter']) . '\s*$#', $matches[1], $matches2)) {
							$matches[1] = $matches2[1];
						}
						// Change the non default delimiter ($$) to the default one(;).
						$line = $matches[1] . $options['delimiter'];
					}

					$query[] = $line;

					if (preg_match('~' . preg_quote($delimiter, '~') . '\s*$~iS', $line) == 1) {
						$query = trim(implode('', $query));
						if ($options['local'] !== null) {
							$query = str_replace('{:local:}', $options['local'], $query);
						}
						if (!empty($options['data'])) {
							$query = str_replace('{:data:}', $options['data'], $query);
						}

						try {
							$qry = $this->pdo->prepare($query);
							$qry->execute();
							echo $this->log->alternateOver('SUCCESS: ') . $this->log->primary($query);
						} catch (\PDOException $e) {
							// Log the problem and the query.
							file_put_contents(
								nZEDb_LOGS . 'patcherrors.log',
								'[' . date('r') . '] [ERROR] [' .
								trim(preg_replace('/\s+/', ' ', $e->getMessage())) . ']' . PHP_EOL .
								'[' . date('r') . '] [QUERY] [' .
								trim(preg_replace('/\s+/', ' ', $query)) . ']' . PHP_EOL,
								FILE_APPEND
							);

							if (
								in_array($e->errorInfo[1], [1091, 1060, 1061, 1071, 1146]) ||
								in_array($e->errorInfo[0], [23505, 42701, 42703, '42P07', '42P16'])
							) {
								if ($e->errorInfo[1] == 1060) {
									echo $this->log->warning(
										"$query The column already exists - No need to worry \{" .
										$e->errorInfo[1] . "}.\n"
									);
								} else {
									echo $this->log->warning(
										"$query Skipped - No need to worry \{" .
										$e->errorInfo[1] . "}.\n"
									);
								}
							} else {
								if (preg_match('/ALTER IGNORE/i', $query)) {
									$this->pdo->queryExec("SET SESSION old_alter_table = 1");
									try {
										$this->pdo->exec($query);
										echo $this->log->alternateOver('SUCCESS: ') . $this->log->primary($query);
									} catch (\PDOException $e) {
										exit($this->log->error("$query Failed \{" . $e->errorInfo[1] . "}\n\t" . $e->errorInfo[2]));
									}
								} else {
									exit($this->log->error("$query Failed \{" . $e->errorInfo[1] . "}\n\t" . $e->errorInfo[2]));
								}
							}
						}

						while (ob_get_level() > 0) {
							ob_end_flush();
						}
						flush();
					}

					if (is_string($query) === true) {
						$query = [];
					}
				}
			}
		}
	}

	/**
	 * @param array $options
	 *
	 * @return void
	 */
	public function updateSchemaData(array $options = []): void
	{
		$changed	= false;
		$default	= [
			'file'	=> '10-settings.tsv',
			'path'	=> 'resources' . DS . 'db' . DS . 'schema' . DS . 'data' . DS,
			'regex'	=> '#^(?P<section>.*)\t(?P<subsection>.*)\t(?P<name>.*)\t(?P<value>.*)\t(?P<hint>.*)\t(?P<setting>.*)$#',
			'value'	=> function(array $matches) {
					return "{$matches['section']}\t{$matches['subsection']}\t{$matches['name']}\t{$matches['value']}\t{$matches['hint']}\t{$matches['setting']}";
				} // WARNING: leaving this empty will blank not remove lines.
		];
		$options += $default;

		$file = [];
		$filespec = Text::trailingSlash($options['path']) . $options['path'];
		if (file_exists($filespec) && ($file = file($filespec, FILE_IGNORE_NEW_LINES))) {
			$count = count($file);
			$index = 0;
			while ($index < $count) {
				if (preg_match($options['regex'], $file[$index], $matches)) {
					if (VERBOSE) {
						echo $this->log->primary("Matched: " . $file[$index]);
					}
					$index++;

					if (is_callable($options['value'])) {
						$file[$index] = $options['value']($matches);
					} else {
						$file[$index] = $options['value'];
					}
					$changed = true;
				}
			}
		}

		if ($changed) {
			if (file_put_contents($filespec, implode("\n", $file)) === false) {
				echo $this->log->error("Error writing file to disc!!");
			}
		}
	}

	/**
	 * @return void
	 */
	protected function backupDb(): void
	{
		if (Misc::hasCommand('php5')) {
			$PHP = 'php5';
		} else {
			$PHP = 'php';
		}

		system("$PHP " . nZEDb_MISC . 'testing' . DS . 'DB' . DS . $this->_DbSystem .
			   'dump_tables.php db dump');
		$this->backedup = true;
	}
}

?>
