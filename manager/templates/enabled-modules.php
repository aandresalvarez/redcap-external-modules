<?php
namespace ExternalModules;
require_once dirname(__FILE__) . '/../../classes/ExternalModules.php';

if(!ExternalModules::areTablesPresent()){
	echo 'Before using External Modules, you must run the following sql to create the appropriate tables:<br><br>';
	echo '<textarea style="width: 100%; height: 300px">' . htmlspecialchars(file_get_contents(__DIR__ . '/../../sql/create tables.sql')) . '</textarea>';
	return;
}

$pid = $_GET['pid'];
?>

<h3>Enabled Modules</h3>

<table id='external-modules-enabled' class="table">
	<?php

	$versionsByPrefix = ExternalModules::getEnabledModules();
	$configsByPrefix = array();

	if (empty($versionsByPrefix)) {
		echo 'None';
	} else {
		foreach ($versionsByPrefix as $prefix => $version) {
			$config = ExternalModules::getConfig($prefix, $version,$pid);
			$configsByPrefix[$prefix] = $config;
			?>
			<tr data-module='<?= $prefix ?>'>
				<td><?= $config['name'] . ' - ' . $version ?></td>
				<td class="external-modules-action-buttons">
					<button class='external-modules-configure-button'>Configure</button>
					<?php if (!isset($pid)) { ?>
						<button class='external-modules-disable-button'>Disable</button>
					<?php } ?>
				</td>
			</tr>
			<?php
		}
	}

	?>
</table>

<?php
// JSON_PARTIAL_OUTPUT_ON_ERROR was added here to fix an odd conflict between field-list and form-list types
// and some Hebrew characters on the "Israel: Healthcare Personnel (Hebrew)" project that could not be json_encoded.
// This workaround allows configs to be encoded anyway, even though the unencodable characters will be excluded
// (causing form-list and field-list to not work for any fields with unencodeable characters).
// I spent a couple of hours trying to find a solution, but was unable.  This workaround will have to do for now.
$configsByPrefixJSON = json_encode($configsByPrefix, JSON_PARTIAL_OUTPUT_ON_ERROR);
if($configsByPrefixJSON == null){
	echo '<script>alert(' . json_encode('An error occurred while converting the configurations to JSON: ' . json_last_error_msg()) . ');</script>';
	die();
}
?>

<script>
	$(function(){
		var configsByPrefix = <?=$configsByPrefixJSON?>;
		var pid = <?=json_encode($pid)?>;
		var configureModal = $('#external-modules-configure-modal');
		var isSuperUser = <?=json_encode(SUPER_USER == 1)?>;

		var getSelectElement = function(name, choices, selectedValue, selectAttributes){
			if(!selectAttributes){
				selectAttributes = '';
			}

			var optionsHtml = '';
			for(var i in choices ){
				var choice = choices[i];
				var value = choice.value;

				var optionAttributes = ''
				if(value == selectedValue){
					optionAttributes += 'selected'
				}

				optionsHtml += '<option value="' + getAttributeValueHtml(value) + '" ' + optionAttributes + '>' + choice.name + '</option>';
			}

			return '<select name="' + name + '" ' + selectAttributes + '>' + optionsHtml + '</select>';
		};

		var getInputElement = function(type, name, value, inputAttributes){
                        if (typeof value == "undefined") {
                                value = "";
                        }
			return '<input type="' + type + '" name="' + name + '" value="' + getAttributeValueHtml(value) + '" ' + inputAttributes + '>';
		};

		var getSettingColumns = function(setting, inputAttributes, i){
			var html = "<td><label>" + setting.name + ":</label></td>";

			var type = setting.type;
			var key = setting.key
			var value = setting.value
                        if (typeof i != "undefined") {
                                // for looping for repeatable elements
                                value = value[i];
                                if (i > 0) {
                                        key = key + "____" + i;
                                }
                        }

			var inputHtml;
			if(type == 'dropdown'){
				inputHtml = getSelectElement(key, setting.choices, value, inputAttributes);
			}
			else if(type == 'field-list'){
				inputHtml = getSelectElement(key, setting.choices, value, inputAttributes);
			}
			else if(type == 'form-list'){
				inputHtml = getSelectElement(key, setting.choices, value, inputAttributes);
			}
			else if(type == 'project-id'){
				inputAttributes += ' class="project_id_textbox" id="test-id"';
				inputHtml = "<div style='width:200px'>" + getSelectElement(key, setting.choices, value, inputAttributes) + "</div>";
			}
			else if(type == 'radio'){
				inputHtml = "";
				for(var i in setting.choices ){
					var choice = setting.choices[i];

					var checked = ''
					if(choice.value == value){
						checked += ' checked';
					}

					inputHtml += getInputElement(type, key, choice.value, inputAttributes + checked) + '<label>' + choice.name + '</label><br>';
				}
			}
			else{
				if(type == 'checkbox' && value == 1){
					inputAttributes += ' checked';
				}

				inputHtml = getInputElement(type, key, value, inputAttributes);
			}

			html += "<td>" + inputHtml + "</td>";

                        if (setting.repeatable) {
                                // fill with + and - buttons and hide when appropriate
                                // set original sign for first item when + is not displayed

                                html += "<td class='external-modules-add-remove-column'>";
                                var hasShowingButton = false;

                                if ((typeof setting.value == "undefined") ||  (typeof i == "undefined") || (i + 1 >=  setting.value.length)) { 
                                        html += "<button class='external-modules-add-instance' >+</button>";
                                        hasShowingButton = true;
                                } else {
                                        html += "<button class='external-modules-add-instance' style='display: none;'>+</button>";
                                }

                                if ((typeof i != "undefined") && (i > 0)) {
                                        html += "<button class='external-modules-remove-instance'>-</button>";
                                        hasShowingButton = true;
                                } else {
                                        html += "<button class='external-modules-remove-instance' style='display: none;' >-</button>";
                                }

                                if (!hasShowingButton && (typeof i != "undefined") && (i === 0)) {
                                        html += "<span class='external-modules-original-instance'>original</span>";
                                } else {
                                        html += "<span class='external-modules-original-instance' style='display: none;'>original</span>";
                                }

                                html += "</td>";
                        } else {
                                html += "<td></td>";
                        }

			return html;
		};

		var getGlobalSettingColumns = function(setting){
			var columns = getSettingColumns(setting, '');

			if(setting['allow-project-overrides']){
				var overrideChoices = [
					{ value: '', name: 'Superusers Only' },
					{ value: '<?=ExternalModules::OVERRIDE_PERMISSION_LEVEL_DESIGN_USERS?>', name: 'Project Design and Setup Users' },
				];
				columns += '<td>' + getSelectElement(setting.overrideLevelKey, overrideChoices, setting.overrideLevelValue) + '</td>';
			}
			else{
				columns += '<td></td>';
			}

			return columns;
		};

		var getAttributeValueHtml = function(s){
			if(typeof s == 'string'){
				s = s.replace(/"/g, '&quot;');
				s = s.replace(/'/g, '&apos;');
			}

			return s
		}

		var getProjectSettingColumns = function(setting, global, i){
			var setting = $.extend({}, setting);
			var projectName = setting['project-name'];
			if(projectName){
			        setting.name = projectName;
			}

			var inputAttributes = '';
			var overrideCheckboxAttributes = 'data-global-value="' + getAttributeValueHtml(setting.globalValue) + '"';

			if(global && setting.value == setting.globalValue){
				inputAttributes += ' disabled';
			}
			else{
				overrideCheckboxAttributes += ' checked';
			}

			var columns = getSettingColumns(setting, inputAttributes, i);

			if(global){
				columns += '<td class="external-modules-override-column"><input type="checkbox" class="override-global-setting" ' + overrideCheckboxAttributes + '></td>';
			}
			else{
				columns += '<td></td>';
			}

			return columns;
		};

		var shouldShowSettingOnProjectManagementPage = function(setting, global) {
			if(!global){
				// Always show project level settings.
				return true;
			}

			if(setting.overrideLevelValue == null && !isSuperUser){
				// Hide this setting since the override level will prevent the non-superuser from actually saving it.
				return false;
			}

			// Checking whether a global setting is actually overridden is necessary for the UI to reflect when
			// settings are overridden prior to allow-project-overrides being set to false.
			var alreadyOverridden = setting.value != setting.globalValue;

			return setting['allow-project-overrides'] || alreadyOverridden;
		}

		var getSettingRows = function(global, configSettings, savedSettings){
			var rowsHtml = '';

			configSettings.forEach(function(setting){
				var setting = $.extend({}, setting);
				var saved = savedSettings[setting.key];
				if(saved){
					setting.value = saved.value;
					setting.globalValue = saved.global_value;
				}

				setting.overrideLevelKey = setting.key + '<?=ExternalModules::OVERRIDE_PERMISSION_LEVEL_SUFFIX?>';
				var overrideLevel = savedSettings[setting.overrideLevelKey];
				if(overrideLevel){
					setting.overrideLevelValue = overrideLevel.value
				}

				if(!pid){
					rowsHtml += '<tr>' + getGlobalSettingColumns(setting) + '</tr>';
				}
				else if(shouldShowSettingOnProjectManagementPage(setting, global)){
                                        if (setting.repeatable && (Object.prototype.toString.call(setting.value) === '[object Array]')) {
                                                for (var i=0; i < setting.value.length; i++) {
					                rowsHtml += '<tr>' + getProjectSettingColumns(setting, global, i) + '</tr>';
                                                }
                                        } else {
					        rowsHtml += '<tr>' + getProjectSettingColumns(setting, global) + '</tr>';
                                        }
				}
			})

			return rowsHtml;
		};

		$('#external-modules-configure-modal').on('click', '.external-modules-add-instance', function(){
                        // RULE: first variable is base name (e.g., survey_name)
                        // second and following variables are base name + ____X, where X is a 0-based name
                        // so survey_name____1 is the second variable; survey_name____2 is the third variable; etc.

                        // find the name of the variable on this row, which is the old variable
                        var oldName = $(this).closest('tr').find('input').attr('name');
                        if (!oldName) {
                                oldName = $(this).closest('tr').find('select').attr('name');
                        }

                        // make a new variable name for the new variable
                        var idx = 1;
                        var newName = oldName + "____"+idx;   // default: guess that this is the second variable
                        var ary;
                        if (ary = oldName.match(/____(\d+)$/)) {
                                // transfer number (old + 1)
                                idx = Number(ary[1]) + 1;
                                newName = oldName.replace("____"+ary[1], "____"+idx);
                        }
			var $newInstance = $(this).closest('tr').clone();
                        $newInstance.insertAfter($(this).closest('tr'));

                        // rename new instance of input/select and set value to empty string
                        $newInstance.find('[name="'+oldName+'"]').attr('name', newName);
                        $newInstance.find('[name="'+newName+'"]').val('');

                        // show only last +
                        $(this).hide();
                        // show original sign if previous was first item
                        if (!oldName.match(/____/)) {
                                        $("[name='"+oldName+"']").closest("tr").find(".external-modules-original-instance").show();
                        }
                        $newInstance.find(".external-modules-remove-instance").show();
                });

		$('#external-modules-configure-modal').on('click', '.external-modules-remove-instance', function(){
                        // see RULE on external-modules-add-instance
                        // we must maintain said RULE here
                        // RULE 2: Cannot remove first item

                        // get old name
                        var oldName = $(this).closest('tr').find('input').attr('name');
                        if (!oldName) {
                                oldName = $(this).closest('tr').find('select').attr('name');
                        }

                        // this oldName will have a ____ in it; split and conquer
                        var oldNameParts = oldName.split(/____/);
                        var baseName = oldNameParts[0];

                        var i = 1;
                        var j = 1;
                        while ($("[name='"+baseName+"____"+i+"']").length) {
                                if (i == oldNameParts[1]) {
                                        // remove tr
                                        $("[name='"+baseName+"____"+i+"']").closest('tr').remove();
                                } else {
                                        // rename tr: i --> j
                                        $("[name='"+baseName+"____"+i+"']").attr('name', baseName+"____"+j);
                                        j++;
                                }
                                i++;
                        }
                        if (j > 1) {
                                $("[name='"+baseName+"____"+(j-1)+"']").closest("tr").find(".external-modules-add-instance").show();
                        } else {
                                $("[name='"+baseName+"']").closest("tr").find(".external-modules-add-instance").show();
                                $("[name='"+baseName+"']").closest("tr").find(".external-modules-original-instance").hide();
                        }
                });

		$('#external-modules-enabled').on('click', '.external-modules-configure-button', function(){
			var moduleDirectoryPrefix = $(this).closest('tr').data('module');
			configureModal.data('module', moduleDirectoryPrefix);

			var config = configsByPrefix[moduleDirectoryPrefix];
			configureModal.find('.module-name').html(config.name);
			var tbody = configureModal.find('tbody');
			tbody.html('');
			configureModal.modal('show');

			$.post('ajax/get-settings.php', {pid: pid, moduleDirectoryPrefix: moduleDirectoryPrefix}, function(data){
				if(data.status != 'success'){
					return;
				}

				var savedSettings = data.settings;

				var settingsHtml = "";
				settingsHtml += getSettingRows(true, config['global-settings'], savedSettings);

				if(pid) {
					settingsHtml += getSettingRows(false, config['project-settings'], savedSettings);
				}

				tbody.html(settingsHtml);

				configureSettings(config['global-settings'], savedSettings);
			});
		});

		configureModal.on('click', '.override-global-setting', function(){
			var overrideCheckbox = $(this);
			var globalValue = overrideCheckbox.data('global-value');
			var inputs = overrideCheckbox.closest('tr').find('td:nth-child(2)').find('input, select');

			if(overrideCheckbox.prop('checked')){
				inputs.prop('disabled', false);
			}
			else{
				var type = inputs[0].type;
				if(type == 'radio'){
					inputs.filter('[value=' + globalValue + ']').click();
				}
				else if(type == 'checkbox'){
					inputs.prop('checked', globalValue);
				}
				else{ // text or select
					inputs.val(globalValue);
				}

				inputs.prop('disabled', true);
			}
		});

		configureModal.on('click', 'button.save', function(){
			configureModal.hide();
			var moduleDirectoryPrefix = configureModal.data('module');

			var data = {};

			configureModal.find('input, select').each(function(index, element){
				var element = $(element);
				var globalValue = element.closest('tr').find('.override-global-setting').data('global-value');
				var name = element.attr('name');
				var type = element[0].type;

				if(!name || (type == 'radio' && !element.is(':checked'))){
					return;
				}

				var value;
				if(type == 'checkbox'){
					if(element.prop('checked')){
						value = '1';
					}
					else{
						value = '0';
					}
				}
				else{
					value = element.val();
				}

				if(value == globalValue){
					value = '';
				}

				data[name] = value;
			});

			var pidString = pid;
			if(pid == null){
				pidString = '';
			}

			$.post('ajax/save-settings.php?pid=' + pidString + '&moduleDirectoryPrefix=' + moduleDirectoryPrefix, data, function(data){
				if(data.status != 'success'){
					alert('An error occurred while saving settings: ' + data);
					configureModal.show();
					return;
				}

                                if (data.keys) {
                                        alert(data.keys);
                                }

				// Reload the page reload after saving settings, in case a settings affects some page behavior (like which menu items are visible).
				location.reload();
			});
		});
	});
</script>
