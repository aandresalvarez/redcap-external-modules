<?php
namespace ExternalModules;

if (class_exists('ExternalModules\AbstractExternalModule')) {
	return;
}

use Exception;

class AbstractExternalModule
{
	public $PREFIX;
	public $VERSION;

	private $userBasedSettingPermissions = true;

	# constructor
	function __construct()
	{
		// This if statement is only necessary for the BaseTestExternalModule.
		if(!isset($this->PREFIX)){
			list($prefix, $version) = ExternalModules::getParseModuleDirectoryPrefixAndVersion($this->getModuleDirectoryName());

			$this->PREFIX = $prefix;
			$this->VERSION = $version;
		}

		// Disallow illegal configuration options at module instantiation (and enable) time.
		self::checkSettings();
	}

	# checks the config.json settings for validity of syntax
	protected function checkSettings()
	{
		$config = $this->getConfig();
		$systemSettings = $config['system-settings'];
		$projectSettings = $config['project-settings'];

		$handleDuplicate = function($key, $type){
			throw new Exception("The \"" . $this->PREFIX . "\" module defines the \"$key\" $type setting multiple times!");
		};

		$systemSettingKeys = array();
		foreach($systemSettings as $details){
			$key = $details['key'];
			self::checkSettingKey($key);

			if(isset($systemSettingKeys[$key])){
				$handleDuplicate($key, 'system');
			}
			else{
				$systemSettingKeys[$key] = true;
			}
		}

		$projectSettingKeys = array();
		foreach($projectSettings as $details){
			$key = $details['key'];
			self::checkSettingKey($key);

			if(array_key_exists($key, $systemSettingKeys)){
				throw new Exception("The \"" . $this->PREFIX . "\" module defines the \"$key\" setting on both the system and project levels.  If you want to allow this setting to be overridden on the project level, please remove the project setting configuration and set 'allow-project-overrides' to true in the system setting configuration instead.  If you want this setting to have a different name on the project management page, specify a 'project-name' under the system setting.");
			}

			if(array_key_exists('default', $details)){
				throw new Exception("The \"" . $this->PREFIX . "\" module defines a default value for the the \"$key\" project setting.  Default values are only allowed on system settings.");
			}

			if(isset($projectSettingKeys[$key])){
				$handleDuplicate($key, 'project');
			}
			else{
				$projectSettingKeys[$key] = true;
			}
		}
	}

	# checks a config.json setting key $key for validity
	# throws an exception if invalid
	private function checkSettingKey($key)
	{
		if(!self::isSettingKeyValid($key)){
			throw new Exception("The " . $this->PREFIX . " module has a setting named \"$key\" that contains invalid characters.  Only lowercase characters, numbers, and dashes are allowed.");
		}
	}

	# validity check for a setting key $key
	# returns boolean
	protected function isSettingKeyValid($key)
	{
		// Only allow lowercase characters, numbers, dashes, and underscores to ensure consistency between modules (and so we don't have to worry about escaping).
		return !preg_match("/[^a-z0-9-_]/", $key);
	}

	function selectData($some, $params=array())
	{
		self::checkPermissions(__FUNCTION__);

		return 'this could be some data from the database';
	}

	function updateData($some, $params=array())
	{
		self::checkPermissions(__FUNCTION__);

		throw new Exception('Not yet implemented!');
	}

	function deleteData($some, $params=array())
	{
		self::checkPermissions(__FUNCTION__);

		throw new Exception('Not yet implemented!');
	}

	function updateUserPermissions($some, $params=array())
	{
		self::checkPermissions(__FUNCTION__);

		throw new Exception('Not yet implemented!');
	}

	# check whether the current External Module has permission to call the requested method $methodName
	private function checkPermissions($methodName)
	{
		# Convert from camel to snake case.
		# Taken from the second solution here: http://stackoverflow.com/questions/1993721/how-to-convert-camelcase-to-camel-case
		$permissionName = ltrim(strtolower(preg_replace('/[A-Z]/', '_$0', $methodName)), '_');

		if (!$this->hasPermission($permissionName)) {
			throw new Exception("This module must request the \"$permissionName\" permission in order to call the $methodName() method.");
		}
	}

	# checks whether the current External Module has permission for $permissionName
	function hasPermission($permissionName)
	{
		return ExternalModules::hasPermission($this->PREFIX, $this->VERSION, $permissionName);
	}

	# get the config for the current External Module
	# consists of config.json and filled-in values
	function getConfig()
	{
		return ExternalModules::getConfig($this->PREFIX, $this->VERSION);
	}

	# get the directory name of the current external module
	function getModuleDirectoryName()
	{
		$reflector = new \ReflectionClass(get_class($this));
		return basename(dirname($reflector->getFileName()));
	}

	protected function getSettingKeyPrefix(){
		return '';
	}

	private function prefixSettingKey($key){
		return $this->getSettingKeyPrefix() . $key;
	}

	# a SYSTEM setting is a value to be used on all projects. It can be overridden by a particular project
	# a PROJECT setting is a value set by each project. It may be a value that overrides a system setting
	#      or it may be a value set for that project alone with no suggested System-level value.
	#      the project_id corresponds to the value in REDCap
	#      if a project_id (pid) is null, then it becomes a system value

	# Set the setting specified by the key to the specified value
	# systemwide (shared by all projects).
	function setSystemSetting($key, $value)
	{
		$key = $this->prefixSettingKey($key);
		ExternalModules::setSystemSetting($this->PREFIX, $key, $value);
	}

	# Get the value stored systemwide for the specified key.
	function getSystemSetting($key)
	{
		$key = $this->prefixSettingKey($key);
		return ExternalModules::getSystemSetting($this->PREFIX, $key);
	}

	# Remove the value stored systemwide for the specified key.
	function removeSystemSetting($key)
	{
		$key = $this->prefixSettingKey($key);
		ExternalModules::removeSystemSetting($this->PREFIX, $key);
	}

	# Set the setting specified by the key to the specified value for
	# this project (override the system setting).  In most cases
	# the project id can be detected automatically, but it can
	# optionaly be specified as the third parameter instead.
	function setProjectSetting($key, $value, $pid = null)
	{
		$pid = self::requireProjectId($pid);
		$key = $this->prefixSettingKey($key);
		ExternalModules::setProjectSetting($this->PREFIX, $pid, $key, $value);
	}

	# Returns the value stored for the specified key for the current
	# project if it exists.  If this setting key is not set (overriden)
	# for the current project, the system value for this key is
	# returned.  In most cases the project id can be detected
	# automatically, but it can optionaly be specified as the third
	# parameter instead.
	function getProjectSetting($key, $pid = null)
	{
		$pid = self::requireProjectId($pid);
		$key = $this->prefixSettingKey($key);
		return ExternalModules::getProjectSetting($this->PREFIX, $pid, $key);
	}
	
	# Remove the value stored for this project and the specified key.
	# In most cases the project id can be detected automatically, but
	# it can optionaly be specified as the third parameter instead.
	function removeProjectSetting($key, $pid = null)
	{
		$pid = self::requireProjectId($pid);
		$key = $this->prefixSettingKey($key);
		ExternalModules::removeProjectSetting($this->PREFIX, $pid, $key);
	}

	function getSubSettings($key)
	{
		$keys = [];
		$config = $this->getSettingConfig($key);
		foreach($config['sub_settings'] as $subSetting){
			$keys[] = $this->prefixSettingKey($subSetting['key']);
		}

		$subSettings = [];
		$rawSettings = ExternalModules::getProjectSettingsAsArray($this->PREFIX, self::requireProjectId());
		$subSettingCount = count($rawSettings[$keys[0]]['value']);
		for($i=0; $i<$subSettingCount; $i++){
			$subSetting = [];
			foreach($keys as $key){
				$subSetting[$key] = $rawSettings[$key]['value'][$i];
			}

			$subSettings[] = $subSetting;
		}

		return $subSettings;
	}

	function getSettingConfig($key)
	{
		$config = $this->getConfig();
		foreach(['project-settings', 'system-settings'] as $type) {
			foreach ($config[$type] as $setting) {
				if ($key == $setting['key']) {
					return $setting;
				}
			}
		}

		return null;
	}

	function getUrl($path, $noAuth = false, $useApiEndpoint = false)
	{
        $pid = self::detectProjectId();
		$extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $url = '';
		if ($extension == 'php' || $useApiEndpoint){
			$url = ExternalModules::getUrl($this->PREFIX, $path, $useApiEndpoint);
			if ($extension == 'php') {
				if (!empty($pid)){
					$url .= '&pid='.$pid;
				}
				if ($noAuth) {
					$url .= '&NOAUTH';
				}
			}
		} else {
			// This must be a resource, like an image or css/js file.
			// Go ahead and return the version specific url.
			$url =  ExternalModules::getModuleDirectoryUrl($this->PREFIX, $this->VERSION) . $path;
		}
		return $url;
	}
	
	public function getModulePath()
	{
		return ExternalModules::getModuleDirectoryPath($this->PREFIX, $this->VERSION) . DS;
	}

	public function getModuleName()
	{
		return $this->getConfig()['name'];
	}

	public function resetSurveyAndGetCodes($projectId,$recordId,$surveyFormName = "", $eventId = "") {
		list($surveyId,$surveyFormName) = $this->getSurveyId($projectId,$surveyFormName);

		## Validate surveyId and surveyFormName were found
		if($surveyId == "" || $surveyFormName == "") return false;

		## Find valid event ID for form if it wasn't passed in
		if($eventId == "") {
			$eventId = $this->getValidFormEventId($surveyFormName,$projectId);

			if(!$eventId) return false;
		}

		## Search for a participant and response id for the given survey and record
		list($participantId,$responseId) = $this->getParticipantAndResponseId($surveyId,$recordId);

		## Create participant and return code if doesn't exist yet
		if($participantId == "" || $responseId == "") {
			$hash = self::generateUniqueRandomSurveyHash();

			## Insert a participant row for this survey
			$sql = "INSERT INTO redcap_surveys_participants (survey_id, event_id, participant_email, participant_identifier, hash)
					VALUES ($surveyId,".prep($eventId).", '', null, '$hash')";

			if(!db_query($sql)) echo "Error: ".db_error()." <br />$sql<br />";
			$participantId = db_insert_id();

			## Insert a response row for this survey and record
			$returnCode = generateRandomHash();
			$firstSubmitDate = "'".date('Y-m-d h:m:s')."'";

			$sql = "INSERT INTO redcap_surveys_response (participant_id, record, first_submit_time, return_code)
					VALUES ($participantId, ".prep($recordId).", $firstSubmitDate,'$returnCode')";

			if(!db_query($sql)) echo "Error: ".db_error()." <br />$sql<br />";
			$responseId = db_insert_id();
		}
		## Reset response status if it already exists
		else {
			$sql = "SELECT p.participant_id, p.hash, r.return_code, r.response_id, COALESCE(p.participant_email,'NULL') as participant_email
					FROM redcap_surveys_participants p, redcap_surveys_response r
					WHERE p.survey_id = '$surveyId'
						AND p.participant_id = r.participant_id
						AND r.record = '".prep($recordId)."'";

			$q = db_query($sql);
			$rows = [];
			while($row = db_fetch_assoc($q)) {
				$rows[] = $row;
			}

			## If more than one exists, delete any that are responses to public survey links
			if(db_num_rows($q) > 1) {
				foreach($rows as $thisRow) {
					if($thisRow["participant_email"] == "NULL" && $thisRow["response_id"] != "") {
						$sql = "DELETE FROM redcap_surveys_response
								WHERE response_id = ".$thisRow["response_id"];
						if(!db_query($sql)) echo "Error: ".db_error()." <br />$sql<br />";
					}
					else {
						$row = $thisRow;
					}
				}
			}
			else {
				$row = $rows[0];
			}
			$returnCode = $row['return_code'];
			$hash = $row['hash'];
			$participantId = "";

			if($returnCode == "") {
				$returnCode = generateRandomHash();
			}

			## If this is only as a public survey link, generate new participant row
			if($row["participant_email"] == "NULL") {
				$hash = self::generateUniqueRandomSurveyHash();

				## Insert a participant row for this survey
				$sql = "INSERT INTO redcap_surveys_participants (survey_id, event_id, participant_email, participant_identifier, hash)
						VALUES ($surveyId,".prep($eventId).", '', null, '$hash')";

				if(!db_query($sql)) echo "Error: ".db_error()." <br />$sql<br />";
				$participantId = db_insert_id();
			}

			// Set the response as incomplete in the response table, update participantId if on public survey link
			$sql = "UPDATE redcap_surveys_participants p, redcap_surveys_response r
					SET r.completion_time = null,
						r.first_submit_time = '".date('Y-m-d h:m:s')."',
						r.return_code = '".prep($returnCode)."'".
						($participantId == "" ? "" : ", r.participant_id = '$participantId'")."
					WHERE p.survey_id = $surveyId
						AND p.event_id = ".prep($eventId)."
						AND r.participant_id = p.participant_id
						AND r.record = '".prep($recordId)."'";
			db_query($sql);
		}

		// Set the response as incomplete in the data table
		$sql = "UPDATE redcap_data
				SET value = '0'
				WHERE project_id = ".prep($projectId)."
					AND record = '".prep($recordId)."'
					AND event_id = ".prep($eventId)."
					AND field_name = '{$surveyFormName}_complete'";

		$q = db_query($sql);
		// Log the event (if value changed)
		if ($q && db_affected_rows() > 0) {
			if(function_exists("log_event")) {
				\log_event($sql,"redcap_data","UPDATE",$recordId,"{$surveyFormName}_complete = '0'","Update record");
			}
			else {
				\Logging::logEvent($sql,"redcap_data","UPDATE",$recordId,"{$surveyFormName}_complete = '0'","Update record");
			}
		}

		@db_query("COMMIT");

		return array("hash" => $hash, "return_code" => $returnCode);
	}

	public function generateUniqueRandomSurveyHash() {
		## Generate a random hash and verify it's unique
		do {
			$hash = generateRandomHash(10);

			$sql = "SELECT p.hash
						FROM redcap_surveys_participants p
						WHERE p.hash = '$hash'";

			$result = db_query($sql);

			$hashExists = (db_num_rows($result) > 0);
		} while($hashExists);

		return $hash;
	}

	public function getProjectAndRecordFromHashes($surveyHash, $returnCode) {
		$sql = "SELECT s.project_id as projectId, r.record as recordId, s.form_name as surveyForm, p.event_id as eventId
				FROM redcap_surveys_participants p, redcap_surveys_response r, redcap_surveys s
				WHERE p.hash = '".prep($surveyHash)."'
					AND p.survey_id = s.survey_id
					AND p.participant_id = r.participant_id
					AND r.return_code = '".prep($returnCode)."'";

		$q = db_query($sql);

		$row = db_fetch_assoc($q);

		if($row) {
			return $row;
		}

		return false;
	}

	public function createPassthruForm($projectId,$recordId,$surveyFormName = "", $eventId = "") {
		$codeDetails = $this->resetSurveyAndGetCodes($projectId,$recordId,$surveyFormName,$eventId);

		$hash = $codeDetails["hash"];
		$returnCode = $codeDetails["return_code"];

		$surveyLink = APP_PATH_SURVEY_FULL . "?s=$hash";

		## Build invisible self-submitting HTML form to get the user to the survey
		echo "<html><body>
				<form name='passthruform' action='$surveyLink' method='post' enctype='multipart/form-data'>
				".($returnCode == "NULL" ? "" : "<input type='hidden' value='".$returnCode."' name='__code'/>")."
				<input type='hidden' value='1' name='__prefill' />
				</form>
				<script type='text/javascript'>
					document.passthruform.submit();
				</script>
				</body>
				</html>";
		return false;
	}

	public function getValidFormEventId($formName,$projectId) {
		if(!is_numeric($projectId) || $projectId == "") return false;

		$projectDetails = $this->getProjectDetails($projectId);

		if($projectDetails["repeatforms"] == 0) {
			$sql = "SELECT e.event_id
					FROM redcap_events_metadata e, redcap_events_arms a
					WHERE a.project_id = $projectId
						AND a.arm_id = e.arm_id
					ORDER BY e.event_id ASC
					LIMIT 1";

			$q = db_query($sql);

			if($row = db_fetch_assoc($q)) {
				return $row['event_id'];
			}
		}
		else {
			$sql = "SELECT f.event_id
					FROM redcap_events_forms f, redcap_events_metadata m, redcap_events_arms a
					WHERE a.project_id = $projectId
						AND a.arm_id = m.arm_id
						AND m.event_id = f.event_id
						AND f.form_name = '".prep($formName)."'
					ORDER BY f.event_id ASC
					LIMIT 1";

			$q = db_query($sql);

			if($row = db_fetch_assoc($q)) {
				return $row['event_id'];
			}
		}

		return false;
	}

	public function getSurveyId($projectId,$surveyFormName = "") {
		// Get survey_id, form status field, and save and return setting
		$sql = "SELECT s.survey_id, s.form_name, s.save_and_return
		 		FROM redcap_projects p, redcap_surveys s, redcap_metadata m
					WHERE p.project_id = ".prep($projectId)."
						AND p.project_id = s.project_id
						AND m.project_id = p.project_id
						AND s.form_name = m.form_name
						".($surveyFormName != "" ? (is_numeric($surveyFormName) ? "AND s.survey_id = '$surveyFormName'" : "AND s.form_name = '".prep($surveyFormName)."'") : "")
				."ORDER BY s.survey_id ASC
				 LIMIT 1";

		$q = db_query($sql);
		$surveyId = db_result($q, 0, 'survey_id');
		$surveyFormName = db_result($q, 0, 'form_name');

		return [$surveyId,$surveyFormName];
	}

	public function getParticipantAndResponseId($surveyId,$recordId) {
		$sql = "SELECT p.participant_id, r.response_id
				FROM redcap_surveys_participants p, redcap_surveys_response r
				WHERE p.survey_id = '$surveyId'
					AND p.participant_id = r.participant_id
					AND r.record = '".$recordId."'";

		$q = db_query($sql);
		$participantId = db_result($q, 0, 'participant_id');
		$responseId = db_result($q, 0, 'response_id');

		return [$participantId,$responseId];
	}

	public function getProjectDetails($projectId) {
		$sql = "SELECT *
				FROM redcap_projects
				WHERE project_id = '".prep($projectId)."'";

		$q = db_query($sql);

		return db_fetch_assoc($q);
	}

	public function getMetadata($projectId,$forms = NULL) {
		$metadata = \REDCap::getDataDictionary($projectId,"array",TRUE,NULL,$forms);

		return $metadata;
	}

	public function getData($projectId,$recordId,$eventId="",$format="array") {
		$data = \REDCap::getData($projectId,$format,$recordId);

		if($eventId != "") {
			return $data[$recordId][$eventId];
		}
		return $data;
	}

	public function saveData($projectId,$recordId,$eventId,$data) {
		return \REDCap::saveData($projectId,"array",[$recordId => [$eventId =>$data]]);
	}

	/**
	 * @param $projectId
	 * @param $recordId
	 * @param $eventId
	 * @param $formName
	 * @param $data array This must be in [instance => [field => value]] format
	 * @return array
	 */
	public function saveInstanceData($projectId,$recordId,$eventId,$formName,$data) {
		return \REDCap::saveData($projectId,"array",[$recordId => [$eventId => [$formName => $data]]]);
	}

	# function to enforce that a pid is required for a particular function
	private function requireProjectId($pid = null)
	{
		return $this->requireParameter('pid', $pid);
	}

	private function requireEventId($eventId = null)
	{
		return $this->requireParameter('event_id', $eventId);
	}

	private function requireInstanceId($instanceId = null)
	{
		return $this->requireParameter('instance', $instanceId);
	}

	private function requireParameter($parameterName, $value)
	{
		$value = self::detectParameter($parameterName, $value);

		if(!isset($value)){
			throw new Exception("You must supply the following either as a GET parameter or as the last argument to this method: $parameterName");
		}

		return $value;
	}

	private function detectParameter($parameterName, $value)
	{
		if($value == null){
			$value = @$_GET[$parameterName];
		}

		return $value;
	}

	# if $pid is empty/null, can get the pid from $_GET if it exists
	private function detectProjectId($projectId=null)
	{
		return $this->detectParameter('pid', $projectId);
	}

	private function detectEventId($eventId=null)
	{
		return $this->detectParameter('event_id', $eventId);
	}

	private function detectInstanceId($instanceId=null)
	{
		return $this->detectParameter('instance', $instanceId);
	}

	# pushes the execution of the module to the end of the queue
	# helpful to wait for data to be processed by other modules
	# execution of the module will be restarted from the beginning
	# For example:
	# 	if ($data['field'] === "") {
	#		delayModuleExecution();
	#		return;       // the module will be restarted from the beginning
	#	}
	public function delayModuleExecution() {
		return ExternalModules::delayModuleExecution();
	}

    /**
     * Function that returns the label name from checkboxes, radio buttons, etc instead of the value
     * @param $var, Redcap variable
     * @param $value, variable value
     * @param $data, project data => $data[$record][$event_id]
     * @return string, the label of the field. If mulptiple choice returns data separated by commas
     */
    public function getLogicLabel ($var, $value, $data){
        $project_id = self::detectProjectId();
        $field_name = str_replace('[', '', $var);
        $field_name = str_replace(']', '', $field_name);
        $metadata = \REDCap::getDataDictionary($project_id,'array',false,$field_name);
        $label = "";
        if($metadata[$field_name]['field_type'] == 'checkbox' || $metadata[$field_name]['field_type'] == 'dropdown' || $metadata[$field_name]['field_type'] == 'radio'){
            $choices = preg_split("/\s*\|\s*/", $metadata[$field_name]['select_choices_or_calculations']);
            $deletecoma = false;
            foreach ($choices as $choice){
                $option_value = preg_split("/,/", $choice)[0];
                if(empty($value)){
                    foreach ($data[$field_name] as $choiceValue=>$multipleChoice){
                        if($multipleChoice === "1" && $choiceValue == $option_value) {
                            $label .= trim(preg_split("/^(.+?),/", $choice)[1]).", ";
                            $deletecoma = true;
                        }
                    }

                }else if($value === $option_value){
                    $label = trim(preg_split("/^(.+?),/", $choice)[1]);
                    break;
                }
            }
            if($deletecoma){
                //we delete the last comma
                $label = substr($label, 0, -2);
            }
        }else if($metadata[$field_name]['field_type'] == 'truefalse'){
            if($value == '1'){
                $label = "True";
            }else{
                $label = "False";
            }
        }
        return $label;
    }

    public function sendAdminEmail($subject, $message){
    	ExternalModules::sendAdminEmail($subject, $message, $this->PREFIX);
	}

	public function getChoiceLabel($fieldName, $value, $pid = null){
		return $this->getChoiceLabels($fieldName, $pid)[$value];
	}

	public function getChoiceLabels($fieldName, $pid = null){
		// Caching could be easily added to this method to improve performance on repeat calls.

		$pid = $this->requireProjectId($pid);

		$dictionary = \REDCap::getDataDictionary($pid, 'array', false, [$fieldName]);
		$choices = explode('|', $dictionary[$fieldName]['select_choices_or_calculations']);
		$choicesById = [];
		foreach($choices as $choice){
			$parts = explode(', ', $choice);
			$id = trim($parts[0]);
			$label = trim($parts[1]);
			$choicesById[$id] = $label;
		}

		return $choicesById;
	}

	private function query($sql){
		return ExternalModules::query($sql);
	}

	public function createDAG($dagName){
		$pid = db_escape(self::requireProjectId());
		$dagName = db_escape($dagName);

		$this->query("insert into redcap_data_access_groups (project_id, group_name) values ($pid, '$dagName')");

		return db_insert_id();
	}

	public function renameDAG($dagId, $dagName){
		$pid = db_escape(self::requireProjectId());
		$dagId = db_escape($dagId);
		$dagName = db_escape($dagName);

		$this->query("update redcap_data_access_groups set group_name = '$dagName' where project_id = $pid and group_id = $dagId");
	}

	public function setDAG($record, $dagId){
		// $this->setData() is used instead of REDCap::saveData(), since REDCap::saveData() has some (perhaps erroneous) limitations for super users around setting DAGs on records that are already in DAGs  .
		// It also doesn't seem to be aware of DAGs that were just added in the same hook call (likely because DAGs are cached before the hook is called).
		// Specifying a "redcap_data_access_group" parameter for REDCap::saveData() doesn't work either, since that parameter only accepts the auto generated names (not ids or full names).

		$this->setData($record, '__GROUPID__', $dagId);
	}

	public function setData($record, $fieldName, $values){
		$instanceId = db_escape(self::requireInstanceId());
		if($instanceId != 1){
			throw new Exception("Multiple instances are not currently supported!");
		}

		$pid = db_escape(self::requireProjectId());
		$eventId = db_escape(self::requireEventId());
		$record = db_escape($record);
		$fieldName = db_escape($fieldName);

		if(!is_array($values)){
			$values = [$values];
		}

		mysqli_begin_transaction();

		$this->query("DELETE FROM redcap_data where project_id = $pid and event_id = $eventId and record = '$record' and field_name = '$fieldName'");

		foreach($values as $value){
			$value = db_escape($value);
			$this->query("INSERT INTO redcap_data (project_id, event_id, record, field_name, value) VALUES ($pid, $eventId, '$record', '$fieldName', '$value')");
		}

		mysqli_commit();
	}

	public function areSettingPermissionsUserBased(){
		return $this->userBasedSettingPermissions;
	}

	public function disableUserBasedSettingPermissions(){
		$this->userBasedSettingPermissions = false;
	}
}
