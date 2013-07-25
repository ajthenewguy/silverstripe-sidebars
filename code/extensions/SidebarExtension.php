<?php
/**
 * Plug-ins for additional functionality in your Sidebar classes.
 *
 * @author Allen
 * @package silverstripe-sidebars
 */
abstract class SidebarExtension extends DataExtension {
	
	/**
	 * Hook called before the page's {@link SiteTree::doPublish()} action is completed
	 * 
	 * @param SiteTree &$original The current Live SiteTree record prior to publish
	 */
	public function onBeforePublish(&$original) {
	}

	/**
	 * Hook called after the page's {@link SiteTree::doPublish()} action is completed
	 * 
	 * @param SiteTree &$original The current Live SiteTree record prior to publish
	 */
	public function onAfterPublish(&$original) {
	}

	/**
	 * Hook called before the page's {@link SiteTree::doUnpublish()} action is completed
	 */
	public function onBeforeUnpublish() {
	}


	/**
	 * Hook called after the page's {@link SiteTree::doUnpublish()} action is completed
	 */
	public function onAfterUnpublish() {
	}
	
	/**
	 * Hook called to determine if a user may publish this Sidebar object
	 * 
	 * @see SiteTree::canPublish()
	 * 
	 * @param Member $member The member to check permission against, or the currently
	 * logged in user
	 * @return boolean|null Return false to deny rights, or null to yield to default
	 */
	public function canPublish($member) {
	}
}

?>
