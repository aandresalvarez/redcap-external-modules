$(function(){
	// first show disabledModal and then show enableModal
	var disabledModal = $('#external-modules-disabled-modal');
	var enableModal = $('#external-modules-enable-modal');

	var reloadThisPage = function(){
		$('<div class="modal-backdrop fade in"></div>').appendTo(document.body);
		window.location.reload();
	}

	disabledModal.find('.disable-button').click(function(event){
		var row = $(event.target).closest('tr');
		var title = row.find('td:eq(0)').text().trim();
		var prefix = row.data('module');
		var version = row.find('[name="version"]').val();		
		simpleDialog("Do you wish to delete the module \"<b>"+title+"</b>\" (<b>"+prefix+"_"+version+"</b>)? "
			+"Doing so will permanently remove the module's directory from the REDCap server.","DELETE MODULE?",null,null,null,"Cancel",function(){
				showProgress(1);
				$.post('ajax/delete-module.php', { module_dir: prefix+'_'+version },function(data){
					showProgress(0,0);
					if (data == '1') {
						simpleDialog("An error occurred because the External Module directory could not be found on the REDCap web server.","ERROR");
					} else if (data == '0') {
						simpleDialog("An error occurred because the External Module directory could not be deleted from the REDCap web server.","ERROR");
					} else {
						$('#external-modules-disabled-modal').hide();
						simpleDialog(data,"SUCCESS",null,null,function(){
							window.location.reload();
						},"Close");
					}
				});
			},"Delete module");
		return false;
	});

	disabledModal.find('.enable-button').click(function(event){
		disabledModal.hide();

		var row = $(event.target).closest('tr');
		var prefix = row.data('module');
		var version = row.find('[name="version"]').val();

		if (!pid) {
			var enableButton = enableModal.find('.enable-button');
			enableButton.html('Enable');
			enableModal.find('button').attr('disabled', false);

			var list = enableModal.find('.modal-body ul');
			list.html('');
			
			var permissionCount = 0;
			disabledModules[prefix][version].permissions.forEach(function(permission){
				if (permission != "") {
					list.append("<li>" + permission + "</li>");
					permissionCount++;
				}
			});
			if (permissionCount == 0) {
				list.append("<li><i>None (no permissions requested)</i></li>");
			}

			enableButton.off('click'); // disable any events attached from other modules
			enableButton.click(function(){
				enableButton.html('Enabling...');
				enableModal.find('button').attr('disabled', true);

				$.post('ajax/enable-module.php', {prefix: prefix, version: version}, function (data) {
					try {
						var jsonAjax = jQuery.parseJSON(data);
						if (typeof jsonAjax == 'object') {
							console.log('a');
							console.log("Message: "+jsonAjax['error_message']);
							if (jsonAjax['error_message'] != "") {
								$('#external-modules-enable-modal-error').show();
								$('#external-modules-enable-modal-error').html(jsonAjax['error_message']);
								$('.close-button').attr('disabled', false);
								enableButton.hide();
							}else if (jsonAjax['message'] == 'success') {
								reloadThisPage();
								disabledModal.modal('hide');
								enableModal.modal('hide');
							}
						}else{
							var message = 'An error occurred while enabling the module: ' + data;
							console.log('AJAX Request Error:', message);
							alert(message);
							enableModal.modal('hide');
						}
					} catch(err) {
						var message = 'An error occurred while enabling the module: ' + data;
						console.log('AJAX Request Error:', message);
						alert(message);
						enableModal.modal('hide');
					}
				});
			});
			enableButton.show();
			enableModal.modal('show');
			return false;
		} else {   // pid
			var enableButton = enableModal.find('.enable-button');
			$.post('ajax/enable-module.php?pid=' + pid, {prefix: prefix, version: version}, function(data){
				try {
					var jsonAjax = jQuery.parseJSON(data);
					if (typeof jsonAjax == 'object') {
						$('#external-modules-enable-modal-error').hide();
						if ((typeof jsonAjax['error_message'] != "undefined") && (jsonAjax['error_message'] != "")) {
							$('#external-modules-enable-modal-error').show();
							$('#external-modules-enable-modal-error').html(jsonAjax['error_message']);
							$('.close-button').attr('disabled', false);
							enableButton.hide();
						}else if (jsonAjax['message'] == 'success') {
							reloadThisPage();
							disabledModal.modal('hide');
							enableModal.modal('hide');
						}
					}else{
						var message = 'An error occurred while enabling the module: ' + jsonAjax;
						console.log('AJAX Request Error:', message);
						alert(message);
						enableModal.modal('hide');
					}
				} catch(err) {
					var message = 'An error occurred while enabling the module: ' + jsonAjax;
					console.log('AJAX Request Error:', message);
					alert(message);
					enableModal.modal('hide');
				}
			});
			return false;
		}
	});

	if (enableModal) {
		enableModal.on('hide.bs.modal', function(){
			if($('#external-modules-disabled-table tr').length === 0){
				// Reload since there aren't any more disabled modules to enable.
				reloadThisPage();
			}
			else{
				disabledModal.show();
			}
		});
	}
});
