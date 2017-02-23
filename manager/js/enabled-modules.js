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

			return '<select class="external-modules-input-element" name="' + name + '" ' + selectAttributes + '>' + optionsHtml + '</select>';
		};

		var getInputElement = function(type, name, value, inputAttributes){
			if (typeof value == "undefined") {
				value = "";
			}
			if (type == "file") {
				if (pid) {
					return getProjectFileFieldElement(name, value, inputAttributes);
				} else {
					return getSystemFileFieldElement(name, value, inputAttributes);
				}
			} else {
				return '<input class="external-modules-input-element" type="' + type + '" name="' + name + '" value="' + getAttributeValueHtml(value) + '" ' + inputAttributes + '>';
			}
		};

		// abstracted because file fields need to be reset in multiple places
		var getSystemFileFieldElement = function(name, value, inputAttributes) {
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
				var htmlInputElement = '<input type="hidden" name="' + name + '" value="' + getAttributeValueHtml(value) + '" >';
                                var html = htmlInputElement + '<span class="external-modules-edoc-file"></span>';
                                $.post('ajax/get-edoc-name.php?' + pidString, { edoc : value }, function(data) {
					var htmlNew = "<b>" + data.doc_name + "</b><br>";
                                	htmlNew += '<button class="external-modules-delete-file" '+inputAttributes+'>Delete File</button>';
                                        var row = $("[name='"+name+"']").closest("tr");
					row.find(".external-modules-edoc-file").html(htmlNew);
                                });
                                return html;
			} else {
				return '<input class="external-modules-input-element" type="' + type + '" name="' + name + '" value="' + getAttributeValueHtml(value) + '" ' + inputAttributes + '>';
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
				// TODO Is this only triggered when a project is overriding the system value, but now allow-project-overrides is disabled?
				var alreadyOverridden = setting.value != setting.systemValue;
				if ((type == 'file') && (!setting['allow-project-overrides'] && alreadyOverridden)) {
					inputAttributes += "disabled";
				}

				inputHtml = getInputElement(type, key, value, inputAttributes);
			}

			html += "<td class='external-modules-input-td'>" + inputHtml + "</td>";

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

		var getSystemSettingColumns = function(setting){
			var columns = getSettingColumns(setting, '');

			if(setting['allow-project-overrides']){
				var overrideChoices = [
					{ value: '', name: 'Superusers Only' },
					{ value: override, name: 'Project Admins' },
				];

				var selectAttributes = '';
				if(setting.key == enabled){
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

		var getProjectSettingColumns = function(setting, system, instance){
			var setting = $.extend({}, setting);
			var projectName = setting['project-name'];
			if(projectName){
				setting.name = projectName;
			}

			var inputAttributes = '';
			var overrideButtonAttributes = 'data-system-value="' + getAttributeValueHtml(setting.systemValue) + '"';

			if(system && (setting.type == "checkbox")) {
				if (setting.value == "false") {
					setting.value = 0;
				}
				if (setting.systemValue == "false") {
					setting.systemValue = 0;
				} 
			}
			if(system && (setting.value == setting.systemValue)){
				overrideButtonAttributes += " style='display: none;'";
			}

			if (((setting.value == "true") || (setting.value == 1)) && (setting.type == "checkbox")) {
				inputAttributes += " checked";
			}

			var columns = getSettingColumns(setting, inputAttributes, instance);

			if(system){
				columns += "<td style='width: 50px'><div style='min-height: 50px;'><button "+overrideButtonAttributes+" class='external-modules-use-system-setting'>Use<br>System<br>Setting</button></div></td>";
			}
			else{
				columns += '<td></td>';
			}

			return columns;
		};

		var shouldShowSettingOnProjectManagementPage = function(setting, system) {
			if(!system){
				// Always show project level settings.
				return true;
			}

			if(setting.overrideLevelValue == null && !isSuperUser){
				// Hide this setting since the override level will prevent the non-superuser from actually saving it.
				return false;
			}

			// Checking whether a system setting is actually overridden is necessary for the UI to reflect when
			// settings are overridden prior to allow-project-overrides being set to false.
			var alreadyOverridden = setting.value != setting.systemValue;

			return setting['allow-project-overrides'] || alreadyOverridden;
		}

		var getSettingRows = function(system, configSettings, savedSettings){
			var rowsHtml = '';

			configSettings.forEach(function(setting){
				var setting = $.extend({}, setting);
				var saved = savedSettings[setting.key];
				if(saved){
					setting.value = saved.value;
					setting.systemValue = saved.system_value;
				}

				setting.overrideLevelKey = setting.key + overrideSuffix;
				var overrideLevel = savedSettings[setting.overrideLevelKey];
				if(overrideLevel){
					setting.overrideLevelValue = overrideLevel.value
				}


				if(!pid){
					rowsHtml += '<tr>' + getSystemSettingColumns(setting) + '</tr>';
				}
				else if(shouldShowSettingOnProjectManagementPage(setting, system)){
					if (setting.repeatable && (Object.prototype.toString.call(setting.value) === '[object Array]')) {
						for (var instance=0; instance < setting.value.length; instance++) {
							rowsHtml += '<tr>' + getProjectSettingColumns(setting, system, instance) + '</tr>';
						}
					} else {
						rowsHtml += '<tr>' + getProjectSettingColumns(setting, system) + '</tr>';
					}
				}
			})

			return rowsHtml;
		};

		var onValueChange = function() {
			var val;
			if ($(this).type == "checkbox") {
				val = $(this).is(":checked");
			} else {
				val = $(this).val();
			}
			var overrideButton = $(this).closest('tr').find('button.external-modules-use-system-setting');
			if (overrideButton) {
				var systemValue = overrideButton.data('system-value');
				if (typeof systemValue != "undefined") {
					if (systemValue == val) {
						overrideButton.hide();
					} else {
						overrideButton.show();
					}
				}
			}
		};

		$('#external-modules-configure-modal').on('change', '.external-modules-input-element', onValueChange);
		$('#external-modules-configure-modal').on('check', '.external-modules-input-element', onValueChange);

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
				settingsHtml += getSettingRows(true, config['system-settings'], savedSettings);

				if(pid) {
					settingsHtml += getSettingRows(false, config['project-settings'], savedSettings);
				}

				tbody.html(settingsHtml);

				configureSettings(config['system-settings'], savedSettings);
			});
		});

		var deleteFile = function(ob) {
			var moduleDirectoryPrefix = configureModal.data('module');

			var row = ob.closest("tr");
			var input = row.find("input[type=hidden]");
			var disabled = input.prop("disabled");
			var deleteFileButton = row.find("button.external-modules-delete-file");
			if (deleteFileButton) {
				deleteFileButton.hide();
			}

			$.post("ajax/delete-file.php?pid="+pidString, { moduleDirectoryPrefix: moduleDirectoryPrefix, key: input.attr('name'), edoc: input.val() }, function(data) { 
				alert(JSON.stringify(data));
				if (data.status == "success") {
					var inputAttributes = "";
					if (disabled) {
						inputAttributes = "disabled";
					}
					row.find(".external-modules-input-td").html(getProjectFileFieldElement(input.attr('name'), "", inputAttributes));
					input.remove();
				} else {		// failure
					alert("The file was not able to be deleted. "+JSON.stringify(data));
				}

				var overrideButton = row.find("button.external-modules-use-system-setting");
				var systemValue = overrideButton.data("system-value");

				if (systemValue != "") {    // compare to new value
					overrideButton.show();
				} else {
					overrideButton.hide();
				}
			});
		};
		configureModal.on('click', '.external-modules-delete-file', function() {
			deleteFile($(this));
		});

		var resetSaveButton = function(val) {
			if (val != "") {
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
		};

		configureModal.on('click', '.external-modules-use-system-setting', function(){
			var overrideButton = $(this);
			var systemValue = overrideButton.data('system-value');
			var row = overrideButton.closest('tr');
			var inputs = row.find('td:nth-child(2)').find('input, select');

			var type = inputs[0].type;
			if(type == 'radio'){
				inputs.filter('[value=' + systemValue + ']').click();
			}
			else if(type == 'checkbox'){
				inputs.prop('checked', systemValue);
			}
			else if((type == 'hidden') && (inputs.closest("tr").find(".external-modules-edoc-file").length > 0)) {   // file
				deleteFile($(this));
				resetSaveButton("");
 			}
			else if(type == 'file') {
				// if a real value
				if (!isNaN(systemValue)) {
					var edocLine = row.find(".external-modules-input-td");
					if (edocLine) {
						var inputAttributes = "";
						if (inputs.prop("disabled")) {
							inputAttributes = "disabled";
						}
						edocLine.html(getSystemFileFieldElement(inputs.attr('name'), systemValue, inputAttributes));
						resetSaveButton(systemValue);
						row.find(".external-modules-delete-file").show();
					}
				}
			}
			else{ // text or select
				inputs.val(systemValue);
			}
			overrideButton.hide();
		});

		configureModal.on('change', 'input[type=file]', function() { resetSaveButton($(this).val()); });

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
							alert("One or more of the files could not be saved. "+JSON.stringify(returnData));
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
				var systemValue = element.closest('tr').find('.override-system-setting').data('system-value');
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
	
					if(value == systemValue){
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