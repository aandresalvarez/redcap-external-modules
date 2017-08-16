<?php
namespace ExternalModules;
require_once __DIR__ . '/../classes/ExternalModules.php';
require_once APP_PATH_DOCROOT . 'ControlCenter/header.php';

?>

<h4 style="margin-top: 0;">
	<img src='../images/puzzle_medium.png'>
	External Modules - Module Manager
</h4>

<?php
ExternalModules::safeRequireOnce('templates/enabled-modules.php');
?>

<div id="external-modules-enable-modal" class="modal fade" role="dialog" data-backdrop="static">
	<div class="modal-dialog">
		<div class="modal-content">
			<div class="modal-header">
				<button type="button" class="close close-button" data-dismiss="modal">&times;</button>
				<h4 class="modal-title">Enable Module: <span class="module-name"></span></h4>
			</div>
			<div class="modal-body">
				<div id="external-modules-enable-modal-error"></div>
				<p>This module requests the following permissions:</p>
				<ul></ul>
			</div>
			<div class="modal-footer">
				<button class="close-button" data-dismiss="modal">Cancel</button>
				<button class="enable-button"></button>
			</div>
		</div>
	</div>
</div>

<div id="external-modules-configure-modal" class="modal fade" role="dialog" data-backdrop="static">
	<div class="modal-dialog">
		<div class="modal-content">
			<div class="modal-header">
				<button type="button" class="close" data-dismiss="modal">&times;</button>
				<h4 class="modal-title">Configure Module: <span class="module-name"></span></h4>
			</div>
			<div class="modal-body">
				<table class="table table-no-top-row-border">
					<thead>
						<tr>
							<th colspan="3">System Settings for All Projects</th>
							<th>Project Override<br>Permission Level</th>
						</tr>
					</thead>
					<tbody></tbody>
				</table>
			</div>
			<div class="modal-footer">
				<button data-dismiss="modal">Cancel</button>
				<button class="save">Save</button>
			</div>
		</div>
	</div>
</div>

<?php ExternalModules::addResource(ExternalModules::getManagerJSDirectory().'spin.js'); ?>
<?php ExternalModules::addResource(ExternalModules::getManagerJSDirectory().'control_center.js'); ?>

<?php

require_once APP_PATH_DOCROOT . 'ControlCenter/footer.php';
