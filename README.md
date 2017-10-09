**PULL REQUESTS:** Please create pull requests against the **testing** branch (as opposed to **master**).

# REDCap External Modules
Development work for REDCap External Modules/Packages to support a standardized Hook/Plugin framework and management mechanism

## Installation
Clone this repo into to an **external_modules** directory under your REDCap web root (e.g., /redcap/external_modules/).

## Usage

The best way to get started is to download the example module from here:
https://github.com/mmcev106/redcap-external-module-example

It can be installed by downloading that repo as a ZIP file, then extracting it's contents to ```<redcap-web-root>/modules/vanderbilt_example_v1.0```

Here are a few details on managing modules:

* Once installed, modules can be enabled under the **Manage External Modules** link under **Control Center**.
* Enabling a module under **Control Center** does not enable it on any projects by default.
* To enable a module on a specific project, go to the **Manage External Modules** link on the project homepage, click **Search for Additional Module(s)**, and click **Enable** next to the desired module name. Then to configure a module, click **Configure** next to the module's name.
* To enable a module on ALL projects by default, go to the **Manage External Modules** link under **Control Center**, click **Configure** next to the module name, check the **Enable on all projects by default** checkbox, then click save.

The only setting that actually does anything in the example module is the **Project Menu Background CSS** setting.  This will change the background color of the menu on project pages, and is a great demo of simple hook usage, and how a setting can be set systemwide and/or overridden per project.


## AbstractExternalModule

The **AbstractExternalModule** class must be extended when creating an external module.  Module creators may make use of the following methods to store and manage settings for their module.  This includes both settings set via the **Manage External Modules** interface, as well as any other data the module creator wants to store:

Method  | Description
------- | -----------
createDAG($name) | Creates a DAG with the specified name, and returns it's ID.
delayModuleExecution() | pushes the execution of the module to the end of the queue; helpful to wait for data to be processed by other modules; execution of the module will be restarted from the beginning
disableUserBasedSettingPermissions() | By default an exception will be thrown if a set/remove setting method is called and the current user doesn't have access to change that setting.  Call this method in a module's constructor to disable this behavior and leave settings permission checking up to the module itself.
getChoiceLabel($fieldName, $value[, $pid]) | Get the label associated with the specified choice value for a particular field.
getChoiceLabels($fieldName[, $pid]) | Returns an array mapping all choice values to labels for the specified field.
getConfig() | get the config for the current External Module; consists of config.json and filled-in values
getModuleDirectoryName() | get the directory name of the current external module
getModuleName() | get the name of the current external module
getProjectSetting($key&nbsp;[,&nbsp;$pid]) | Returns the value stored for the specified key for the current project if it exists.  If this setting key is not set (overriden) for the current project, the systemwide value for this key is returned.  In most cases the project id can be detected automatically, but it can optionaly be specified as the third parameter instead.
getSettingConfig($key) | Returns the configuration for the specified setting.
getSettingKeyPrefix() | This method can be overridden to prefix all setting keys.  This allows for multiple versions of settings depending on contexts defined by the module.
getSubSettings($key) | Returns the sub-settings under the specified key in a user friendly array format.
getSystemSetting($key) | Get the value stored systemwide for the specified key.
getUrl($path [, $noAuth=false [, $useApiEndpoint=false]]) | Get the url to a resource (php page, js/css file, image etc.) at the specified path relative to the module directory. If the $noAuth parameter is set to true, then "&NOAUTH" will be appended to the URL, which disables REDCap's authentication for that PHP page (assuming the link's URL in config.json contains "&NOAUTH"). Also, if you wish to obtain an alternative form of the URL that does not contain the REDCap version directory (e.g., https://example.com/redcap/redcap_vX.X.X/ExternalModules/?id=1&page=index&pid=33), then set $useApiEndpoint=true, which will return a version-less URL using the API end-point (e.g., https://example.com/redcap/api/?id=1&page=index&pid=33). Both links will work identically.
hasPermission($permissionName) | checks whether the current External Module has permission for $permissionName
removeProjectSetting($key&nbsp;[,&nbsp;$pid]) | Remove the value stored for this project and the specified key.  In most cases the project id can be detected automatically, but it can optionaly be specified as the third parameter instead. 
removeSystemSetting($key) | Remove the value stored systemwide for the specified key.
renameDAG($dagId, $name) | Renames the DAG with the given ID to the specified name.
setDAG($record, $dagId) | Sets the DAG for the given record ID to given DAG ID.
setData($record, $fieldName, $values) | Sets the data for the given record and field name to the specified value or array of values.
setProjectSetting($key,&nbsp;$value&nbsp;[,&nbsp;$pid]) | Set the setting specified by the key to the specified value for this project (override the systemwide setting).  In most cases the project id can be detected automatically, but it can optionaly be specified as the third parameter instead.
setSystemSetting($key,&nbsp;$value) | Set the setting specified by the key to the specified value systemwide (shared by all projects).


## How to Create an External Module from the Example

External Modules combine the concepts of REDCap Plugins and REDCap Hooks. They consist of hooks and plugin (webpages) that work under configurable settings. Individual Hooks and Plugins are best for projects that need specific and peculiar customization. External Modules are best for general functions that are needed by many projects. Hooks and Plugins can even import External Modules and customize their functionality if everything is configured properly.

1. Copy an existing External Module into a new directory named **<institution>_<module name>_v1.0/**.

1. Change the filename of the ** *ExternalModule.php** file to **<module name>ExternalModule.php** with the first letter capitalized. This will now be called **The External Module File**. This will contain all of the hook information.

1. Change the name of the class **and** the namespace to **<module name>ExternalModule* with the first letter capitalized, just as in changing the directory name. This should extend the AbstractExternalModule class.
	* Enter in your hook name, beginning with hook_ instead of redcap_. Also, enter in your parameters listed in the REDCap documentation. Like
		* **function hook_save_record($project_id, $record, $instrument, $event_id, $group_id)**
	* Fill in all of the hooks like above. You can also add helper methods or reference outside classes.
	* Make note of all of the hooks used. We will have to grant them permission in the configuration JSON.

1. Open the PHP code to any plugin webpages. These will just run as stand-alone webpages within the External Module infrastructure. To access External Module settings, include **../../external_modules/classes/ExternalModules.php** and use the appropriate public functions.

1. Open the **config.json** file. This is a JSON used to specify the External Module.
	* Fill in the **name**
	* Fill in the **description**
	* Fill in the **name** and **email** (and optional **institution**) for authors. At least one email is required to run the module.
	* Grant **permissions** for all of the operations. Start with **hook_...**. For example, **hook_save_record**.
	* **links** specify any links to show up on the left-hand toolbar. These include stand-alone webpages (substitutes for plugins) or links to outside websites. These are listable at the control-center level or at the project level.  A **link** consists of:
		* A **name** to be displayed on the site
		* An **icon** in REDCap's image repository
		* A **url** either in the local directory or external to REDCap.
	* **system-settings** specify settings configurable at the system-wide level (this Control Center). Individual projects can override these settings. 
	* **project-settings** specify settings configurable at the project level, different for each project.  
	* A setting consists of:
		* A **key** that is the unique identifier for the item. Dashes (-'s) are preferred to underscores (_'s).
		* A **name** that is the plain-text label for the identifier. You have to tell your users what they are filling out.
		* **required** is a boolean to specify whether the user has to fill this item out in order to use the module.
		* **type** is the data type. Can be: text
			* json
			* textarea
			* rich-text
			* field-list
			* user-role-list
			* user-list
			* dag-list
			* dropdown
			* checkbox
			* project-id
			* form-list
			* sub_settings
			* radio
			* file
		* **choices** consist of a **value** and a **name** for selecting elements (dropdowns, radios). 
		* **repeatable** is a boolean that specifies whether the element can repeat many times. **If it is repeatable (true), the element will return an array of values.**
		* When type = **sub_settings**, the sub_settings element can specify a group of items that can be repeated as a group if the sub_settings itself is repeatable. The settings within sub_settings follow the same specification here.
			* Repeatable elements of repeatable elements are not allowed. Only one level of repeat is supported.
			* sub_settings of sub_settings are not supported either.
		* As a reminder, true and false are specified as their actual values (true/false not as the strings "true"/"false"). Other than that, all values and variables are strings.
	* If your JSON is not properly specified, an Exception will be thrown.

1. All run-time errors throw Exceptions that generate emails, so be careful.

1. You can use the REDCap class by \REDCap inside of the External Module.

