<?php
namespace ExternalModules;

// Uncomment this line to quickly disable all External Module hooks (for troubleshooting).
//define('EXTERNAL_MODULES_KILL_SWITCH', '');

if (!defined(__DIR__)){
	define(__DIR__, dirname(__FILE__));
}

require_once __DIR__ . "/AbstractExternalModule.php";

if(PHP_SAPI == 'cli'){
	// This is required for redcap when running on the command line (including unit testing).
	define('NOAUTH', true);
}

if(!defined('APP_PATH_WEBROOT')){
	// Only include redcap_connect.php if it hasn't been included at some point before.
	// Upgrades crash without this check.
	// Perhaps it has something to do with loading both the new and old version of redcap_connect.php......
	$connectPath = dirname(dirname(dirname(dirname(__DIR__)))) . DIRECTORY_SEPARATOR . "redcap_connect.php";
	if (file_exists($connectPath)) {
		require_once $connectPath;
	} else {
		$connectPath = dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . "redcap_connect.php";
		if (file_exists($connectPath)) {
			require_once $connectPath;
		}
	}
}

if (class_exists('ExternalModules\ExternalModules')) {
	return;
}

use \Exception;

class ExternalModules
{
	const SYSTEM_SETTING_PROJECT_ID = 'NULL';
	const KEY_VERSION = 'version';
	const KEY_ENABLED = 'enabled';

	const TEST_MODULE_PREFIX = 'UNIT-TESTING-PREFIX';

	const DISABLE_EXTERNAL_MODULE_HOOKS = 'disable-external-module-hooks';
	const RICH_TEXT_UPLOADED_FILE_LIST = 'rich-text-uploaded-file-list';

	const OVERRIDE_PERMISSION_LEVEL_SUFFIX = '_override-permission-level';
	const OVERRIDE_PERMISSION_LEVEL_DESIGN_USERS = 'design';

	// We can't write values larger than this to the database, or they will be truncated.
	const SETTING_SIZE_LIMIT = 65535;

	// The minimum required PHP version for External Modules to run
	const MIN_PHP_VERSION = '5.4.0';

	# base URL for external modules
	public static $BASE_URL;

	# URL for the modules directory
	public static $BASE_PATH;
	public static $MODULES_URL;

	# path for the modules directory
	public static $MODULES_BASE_PATH;
	public static $MODULES_PATH;

	# index is hook $name, then $prefix, then $version
	private static $delayed;
	private static $INCLUDED_RESOURCES;

	private static $hookBeingExecuted;
	private static $versionBeingExecuted;

	private static $initialized = false;
	private static $activeModulePrefix;
	private static $instanceCache = array();
	private static $idsByPrefix;

	private static $systemwideEnabledVersions;
	private static $projectEnabledDefaults;
	private static $projectEnabledOverrides;

	private static $configs = array();

	# two reserved settings that are there for each project
	# KEY_VERSION, if present, denotes that the project is enabled system-wide
	# KEY_ENABLED is present when enabled for each project
	# Modules can be enabled for all projects (system-wide) if KEY_ENABLED == 1 for system value
	private static $RESERVED_SETTINGS = array(
		array(
			'key' => self::KEY_VERSION,
			'hidden' => true,
		),
		array(
			'key' => self::KEY_ENABLED,
			'name' => 'Enable on all projects by default',
			'project-name' => 'Enable on this project',
			'type' => 'checkbox',
			'allow-project-overrides' => true,
		)
	);

	# defines criteria to judge someone is on a development box or not
	private static function isLocalhost()
	{
		$host = @$_SERVER['HTTP_HOST'];

		// If the hostname is an IP address, assume we're accessing a developer's PC and return true.
		$isIpAddress = ip2long($host);

		return $host == 'localhost' || $isIpAddress;
	}

	static function saveSettingsFromPost($moduleDirectoryPrefix, $pid)
	{
		# for screening out files below
		$config = self::getConfig($moduleDirectoryPrefix, null, $pid);
		$files = array();
		foreach(['system-settings', 'project-settings'] as $settingsKey){
			foreach($config[$settingsKey] as $row) {
				if ($row['type'] && ($row['type'] == "file")) {
					$files[] = $row['key'];
				}
			}
		}

		$instances = array();   # for repeatable elements, you must save them after the original is saved
		# if not, the value is overwritten by a string/int/etc. - not a JSON

		# returns boolean
		function isExternalModuleFile($key, $fileKeys) {
			if (in_array($key, $fileKeys)) {
				return true;
			}
			foreach ($fileKeys as $fileKey) {
				if (preg_match('/^'.$fileKey.'____\d+$/', $key)) {
					return true;
				}
			}
			return false;
		}

		# store everything BUT files and multiple instances (after the first one)
		foreach($_POST as $key=>$value){
			# files are stored in a separate $.ajax call
			# numeric value signifies a file present
			# empty strings signify non-existent files (systemValues or empty)
			if (!isExternalModuleFile($key, $files) || !is_numeric($value)) {
				if($value == '') {
					$value = null;
				}

				if (preg_match("/____/", $key)) {
					$parts = preg_split("/____/", $key);
					$shortKey = array_shift($parts);

					if(!isset($instances[$shortKey])){
						$instances[$shortKey] = [];
					}

					if(!$value){
						$value = '';
					}

					$thisInstance = &$instances[$shortKey];
					foreach($parts as $thisIndex) {
						if(!isset($thisInstance[$thisIndex])) {
							$thisInstance[$thisIndex] = [];
						}
						$thisInstance = &$thisInstance[$thisIndex];
					}

					$thisInstance = $value;
				} else if (empty($pid)) {
					$saved[$key] = $value;
					self::setSystemSetting($moduleDirectoryPrefix, $key, $value);
				} else {
					$saved[$key] = $value;
					self::setProjectSetting($moduleDirectoryPrefix, $pid, $key, $value);
				}
			}
		}

		foreach($instances as $key => $values) {
			self::setSetting($moduleDirectoryPrefix, $pid, $key, json_encode($values),"json");
		}
	}

	# initializes the External Module aparatus
	static function initialize()
	{
		if(self::isLocalhost()){
			// Assume this is a developer's machine and enable errors.
			ini_set('display_errors', 1);
			ini_set('display_startup_errors', 1);
			error_reporting(E_ALL);
		}
		
		if (defined("APP_PATH_EXTMOD")) {
			$modulesDirectories = [dirname(APP_PATH_DOCROOT).DS.'modules'.DS, APP_PATH_EXTMOD.'example_modules'.DS];
		} else {
			$modulesDirectories = [dirname(APP_PATH_DOCROOT).DS.'modules'.DS, dirname(APP_PATH_DOCROOT).DS.'external_modules'.DS.'example_modules'.DS];
		}
		$modulesDirectoryName = '/modules/';

		if(strpos($_SERVER['REQUEST_URI'], $modulesDirectoryName) === 0){
			throw new Exception('Requests directly to module version directories are disallowed.  Please use the getUrl() method to build urls to your module pages instead.');
		}

		// We must use APP_PATH_WEBROOT_FULL here because some REDCap installations are hosted under subdirectories.
		self::$BASE_URL = defined("APP_URL_EXTMOD") ? APP_URL_EXTMOD : APP_PATH_WEBROOT_FULL.'external_modules/';
		self::$MODULES_URL = APP_PATH_WEBROOT_FULL.'modules/';
		self::$BASE_PATH = defined("APP_PATH_EXTMOD") ? APP_PATH_EXTMOD : APP_PATH_DOCROOT . '../external_modules/';
		self::$MODULES_BASE_PATH = dirname(APP_PATH_DOCROOT) . DS;
		self::$MODULES_PATH = $modulesDirectories;
		self::$INCLUDED_RESOURCES = [];

		if(!self::isLocalhost()){
			register_shutdown_function(function(){
				$activeModulePrefix = self::getActiveModulePrefix();

				if ($activeModulePrefix == null){
					// A fatal error did not occur in the middle of a module operation.
					return;
				}

				$error = error_get_last();
				$message = "The '$activeModulePrefix' module was automatically disabled because of the following error:\n\n";
				$message .= 'Error Message: ' . $error['message'] . "\n";
				$message .= 'File: ' . $error['file'] . "\n";
				$message .= 'Line: ' . $error['line'] . "\n";

				if (basename($_SERVER['REQUEST_URI']) == 'enable-module.php') {
					// An admin was attempting to enable a module.
					// Simply display the error to the current user, instead of sending an email to all admins about it.
					echo $message;
					return;
				}

				error_log($message);
				ExternalModules::sendAdminEmail("REDCap External Module Automatically Disabled - $activeModulePrefix", $message, $activeModulePrefix);

				// We can't just call disable() from here because the database connection has been destroyed.
				// Disable this module via AJAX instead.
				?>
				<br>
				<h4 id="external-modules-message">
					A fatal error occurred while loading the "<?=$activeModulePrefix?>" external module.<br>
					Disabling that module...
				</h4>
				<script>
					var request = new XMLHttpRequest();
					request.onreadystatechange = function() {
						if (request.readyState == XMLHttpRequest.DONE ) {
							var messageElement = document.getElementById('external-modules-message')
							if(request.responseText == 'success'){
								messageElement.innerHTML = 'The "<?=$activeModulePrefix?>" external module was automatically disabled in order to allow REDCap to function properly.  The REDCap administrator has been notified.  Please save a copy of the above error and fix it before re-enabling the module.';
							}
							else{
								messageElement.innerHTML += '<br>An error occurred while disabling the "<?=$activeModulePrefix?>" module: ' + request.responseText;
							}
						}
					};

					request.open("POST", "<?=self::$BASE_URL?>manager/ajax/disable-module.php?<?=self::DISABLE_EXTERNAL_MODULE_HOOKS?>");
					request.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
					request.send("module=<?=$activeModulePrefix?>");
				</script>
				<?php
			});
		}
	}

	# controls which module is currently being manipulated
	private static function setActiveModulePrefix($prefix)
	{
		self::$activeModulePrefix = $prefix;
	}

	# returns which module is currently being manipulated
	private static function getActiveModulePrefix()
	{
		return self::$activeModulePrefix;
	}

	private static function lastTwoNodes($hostname) {
		$nodes = preg_split("/\./", $hostname);
		$count = count($nodes);
		return $nodes[$count - 2].".".$nodes[$count - 1];
	}

	public static function sendAdminEmail($subject, $message, $prefix = null)
	{
		global $project_contact_email;

		$additionalToAddresses = array();
		if ($prefix) {
            try {
			    $config = self::getConfig($prefix);
			    foreach ($config['authors'] as $author) {
				    if (isset($author['email']) && preg_match("/@/", $author['email'])) {
					    $parts = preg_split("/@/", $author['email']);
					    if (count($parts) >= 2) {
						    $domain = $parts[1];
						    if (self::lastTwoNodes($_SERVER['HTTP_HOST']) == $domain) {
							    $additionalToAddresses[] = $author['email'];
						    }
					    }
				    }
			    }
            } catch(Exception $e) {
            }
		}
		$additionalTo = "";
		if (count($additionalToAddresses) > 0) {
			$additionalTo = ",".implode(",", $additionalToAddresses);
		}

		if(substr(gethostname(), -1) != 't'){
			// We're not on the test server, so append additional addresses.
			$additionalTo .= ',datacore@vanderbilt.edu,redcap@vanderbilt.edu';
		}

		$message .= "<br><br>Server: " . gethostname() . "<br>";

		$email = new \Message();
		$email->setFrom($project_contact_email);
		$email->setTo('mark.mcever@vanderbilt.edu,kyle.mcguffin@vanderbilt.edu'.$additionalTo);
		$email->setSubject($subject);
		$email->setBody($message, true);
		$email->send();
	}

	# there are two situations which external modules are displayed
	# under a project or under the control center

	# this gets the project header
	static function getProjectHeaderPath()
	{
		return APP_PATH_DOCROOT . 'ProjectGeneral/header.php';
	}

	static function getProjectFooterPath()
	{
		return APP_PATH_DOCROOT . 'ProjectGeneral/footer.php';
	}

	# disables a module system-wide
	static function disable($moduleDirectoryPrefix)
	{
		self::removeSystemSetting($moduleDirectoryPrefix, self::KEY_VERSION);
	}

	# enables a module system-wide
//	static function enable($moduleDirectoryPrefix, $version)
	static function enableForProject($moduleDirectoryPrefix, $version, $project_id)
	{
		# Attempt to create an instance of the module before enabling it system wide.
		# This should catch problems like syntax errors in module code.
		$instance = self::getModuleInstance($moduleDirectoryPrefix, $version);
		
		// Ensure compatibility with PHP version and REDCap version before instantiating the module class
		self::isCompatibleWithREDCapPHP($moduleDirectoryPrefix, $version);

		if (!isset($project_id)) {
			self::initializeSettingDefaults($instance);
			self::setSystemSetting($moduleDirectoryPrefix, self::KEY_VERSION, $version);
		} else {
			self::setProjectSetting($moduleDirectoryPrefix, $project_id, self::KEY_ENABLED, true);
		}
	}

	static function enable($moduleDirectoryPrefix, $version)
	{
		self::enableForProject($moduleDirectoryPrefix, $version, null);
	}

	static function enableAndCatchExceptions($moduleDirectoryPrefix, $version)
	{
		try {
			self::enable($moduleDirectoryPrefix, $version);
		} catch (\Exception $e) {
			self::setActiveModulePrefix(null); // Unset the active module prefix, so an error email is not sent out.
			return $e;
		}

		return null;
	}

	# initializes the system settings
	static function initializeSettingDefaults($moduleInstance)
	{
		$config = $moduleInstance->getConfig();
		foreach($config['system-settings'] as $details){
			$key = $details['key'];
			$default = @$details['default'];
			$existingValue = $moduleInstance->getSystemSetting($key);
			if(isset($default) && $existingValue == null){
				$moduleInstance->setSystemSetting($key, $default);
			}
		}
	}

	static function getSystemSetting($moduleDirectoryPrefix, $key)
	{
		return self::getSetting($moduleDirectoryPrefix, self::SYSTEM_SETTING_PROJECT_ID, $key);
	}

	static function getSystemSettings($moduleDirectoryPrefixes, $keys = null)
	{
		return self::getSettings($moduleDirectoryPrefixes, self::SYSTEM_SETTING_PROJECT_ID, $keys);
	}

	static function setSystemSetting($moduleDirectoryPrefix, $key, $value)
	{
		self::setProjectSetting($moduleDirectoryPrefix, self::SYSTEM_SETTING_PROJECT_ID, $key, $value);
	}

	static function removeSystemSetting($moduleDirectoryPrefix, $key)
	{
		self::removeProjectSetting($moduleDirectoryPrefix, self::SYSTEM_SETTING_PROJECT_ID, $key);
	}

	static function setProjectSetting($moduleDirectoryPrefix, $projectId, $key, $value)
	{
		self::setSetting($moduleDirectoryPrefix, $projectId, $key, $value);
	}

	# value is edoc ID
	static function setSystemFileSetting($moduleDirectoryPrefix, $key, $value)
	{
		self::setFileSetting($moduleDirectoryPrefix, self::SYSTEM_SETTING_PROJECT_ID, $key, $value);
	}

	# value is edoc ID
	static function setFileSetting($moduleDirectoryPrefix, $projectId, $key, $value)
	{
		self::setSetting($moduleDirectoryPrefix, $projectId, $key, $value, "file");
	}

	static function removeSystemFileSetting($moduleDirectoryPrefix, $key)
	{
		self::removeFileSetting($moduleDirectoryPrefix, self::SYSTEM_SETTING_PROJECT_ID, $key);
	}

	static function removeFileSetting($moduleDirectoryPrefix, $projectId, $key)
	{
		self::setSetting($moduleDirectoryPrefix, $projectId, $key, null, "file");
	}

	# returns boolean
	public static function isProjectSettingDefined($prefix, $key)
	{
		$config = self::getConfig($prefix);
		foreach($config['project-settings'] as $setting){
			if($setting['key'] == $key){
				return true;
			}
		}

		return false;
	}

	# this is a helper method
	# call set [System,Project] Setting instead of calling this method
	private static function setSetting($moduleDirectoryPrefix, $projectId, $key, $value, $type = "")
	{
		if($projectId == self::SYSTEM_SETTING_PROJECT_ID){
			if(!self::hasSystemSettingsSavePermission($moduleDirectoryPrefix)){
				throw new Exception("You don't have permission to save system settings!");
			}
		}
		else if(!self::hasProjectSettingSavePermission($moduleDirectoryPrefix, $key)) {
			if(self::isProjectSettingDefined($moduleDirectoryPrefix, $key)){
				throw new Exception("You don't have permission to save the following project setting: $key");
			}
			else{
				// The setting is not defined in the config.  Allow any user to save it
				// (effectively leaving permissions up to the module creator).
				// This is required for user based configuration (like reporting for ED Data).
			}
		}

		$projectId = db_real_escape_string($projectId);
		$key = db_real_escape_string($key);

		$oldValue = self::getSetting($moduleDirectoryPrefix, $projectId, $key);

		if(is_array($oldValue)) {
			$oldValue = json_encode($oldValue);
		}

		# if $value is an array, then encode as JSON
		# else store $value as type specified in gettype(...)
		if ($type === "") {
			$type = gettype($value);
		}
		if ($type == "array") {
			$type = "json";
			$newValue = array();
			foreach ($value as $v) {
				# cannot store null values; store as blank strings instead
				if ($v === null) {
					$v = "";
				}
				$newValue[] = $v;
			}
			$value = json_encode($newValue);
		}

		// Triple equals includes type checking, and even order checking for complex nested arrays!
		if($value === $oldValue){
			// Nothing changed, so we don't need to do anything.
			return;
		}

		$externalModuleId = self::getIdForPrefix($moduleDirectoryPrefix);

		$pidString = $projectId;
		if (!$projectId || $projectId == "") {
			$pidString = "NULL";
		}

		if ($type == "boolean") {
			$value = ($value) ? 'true' : 'false';
		}

		if($value === null){
			$event = "DELETE";
			$sql = "DELETE FROM redcap_external_module_settings
					WHERE
						external_module_id = $externalModuleId
						AND " . self::getSqlEqualClause('project_id', $pidString) . "
						AND `key` = '$key'";
		} else {
			$value = db_real_escape_string($value);

			if(strlen($value) > self::SETTING_SIZE_LIMIT){
				throw new Exception("Cannot save the setting for prefix '$moduleDirectoryPrefix' and key '$key' because the value is larger than the " . self::SETTING_SIZE_LIMIT . " character limit.");
			}

			if($oldValue === null) {
				$event = "INSERT";
				$sql = "INSERT INTO redcap_external_module_settings
							(
								`external_module_id`,
								`project_id`,
								`key`,
								`type`,
								`value`
							)
						VALUES
						(
							$externalModuleId,
							$pidString,
							'$key',
							'$type',
							'$value'
						)";
			} else {
				$event = "UPDATE";
				$sql = "UPDATE redcap_external_module_settings
						SET value = '$value',
							type = '$type'
						WHERE
							external_module_id = $externalModuleId
							AND " . self::getSqlEqualClause('project_id', $pidString) . "
							AND `key` = '$key'";
			}
		}

		self::query($sql);

		$affectedRows = db_affected_rows();

		if($affectedRows != 1){
			throw new Exception("Unexpected number of affected rows ($affectedRows) on External Module setting query: $sql");
		}
	}

	# getSystemSettingsAsArray and getProjectSettingsAsArray

	# get all the settings as an array instead of one by one
	# returns an associative array with index of key and value of value
	# arrays of values (e.g., repeatble) will be returned as arrays
	# As in,
	# 	$ary['key'] = 'string';
	#	$ary['key2'] = 123;
	#	$ary['key3'] = [ 1, 'abc', 3 ];
	#	$ary['key3'][0] = 1;
	#	$ary['key3'][1] = 'abc';
	#	$ary['key3'][2] = 3;

	static function getSystemSettingsAsArray($moduleDirectoryPrefixes)
	{
		return self::getSettingsAsArray($moduleDirectoryPrefixes);
	}

	static function getProjectSettingsAsArray($moduleDirectoryPrefixes, $projectId)
	{
		if (!$projectId) {
			throw new Exception("The Project Id cannot be null!");
		}
		return self::getSettingsAsArray($moduleDirectoryPrefixes, $projectId);
	}

	private static function getSettingsAsArray($moduleDirectoryPrefixes, $projectId = NULL)
	{
		if ($projectId === NULL) {
			$result = self::getSettings($moduleDirectoryPrefixes, self::SYSTEM_SETTING_PROJECT_ID);
		} else {
			$result = self::getSettings($moduleDirectoryPrefixes, array(self::SYSTEM_SETTING_PROJECT_ID, $projectId));
		}

		$settings = array();
		while($row = self::validateSettingsRow(db_fetch_assoc($result))){
			$key = $row['key'];
			$value = $row['value'];

			$setting =& $settings[$key];
			if(!isset($setting)){
				$setting = array();
				$settings[$key] =& $setting;
			}

			if($row['project_id'] === null){
				$setting['system_value'] = $value;

				if(!isset($setting['value'])){
					$setting['value'] = $value;
				}
			}
			else{
				$setting['value'] = $value;
			}
		}

		return $settings;
	}

	static function getSettings($moduleDirectoryPrefixes, $projectIds, $keys = array())
	{
		$whereClauses = array();

		if (!empty($moduleDirectoryPrefixes)) {
			$whereClauses[] = self::getSQLInClause('m.directory_prefix', $moduleDirectoryPrefixes);
		}

		if (!empty($projectIds)) {
			$whereClauses[] = self::getSQLInClause('s.project_id', $projectIds);
		}
		else if($projectIds !== null) {
			$whereClauses[] = self::getSQLInClause('s.project_id', ["NULL"]);
		}

		if (!empty($keys)) {
			$whereClauses[] = self::getSQLInClause('s.key', $keys);
		}

		return self::query("SELECT directory_prefix, s.project_id, s.project_id, s.key, s.value, s.type
							FROM redcap_external_modules m
							JOIN redcap_external_module_settings s
								ON m.external_module_id = s.external_module_id
							WHERE " . implode(' AND ', $whereClauses));
	}

	static function getEnabledProjects($prefix)
	{
		$prefix = db_real_escape_string($prefix);

		return self::query("SELECT s.project_id, p.app_title as name
							FROM redcap_external_modules m
							JOIN redcap_external_module_settings s
								ON m.external_module_id = s.external_module_id
							JOIN redcap_projects p
								ON s.project_id = p.project_id
							WHERE m.directory_prefix = '$prefix'
								and `key` = '" . self::KEY_ENABLED . "'
								and value = 'true'");
	}

	# row contains the data type in field 'type' and the value in field 'value'
	# this makes sure that the data returned in 'value' is of that correct type
	static function validateSettingsRow($row)
	{
		if ($row == null) {
			return null;
		}

		$type = $row['type'];
		$value = $row['value'];

		if ($type == "json") {
			$json = json_decode($value,true);
			if ($json !== false) {
				$value = $json;
			}
		}
		else if ($type == 'file') {
			// do nothing
		}
		else if ($type == "boolean") {
			if ($value === "true") {
				$value = true;
			} else if ($value === "false") {
				$value = false;
			}
		}
		else {
			if (!settype($value, $type)) {
				throw new Exception('Unable to set the type of "' . $value . '" to "' . $type . '"!  This should never happen, as it means unexpected/inconsistent values exist in the database.');
			}
		}

		$row['value'] = $value;

		return $row;
	}

	private static function getSetting($moduleDirectoryPrefix, $projectId, $key)
	{
		if(empty($key)){
			throw new Exception('The setting key cannot be empty!');
		}

		$result = self::getSettings($moduleDirectoryPrefix, $projectId, $key);

		$numRows = db_num_rows($result);
		if($numRows == 1) {
			$row = self::validateSettingsRow(db_fetch_assoc($result));

			return $row['value'];
		}
		else if($numRows == 0){
			return null;
		}
		else{
			throw new Exception("More than one ($numRows) External Module setting exists for prefix '$moduleDirectoryPrefix', project ID '$projectId', and key '$key'!  This should never happen!");
		}
	}

	static function getProjectSetting($moduleDirectoryPrefix, $projectId, $key)
	{
		if (!$projectId) {
			throw new Exception("The Project Id cannot be null!");
		}

		$value = self::getSetting($moduleDirectoryPrefix, $projectId, $key);

		if($value === null){
			$value =  self::getSystemSetting($moduleDirectoryPrefix, $key);
		}

		return $value;
	}

	static function removeProjectSetting($moduleDirectoryPrefix, $projectId, $key){
		self::setProjectSetting($moduleDirectoryPrefix, $projectId, $key, null);
	}

	# directory name is [institution]_[module]_v[X].[Y]
	# prefix is [institution]_[module]
	# gets stored in database as module_id number
	# translates prefix string into a module_id number
	private static function getIdForPrefix($prefix)
	{
		if(!isset(self::$idsByPrefix)){
			$result = self::query("SELECT external_module_id, directory_prefix FROM redcap_external_modules");

			$idsByPrefix = array();
			while($row = db_fetch_assoc($result)){
				$idsByPrefix[$row['directory_prefix']] = $row['external_module_id'];
			}

			self::$idsByPrefix = $idsByPrefix;
		}

		$id = @self::$idsByPrefix[$prefix];
		if($id == null){
			self::query("INSERT INTO redcap_external_modules (directory_prefix) VALUES ('$prefix')");
			$id = db_insert_id();
			self::$idsByPrefix[$prefix] = $id;
		}

		return $id;
	}

	# translates a module_id number into a prefix string
	public static function getPrefixForID($id){
		$id = db_real_escape_string($id);

		$result = self::query("SELECT directory_prefix FROM redcap_external_modules WHERE external_module_id = '$id'");

		$row = db_fetch_assoc($result);
		if($row){
			return $row['directory_prefix'];
		}

		return null;
	}

	# gets the currently installed module's version based on the module prefix string
	public static function getModuleVersionByPrefix($prefix){
		$prefix = db_real_escape_string($prefix);
		
		$sql = "SELECT s.value FROM redcap_external_modules m, redcap_external_module_settings s 
				WHERE m.external_module_id = s.external_module_id AND m.directory_prefix = '$prefix'
				AND s.project_id IS NULL AND s.`key` = '" . self::KEY_VERSION . "' LIMIT 1";
		
		$result = self::query($sql);

		return db_result($result, 0);
	}

	# executes a database query and returns the result
	private static function query($sql)
	{
		$result = db_query($sql);

		if($result == FALSE){
			throw new Exception("Error running External Module query: \nDB Error: " . db_error() . "\nSQL: $sql");
		}

		return $result;
	}

	# converts an equals clause into SQL
	private static function getSQLEqualClause($columnName, $value)
	{
		$columnName = db_real_escape_string($columnName);
		$value = db_real_escape_string($value);

		if($value == 'NULL'){
			return "$columnName IS NULL";
		}
		else{
			return "$columnName = '$value'";
		}
	}

	# converts an IN array clause into SQL
	private static function getSQLInClause($columnName, $array)
	{
		if(!is_array($array)){
			$array = array($array);
		}

		$columnName = db_real_escape_string($columnName);

		$valueListSql = "";
		$nullSql = "";

		foreach($array as $item){
			if(!empty($valueListSql)){
				$valueListSql .= ', ';
			}

			$item = db_real_escape_string($item);

			if($item == 'NULL'){
				$nullSql = "$columnName IS NULL";
			}
			else{
				$valueListSql .= "'$item'";
			}
		}

		$parts = array();

		if(!empty($valueListSql)){
			$parts[] = "$columnName IN ($valueListSql)";
		}

		if(!empty($nullSql)){
			$parts[] = $nullSql;
		}

		return "(" . implode(" OR ", $parts) . ")";
	}

	# begins the execution of a hook
	# helper method
	# should call callHook
	private static function startHook($prefix, $version, $arguments) {
		if(!self::hasPermission($prefix, $version, self::$hookBeingExecuted)){
			// To prevent unnecessary class conflicts (especially with old plugins), we should avoid loading any module classes that don't actually use this hook.
			return;
		}

		$instance = self::getModuleInstance($prefix, $version);
		if(method_exists($instance, self::$hookBeingExecuted)){
			self::setActiveModulePrefix($prefix);
			try{
				call_user_func_array(array($instance,self::$hookBeingExecuted), $arguments);
			}
			catch(Exception $e){
				$message = "The '" . $prefix . "' module threw the following exception when calling the hook method '".self::$hookBeingExecuted."':\n\n" . $e;
				error_log($message);
				ExternalModules::sendAdminEmail("REDCap External Module Hook Exception - $prefix", $message, $prefix);
			}
			self::setActiveModulePrefix(null);
		}
	}

	# calls a hooke via startHook
	static function callHook($name, $arguments)
	{
		try {
			if(isset($_GET[self::DISABLE_EXTERNAL_MODULE_HOOKS]) || defined('EXTERNAL_MODULES_KILL_SWITCH')){
				return;
			}

			if(!defined('PAGE')){
				$page = ltrim($_SERVER['REQUEST_URI'], '/');
				define('PAGE', $page);
			}

			# We must initialize this static class here, since this method actually gets called before anything else.
			# We can't initialize sooner than this because we have to wait for REDCap to initialize it's functions and variables we depend on.
			# This method is actually called many times (once per hook), so we should only initialize once.
			if(!self::$initialized){
				self::initialize();
				self::$initialized = true;
			}
	
			$name = str_replace('redcap_', '', $name);
	
			$templatePath = __DIR__ . "/../manager/templates/hooks/$name.php";
			if(file_exists($templatePath)){
				self::safeRequire($templatePath, $arguments);
			}
	
			$pid = null;
			if(!empty($arguments)){
				$firstArg = $arguments[0];
				if((int)$firstArg == $firstArg){
					// As of REDCap 6.16.8, the above checks allow us to safely assume the first arg is the pid for all hooks.
					$pid = $arguments[0];
				}
			}

			self::$hookBeingExecuted = "hook_$name";
	
			if (!self::$delayed) {
				self::$delayed = array();
			}
			self::$delayed[self::$hookBeingExecuted] = array();
	
			$versionsByPrefix = self::getEnabledModules($pid);
			foreach($versionsByPrefix as $prefix=>$version){
				self::$versionBeingExecuted = $version;
	
				self::startHook($prefix, $version, $arguments);
			}
	
			# runs delayed modules
			# terminates if queue is 0 or if it is the same as in the previous iteration
			# (i.e., no modules completing)
			$prevNumDelayed = count($versionsByPrefix) + 1;
			while (($prevNumDelayed > count(self::$delayed[self::$hookBeingExecuted])) && (count(self::$delayed[self::$hookBeingExecuted]) > 0)) {
				$prevDelayed = self::$delayed[self::$hookBeingExecuted];
			 	$prevNumDelayed = count($prevDelayed);
				self::$delayed[self::$hookBeingExecuted] = array();
				foreach ($prevDelayed as $prefix=>$version) {
					self::$versionBeingExecuted = $version;
	
					if(!self::hasPermission($prefix, $version, self::$hookBeingExecuted)){
						// To prevent unnecessary class conflicts (especially with old plugins), we should avoid loading any module classes that don't actually use this hook.
						continue;
					}
	
					self::startHook($prefix, $version, $arguments);
				}
			}
			self::$hookBeingExecuted = "";
			self::$versionBeingExecuted = "";
		} catch(Exception $e) {
			$message = "REDCap External Modules threw the following exception:\n\n" . $e;
			error_log($message);
			ExternalModules::sendAdminEmail("REDCap External Module Exception", $message, $prefix);
		}
	}

	# places module in delaying queue to be executed after all others are executed
	public static function delayModuleExecution() {
		self::$delayed[self::$hookBeingExecuted][self::$activeModulePrefix] = self::$versionBeingExecuted;
	}

	# This function exists solely to provide a scope where we don't care if local variables get overwritten by code in the required file.
	# Use the $arguments variable to pass data to the required file.
	static function safeRequire($path, $arguments = array()){
		require $path;
	}

	# This function exists solely to provide a scope where we don't care if local variables get overwritten by code in the required file.
	# Use the $arguments variable to pass data to the required file.
	static function safeRequireOnce($path, $arguments = array()){
		require_once $path;
	}

	# Ensure compatibility with PHP version and REDCap version during module installation using config values
	private static function isCompatibleWithREDCapPHP($moduleDirectoryPrefix, $version)
	{
		$config = self::getConfig($moduleDirectoryPrefix, $version);
		if (!isset($config['compatibility'])) return;
		$Exceptions = array();
		$compat = $config['compatibility'];
		if (isset($compat['php-version-max']) && !empty($compat['php-version-max']) && !version_compare(PHP_VERSION, $compat['php-version-max'], '<=')) {
			$Exceptions[] = "This module's maximum compatible PHP version is {$compat['php-version-max']}, but you are currently running PHP " . PHP_VERSION . ".";
		}
		elseif (isset($compat['php-version-min']) && !empty($compat['php-version-min']) && !version_compare(PHP_VERSION, $compat['php-version-min'], '>=')) {
			$Exceptions[] = "This module's minimum required PHP version is {$compat['php-version-min']}, but you are currently running PHP " . PHP_VERSION . ".";
		}
		if (isset($compat['redcap-version-max']) && !empty($compat['redcap-version-max']) && !version_compare(REDCAP_VERSION, $compat['redcap-version-max'], '<=')) {
			$Exceptions[] = "This module's maximum compatible REDCap version is {$compat['redcap-version-max']}, but you are currently running REDCap " . REDCAP_VERSION . ".";
		}
		elseif (isset($compat['redcap-version-min']) && !empty($compat['redcap-version-min']) && !version_compare(REDCAP_VERSION, $compat['redcap-version-min'], '>=')) {
			$Exceptions[] = "This module's minimum required REDCap version is {$compat['redcap-version-min']}, but you are currently running REDCap " . REDCAP_VERSION . ".";
		}
		if (!empty($Exceptions)) {
			throw new Exception("COMPATIBILITY ERROR: This version of the module \"".$config['name']."\"
								is not compatible with your current version of PHP and/or REDCap, so cannot be installed on your 
								REDCap server at this time. Details:<ul><li>" . implode("</li><li>", $Exceptions) . "</li></ul>");
		}
	}

	# this is where a module has its code loaded
	public static function getModuleInstance($prefix, $version)
	{
		self::setActiveModulePrefix($prefix);

		$modulePath = self::getModuleDirectoryPath($prefix, $version);
		$instance = @self::$instanceCache[$prefix][$version];
		if(!isset($instance)){
			$config = self::getConfig($prefix, $version);

			$namespace = @$config['namespace'];
			if($namespace) {
				$parts = explode('\\', $namespace);
				$className = end($parts);
			}
			else{
				$namespace = __NAMESPACE__;
				$className = self::getMainClassName($prefix, $version);

				// TODO - Once all modules have been given a namespace, require a namespace by throwing an exception here.
				//throw new Exception("The '$prefix' module MUST specify a 'namespace' in it's config.json file.");
			}

			$classNameWithNamespace = "\\$namespace\\$className";

			if(!class_exists($classNameWithNamespace)){
				$classFilePath = "$modulePath/$className.php";

				if(!file_exists($classFilePath)){
					throw new Exception("Could not find the module class file '$className.php' for the module with prefix '$prefix'.");
				}

				self::safeRequireOnce($classFilePath);

				if (!class_exists($classNameWithNamespace)) {
					throw new Exception("The file '$className.php' file must define the '$classNameWithNamespace' class for the '$prefix' module.");
				}
			}

			$instance = new $classNameWithNamespace();
			self::$instanceCache[$prefix][$version] = $instance;
		}

		self::setActiveModulePrefix(null);

		return $instance;
	}

	# parses the prefix and turns it into a class name
	# convention is [institution]_[module]_v[X].[Y]
	# module is converted into camelCase, has its first letter capitalized, and is appended with "ExternalModule"
	# note well that if [module] contains an underscore (_), only the first chain link will be dealt with
	# E.g., vanderbilt_example_v1.0 yields a class name of "ExampleExternalModule"
	# vanderbilt_pdf_modify_v1.2 yields a class name of "PdfExternalModule"
	private static function getMainClassName($prefix)
	{
		$parts = explode('_', $prefix);
		$parts = explode('-', $parts[1]);

		$className = '';
		foreach($parts as $part){
			$className .= ucfirst($part);
		}

		$className .= 'ExternalModule';

		return $className;
	}

	# Accepts a project id as the first parameter.
	# If the project id is null, all system-wide enabled module instances are returned.
	# Otherwise, only instances enabled for the current project id are returned.
	static function getEnabledModules($pid = null)
	{
		if($pid == null){
			return self::getSystemwideEnabledVersions();
		}
		else{
			return self::getEnabledModuleVersionsForProject($pid);
		}
	}

	private static function getSystemwideEnabledVersions()
	{
		if(!isset(self::$systemwideEnabledVersions)){
			self::cacheAllEnableData();
		}

		return self::$systemwideEnabledVersions;
	}

	private static function getProjectEnabledDefaults()
	{
		if(!isset(self::$projectEnabledDefaults)){
			self::cacheAllEnableData();
		}

		return self::$projectEnabledDefaults;
	}

	private static function getProjectEnabledOverrides()
	{
		if(!isset(self::$projectEnabledOverrides)){
			self::cacheAllEnableData();
		}

		return self::$projectEnabledOverrides;
	}

	# get all versions enabled for a given project
	private static function getEnabledModuleVersionsForProject($pid)
	{
		$projectEnabledOverrides = self::getProjectEnabledOverrides();

		$enabledPrefixes = self::getProjectEnabledDefaults();
		$overrides = @$projectEnabledOverrides[$pid];
		if(isset($overrides)){
			foreach($overrides as $prefix => $value){
				if($value){
					$enabledPrefixes[$prefix] = true;
				}
				else{
					unset($enabledPrefixes[$prefix]);
				}
			}
		}

		$systemwideEnabledVersions = self::getSystemwideEnabledVersions();

		$enabledVersions = array();
		foreach(array_keys($enabledPrefixes) as $prefix){
			$version = @$systemwideEnabledVersions[$prefix];

			// Check the version to make sure the module is not systemwide disabled.
			if(isset($version)){
				$enabledVersions[$prefix] = $version;
			}
		}

		return $enabledVersions;
	}

	private static function shouldExcludeModule($prefix, $version)
	{
		if(strpos($_SERVER['REQUEST_URI'], '/manager/ajax/enable-module.php') !== false && $prefix == $_POST['prefix']){
			// We are in the process of switching an already enabled module from one version to another.
			// Do NOT include the currently enabled version of the module to avoid a class name conflict
			// for the ComposerAutoloaderInit class (if it hasn't changed between module versions).
			return true;
		}

		$modulePath = self::getModuleDirectoryPath($prefix, $version);
		$doesDirectoryExist = (file_exists($modulePath) && is_dir($modulePath));

		$isTestPrefix = strpos($prefix, self::TEST_MODULE_PREFIX) === 0;

		if($doesDirectoryExist && $isTestPrefix && !self::isTesting($prefix)){
			// This php process is not running unit tests.
			// Ignore the test prefix so it doesn't interfere with this process.
			return true;
		}

		return false;
	}

	private static function isTesting()
	{
		return PHP_SAPI == 'cli' && strpos($_SERVER['argv'][0], 'phpunit') !== FALSE;
	}

	# calling this method stores a local cache of all relevant data from the database
	private static function cacheAllEnableData()
	{
		$systemwideEnabledVersions = array();
		$projectEnabledOverrides = array();
		$projectEnabledDefaults = array();

		// Only attempt to detect enabled modules if the external module tables exist.
		if(self::getSqlToRunIfDBOutdated() === ""){
			$result = self::getSettings(null, null, array(self::KEY_VERSION, self::KEY_ENABLED));
			while($row = self::validateSettingsRow(db_fetch_assoc($result))){
				$pid = $row['project_id'];
				$prefix = $row['directory_prefix'];
				$key = $row['key'];
				$value = $row['value'];

				if(self::shouldExcludeModule($prefix, self::KEY_VERSION)){
					continue;
				}

				if($key == self::KEY_VERSION){
					$systemwideEnabledVersions[$prefix] = $value;
				}
				else if($key == self::KEY_ENABLED){
					if(isset($pid)){
						$projectEnabledOverrides[$pid][$prefix] = $value;
					}
					else if($value) {
						$projectEnabledDefaults[$prefix] = true;
					}
				}
				else{
					throw new Exception("Unexpected key: $key");
				}
			}
		}

		// Overwrite any previously cached results
		self::$systemwideEnabledVersions = $systemwideEnabledVersions;
		self::$projectEnabledDefaults = $projectEnabledDefaults;
		self::$projectEnabledOverrides = $projectEnabledOverrides;
	}

	# tests whether External Modules has been initially configured
	static function areTablesPresent()
	{
		$result = self::query("SHOW TABLES LIKE 'redcap_external_module%'");
		return db_num_rows($result) > 0;
	}

	# tests whether another database upgrade has taken place
	static function isTypePresentInTable()
	{
		global $db;
		$sql = "SELECT * 
			FROM information_schema.COLUMNS 
			WHERE 
				TABLE_SCHEMA = '".db_real_escape_string($db)."' 
				AND TABLE_NAME = 'redcap_external_module_settings' 
				AND COLUMN_NAME = 'type'";

		$result = self::query($sql);
		return db_num_rows($result) > 0;
	}

	# returns SQL statements to be run when the database is outdated.
	# returns "" if the database is up-to-date
	#
	# Checks in order for various conditions
	# Helper methods in methodName return true if up-to-date; false if out-of-date
	static function getSqlToRunIfDBOutdated()
	{
		$sql = array();
		$sql[] = array( "file" => "sql/create tables.sql",
				"methodName" => "areTablesPresent"
				);
		$sql[] = array( "file" => "sql/migration-2017-01-18_10-03-00.sql",
				"methodName" => "isTypePresentInTable"
				);

		$sqlToReturn = array();
		foreach ($sql as $row) {
			$isPresent = self::callPrivateMethod($row['methodName']);
			if (!$isPresent) {
				$sqlToReturn[] = htmlspecialchars(file_get_contents(__DIR__ . '/../'.$row['file']));
			}
		}
		return implode("\n", $sqlToReturn);
	}

	# calls a private method in the ExternalModules class
        private function callPrivateMethod($methodName)
        {
                $args = func_get_args();
                array_shift($args); // remove the method name

                $class = self::getReflectionClass();
                $method = $class->getMethod($methodName);
                $method->setAccessible(true);

                return $method->invokeArgs(null, $args);
        }

        private function getReflectionClass()
        {
                return new \ReflectionClass('ExternalModules\ExternalModules');
        }


	# echo's HTML for adding an approriate resource; also prepends appropriate directory structure
	static function addResource($path, $cdnUrl = null, $integrity = null)
	{
		$extension = pathinfo($path, PATHINFO_EXTENSION);

		if(substr($path,0,8) == "https://" || substr($path,0,7) == "http://") {
			$url = $path;
		}
		else {
			$localFile = true;
			if(empty($cdnUrl)){
				// This is a local resource.
				$path = "manager/$path";
				$fullLocalPath = __DIR__ . "/../$path";
			}
			else{
				// This is a third party resource.  We check for the node module, then fall back to the CDN url if it doesn't exist.
				// These local Yarn (node_module) dependencies were added only for PMI (which doesn't allow CDNs).
				// Running 'yarn install' is currently only required prior to deploying to the PMI REDCap instance.
				$path = "node_modules/$path";
				$fullLocalPath = __DIR__ . "/../$path";

				if(!file_exists($fullLocalPath)){
					$localFile = false;
					$url = $cdnUrl;
				}
			}

			if($localFile){
				// Add the filemtime to the url for cache busting.
				clearstatcache(true, $fullLocalPath);
				$url = ExternalModules::$BASE_URL . $path . '?' . filemtime($fullLocalPath);
			}
		}

		if(in_array($url, self::$INCLUDED_RESOURCES)) return;

		$integrityAttributes = '';
		if(!empty($integrity)){
			$integrityAttributes = "integrity='$integrity' crossorigin='anonymous'";
		}

		if ($extension == 'css') {
			echo "<link rel='stylesheet' type='text/css' href='" . $url . "' $integrityAttributes>";
		}
		else if ($extension == 'js') {
			echo "<script src='" . $url . "' $integrityAttributes></script>";
		}
		else {
			throw new Exception('Unsupported resource added: ' . $path);
		}

		self::$INCLUDED_RESOURCES[] = $url;
	}

	# returns an array of links requested by the config.json
	static function getLinks(){
		$pid = self::getPID();

		if(isset($pid)){
			$type = 'project';
		}
		else{
			$type = 'control-center';
		}

		$links = array();

		$versionsByPrefix = self::getEnabledModules($pid);
		foreach($versionsByPrefix as $prefix=>$version){
			$config = ExternalModules::getConfig($prefix, $version);

			foreach($config['links'][$type] as $link){
				$name = $link['name'];
				$link['url'] = self::getUrl($prefix, $link['url']);
				$links[$name] = $link;
			}
		}

		ksort($links);

		return $links;
	}

	# returns the pid from the $_GET array
	private static function getPID()
	{
		return @$_GET['pid'];
	}

	# for an internal request for a project URL, transforms the request into a URL
	static function getUrl($prefix, $page)
	{
		$id = self::getIdForPrefix($prefix);
		$page = preg_replace('/\.php$/', '', $page); // remove .php extension if it exists
		return self::$BASE_URL . "?id=$id&page=$page";
	}

	# returns the configs for disabled modules
	static function getDisabledModuleConfigs($enabledModules)
	{
		$dirs = [];
		foreach(self::$MODULES_PATH as $path) {
			$dirs = array_merge($dirs,scandir($path));
		}

		$disabledModuleVersions = array();
		foreach ($dirs as $dir) {
			if ($dir[0] == '.') {
				continue;
			}

			list($prefix, $version) = self::getParseModuleDirectoryPrefixAndVersion($dir);

			if($prefix && @$enabledModules[$prefix] != $version) {
				$versions = @$disabledModuleVersions[$prefix];
				if(!isset($versions)){
					$versions = array();
				}

				// Use array_merge_recursive() to show newest versions first.
				$disabledModuleVersions[$prefix] = array_merge_recursive(
					array($version => self::getConfig($prefix, $version)),
					$versions
				);
			}
		}

		return $disabledModuleVersions;
	}

	# Parses [institution]_[module]_v[X].[Y] into [ [institution]_[module], v[X].[Y] ]
	# e.g., vanderbilt_example_v1.0 becomes [ "vanderbilt_example", "v1.0" ]
	static function getParseModuleDirectoryPrefixAndVersion($directoryName){
		$directoryName = basename($directoryName);

		$parts = explode('_', $directoryName);

		$version = array_pop($parts);
		$prefix = implode('_', $parts);

		return array($prefix, $version);
	}

	# returns the config.json for a given module
	static function getConfig($prefix, $version = null, $pid = null)
	{
		if($version == null){
			$version = self::getEnabledVersion($prefix);
		}

		$configFilePath = self::getModuleDirectoryPath($prefix, $version)."/config.json";
		$config = @self::$configs[$prefix][$version];
		if($config === null){
			$fileTesting = file_get_contents($configFilePath);
			$config = json_decode($fileTesting, true);

			if($fileTesting == "") {
				return [];
			}

			if($config == null){
				// Disable the module to prevent repeated errors, especially those that prevent the External Modules menu items from appearing.
				self::disable($prefix);

				throw new Exception("An error occurred while parsing a configuration file!  The following file is likely not valid JSON: $configFilePath");
			}

			foreach(['permissions', 'system-settings', 'project-settings', 'no-auth-pages'] as $key){
				if(!isset($config[$key])){
					$config[$key] = array();
				}
			}

			self::$configs[$prefix][$version] = $config;
		}

		## Pull form and field list for choice list of project-settings field-list and form-list settings
		if(!empty($pid)) {
			foreach($config['project-settings'] as $configKey => $configRow) {
				$config['project-settings'][$configKey] = self::getAdditionalFieldChoices($configRow,$pid);
			}
		}

		if($pid === null) {
			$config = self::addReservedSettings($config);
		}

		return $config;
	}

	# specialty field lists include: user-role-list, user-list, dag-list, field-list, and form-list
	public static function getAdditionalFieldChoices($configRow,$pid) {
		if ($configRow['type'] == 'user-role-list') {
				$choices = [];

				$sql = "SELECT role_id,role_name
						FROM redcap_user_roles
						WHERE project_id = '" . db_real_escape_string($pid) . "'
						ORDER BY role_id";
				$result = self::query($sql);

				while ($row = db_fetch_assoc($result)) {
						$choices[] = ['value' => $row['role_id'], 'name' => $row['role_name']];
				}

				$configRow['choices'] = $choices;
		}
		else if ($configRow['type'] == 'user-list') {
				$choices = [];

				$sql = "SELECT ur.username,ui.user_firstname,ui.user_lastname
						FROM redcap_user_rights ur, redcap_user_information ui
						WHERE ur.project_id = '" . db_real_escape_string($pid) . "'
								AND ui.username = ur.username
						ORDER BY ui.ui_id";
				$result = self::query($sql);

				while ($row = db_fetch_assoc($result)) {
						$choices[] = ['value' => $row['username'], 'name' => $row['user_firstname'] . ' ' . $row['user_lastname']];
				}

				$configRow['choices'] = $choices;
		}
		else if ($configRow['type'] == 'dag-list') {
				$choices = [];

				$sql = "SELECT group_id,group_name
						FROM redcap_data_access_groups
						WHERE project_id = '" . db_real_escape_string($pid) . "'
						ORDER BY group_id";
				$result = self::query($sql);

				while ($row = db_fetch_assoc($result)) {
						$choices[] = ['value' => $row['group_id'], 'name' => $row['group_name']];
				}

				$configRow['choices'] = $choices;
		}
		else if ($configRow['type'] == 'field-list') {
			$choices = [];

			$sql = "SELECT field_name,element_label
					FROM redcap_metadata
					WHERE project_id = '" . db_real_escape_string($pid) . "'
					ORDER BY field_order";
			$result = self::query($sql);

			while ($row = db_fetch_assoc($result)) {
				if(strpos($row['element_label'],"<") !== false) {
					$row['element_label'] = preg_replace("/\\<.*?\\>/","",$row['element_label']);
				}
				$choices[] = ['value' => $row['field_name'], 'name' => $row['field_name'] . " - " . htmlspecialchars(substr($row['element_label'], 0, 20))];
			}

			$configRow['choices'] = $choices;
		}
		else if ($configRow['type'] == 'form-list') {
			$choices = [];

			$sql = "SELECT DISTINCT form_name
					FROM redcap_metadata
					WHERE project_id = '" . db_real_escape_string($pid) . "'
					ORDER BY field_order";
			$result = self::query($sql);

			while ($row = db_fetch_assoc($result)) {
				$choices[] = ['value' => $row['form_name'], 'name' => $row['form_name']];
			}

			$configRow['choices'] = $choices;
		}
		else if ($configRow['type'] == 'arm-list') {
			$choices = [];

			$sql = "SELECT a.arm_id, a.arm_name
					FROM redcap_events_arms a
					WHERE a.project_id = '" . db_real_escape_string($pid) . "'
					ORDER BY a.arm_id";
			$result = self::query($sql);

			while ($row = db_fetch_assoc($result)) {
				$choices[] = ['value' => $row['arm_id'], 'name' => $row['arm_name']];
			}

			$configRow['choices'] = $choices;
		}
		else if ($configRow['type'] == 'event-list') {
			$choices = [];

			$sql = "SELECT e.event_id, e.descrip, a.arm_id, a.arm_name
					FROM redcap_events_metadata e, redcap_events_arms a
					WHERE a.project_id = '" . db_real_escape_string($pid) . "'
						AND e.arm_id = a.arm_id
					ORDER BY e.event_id";
			$result = self::query($sql);

			while ($row = db_fetch_assoc($result)) {
				$choices[] = ['value' => $row['event_id'], 'name' => "Arm: ".$row['arm_name']." - Event: ".$row['descrip']];
			}

			$configRow['choices'] = $choices;
		}
		else if($configRow['type'] == 'sub_settings') {
            // Build a comma-separated list of sources in sub-settings
            $sources = !empty($configRow["source"]) ? array($configRow["source"]) : array();
			foreach ($configRow['sub_settings'] as $subConfigKey => $subConfigRow) {
				$configRow['sub_settings'][$subConfigKey] = self::getAdditionalFieldChoices($subConfigRow,$pid);
				if (!empty($configRow['sub_settings'][$subConfigKey]['source'])) $sources[] = $configRow['sub_settings'][$subConfigKey]['source'];
			}
			$configRow["source"] = implode(",", $sources);
		}
		else if($configRow['type'] == 'project-id') {
			$sql = "SELECT p.project_id, p.app_title
					FROM redcap_projects p, redcap_user_rights u
					WHERE p.project_id = u.project_id
						AND u.username = '".db_real_escape_string(USERID)."'
						AND LOWER(p.app_title) LIKE '%".strtolower(db_real_escape_string($pid))."%'";

			$result = db_query($sql);

			$matchingProjects = [];

			while($row = db_fetch_assoc($result)) {
				$matchingProjects[] = ["id" => $row["project_id"], "text" => $row["app_title"]];
			}
			$configRow['choices'] = $matchingProjects;
		}

		return $configRow;
	}

	# gets the version of a module
	public static function getEnabledVersion($prefix)
	{
		$versionsByPrefix = self::getSystemwideEnabledVersions();
		return @$versionsByPrefix[$prefix];
	}

	# adds the RESERVED_SETTINGS (above) to the config
	private static function addReservedSettings($config)
	{
		$systemSettings = $config['system-settings'];
		$projectSettings = $config['project-settings'];

		$existingSettingKeys = array();
		foreach($systemSettings as $details){
			$existingSettingKeys[$details['key']] = true;
		}

		foreach($projectSettings as $details){
			$existingSettingKeys[$details['key']] = true;
		}

		$visibleReservedSettings = array();
		foreach(self::$RESERVED_SETTINGS as $details){
			$key = $details['key'];
			if(isset($existingSettingKeys[$key])){
				throw new Exception("The '$key' setting key is reserved for internal use.  Please use a different setting key in your module.");
			}

			if(@$details['hidden'] != true){
				$visibleReservedSettings[] = $details;
			}
		}

		// Merge arrays so that reserved settings always end up at the top of the list.
		$config['system-settings'] = array_merge($visibleReservedSettings, $systemSettings);

		return $config;
	}

	# formats directory name from $prefix and $version
	static function getModuleDirectoryPath($prefix, $version){
		$directoryToFind = $prefix . '_' . $version;
		foreach(self::$MODULES_PATH as $pathDir) {
			$modulePath = $pathDir . $directoryToFind;
			if(is_dir($modulePath)) {
				break;
			}
		}
		return $modulePath;
	}

	static function getModuleDirectoryUrl($prefix, $version)
	{
		$filePath = ExternalModules::getModuleDirectoryPath($prefix, $version);

		$url = APP_PATH_WEBROOT_FULL.substr($filePath,strlen(dirname(dirname(__DIR__))."/"))."/";

		return $url;
	}

	static function hasProjectSettingSavePermission($moduleDirectoryPrefix, $key = null)
	{
		if(self::hasSystemSettingsSavePermission($moduleDirectoryPrefix)){
			return true;
		}

		if(self::hasDesignRights()){
			if(!self::isSystemSetting($moduleDirectoryPrefix, $key)){
				return true;
			}

			$level = self::getSystemSetting($moduleDirectoryPrefix, $key . self::OVERRIDE_PERMISSION_LEVEL_SUFFIX);
			return $level == self::OVERRIDE_PERMISSION_LEVEL_DESIGN_USERS;
		}

		return false;
	}

	public static function hasPermission($prefix, $version, $permissionName)
	{
		return in_array($permissionName, self::getConfig($prefix, $version)['permissions']);
	}

	static function isSystemSetting($moduleDirectoryPrefix, $key)
	{
		$config = self::getConfig($moduleDirectoryPrefix);

		foreach($config['system-settings'] as $details){
			if($details['key'] == $key){
				return true;
			}
		}

		return false;
	}

	static function getSettingDetails($prefix, $key)
	{
		$config = self::getConfig($prefix);

		$settingsTypes = [$config['system-settings'], $config['project-settings']];

		foreach($settingsTypes as $type){
			foreach($type as $details){
				if($details['key'] == $key){
					return $details;
				}
			}
		}

		return null;
	}

	# returns boolean if design rights are given by REDCap for current user
	static function hasDesignRights()
	{
		if(SUPER_USER){
			return true;
		}

		if(!isset($_GET['pid'])){
			// REDCap::getUserRights() will crash if no pid is set, so just return false.
			return false;
		}

		$rights = \REDCap::getUserRights();
		return $rights[USERID]['design'] == 1;
	}

	static function hasSystemSettingsSavePermission()
	{
		return self::isTesting() || SUPER_USER;
	}

	# Taken from: http://stackoverflow.com/questions/3338123/how-do-i-recursively-delete-a-directory-and-its-entire-contents-files-sub-dir
	private static function rrmdir($dir)
	{
		if (is_dir($dir)) {
			$objects = scandir($dir);
			foreach ($objects as $object) {
				if ($object != "." && $object != "..") {
					if (is_dir($dir . "/" . $object))
						self::rrmdir($dir . "/" . $object);
					else
						unlink($dir . "/" . $object);
				}
			}
			rmdir($dir);
		}
	}

	# there is no getInstance because settings returns an array of repeated elements
	# getInstance would merely consist of dereferencing the array; Ockham's razor

	# sets the instance to a JSON string into the database
	# $instance is 0-based index for array
	# if the old value is a number/string, etc., this function will transform it into a JSON
	# fills is with null values for non-expressed positions in the JSON before instance
	# JSON is a 0-based, one-dimensional array. It can be filled with associative arrays in
	# the form of other JSON-encoded strings.
	# This method is currently used in the Selective Email module (so don't remove it).
	static function setInstance($prefix, $projectId, $key, $instance, $value) {
		$instance = (int) $instance;
		$oldValue = self::getSetting($prefix, $projectId, $key);
		$json = array();
		if (gettype($oldValue) != "array") {
			if ($oldValue !== null) {
				$json[] = $oldValue;
			}
		}

		# fill in with prior values
		for ($i=count($json); $i < $instance; $i++) {
			if ((gettype($oldValue) == "array") && (count($oldValue) > $i)) {
				$json[$i] = $oldValue[$i];
			} else {
				# pad with null for prior values when $n is ahead; should never be used
				$json[$i] = null;
			}
		}

		# do not set null values for current instance; always set to empty string
		if ($value !== null) {
			$json[$instance] = $value;
		} else {
			$json[$instance] = "";
		}

		# fill in remainder if extant
		if (gettype($oldValue) == "array") {
			for ($i = $instance + 1; $i < count($oldValue); $i++) {
				$json[$i] = $oldValue[$i];
			}
		}

		#single-element JSONs are simply data values
		if (count($json) == 1) {
			self::setSetting($prefix, $projectId, $key, $json[0]);
		} else {
			self::setSetting($prefix, $projectId, $key, $json);
		}
	}

	static function getManagerJSDirectory() {
		return "js/";
		# just in case absolute path is needed, I have documented it here
		// return APP_PATH_WEBROOT_PARENT."/external_modules/manager/js/";
	}

	static function getManagerCSSDirectory() {
		return "css/";
		# just in case absolute path is needed, I have documented it here
		// return APP_PATH_WEBROOT_PARENT."/external_modules/manager/css/";
	}

	/**
	 * This is used by the EmailTriggerModule
	 */
	public static function getGlobalJSURL()
	{
		return self::$BASE_URL . '/manager/js/globals.js';
	}

	public static function isProjectSettingsConfigOverwrittenBySystem($config)
	{
		if(!empty($config)){
			$systemSettings = $config['system-settings'];
			if(empty($systemSettings)){
				return false;
			}

			$reservedKeys = [];
			foreach(self::$RESERVED_SETTINGS as $reservedSetting){
				$reservedKeys[$reservedSetting['key']] = true;
			}

			foreach ($systemSettings as $setting){
				$key = $setting['key'];
				if(@$reservedKeys[$key] == null){
					if(array_key_exists("allow-project-overrides",$setting) && $setting["allow-project-overrides"] == true){
						return true;
					}
				}
			}
		}
		return false;
	}

	public static function deleteEDoc($edocId){
		// Prevent SQL injection
		$edocId = intval($edocId);

		if(!$edocId){
			throw new Exception("The EDoc ID specified is not valid: $edocId");
		}

		# flag for deletion in the edocs database
		$sql = "UPDATE `redcap_edocs_metadata`
				SET `delete_date` = NOW()
				WHERE doc_id = $edocId";

		self::query($sql);
	}

	public static function downloadModule($module_id=null){
		// Set modules directory path
		$modulesDir = dirname(APP_PATH_DOCROOT).DS.'modules'.DS;
		// Validate module_id
		if (empty($module_id) || !is_numeric($module_id)) exit("0");
		$module_id = (int)$module_id;
		// Also obtain the folder name of the module
		$moduleFolderName = http_get(APP_URL_EXTMOD_LIB . "download.php?module_id=$module_id&name=1");
		// First see if the module directory already exists
		$moduleFolderDir = $modulesDir . $moduleFolderName . DS;
		if (file_exists($moduleFolderDir) && is_dir($moduleFolderDir)) {
		   exit("4");
		}
		// Call the module download service to download the module zip
		$moduleZipContents = http_get(APP_URL_EXTMOD_LIB . "download.php?module_id=$module_id");
		// Errors?
		if ($moduleZipContents == 'ERROR') {
			// 0 = Module does not exist in library
			exit("0");
		}
		// Place the file in the temp directory before extracting it
		$filename = APP_PATH_TEMP . date('YmdHis') . "_externalmodule_" . substr(sha1(rand()), 0, 6) . ".zip";
		if (file_put_contents($filename, $moduleZipContents) === false) {
			// 1 = Module zip couldn't be written to temp
			exit("1");
		}
		// Extract the module to /redcap/modules
		$zip = new \ZipArchive;
		if ($zip->open($filename) !== TRUE) {
		   exit("2");
		}
		// First, we need to rename the parent folder in the zip because GitHub has it as something else
		$i = 0;
		while ($item_name = $zip->getNameIndex($i)){
			$item_name_end = substr($item_name, strpos($item_name, "/"));
			$zip->renameIndex($i++, $moduleFolderName . $item_name_end);
		}
		$zip->close();
		// Now extract the zip to the modules folder
		$zip = new \ZipArchive;
		if ($zip->open($filename) === TRUE) {
			$zip->extractTo($modulesDir);
			$zip->close();
		}
		// Remove temp file
		unlink($filename);
		// Now double check that the new module directory got created
		if (!(file_exists($moduleFolderDir) && is_dir($moduleFolderDir))) {
		   exit("3");
		}
		// Give success message
		print "The module was successfully downloaded to the REDCap server, and can now be enabled.";
	}
}
