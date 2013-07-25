<?php

/**
 * Description of SidebarSiteTreeExtension
 *
 * @author Allen
 */
class SidebarSiteTreeExtension extends DataExtension {
	
	public static $db = array(
		"ShowSidebar" => "Boolean"
	);
	
	public static $has_one = array(
		"Sidebar" => "Sidebar"
	);
	
	
	/**
	 * Add a Sidebar field
	 * 
	 * @param FieldList $fields
	 */
	public function updateCMSFields(FieldList $fields) {
		$fields->addFieldToTab('Root.Main', new DropdownField('Sidebar', 'Sidebar', Sidebar::get()->map()), 'Metadata');
	}
}

?>
