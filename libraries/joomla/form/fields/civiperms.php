<?php

defined('JPATH_PLATFORM') or die;

use Joomla\CMS\Access\Access;
use Joomla\CMS\Language\Text;

class JFormFieldCiviperms extends JFormFieldRules {

  /**
   * @var CRM_Core_Config
   */
  private static $civiConfig;

  public function __construct($form = null) {
    $this->bootstrapCivi();
    parent::__construct($form);
  }

  /**
   * TODO: This seems like it should go somewhere more general.
   */
  protected function bootstrapCivi() {
    if (!defined('CIVICRM_SETTINGS_PATH')) {
      define('CIVICRM_SETTINGS_PATH', JPATH_SITE . '/' . 'administrator/components/com_civicrm/civicrm.settings.php');
    }
    if (!defined('CIVICRM_CORE_PATH')) {
      define('CIVICRM_CORE_PATH', JPATH_SITE . '/' . 'administrator/components/com_civicrm/civicrm');
    }
    require_once CIVICRM_SETTINGS_PATH;
    require_once CIVICRM_CORE_PATH . '/CRM/Core/Config.php';
    self::$civiConfig = CRM_Core_Config::singleton();
  }

  /**
   * Overrides parent to allow fetching of extension permissions via custom
   * method JFormFieldCiviperms::getCiviperms().
   *
   * This method was copied from Joomla's /libraries/joomla/form/fields/rules.php.
   * One line of code was changed to allow extension-declared permissions to be
   * displayed on the screen for managing Joomla permissions/ACLs. Search for
   * CRM-12059 to find the changed line.
   *
   * Future developers: If you change anything else in this method, please note
   * the issue ID in this comment and reference it in the code as well.
   */
  protected function getInput() {
    JHtml::_('bootstrap.tooltip');
    // Add Javascript for permission change
    JHtml::_('script', 'system/permissions.js', array('version' => 'auto', 'relative' => true));
    // Load JavaScript message titles
    Text::script('ERROR');
    Text::script('WARNING');
    Text::script('NOTICE');
    Text::script('MESSAGE');
    // Add strings for JavaScript error translations.
    Text::script('JLIB_JS_AJAX_ERROR_CONNECTION_ABORT');
    Text::script('JLIB_JS_AJAX_ERROR_NO_CONTENT');
    Text::script('JLIB_JS_AJAX_ERROR_OTHER');
    Text::script('JLIB_JS_AJAX_ERROR_PARSE');
    Text::script('JLIB_JS_AJAX_ERROR_TIMEOUT');
    // Initialise some field attributes.
    $section = $this->section;
    $assetField = $this->assetField;
    $component = empty($this->component) ? 'root.1' : $this->component;
    // Current view is global config?
    $isGlobalConfig = $component === 'root.1';
    // CRM-12059: Get the list of permissions for CiviCRM core and extensions.
    $actions = self::getCiviperms($component, $section);
    // Iterate over the children and add to the actions.
    foreach ($this->element->children() as $el) {
      if ($el->getName() == 'action') {
        $actions[] = (object) array(
              'name' => (string) $el['name'],
              'title' => (string) $el['title'],
              'description' => (string) $el['description'],
        );
      }
    }
    // Get the asset id.
    // Note that for global configuration, com_config injects asset_id = 1 into the form.
    $assetId = $this->form->getValue($assetField);
    $newItem = empty($assetId) && $isGlobalConfig === false && $section !== 'component';
    $parentAssetId = null;
    // If the asset id is empty (component or new item).
    if (empty($assetId)) {
      // Get the component asset id as fallback.
      $db = JFactory::getDbo();
      $query = $db->getQuery(true)
          ->select($db->quoteName('id'))
          ->from($db->quoteName('#__assets'))
          ->where($db->quoteName('name') . ' = ' . $db->quote($component));
      $db->setQuery($query);
      $assetId = (int) $db->loadResult();
      /**
       * @to do: incorrect info
       * When creating a new item (not saving) it uses the calculated permissions from the component (item <-> component <-> global config).
       * But if we have a section too (item <-> section(s) <-> component <-> global config) this is not correct.
       * Also, currently it uses the component permission, but should use the calculated permissions for achild of the component/section.
       */
    }
    // If not in global config we need the parent_id asset to calculate permissions.
    if (!$isGlobalConfig) {
      // In this case we need to get the component rules too.
      $db = JFactory::getDbo();
      $query = $db->getQuery(true)
          ->select($db->quoteName('parent_id'))
          ->from($db->quoteName('#__assets'))
          ->where($db->quoteName('id') . ' = ' . $assetId);
      $db->setQuery($query);
      $parentAssetId = (int) $db->loadResult();
    }
    // Full width format.
    // Get the rules for just this asset (non-recursive).
    $assetRules = JAccess::getAssetRules($assetId, false, false);
    // Get the available user groups.
    $groups = $this->getUserGroups();
    // Ajax request data.
    $ajaxUri = JRoute::_('index.php?option=com_config&task=config.store&format=json&' . JSession::getFormToken() . '=1');
    // Prepare output
    $html = array();
    // Description
    $html[] = '<details><summary class="rule-notes">' . Text::_('JLIB_RULES_SETTINGS_DESC') . '</summary>';

    if ($section === 'component' || !$section) {
      $html[] = '<div class="rule-notes">' . Text::_('JLIB_RULES_SETTING_NOTES') . '</div></details>';
    }
    else {
      $html[] = '<div class="rule-notes">' . Text::alt('JLIB_RULES_SETTING_NOTES_ITEM', $component . '_' . $section) . '</div></details>';
    }

    // Begin tabs
    $html[] = '<div class="tabbable tabs-left" data-ajaxuri="' . $ajaxUri . '" id="permissions-sliders">';
    $html[] = '<joomla-field-permissions class="row mb-2" data-uri="' . $ajaxUri . '" '. $this->dataAttribute . '>';
    $html[] = '<joomla-tab orientation="vertical" id="permissions-sliders" recall breakpoint="728">';
    foreach ($groups as $group) {
      $active = (int) $group->value === 1 ? ' active' : '';
      $html[] = '<joomla-tab-element class="tab-pane"' . $active . 'name="' . htmlentities(JLayoutHelper::render('joomla.html.treeprefix', array('level' => $group->level + 1)), ENT_COMPAT, 'utf-8') . $this->text . '" id="permission-' . $group->value . '">';

      $html[] = '<table class="table respTable">
        <thead>
          <tr>
            <th class="actions w-30" id="actions-th' . $group->value . '">
              <span class="acl-action">' . Text::_('JLIB_RULES_ACTION') . '</span>
            </th>

            <th class="settings w-40" id="settings-th' . $group->value . '">
              <span class="acl-action">' . Text::_('JLIB_RULES_SELECT_SETTING') . '</span>
            </th>

            <th class="w-30" id="aclaction-th' . $group->value . '">
              <span class="acl-action">' . Text::_('JLIB_RULES_CALCULATED_SETTING') . '</span>
            </th>
          </tr>
        </thead>';
        $isSuperUserGroup = Access::checkGroup($group->value, 'core.admin');
        $html[] = '<tbody>';
        foreach ($actions as $action) {
          $html[] = '<tr>
            <td class="oddCol" data-label="' . Text::_('JLIB_RULES_ACTION') . '" headers="actions-th' . $group->value . '">
              <label for="' . $this->id . '_' . $action->name . '_' . $group->value . '">' . Text::_($action->title) . '</label>';
          if (!empty($action->description)) {
            $html[] = '<div role="tooltip" id="tip-' . $this->id . '">' . htmlspecialchars(Text::_($action->description)) . '</div>';
          }
          $html[] = '</td>
            <td data-label="' . Text::_('JLIB_RULES_SELECT_SETTING') . '" headers="settings-th'. $group->value . '">
              <div class="d-flex align-items-center">
                <select data-onchange-task="permissions.apply"
                    class="form-select novalidate"
                    name="' . $name . '[' . $action->name . '][' . $group->value . ']"
                    id="' . $this->id . '_' . $action->name . '_' . $group->value . '">';
          // Get the actual setting for the action for this group.
          $assetRule = $newItem === false ? $assetRules->allow($action->name, $group->value) : null;
          // Build the dropdowns for the permissions sliders
          // The parent group has "Not Set", all children can rightly "Inherit" from that.
          $html[] = '<option value=""' . ($assetRule === null ? ' selected="selected"' : '') . '>'
              . Text::_(empty($group->parent_id) && $isGlobalConfig ? 'JLIB_RULES_NOT_SET' : 'JLIB_RULES_INHERITED') . '</option>';
          $html[] = '<option value="1"' . ($assetRule === true ? ' selected="selected"' : '') . '>' . Text::_('JLIB_RULES_ALLOWED')
              . '</option>';
          $html[] = '<option value="0"' . ($assetRule === false ? ' selected="selected"' : '') . '>' . Text::_('JLIB_RULES_DENIED')
              . '</option>';
          $html[] = '</select>&#160; ';
          $html[] = '<span id="icon_' . $this->id . '_' . $action->name . '_' . $group->value . '"></span>';
          $html[] = '</div></td>';

          $inheritedGroupRule 	= Access::checkGroup((int) $group->value, $action->name, $assetId);
          $inheritedGroupParentAssetRule = !empty($parentAssetId) ? Access::checkGroup($group->value, $action->name, $parentAssetId) : null;
          $inheritedParentGroupRule      = !empty($group->parent_id) ? Access::checkGroup($group->parent_id, $action->name, $assetId) : null;
          $html[] = '<td data-label="' . Text::_('JLIB_RULES_CALCULATED_SETTING') . '" headers="aclaction-th' . $group->value . '">';
          $result = [];

          if ($isSuperUserGroup) {
            $result['class'] = 'badge bg-success';
            $result['text']  = '<span class="icon-lock icon-white" aria-hidden="true"></span>' . Text::_('JLIB_RULES_ALLOWED_ADMIN');
          }
          else {
            // First get the real recursive calculated setting and add (Inherited) to it.

            // If recursive calculated setting is "Denied" or null. Calculated permission is "Not Allowed (Inherited)".
            if ($inheritedGroupRule === null || $inheritedGroupRule === false)
            {
              $result['class'] = 'badge bg-danger';
              $result['text']  = Text::_('JLIB_RULES_NOT_ALLOWED_INHERITED');
            }
            // If recursive calculated setting is "Allowed". Calculated permission is "Allowed (Inherited)".
            else
            {
              $result['class'] = 'badge bg-success';
              $result['text']  = Text::_('JLIB_RULES_ALLOWED_INHERITED');
            }

            // Second part: Overwrite the calculated permissions labels if there is an explicit permission in the current group.

            /**
            * @to do: incorrect info
            * If a component has a permission that doesn't exists in global config (ex: frontend editing in com_modules) by default
            * we get "Not Allowed (Inherited)" when we should get "Not Allowed (Default)".
            */

            // If there is an explicit permission "Not Allowed". Calculated permission is "Not Allowed".
            if ($assetRule === false)
            {
              $result['class'] = 'badge bg-danger';
              $result['text']  = 	Text::_('JLIB_RULES_NOT_ALLOWED');
            }
            // If there is an explicit permission is "Allowed". Calculated permission is "Allowed".
            elseif ($assetRule === true)
            {
              $result['class'] = 'badge bg-success';
              $result['text']  = Text::_('JLIB_RULES_ALLOWED');
            }

            // Third part: Overwrite the calculated permissions labels for special cases.

            // Global configuration with "Not Set" permission. Calculated permission is "Not Allowed (Default)".
            if (empty($group->parent_id) && $isGlobalConfig === true && $assetRule === null)
            {
              $result['class'] = 'badge bg-danger';
              $result['text']  = Text::_('JLIB_RULES_NOT_ALLOWED_DEFAULT');
            }

            /**
            * Component/Item with explicit "Denied" permission at parent Asset (Category, Component or Global config) configuration.
            * Or some parent group has an explicit "Denied".
            * Calculated permission is "Not Allowed (Locked)".
            */
            elseif ($inheritedGroupParentAssetRule === false || $inheritedParentGroupRule === false)
            {
              $result['class'] = 'badge bg-danger';
              $result['text']  = '<span class="icon-lock icon-white" aria-hidden="true"></span>'. Text::_('JLIB_RULES_NOT_ALLOWED_LOCKED');
            }
          }
          $html[] = '<output><span class="' . $result['class'] . '">' . $result['text'] . '</span></output>';
          $html[] = '</td>';
          $html[] = '</tr>';
        }
        $html[] = '</tbody>';
        $html[] = '</table>';
        $html[] = '</joomla-tab-element>';
    }
    $html[] = '</joomla-tab>';
    $html[] = '</joomla-field-permissions>';
    return implode("\n", $html);
  }

  /**
   * Wrapper around Access::getActionsFromFile() to retrieve CiviCRM extension as well
   * as core permissions.
   *
   * @param type $component
   *   @see JAccess::getActions()
   * @param type $section
   *   @see JAccess::getActions()
   */
  private static function getCiviperms($component, $section) {
    $actions = Access::getActionsFromFile(JPATH_ADMINISTRATOR . "/components/{$component}/access.xml", "/access/section[@name='{$section}']/");

    $extPerms = self::$civiConfig->userPermissionClass->getAllModulePermissions(TRUE);
    foreach ($extPerms as $key => $perm) {
      $translation = self::$civiConfig->userPermissionClass->translateJoomlaPermission($key);
      $actions[] = (object) array(
        'name' => $translation[0],
        'title' => $perm[0],
        'description' => $perm[1],
      );
    }
    return $actions;
  }

}
