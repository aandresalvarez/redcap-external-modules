<?php

namespace ExternalModules;
require_once dirname(__FILE__) . '/../../classes/ExternalModules.php';

if(empty($versionsByPrefixJSON)) {
    $versionsByPrefixJSON = "''";
}

if(empty($configsByPrefixJSON)) {
    $configsByPrefixJSON = "''";
}


// The decision to use TinyMCE was not taken lightly.  I actually tried integrating Quill, Trix, and Summernote as well, but they either
// didn't work as well out of the box when placed inside the configuration model, or were not as flexible/customizable.
ExternalModules::addResource(ExternalModules::getManagerJSDirectory().'tinymce.min.js');
ExternalModules::addResource(ExternalModules::getManagerCSSDirectory().'select2.css');
ExternalModules::addResource(ExternalModules::getManagerJSDirectory().'select2.js');
ExternalModules::addResource(ExternalModules::getManagerJSDirectory().'globals.js');
?>
<script>
    ExternalModules.PID = <?=json_encode(@$_GET['pid'])?>;
    ExternalModules.SUPER_USER = <?=SUPER_USER?>;
    ExternalModules.KEY_ENABLED = <?=json_encode(ExternalModules::KEY_ENABLED)?>;
    ExternalModules.OVERRIDE_PERMISSION_LEVEL_DESIGN_USERS = <?=json_encode(ExternalModules::OVERRIDE_PERMISSION_LEVEL_DESIGN_USERS)?>;
    ExternalModules.OVERRIDE_PERMISSION_LEVEL_SUFFIX = <?=json_encode(ExternalModules::OVERRIDE_PERMISSION_LEVEL_SUFFIX)?>;
    ExternalModules.BASE_URL = <?=json_encode(ExternalModules::$BASE_URL)?>;
    ExternalModules.configsByPrefixJSON = <?=$configsByPrefixJSON?>;
    ExternalModules.versionsByPrefixJSON = <?=$versionsByPrefixJSON?>;
	ExternalModules.LIB_URL = '<?=APP_URL_EXTMOD_LIB?>login.php?referer=<?=urlencode(APP_URL_EXTMOD)."manager/control_center.php"?>'
		+ '&php_version=<?=urlencode(PHP_VERSION)?>&redcap_version=<?=urlencode(REDCAP_VERSION)?>';
    
    $(function () {
        var disabledModal = $('#external-modules-disabled-modal');
        $('#external-modules-enable-modules-button').click(function(){
            var form = disabledModal.find('.modal-body form');
            var loadingIndicator = $('<div class="loading-indicator"></div>');

            var pid = ExternalModules.PID;
            if (!pid) {
                new Spinner().spin(loadingIndicator[0]);
            }
            form.html('');
            form.append(loadingIndicator);

            // This ajax call was originally written thinking the list of available modules would come from a central repo.
            // It may not be necessary any more.
            var url = "ajax/get-disabled-modules.php";
            if (pid) {
                url += "?pid="+pid;
            }
            $.post(url, { }, function (html) {
                form.html(html);
				// Enable module search
				$('input#disabled-modules-search').quicksearch('table#external-modules-disabled-table tbody tr', {
					selector: 'td:eq(0)'
				});
            });

            disabledModal.modal('show');
        });
        $('#external-modules-download-modules-button').click(function(){
			$('#download-new-mod-form').submit();
		});
        $('#external-modules-add-custom-text-button').click(function(){
			$('#external-modules-custom-text-dialog').dialog({ title: 'Set custom text for Project Module Manager (optional)', bgiframe: true, modal: true, width: 550, 
				buttons: {
					'Cancel': function() {
						$(this).dialog('close'); 
					},
					'Save': function() {
						showProgress(1,0);
						$.post(app_path_webroot+'ControlCenter/set_config_val.php',{ settingName: 'external_modules_project_custom_text', value: $('#external_modules_project_custom_text').val() },function(data){
							showProgress(0,0);
							if (data == '1') {
								simpleDialog("The custom text was successfully saved!","SUCCESS");
							} else {
								alert(woops);
							}
						});
						$(this).dialog('close'); 
					}
				} 
			});
		});
		if (isNumeric(getParameterByName('download_module_id')) && getParameterByName('download_module_name') != '') {
			$('#external-modules-download').dialog({ title: 'Download external module?', bgiframe: true, modal: true, width: 550, 
				buttons: {
					'Cancel': function() {
						modifyURL('<?=PAGE_FULL?>');
						$(this).dialog('close'); 
					},
					'Download': function() {
						showProgress(1);
						$.get('<?=APP_URL_EXTMOD?>manager/ajax/download-module.php?module_id='+getParameterByName('download_module_id'),{},function(data){
							showProgress(0,0);
							if (data == '0') {
								simpleDialog("An error occurred because the External Module could not be found.","ERROR");
							} else if (data == '1') {
								simpleDialog("An error occurred because the External Module zip file could not be written to the REDCap temp directory before extracting it.","ERROR");
							} else if (data == '2' || data == '3') {
								simpleDialog("An error occurred because the External Module zip file could not be extracted or could not create a new modules directory on the REDCap web server.","ERROR");
							} else if (data == '4') {
								simpleDialog("An error occurred because the External Module directory already exists on the REDCap web server. Thus, it cannot be used for this module.","ERROR");
							} else {
								// Append module name to form
								$('#download-new-mod-form').append('<input type="hidden" name="downloaded_modules[]" value="'+getParameterByName('download_module_name')+'">');
								// Success msg
								simpleDialog(data,"SUCCESS",null,null,function(){
									$('#external-modules-enable-modules-button').trigger('click');
								},"Close");
							}
							modifyURL('<?=PAGE_FULL?>');
						});
						$(this).dialog('close'); 
					}
				} 
			});
		}
    });
</script>
