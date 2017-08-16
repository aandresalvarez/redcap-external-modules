<?php
namespace ExternalModules;
require_once dirname(__FILE__) . '/../../classes/ExternalModules.php';

use Exception;

ExternalModules::addResource('css/style.css');

$sql = ExternalModules::getSqlToRunIfDBOutdated();
if($sql !== ""){
	echo '<p>Your current database table structure does not match REDCap\'s expected table structure for External Modules, which means that database tables and/or parts of tables are missing. Copy the SQL in the box below and execute it in the MySQL database named '.$db.' where the REDCap database tables are stored. Once the SQL has been executed, reload this page to run this check again.</p>';
	echo '<textarea style="width: 100%; height: 300px" onclick="this.focus();this.select()" readonly="readonly">' . $sql . '</textarea>';
	return;
}

$pid = $_GET['pid'];
$disableModuleConfirmProject = (isset($_GET['pid']) & !empty($_GET['pid'])) ? " for the current project" : "";
?>

<div id="external-modules-download" class="simpleDialog" role="dialog">
	Do you wish to download the External Module named 
	"<b><?php print \RCView::escape(rawurldecode(urldecode($_GET['download_module_title']))) ?></b>"?
	This will create a new directory folder for the module on the REDCap web server.
</div>

<div id="external-modules-disable-confirm-modal" class="modal fade" role="dialog" data-backdrop="static">
	<div class="modal-dialog">
		<div class="modal-content">
			<div class="modal-header">
				<button type="button" class="close" data-dismiss="modal">&times;</button>
				<h4 class="modal-title">Disable module? <span class="module-name"></span></h4>
			</div>
			<div class="modal-body">
				Are you sure you wish to disable this module 
				(<b><span id="external-modules-disable-confirm-module-name"></span>_<span id="external-modules-disable-confirm-module-version"></span></b>)<?=$disableModuleConfirmProject?>?
			</div>
			<div class="modal-footer">
				<button data-dismiss="modal">Cancel</button>
				<button id="external-modules-disable-button-confirmed" class="save">Disable module</button>
			</div>
		</div>
	</div>
</div>

<div id="external-modules-disabled-modal" class="modal fade" role="dialog">
	<div class="modal-dialog">
		<div class="modal-content">
			<div class="modal-header">
				<button type="button" class="close" data-dismiss="modal">&times;</button>
				<h4 class="modal-title">Available Modules</h4>
			</div>
			<div class="modal-body">
				<form>
				</form>
			</div>
		</div>
	</div>
</div>

<div id="external-modules-usage-modal" class="modal fade" role="dialog" data-backdrop="static">
	<div class="modal-dialog">
		<div class="modal-content">
			<div class="modal-header">
				<button type="button" class="close" data-dismiss="modal">&times;</button>
				<h4 class="modal-title"></h4>
			</div>
			<div class="modal-body">
			</div>
		</div>
	</div>
</div>

<p>External Modules are individual packages of software that can be downloaded and installed by a REDCap administrator.
Modules can extend REDCap's current functionality, and can also provide customizations and enhancements for REDCap's
existing behavior and appearance at the system level or project level.</p>

<?php if (isset($_GET['pid']) && SUPER_USER) { ?>

<p>As a REDCap administrator, you may enable any module that has been installed in REDCap for this project.
Some configuration settings might be required to be set, in which administrators or
users in this project with Project Setup/Design privileges can modify the configuration of any module at any time after the module
has first been enabled by an administrator. Note: Normal project users will not be able to enable or disable modules.</p>

<?php } elseif (isset($_GET['pid']) && !SUPER_USER) { ?>

<p>As a user with Project Setup/Design privileges in this project, you can modify the configuration (if applicable)
of any enabled module. Note: Only REDCap administrators are able to enable or disable modules.</p>

<?php } else { ?>

<p>You may click the "Download new module" button below to navigate to the External Modules Repository, which is a centralized catalog 
of curated modules that have been submitted by various REDCap partner institutions. If you find a module in the repository that you wish
to download, you will be able to install it, enable it, and then set any configuration settings (if applicable).
If you choose not to enable the module in all REDCap projects by default, then you will need to navigate to the External Modules page
on the left-hand menu of a given project to enable it there for that project. Some project-level configuration settings, depending on the module,
may also need to set on the project page.</p>

<?php } ?>

<?php
// Ensure that server is running PHP 5.4.0+ since REDCap's minimum requirement is PHP 5.3.0
if (version_compare(PHP_VERSION, ExternalModules::MIN_PHP_VERSION, '<')) {
	?>
	<div class="red">
		<b>PHP <?=ExternalModules::MIN_PHP_VERSION?> or higher is required for External Modules:</b>
		Sorry, but unfortunately your REDCap web server must be running PHP <?=ExternalModules::MIN_PHP_VERSION?>
		or a later version to utilize the External Modules functionality. Your current version is PHP <?=PHP_VERSION?>.
		You should consider upgrading PHP.
	</div>
	<?php
	require_once APP_PATH_DOCROOT . 'ControlCenter/footer.php';
	exit;
}
?>
<br>
<?php if(SUPER_USER) { ?>
	<button id="external-modules-enable-modules-button" class="btn btn-success btn-sm">
		<span class="glyphicon glyphicon-off" aria-hidden="true"></span>
		Enable a module
	</button>
<?php } ?>
<?php if (SUPER_USER && !isset($_GET['pid'])) { ?>
	<button id="external-modules-download-modules-button" class="btn btn-primary btn-sm">
		<span class="glyphicon glyphicon-save" aria-hidden="true"></span>
		Download new module from repository
	</button>
<?php } ?>
<br>
<br>

<?php if (isset($_GET['pid'])) { ?>
<h4><b>Currently Enabled Modules</b></h4>
<?php } else { ?>
<h4><b>Modules Currently Available on this System</b></h4>
<?php } ?>

<script>
	var override = '<?=ExternalModules::OVERRIDE_PERMISSION_LEVEL_DESIGN_USERS?>';
	var enabled = '<?=ExternalModules::KEY_ENABLED?>';
	var overrideSuffix = '<?=ExternalModules::OVERRIDE_PERMISSION_LEVEL_SUFFIX?>';
</script>

<table id='external-modules-enabled' class="table">
	<?php

	$versionsByPrefix = ExternalModules::getEnabledModules($_GET['pid']);
	$configsByPrefix = array();

	if (empty($versionsByPrefix)) {
		echo 'None';
	} else {
		foreach ($versionsByPrefix as $prefix => $version) {
			if (isset($_GET['pid'])) {
				$config = ExternalModules::getConfig($prefix, $version, $_GET['pid']);
			} else {
				$config = ExternalModules::getConfig($prefix, $version);
			}

			## Add resources for custom javascript fields
			foreach(array_merge($config['project-settings'],$config['system-settings']) as $configRow) {
				if($configRow['source']) {
					$sources = explode(",",$configRow['source']);
					foreach($sources as $sourceLocation) {
						if(file_exists(ExternalModules::getModuleDirectoryPath($prefix,$version)."/".$sourceLocation)) {
							// include file from module directory
							ExternalModules::addResource(ExternalModules::getModuleDirectoryUrl($prefix,$version)."/".$sourceLocation);
						}
						else if(file_exists(dirname(__DIR__)."/js/".$sourceLocation)) {
							// include file from external_modules directory
							ExternalModules::addResource("js/".$sourceLocation);
						}
					}
				}
			}


			$configsByPrefix[$prefix] = $config;
			$enabled = false;
			$system_enabled = ExternalModules::getSystemSetting($prefix, ExternalModules::KEY_ENABLED);

			if (isset($_GET['pid'])) {
				$enabled = ExternalModules::getProjectSetting($prefix, $_GET['pid'], ExternalModules::KEY_ENABLED);
			}
			if ((isset($_GET['pid']) && $enabled) || (!isset($_GET['pid']) && isset($config['system-settings']))) {
			?>
				<tr data-module='<?= $prefix ?>' data-version='<?= $version ?>'>
					<td><div class='external-modules-title'><?= $config['name'] . ' - ' . $version ?>
                            <?php if ($system_enabled) print "<span class='label label-warning'>Global Module</span>" ?>
                        </div><div class='external-modules-description'><?php echo $config['description'] ? $config['description'] : ''; ?></div><div class='external-modules-byline'>
<?php
        if ($config['authors']) {
                $names = array();
                foreach ($config['authors'] as $author) {
                        $name = $author['name'];
                        $institution = empty($author['institution']) ? "" : " <span class='author-institution'>({$author['institution']})</span>";
                        if ($name) {
                                if ($author['email']) {
                                        $names[] = "<a href='mailto:".$author['email']."?subject=".rawurlencode($config['name']." - ".$version)."'>".$name."</a>$institution";
                                } else {
                                        $names[] = $name . $institution;
                                }
                        }
                }
                if (count($names) > 0) {
                        echo "by ".implode($names, ", ");
                }
        }
?>
</div></td>
					<td class="external-modules-action-buttons">
						<?php if(ExternalModules::isProjectSettingsConfigOverwrittenBySystem($config) || !empty($config['project-settings'])){?>
							<button class='external-modules-configure-button'>Configure</button>
						<?php } ?>
						<?php if(SUPER_USER) { ?>
							<button class='external-modules-disable-button'>Disable</button>
						<?php } ?>
						<?php if(!isset($_GET['pid'])) { ?>
							<button class='external-modules-usage-button' style="min-width: 90px">View Usage</button>
						<?php } ?>
					</td>
				</tr>
			<?php
			}
		}
	}

	?>
</table>

<?php
global $configsByPrefixJSON,$versionsByPrefixJSON;

// JSON_PARTIAL_OUTPUT_ON_ERROR was added here to fix an odd conflict between field-list and form-list types
// and some Hebrew characters on the "Israel: Healthcare Personnel (Hebrew)" project that could not be json_encoded.
// This workaround allows configs to be encoded anyway, even though the unencodable characters will be excluded
// (causing form-list and field-list to not work for any fields with unencodeable characters).
// I spent a couple of hours trying to find a solution, but was unable.  This workaround will have to do for now.
$configsByPrefixJSON = json_encode($configsByPrefix, JSON_PARTIAL_OUTPUT_ON_ERROR);
if($configsByPrefixJSON == null){
	echo '<script>alert(' . json_encode('An error occurred while converting the configurations to JSON: ' . json_last_error_msg()) . ');</script>';
	throw new Exception('An error occurred while converting the configurations to JSON: ' . json_last_error_msg());
}

$versionsByPrefixJSON = json_encode($versionsByPrefix, JSON_PARTIAL_OUTPUT_ON_ERROR);
if($versionsByPrefixJSON == null){
	echo '<script>alert(' . json_encode('An error occurred while converting the versions to JSON: ' . json_last_error_msg()) . ');</script>';
	throw new Exception("An error occurred while converting the versions to JSON: " . json_last_error_msg());
}

require_once 'globals.php';

?>
<script>
	ExternalModules.sortModuleTable($('#external-modules-enabled'))
</script>
