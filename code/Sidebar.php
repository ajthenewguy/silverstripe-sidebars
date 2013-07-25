<?php
/**
 * Sidebar class
 *
 * @author Allen
 * @package silverstripe-sidebars
 */
class Sidebar extends DataObject implements PermissionProvider,i18nEntityProvider {
	
	/**
	 *
	 * @config
	 * @var array 
	 */
	private static $allowed_children = array();
	
	/**
	 *
	 * @config
	 * @var string
	 */
	private static $default_child;
	
	/**
	 *
	 * @config
	 * @var string
	 */
	private static $default_parent = null;
	
	
	private static $db = array(
		"Title" => "Varchar(255)",
		"Content" => "HTMLText",
		"ShowOnSite" => "Boolean",
		"Sort" => "Int",
		"CanViewType" => "Enum('Anyone, LoggedInUsers, OnlyTheseUsers, Inherit', 'Inherit')",
		"CanEditType" => "Enum('LoggedInUsers, OnlyTheseUsers, Inherit', 'Inherit')",
	);
	
	private static $has_one = array();
	
	private static $has_many = array(
		"Pages" => "SiteTree"
	);
	
	private static $many_many = array(
		"ViewerGroups" => "Group",
		"EditorGroups" => "Group",
	);
	
	private static $belongs_many_many = array();
	
	private static $many_many_extraFields = array();
	
	private static $casting = array(
		"LastEdited" => "SS_Datetime",
		"Created" => "SS_Datetime",
	);
	
	private static $defaults = array(
		"CanViewType" => "Inherit",
		"CanEditType" => "Inherit"
	);
	
	private static $versioning = array(
		"Stage",  "Live"
	);
	
	private static $default_sort = "\"Sort\"";
	
	/**
	 * If this is false, the class cannot be created in the CMS by regular content authors, only by ADMINs.
	 * @config
	 * @var boolean
	 */
	private static $can_create = true;
	
	/**
	 * Icon to use in the CMS page tree. This should be the full filename, relative to the webroot.
	 * Also supports custom CSS rule contents (applied to the correct selector for the tree UI implementation).
	 * 
	 * @config
	 * @var string
	 */
	private static $icon = null;
	
	/**
	 * @config
	 * @var string
	 */
	private static $description = 'A generic Sidebar';
	
	private static $extensions = array(
		"Versioned('Stage', 'Live')",
	);
	
	private static $searchable_fields = array(
		'Title',
		'Content'
	);
	
	private static $field_labels = array();
	
	/**
	 * This controls whether or not extendCMSFields() is called by getCMSFields.
	 * @var bool
	 */
	private static $runCMSFieldsExtensions = true;
	
	/**
	 * Cache for canView/Edit/Publish/Delete permissions.
	 * Keyed by permission type (e.g. 'edit'), with an array
	 * of IDs mapped to their boolean permission ability (true=allow, false=deny).
	 */
	private static $cache_permissions = array();
	
	
	/**
	 * Return a subclass map of Sidebar that shouldn't 
	 * be hidden through Sidebar::$hide_ancestor
	 * 
	 * @return array
	 */
	static public function sidebar_type_classes() {
		$classes = ClassInfo::getValidSubClasses();
		
		$baseClassIndex = array_search('Sidebar', $classes);
		if($baseClassIndex !== FALSE) unset($classes[$baseClassIndex]);
		
		$kill_ancestors = array();
		
		// figure out if there are any classes we don't want to appear
		foreach($classes as $class) {
			$instance = singleton($class);
			
			// do any of the progeny want to hide an ancestor?
			if($ancestor_to_hide = $instance->stat('hide_ancestor')) {
				// note for killing later
				$kill_ancestors[] = $ancestor_to_hide;
			}
		}
		
		// If any of the descendents don't want any of the elders to show up, cruelly render the elders surplus to requirements.
		if($kill_ancestors) {
			$kill_ancestors = array_unique($kill_ancestors);
			foreach($kill_ancestors as $mark) {
				// unset from $classes
				$idx = array_search($mark, $classes);
				unset($classes[$idx]);
			}
		}
		
		return $classes;
	}
	
	/**
	 * TODO: Define CMSSidebarEditController
	 * 
	 * @return string
	 */
	public function CMSEditLink() {
		//return Controller::join_links(singleton('CMSSidebarEditController')->Link('show'), $this->ID);
	}
	
	/**
	 * TODO: Check if this associated with the currently 
	 * active page that is being used to handle a request.
	 * 
	 * @return boolean
	 */
	public function isCurrent() {
		return $this->ID ? $this->ID == Director::get_current_page()->SidebarID : null;
	}
	
	/**
	 * Get the Page associated with this
	 * 
	 * @return Page
	 */
	public function getPage() {
		// has_one
		if($this->PageID) {
			return $this->Page();
		}
		
		// has_many|many_many
		if($this->Pages) {
			foreach($this->Pages as $Page) {
				if($this->ID == $Page->SidebarID) {
					return $Page;
				}
			}
		}
	}


	/**
	 * Get a Sidebar instance, checking anscestors for closest
	 * valid associated instance.
	 * 
	 * @return mixed(Sidebar|false)
	 */
	public function getCurrent() {
		$page = Director::get_current_page();
		while($page) {
			if($Sidebar = $page->Sidebar()) {
				if($Sidebar->ShowOnSite) {
					return $Sidebar;
				}
			}
			$page = $page->Parent;
		}
		return false;
	}
	
	/**
	 * Create a duplicate of this node. Doesn't affect joined data - create a
	 * custom overloading of this if you need such behaviour.
	 * 
	 * @param bool $doWrite
	 * @return Sidebar The duplicated object
	 */
	public function duplicate($doWrite = true) {
		$Sidebar = parent::duplicate(false);
		$Sidebar->Sort = 0;
		$this->invokeWithExtensions('onBeforeDuplicate', $Sidebar);
		
		if($doWrite) {
			$Sidebar->write();
			$Sidebar = $this->duplicateManyManyRelations($this, $Sidebar);
		}
		$this->invokeWithExtensions('onAfterDuplicate', $Sidebar);
		
		return $Sidebar;
	}
	
	/**
	 * This function should return true if the current user can execute this action.
	 * 
	 * @uses DataObjectDecorator->can()
	 * 
	 * @param string $perm The permission to be checked, such as 'View'.
	 * @param Member $member The member whose permissions need checking.
	 * @return boolean True if the the member is allowed to do the given action.
	 */
	public function can($perm, $member = null) {
		if(!$member || !(is_a($member, 'Member')) || is_numeric($member)) {
			$member = Member::currentUserID();
		}
		
		// admin override
		if($member && Permission::checkMember($member, "ADMIN")) return true;
		
		if(is_string($perm) && method_exists($this, 'can' . ucfirst($perm))) {
			$method = 'can' . ucfirst($perm);
			return $this->$method($member);
		}
		
		$results = $this->extend('can', $member);
		if($results && is_array($results)) if(!min($results)) return false;
		
		return ($member && Permission::checkMember($member, $perm));
	}
	
	/**
	 * This function should return true if the current user can view this
	 * page. It can be overloaded to customise the security model for an
	 * application.
	 * 
	 * @uses DataExtension->canView()
	 * @uses ViewerGroups()
	 * 
	 * @param Member $member
	 * @return boolean True if the current user can view this sidebar.
	 */
	public function canView($member = null) {
		if(!$member || !(is_a($member, 'Member')) || is_numeric($member)) {
			$member = Member::currentUserID();
		}
		
		// admin override
		if($member && Permission::checkMember($member, array("ADMIN", "SITETREE_VIEW_ALL"))) return true;
		
		// Standard mechanism for accepting permission changes from extensions
		$extended = $this->extendedCan('canView', $member);
		if($extended !== null) return $extended;
		
		// check for inherit
		if($this->CanViewType == 'Inherit') {
			if($this->PageID) return $this->Page()->canView($member);
		}
		
		// check for any logged-in users
		if($this->CanViewType == 'LoggedInUsers' && $member) {
			return true;
		}
		
		// check for specific groups
		if($member && is_numeric($member)) $member = DataObject::get_by_id('Member', $member);
		if(
			$this->CanViewType == 'OnlyTheseUsers' 
			&& $member 
			&& $member->inGroups($this->ViewerGroups())
		) return true;
		
		return false;
	}
	
	/**
	 * Determines canView permissions for the latest version of this Sidebar on a specific 
	 * stage (see {@link Versioned}). Usually the stage is read from {@link Versioned::current_stage()}.
	 * 
	 * @param string $stage
	 * @param Member $member
	 * @return boolean
	 */
	public function canViewStage($stage = 'Live', $member = null) {
		$oldMode = Versioned::get_reading_mode();
		Versioned::reading_stage($stage);
		
		$versionFromStage = DataObject::get($this->class)->byID($this->ID);
		
		Versioned::set_reading_mode($oldMode);
		return $versionFromStage ? $versionFromStage->canView($member) : false;
	}
	
	/**
	 * This function should return true if the current user can delete this
	 * page. It can be overloaded to customise the security model for an
	 * application.
	 * 
	 * @uses canDelete()
	 * @uses SiteTreeExtension->canDelete()
	 * @uses canEdit()
	 * 
	 * @param type $member
	 * @return boolean True if the current user can delete this sidebar.
	 */
	public function canDelete($member = null) {
		if($member instanceof Member) $memberID = $member->ID;
		else if(is_numeric($member)) $memberID = $member;
		else $memberID = Member::currentUserID();
		
		// admin override
		if($memberID && Permission::checkMember($memberID, array("ADMIN", "SITETREE_EDIT_ALL"))) {
			return true;
		}
		
		// Standard mechanism for accepting permission changes from extensions
		$extended = $this->extendedCan('canDelete', $memberID);
		if($extended !== null) return $extended;
		
		// Regular canEdit logic is handled by can_edit_multiple
		$results = self::can_delete_multiple(array($this->ID), $memberID);
		
		// If this sidebar no longer exists in stage/live results won't contain the sidebar.
		// Fail-over to false
		
		return isset($results[$this->ID]) ? $results[$this->ID] : false;
	}
	
	/**
	 * This function should return true if the current user can create new
	 * sidebars of this class. It can be overloaded to customise the security 
	 * model for an application.
	 * 
	 * Denies permission if any of the following conditions is TRUE:
	 * - canCreate() returns FALSE on any extension
	 * - $can_create is set to FALSE and the site is not in "dev mode"
	 * 
	 * @uses $can_create
	 * @uses DataExtension->canCreate()
	 * 
	 * @param Member $member
	 * @return boolean True if the current user can create sidebars on this class.
	 */
	public function canCreate($member = null) {
		if(!$member || !(is_a($member, 'Member')) || is_numeric($member)) {
			$member = Member::currentUserID();
		}

		// admin override
		if($member && Permission::checkMember($member, "ADMIN")) return true;

		// Standard mechanism for accepting permission changes from extensions
		$extended = $this->extendedCan('canCreate', $member);
		if($extended !== null) return $extended;

		return $this->stat('can_create') != false || Director::isDev();
	}
	
	/**
	 * This function should return true if the current user can edit this
	 * sidebar. It can be overloaded to customise the security model for an
	 * application.
	 * 
	 * Denies permission if any of the following conditions is TRUE:
	 * - canEdit() on any extension returns FALSE
	 * - canView() return false
	 * - "CanEditType" directive is set to "Inherit" and related page return false for canEdit()
	 * - "CanEditType" directive is set to "LoggedInUsers" and no user is logged in or doesn't have the CMS_Access_CMSMAIN permission code
	 * - "CanEditType" directive is set to "OnlyTheseUsers" and user is not in the given groups
	 * 
	 * @uses canView()
	 * @uses EditorGroups()
	 * @uses DataExtension->canEdit()
	 *
	 * @param Member $member Set to FALSE if you want to explicitly test permissions without a valid user (useful for unit tests)
	 * @return boolean True if the current user can edit this sidebar.
	 */
	public function canEdit($member = null) {
		if($member instanceof Member) $memberID = $member->ID;
		else if(is_numeric($member)) $memberID = $member;
		else $memberID = Member::currentUserID();

		// admin override
		if($memberID && Permission::checkMember($memberID, array("ADMIN", "SITETREE_EDIT_ALL"))) return true;

		// Standard mechanism for accepting permission changes from extensions
		$extended = $this->extendedCan('canEdit', $memberID);
		if($extended !== null) return $extended;

		if($this->ID) {
			// Regular canEdit logic is handled by can_edit_multiple
			$results = self::can_edit_multiple(array($this->ID), $memberID);

			// If this page no longer exists in stage/live results won't contain the page.
			// Fail-over to false
			return isset($results[$this->ID]) ? $results[$this->ID] : false;

		// Default for unsaved sidebars
		} else {
			return $this->getSiteConfig()->canEdit($member);
		}
	}
	
	/**
	 * This function should return true if the current user can publish this
	 * sidebar. It can be overloaded to customise the security model for an
	 * application.
	 * 
	 * Denies permission if any of the following conditions is TRUE:
	 * - canPublish() on any extension returns FALSE
	 * - canEdit() returns FALSE
	 * 
	 * @uses SidebarExtension->canPublish()
	 *
	 * @param Member $member
	 * @return boolean True if the current user can publish this page.
	 */
	public function canPublish($member = null) {
		if(!$member || !(is_a($member, 'Member')) || is_numeric($member)) $member = Member::currentUser();

		if($member && Permission::checkMember($member, "ADMIN")) return true;

		// Standard mechanism for accepting permission changes from extensions
		$extended = $this->extendedCan('canPublish', $member);
		if($extended !== null) return $extended;

		// Normal case - fail over to canEdit()
		return $this->canEdit($member);
	}

	public function canDeleteFromLive($member = null) {
		// Standard mechanism for accepting permission changes from extensions
		$extended = $this->extendedCan('canDeleteFromLive', $member);
		if($extended !==null) return $extended;

		return $this->canPublish($member);
	}
	
	/**
	 * Pre-populate the cache of canEdit, canView, canDelete, canPublish permissions.
	 * This method will use the static can_(perm)_multiple method for efficiency.
	 * 
	 * @param $permission String The permission: edit, view, publish, approve, etc.
	 * @param $ids array An array of page IDs
	 * @param $batchCallBack The function/static method to call to calculate permissions.  Defaults
	 * to 'SiteTree::can_(permission)_multiple'
	 */
	static public function prepopulate_permission_cache($permission = 'CanEditType', $ids, $batchCallback = null) {
		if(!$batchCallback) $batchCallback = "SiteTree::can_{$permission}_multiple";

		if(is_callable($batchCallback)) {
			call_user_func($batchCallback, $ids, Member::currentUserID(), false);
		} else {
			user_error("SiteTree::prepopulate_permission_cache can't calculate '$permission' "
				. "with callback '$batchCallback'", E_USER_WARNING);
		}
	}
	
	/**
	 * Stub method to get the site config, provided so it's easy to override
	 */
	public function getSiteConfig() {

		if($this->hasMethod('alternateSiteConfig')) {
			$altConfig = $this->alternateSiteConfig();
			if($altConfig) return $altConfig;
		}

		return SiteConfig::current_site_config();
	}
	
	
	/**
	 * Add default records to database.
	 *
	 * This function is called whenever the database is built, after the
	 * database tables have all been created. Overload this to add default
	 * records when the database is built, but make sure you call
	 * parent::requireDefaultRecords().
	 */
	public function requireDefaultRecords() {
		parent::requireDefaultRecords();
	}
	
	/**
	 * 
	 */
	protected function onBeforeWrite() {
		parent::onBeforeWrite();
		
		// If Sort hasn't been set, make this page come after it's siblings
		if(!$this->Sort) {
			$PageID = ($this->PageID) ?: 0;
			$this->Sort = DB::query("SELECT MAX(\"Sort\") + 1 FROM \"Sidebar\" WHERE \"PageID\" = $parentID")->value();
		}
		
		// Check to see if we've only altered fields that shouldn't affect versioning
		$fieldsIgnoredByVersioning = array('HasBrokenLink', 'Status', 'HasBrokenFile', 'ToDo', 'VersionID', 'SaveCount');
		$changedFields = array_keys($this->getChangedFields(true, 2));
		
		// This more rigorous check is inline with the test that write()
		// does to dedcide whether or not to write to the DB.  We use that
		// to avoid cluttering the system with a migrateVersion() call
		// that doesn't get used
		$oneChangedFields = array_keys($this->getChangedFields(true, 1));

		if($oneChangedFields && !array_diff($changedFields, $fieldsIgnoredByVersioning)) {
			// This will have the affect of preserving the versioning
			$this->migrateVersion($this->Version);
		}
	}
	
	/**
	 * 
	 */
	public function onAfterWrite() {
		// Need to flush cache to avoid outdated versionnumber references
		$this->flushCache();

		parent::onAfterWrite();
	}
	
	/**
	 * 
	 */
	public function onBeforeDelete() {
		parent::onBeforeDelete();
	}

	/**
	 * 
	 */
	public function onAfterDelete() {
		// Need to flush cache to avoid outdated versionnumber references
		$this->flushCache();

		parent::onAfterDelete();
	}
	
	/**
	 * 
	 * @param boolean $persistent
	 */
	public function flushCache($persistent = true) {
		parent::flushCache($persistent);
		$this->_cache_statusFlags = null;
	}
	
	/**
	 * Returns a FieldList with which to create the main editing form.
	 *
	 * You can override this in your child classes to add extra fields - first
	 * get the parent fields using parent::getCMSFields(), then use
	 * addFieldToTab() on the FieldList.
	 * 
	 * See {@link getSettingsFields()} for a different set of fields
	 * concerned with configuration aspects on the record, e.g. access control
	 *
	 * @return FieldList The fields to be displayed in the CMS.
	 */
	public function getCMSFields() {
		$fields = new FieldList(
			$rootTab = new TabSet("Root",
				$tabMain = new Tab('Main',
					new TextField("Title", $this->fieldLabel('Title')),
					$htmlField = new HtmlEditorField("Content", _t('SiteTree.HTMLEDITORTITLE', "Content", 'HTML editor title'))
				),
				$tabPages = new Tab('Pages'/*
					new GridField()*/
				)
			)
		);
		$tabMain->setTitle(_t('SiteTree.TABCONTENT', "Main Content"));
		$htmlField->addExtraClass('stacked');
		
		if(self::$runCMSFieldsExtensions) {
			$this->extend('updateCMSFields', $fields);
		}

		return $fields;
	}
	
	/**
	 * Returns fields related to configuration aspects on this record, e.g. access control.
	 * See {@link getCMSFields()} for content-related fields.
	 * 
	 * @return FieldList
	 */
	public function getSettingsFields() {
		$groupsMap = Group::get()->map('ID', 'Breadcrumbs')->toArray();
		asort($groupsMap);

		$fields = new FieldList(
			$rootTab = new TabSet("Root",
				$tabBehaviour = new Tab('Settings',
					new DropdownField(
						"ClassName", 
						$this->fieldLabel('ClassName'), 
						$this->getClassDropdown()
					),
					$parentTypeSelector = new CompositeField(
						new OptionsetField("ParentType", _t("SiteTree.PAGELOCATION", "Page location"), array(
							"root" => _t("SiteTree.PARENTTYPE_ROOT", "Top-level page"),
							"subpage" => _t("SiteTree.PARENTTYPE_SUBPAGE", "Sub-page underneath a parent page"),
						)),
						$parentIDField = new TreeDropdownField("ParentID", $this->fieldLabel('ParentID'), 'SiteTree', 'ID', 'MenuTitle')
					),
					$visibility = new FieldGroup(
						new CheckboxField("ShowInMenus", $this->fieldLabel('ShowInMenus')),
						new CheckboxField("ShowInSearch", $this->fieldLabel('ShowInSearch'))
					),
					$viewersOptionsField = new OptionsetField(
						"CanViewType", 
						_t('SiteTree.ACCESSHEADER', "Who can view this page?")
					),
					$viewerGroupsField = ListboxField::create("ViewerGroups", _t('SiteTree.VIEWERGROUPS', "Viewer Groups"))
						->setMultiple(true)
						->setSource($groupsMap)
						->setAttribute(
							'data-placeholder', 
							_t('SiteTree.GroupPlaceholder', 'Click to select group')
						),
					$editorsOptionsField = new OptionsetField(
						"CanEditType", 
						_t('SiteTree.EDITHEADER', "Who can edit this page?")
					),
					$editorGroupsField = ListboxField::create("EditorGroups", _t('SiteTree.EDITORGROUPS', "Editor Groups"))
						->setMultiple(true)
						->setSource($groupsMap)
						->setAttribute(
							'data-placeholder', 
							_t('SiteTree.GroupPlaceholder', 'Click to select group')
						)
				)
			)
		);

		$visibility->setTitle($this->fieldLabel('Visibility'));

		/*
		 * This filter ensures that the ParentID dropdown selection does not show this node,
		 * or its descendents, as this causes vanishing bugs.
		 */
		$parentIDField->setFilterFunction(create_function('$node', "return \$node->ID != {$this->ID};"));
		$parentTypeSelector->addExtraClass('parentTypeSelector');

		$tabBehaviour->setTitle(_t('SiteTree.TABBEHAVIOUR', "Behavior"));

		// Make page location fields read-only if the user doesn't have the appropriate permission
		if(!Permission::check("SITETREE_REORGANISE")) {
			$fields->makeFieldReadonly('ParentType');
			if($this->ParentType == 'root') {
				$fields->removeByName('ParentID');
			} else {
				$fields->makeFieldReadonly('ParentID');
			}
		}

		$viewersOptionsSource = array();
		$viewersOptionsSource["Inherit"] = _t('SiteTree.INHERIT', "Inherit from parent page");
		$viewersOptionsSource["Anyone"] = _t('SiteTree.ACCESSANYONE', "Anyone");
		$viewersOptionsSource["LoggedInUsers"] = _t('SiteTree.ACCESSLOGGEDIN', "Logged-in users");
		$viewersOptionsSource["OnlyTheseUsers"] = _t('SiteTree.ACCESSONLYTHESE', "Only these people (choose from list)");
		$viewersOptionsField->setSource($viewersOptionsSource);

		$editorsOptionsSource = array();
		$editorsOptionsSource["Inherit"] = _t('SiteTree.INHERIT', "Inherit from parent page");
		$editorsOptionsSource["LoggedInUsers"] = _t('SiteTree.EDITANYONE', "Anyone who can log-in to the CMS");
		$editorsOptionsSource["OnlyTheseUsers"] = _t('SiteTree.EDITONLYTHESE', "Only these people (choose from list)");
		$editorsOptionsField->setSource($editorsOptionsSource);

		if(!Permission::check('SITETREE_GRANT_ACCESS')) {
			$fields->makeFieldReadonly($viewersOptionsField);
			if($this->CanViewType == 'OnlyTheseUsers') {
				$fields->makeFieldReadonly($viewerGroupsField);
			} else {
				$fields->removeByName('ViewerGroups');
			}

			$fields->makeFieldReadonly($editorsOptionsField);
			if($this->CanEditType == 'OnlyTheseUsers') {
				$fields->makeFieldReadonly($editorGroupsField);
			} else {
				$fields->removeByName('EditorGroups');
			}
		}

		if(self::$runCMSFieldsExtensions) {
			$this->extend('updateSettingsFields', $fields);
		}

		return $fields;
	}
	
	/**
	 * Get the actions available in the CMS for this page - eg Save, Publish.
	 *
	 * Frontend scripts and styles know how to handle the following FormFields:
	 * * top-level FormActions appear as standalone buttons
	 * * top-level CompositeField with FormActions within appear as grouped buttons
	 * * TabSet & Tabs appear as a drop ups
	 * * FormActions within the Tab are restyled as links
	 * * major actions can provide alternate states for richer presentation (see ssui.button widget extension).
	 *
	 * @return FieldList The available actions for this page.
	 */
	public function getCMSActions() {
		$existsOnLive = $this->getExistsOnLive();

		// Major actions appear as buttons immediately visible as page actions.
		$majorActions = CompositeField::create()->setName('MajorActions')->setTag('fieldset')->addExtraClass('ss-ui-buttonset');

		// Minor options are hidden behind a drop-up and appear as links (although they are still FormActions).
		$rootTabSet = new TabSet('ActionMenus');
		$moreOptions = new Tab(
			'MoreOptions', 
			_t('SiteTree.MoreOptions', 'More options', 'Expands a view for more buttons')
		);
		$rootTabSet->push($moreOptions);
		$rootTabSet->addExtraClass('ss-ui-action-tabset action-menus');

		// Render page information into the "more-options" drop-up, on the top.
		$live = Versioned::get_one_by_stage('SiteTree', 'Live', "\"SiteTree\".\"ID\"='$this->ID'");
		$moreOptions->push(
			new LiteralField('Information',
				$this->customise(array(
					'Live' => $live,
					'ExistsOnLive' => $existsOnLive
				))->renderWith('SiteTree_Information')
			)
		);

		// "readonly"/viewing version that isn't the current version of the record
		$stageOrLiveRecord = Versioned::get_one_by_stage($this->class, Versioned::current_stage(), sprintf('"SiteTree"."ID" = %d', $this->ID));
		if($stageOrLiveRecord && $stageOrLiveRecord->Version != $this->Version) {
			$moreOptions->push(FormAction::create('email', _t('CMSMain.EMAIL', 'Email')));
			$moreOptions->push(FormAction::create('rollback', _t('CMSMain.ROLLBACK', 'Roll back to this version')));

			$actions = new FieldList(array($majorActions, $rootTabSet));

			// getCMSActions() can be extended with updateCMSActions() on a extension
			$this->extend('updateCMSActions', $actions);

			return $actions;
		}

		if($this->isPublished() && $this->canPublish() && !$this->IsDeletedFromStage && $this->canDeleteFromLive()) {
			// "unpublish"
			$moreOptions->push(
				FormAction::create('unpublish', _t('SiteTree.BUTTONUNPUBLISH', 'Unpublish'), 'delete')
					->setDescription(_t('SiteTree.BUTTONUNPUBLISHDESC', 'Remove this page from the published site'))
					->addExtraClass('ss-ui-action-destructive')
			);
		}

		if($this->stagesDiffer('Stage', 'Live') && !$this->IsDeletedFromStage) {
			if($this->isPublished() && $this->canEdit())	{
				// "rollback"
				$moreOptions->push(
					FormAction::create('rollback', _t('SiteTree.BUTTONCANCELDRAFT', 'Cancel draft changes'), 'delete')
						->setDescription(_t('SiteTree.BUTTONCANCELDRAFTDESC', 'Delete your draft and revert to the currently published page'))
				);
			}
		}

		if($this->canEdit()) {
			if($this->IsDeletedFromStage) {
				// The usual major actions are not available, so we provide alternatives here.
				if($existsOnLive) {
					// "restore"
					$majorActions->push(FormAction::create('revert',_t('CMSMain.RESTORE','Restore')));
					if($this->canDelete() && $this->canDeleteFromLive()) {
						// "delete from live"
						$majorActions->push(
							FormAction::create('deletefromlive',_t('CMSMain.DELETEFP','Delete'))->addExtraClass('ss-ui-action-destructive')
						);
					}
				} else {
					// "restore"
					$majorActions->push(
						FormAction::create('restore',_t('CMSMain.RESTORE','Restore'))->setAttribute('data-icon', 'decline')
					);
				}
			} else {
				if($this->canDelete()) {
					// "delete"
					$moreOptions->push(
						FormAction::create('delete',_t('CMSMain.DELETE','Delete draft'))->addExtraClass('delete ss-ui-action-destructive')
					);
				}

				// "save", supports an alternate state that is still clickable, but notifies the user that the action is not needed.
				$majorActions->push(
					FormAction::create('save', _t('SiteTree.BUTTONSAVED', 'Saved'))
						->setAttribute('data-icon', 'accept')
						->setAttribute('data-icon-alternate', 'addpage')
						->setAttribute('data-text-alternate', _t('CMSMain.SAVEDRAFT','Save draft'))
				);
			}
		}

		if($this->canPublish() && !$this->IsDeletedFromStage) {
			// "publish", as with "save", it supports an alternate state to show when action is needed.
			$majorActions->push(
				$publish = FormAction::create('publish', _t('SiteTree.BUTTONPUBLISHED', 'Published'))
					->setAttribute('data-icon', 'accept')
					->setAttribute('data-icon-alternate', 'disk')
					->setAttribute('data-text-alternate', _t('SiteTree.BUTTONSAVEPUBLISH', 'Save & publish'))
			);

			// Set up the initial state of the button to reflect the state of the underlying SiteTree object.
			if($this->stagesDiffer('Stage', 'Live')) {
				$publish->addExtraClass('ss-ui-alternate');
			}
		}

		$actions = new FieldList(array($majorActions, $rootTabSet));

		// Hook for extensions to add/remove actions.
		$this->extend('updateCMSActions', $actions);

		return $actions;
	}
	
	
}

?>
