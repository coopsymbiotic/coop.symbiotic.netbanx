<?php

require_once 'CRM/Core/Form.php';

/**
 * Form controller class
 *
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC43/QuickForm+Reference
 */
class CRM_Netbanx_Form_NetbanxSettings extends CRM_Core_Form {
  function setDefaultValues() {
    $defaults = $this->_values;

    $defaults = CRM_Core_BAO_Setting::getItem(NETBANX_SETTINGS_GROUP);

    if (! CRM_Utils_Array::value('netbanx_logo', $defaults)) {
      $defaults = '';
    }

    return $defaults;
  }

  function buildQuickForm() {
    CRM_Core_Resources::singleton()
      ->addStyleFile('coop.symbiotic.netbanx', 'netbanx.settings.css')
      ->addScriptFile('coop.symbiotic.netbanx', 'netbanx.settings.js');

    CRM_Core_Resources::singleton()->addSetting(array(
      'netbanx' => array(
        'baseurl_images' =>CRM_Core_Resources::singleton()->getUrl('coop.symbiotic.netbanx', '/images/'),
      ),
    ));

    $logos = array(
      '' => ts('Do not display a logo', array('domain' => 'coop.symbiotic.netbanx')),
      'powered_by_netbanx_standard.jpg' => ts('Netbanx (standard logo)', array('domain' => 'coop.symbiotic.netbanx')),
      'powered_by_netbanx_uk.jpg' => ts('Netbanx (UK cards)', array('domain' => 'coop.symbiotic.netbanx')),
      'powered_by_netbanx_visa_mc_amex.jpg' => ts('Netbanx (Visa/MasterCard/Amex)', array('domain' => 'coop.symbiotic.netbanx')),
      'powered_by_netbanx_visa_mc.jpg' => ts('Netbanx (Visa/MasterCard)', array('domain' => 'coop.symbiotic.netbanx')),
      'powered_by_netbanx_all.jpg' => ts('Netbanx (all cards)', array('domain' => 'coop.symbiotic.netbanx')),
      'desjardins.png' => ts('Desjardins', array('domain' => 'coop.symbiotic.netbanx')),
    );

    $this->add('select', 'netbanx_logo', ts('Logo to display', array('domain' => 'coop.symbiotic.netbanx')), $logos, false);

    $this->addButtons(array(
      array(
        'type' => 'submit',
        'name' => ts('Submit', array('domain' => 'coop.symbiotic.netbanx')),
        'isDefault' => TRUE,
      ),
    ));

    // export form elements
    $this->assign('elementNames', $this->getRenderableElementNames());
    parent::buildQuickForm();
  }

  function postProcess() {
    $values = $this->exportValues();
    $fields = array('netbanx_logo');

    foreach ($fields as $field) {
      $result = CRM_Core_BAO_Setting::setItem($values[$field], NETBANX_SETTINGS_GROUP, $field);
    }

    // we will return to this form by default
    CRM_Core_Session::setStatus(ts('Settings saved.', array('domain' => 'coop.symbiotic.netbanx')), '', 'success');

    parent::postProcess();
  }

  /**
   * Get the fields/elements defined in this form.
   *
   * @return array (string)
   */
  function getRenderableElementNames() {
    // The _elements list includes some items which should not be
    // auto-rendered in the loop -- such as "qfKey" and "buttons".  These
    // items don't have labels.  We'll identify renderable by filtering on
    // the 'label'.
    $elementNames = array();
    foreach ($this->_elements as $element) {
      $label = $element->getLabel();
      if (!empty($label)) {
        $elementNames[] = $element->getName();
      }
    }
    return $elementNames;
  }
}
