	$(function(){
		var configureModal = $('#external-modules-configure-modal');

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
			if (type == "file") {
				if (pid) {
					return getProjectFileFieldElement(name, value, inputAttributes);
				} else {
					return getGlobalFileFieldElement(name, value, inputAttributes);
				}
			} else {
				return '<input type="' + type + '" name="' + name + '" value="' + getAttributeValueHtml(value) + '" ' + inputAttributes + '>';
			}
		};

		// abstracted because file fields need to be reset in multiple places
		var getGlobalFileFieldElement = function(name, value, inputAttributes) {
			return getFileFieldElement(name, value, inputAttributes, "");
		}

		// abstracted because file fields need to be reset in multiple places
		var getProjectFileFieldElement = function(name, value, inputAttributes) {
			return getFileFieldElement(name, value, inputAttributes, "pid=" + pidString);
		}

		// abstracted because file fields need to be reset in multiple places
		var getFileFieldElement = function(name, value, inputAttributes, pidString) {
			var type = "file";
			if ((typeof value != "undefined") && (value !== "")) {
				var html = '<input type="hidden" name="' + name + '" value="' + getAttributeValueHtml(value) + '" >';
                                html += '<span class="external-modules-edoc-file"></span>';
                                html += '<button class="external-modules-delete-file" '+inputAttributes+'>Delete File</button>';
                                $.post('ajax/get-edoc-name.php?' + pidString, { edoc : value }, function(data) {
                                        $("[name='"+name+"']").closest("tr").find(".external-modules-edoc-file").html("<b>" + data.doc_name + "</b><br>");
                                });
                                return html;
			} else {
				return '<input type="' + type + '" name="' + name + '" value="' + getAttributeValueHtml(value) + '" ' + inputAttributes + '>';
			}
		}

		var getSettingColumns = function(setting, inputAttributes, instance){
			var instanceLabel = "";
			if (typeof instance != "undefined") {
				instanceLabel = (instance+1)+". ";
			}
			var html = "<td><span class='external-modules-instance-label'>"+instanceLabel+"</span><label>" + setting.name + ":</label></td>";

			var type = setting.type;
			var key = setting.key
			var value = setting.value
			if (typeof instance != "undefined") {
				// for looping for repeatable elements
				value = value[instance];
				if (instance > 0) {
					key = key + "____" + instance;
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
					if(choice.value == value) {
						checked += ' checked';
					}

					inputHtml += getInputElement(type, key, choice.value, inputAttributes + checked) + '<label>' + choice.name + '</label><br>';
				}
			} else {
				if(type == 'checkbox' && value == 1){
					inputAttributes += ' checked';
				}
				// TODO Is this only triggered when a project is overriding the global value, but now allow-project-overrides is disabled?
				var alreadyOverridden = setting.value != setting.globalValue;
				if ((type == 'file') && (!setting['allow-project-overrides'] && alreadyOverridden)) {
					inputAttributes += "disabled";
				}

				inputHtml = getInputElement(type, key, value, inputAttributes);
			}

			html += "<td>" + inputHtml + "</td>";

			// no repeatable files allowed
			if (setting.repeatable && (type != "file")) {
				// fill with + and - buttons and hide when appropriate
				// set original sign for first item when + is not displayed
				var addButtonStyle = " style='display: none;'";
				var removeButtonStyle = " style='display: none;'";
				var originalTagStyle = " style='display: none;'";

				if ((typeof setting.value == "undefined") ||  (typeof instance == "undefined") || (instance + 1 >=  setting.value.length)) {
					addButtonStyle = "";
				}

				if ((typeof instance != "undefined") && (instance > 0)) {
					removeButtonStyle = "";
				}

				if ((addButtonStyle == "") && (removeButtonStyle == "") && (typeof instance != "undefined") && (instance === 0)) {
					originalTagStyle = "";
				}

				html += "<td class='external-modules-add-remove-column'>";
				html += "<button class='external-modules-add-instance'" + addButtonStyle + ">+</button>";
				html += "<button class='external-modules-remove-instance'" + removeButtonStyle + ">-</button>";
				html += "<span class='external-modules-original-instance'" + originalTagStyle + ">original</span>";
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
					{ value: '<?=ExternalModules::OVERRIDE_PERMISSION_LEVEL_DESIGN_USERS?>', name: 'Project Admins' },
				];

				var selectAttributes = '';
				if(setting.key == '<?=ExternalModules::KEY_ENABLED?>'){
					// For now, we've decided that only super users can enable modules on projects.
					// To enforce this, we disable this override dropdown for ExternalModules::KEY_ENABLED.
					selectAttributes = 'disabled'
				}

				columns += '<td>' + getSelectElement(setting.overrideLevelKey, overrideChoices, setting.overrideLevelValue, selectAttributes) + '</td>';
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

			if (typeof s == "undefined") {
				s = "";
			}

			return s;
		}

		var getProjectSettingColumns = function(setting, global, instance){
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

			var columns = getSettingColumns(setting, inputAttributes, instance);

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
						for (var instance=0; instance < setting.value.length; instance++) {
							rowsHtml += '<tr>' + getProjectSettingColumns(setting, global, instance) + '</tr>';
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

			// rename label
			$newInstance.closest("tr").find('span.external-modules-instance-label').html((idx+1)+". ");
			$(this).closest("tr").find('span.external-modules-instance-label').html((idx)+". ");

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
					// rename label
					$("[name='"+baseName+"____"+i+"']").closest("tr").find('span.external-modules-instance-label').html((j+1)+". ");
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

			$.post('ajax/get-settings.php', {pid: pidString, moduleDirectoryPrefix: moduleDirectoryPrefix}, function(data){
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

				ExternalModules.configureSettings(config['global-settings'], savedSettings);
			});
		});

		configureModal.on('click', '.external-modules-delete-file', function() {
			var moduleDirectoryPrefix = configureModal.data('module');

			var row = $(this).closest("tr");
			var input = row.find("input[type=hidden]");
			var disabled = input.prop("disabled");
			$(this).hide();

			$.post("ajax/delete-file.php?pid="+pidString, { moduleDirectoryPrefix: moduleDirectoryPrefix, key: input.attr('name'), edoc: input.val() }, function(data) { 
				if (data.status == "success") {
					var inputAttributes = "";
					if (disabled) {
						inputAttributes = "disabled";
					}
					row.find(".external-modules-edoc-file").html(getProjectFileFieldElement(input.attr('name'), "", inputAttributes));
					input.remove();
				} else {		// failure
					alert("The file was not able to be deleted. "+JSON.stringify(data));
				}
			});
		});

		configureModal.on('click', '.override-global-setting', function(){
			var overrideCheckbox = $(this);
			var globalValue = overrideCheckbox.data('global-value');
			var inputs = overrideCheckbox.closest('tr').find('td:nth-child(2)').find('input, select');

			if(overrideCheckbox.prop('checked')){
				inputs.prop('disabled', false);
				inputs.closest("tr").find(".external-modules-delete-file").prop("disabled", false);
				resetSaveButton();
			}
			else{
				var type = inputs[0].type;
				if(type == 'radio'){
					inputs.filter('[value=' + globalValue + ']').click();
				}
				else if(type == 'checkbox'){
					inputs.prop('checked', globalValue);
				}
				else if((type == 'hidden') && (inputs.closest("tr").find(".external-modules-edoc-file").length > 0)) {   // file
					inputs.closest("td").html(getGlobalFileFieldElement(inputs.attr('name'), globalValue, "disabled"));
					resetSaveButton();
 				}
				else{ // text or select
					inputs.val(globalValue);
				}

				inputs.prop('disabled', true);
			}
		});

		var resetSaveButton = function() {
			if ($(this).val() != "") {
				$(".save").html("Save and Upload");
			}
			var allEmpty = true;
			$("input[type=file]").each(function() {
				if ($(this).val() !== "") {
					allEmpty = false;
				}
			});
			if (allEmpty) {
				$(".save").html("Save");
			}
		}

		configureModal.on('change', 'input[type=file]', resetSaveButton);

		// helper method for saving
		var saveFilesIfTheyExist = function(url, files, callbackWithNoArgs) {
			var lengthOfFiles = 0;
			var formData = new FormData();
			for (var name in files) {
				lengthOfFiles++;
				formData.append(name, files[name]);   // filename agnostic
			}
			if (lengthOfFiles > 0) {
				// AJAX rather than $.post
				$.ajax({
					url: url,
					data: formData,
					processData: false,
					contentType: false,
					async: false,
					type: 'POST',
					success: function(returnData) {
						if (returnData.status != 'success') {
							alert("One or more of the files could not be saved. "+JSON.stringify(data));
						}

						// proceed anyways to save data
						callbackWithNoArgs();
					},
					error: function(e) {
						alert("One or more of the files could not be saved. "+JSON.stringify(e));
						callbackWithNoArgs();
					}
				});
			} else {
				callbackWithNoArgs();
			}
		}

		// helper method for saving
		var saveSettings = function(pidString, moduleDirectoryPrefix, version, data) {
			$.post('ajax/save-settings.php?pid=' + pidString + '&moduleDirectoryPrefix=' + moduleDirectoryPrefix + "&moduleDirectoryVersion=" + version, data, function(returnData){
				if(returnData.status != 'success'){
					alert('An error occurred while saving settings: ' + returnData);
					configureModal.show();
					return;
				}

				// Reload the page reload after saving settings,
				// in case a settings affects some page behavior (like which menu items are visible).
				location.reload();
			});
		}

		configureModal.on('click', 'button.save', function(){
			configureModal.hide();
			var moduleDirectoryPrefix = configureModal.data('module');
			var version = versionsByPrefix[moduleDirectoryPrefix];

			var data = {};
                        var files = {};

			configureModal.find('input, select').each(function(index, element){
				var element = $(element);
				var globalValue = element.closest('tr').find('.override-global-setting').data('global-value');
				var name = element.attr('name');
				var type = element[0].type;

				if(!name || (type == 'radio' && !element.is(':checked'))){
					return;
				}

				if (type == 'file') {
					// only store one file per variable - the first file
					jQuery.each(element[0].files, function(i, file) {
						if (typeof files[name] == "undefined") {
							files[name] = file;
						}
					});
				} else {
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
				}
			});

			var url = 'ajax/save-file.php?pid=' + pidString +
						'&moduleDirectoryPrefix=' + moduleDirectoryPrefix +
						'&moduleDirectoryVersion=' + version;
                        saveFilesIfTheyExist(url, files, function() {
				saveSettings(pidString, moduleDirectoryPrefix, version, data);
			});
		});
	});