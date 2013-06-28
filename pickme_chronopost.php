<?php

// Avoid direct access to the file
if (!defined('_PS_VERSION_'))
	exit;

class pickme_chronopost extends CarrierModule
{
	public  $id_carrier;

	private $_html = '';
	private $_postErrors = array();
	private $_moduleName = 'pickme_chronopost';


	/*
	** Construct Method
	**
	*/

	public function __construct()
	{
		$this->name = 'pickme_chronopost';
		$this->tab = 'shipping_logistics';
		$this->version = '1.0';
		$this->author = 'motivus.pt';
	  $this->module_key = '11bad94727c2f1530e15c3c93ed2c5ce';
		//$this->limited_countries = array('fr', 'us');

		parent::__construct ();

		$this->displayName = $this->l('PickMe Chronopost');
		$this->description = $this->l('Pick the nearest PickMe Chronopost');

		if (self::isInstalled($this->name))
		{
			// Getting carrier list
			global $cookie;
			$carriers = Carrier::getCarriers($cookie->id_lang, true, false, false, NULL, PS_CARRIERS_AND_CARRIER_MODULES_NEED_RANGE);

			// Saving id carrier list
			$id_carrier_list = array();
			foreach($carriers as $carrier)
				$id_carrier_list[] .= $carrier['id_carrier'];

			// Testing if Carrier Id exists
			$warning = array();
			if (!in_array((int)(Configuration::get('PICKME_CARRIER_ID')), $id_carrier_list))
				$warning[] .= $this->l('"PickMe Chronopost"').' ';

			if (!Configuration::get('PICKME_OVERCOST'))
				$warning[] .= $this->l('"Overcost"').' ';

			if (count($warning))
				$this->warning .= implode(' , ',$warning).$this->l('must be configured to use this module correctly').' ';
		}
	}

	public function updateDatabase()
	{
		//$client = new SoapClient("https://83.240.239.170:7554/ChronoWSB2CPointsv3/GetB2CPoints_v3Service?wsdl");
		$client = new SoapClient(Configuration::get('PICKME_WEBSERVICE'));
    $result = $client->getPointList_V3();
    foreach ($result->return->lB2CPointsArr as $message) {
  		$id_pickme_shop_order = Db::getInstance()->getValue('
				SELECT id_pickme_shop FROM `'._DB_PREFIX_.'pickme_shops`
				WHERE pickme_id="'.$message->Number.'"');

  		if ($id_pickme_shop_order == "") {
  			$query = '
					INSERT INTO `'._DB_PREFIX_.'pickme_shops`
								 (pickme_id, name, address, postal_code, location)
					VALUES ("'.$message->Number.'", "'.$message->Name.'", "'.$message->Address.'", "'.$message->PostalCode.'", "'.$message->PostalCodeLocation.'")';
				Db::getInstance()->execute($query);
  		} else {
  			$query = '
					UPDATE `'._DB_PREFIX_.'pickme_shops`
					SET name="'.$message->Name.'", address="'.$message->Address.'", postal_code="'.$message->PostalCode.', location="'.$message->PostalCodeLocation.'"
					WHERE pickme_id="'.$message->Number.'"';
				Db::getInstance()->execute($query);
  		}
    }
	}


	/*
	** Install / Uninstall Methods
	**
	*/

	public function install()
	{
		Configuration::updateValue('PICKME_WEBSERVICE', "https://83.240.239.170:7554/ChronoWSB2CPointsv3/GetB2CPoints_v3Service?wsdl");

		$sqlpath = _PS_MODULE_DIR_. 'pickme_chronopost/sql/pickme.sql';
		if ((!file_exists($sqlpath)) ||
			(!$sql = file_get_contents($sqlpath)))
			return false;

		$sql = str_replace('PREFIX_', _DB_PREFIX_, $sql);
		$sql = preg_split("/;\s*[\r\n]+/", $sql);
		foreach($sql AS $k => $query)
			if (!empty($query))
				Db::getInstance()->execute(trim($query));

		$this->updateDatabase();

		$carrierConfig = array(
			0 => array('name' => 'PickMe Chronopost',
				'id_tax_rules_group' => 0,
				'active' => true,
				'deleted' => 0,
				'shipping_handling' => false,
				'range_behavior' => 0,
				'delay' => array(Language::getIsoById(Configuration::get('PS_LANG_DEFAULT')) => 'Choose one of our PickMe Chronopost stores.'),
				'id_zone' => 1,
				'is_module' => true,
				'shipping_external' => true,
				'external_module_name' => 'pickme_chronopost',
				'need_range' => true
			),
		);

		$id_carrier1 = $this->installExternalCarrier($carrierConfig[0]);
		Configuration::updateValue('PICKME_CARRIER_ID', (int)$id_carrier1);
		Configuration::updateValue('PICKME_OVERCOST', 0);
		if (!parent::install() ||
		    !Configuration::updateValue('PICKME_OVERCOST', '') ||
		    !$this->registerHook('updateCarrier') ||
		    !$this->registerHook('displayCarrierList') ||
		    !$this->registerHook('displayAdminOrder') ||
		    !$this->registerHook('newOrder') ||
		    !$this->registerHook('header') ||
		    !$this->registerHook('processCarrier') ||
		    !$this->registerHook('displayBeforeCarrier'))
			return false;
		return true;
	}

	public function uninstall()
	{
		// Uninstall
		if (!parent::uninstall() ||
		    !Configuration::deleteByName('PICKME_OVERCOST') ||
		    !Configuration::deleteByName('PICKME_WEBSERVICE') ||
		    !$this->unregisterHook('updateCarrier'))
			return false;

		// Delete External Carrier
		$Carrier1 = new Carrier((int)(Configuration::get('PICKME_CARRIER_ID')));

		// If external carrier is default set other one as default
		if (Configuration::get('PS_CARRIER_DEFAULT') == (int)($Carrier1->id))
		{
			global $cookie;
			$carriersD = Carrier::getCarriers($cookie->id_lang, true, false, false, NULL, PS_CARRIERS_AND_CARRIER_MODULES_NEED_RANGE);
			foreach($carriersD as $carrierD)
				if ($carrierD['active'] AND !$carrierD['deleted'] AND ($carrierD['name'] != $this->_config['name']))
					Configuration::updateValue('PS_CARRIER_DEFAULT', $carrierD['id_carrier']);
		}

		// Then delete Carrier
		$Carrier1->deleted = 1;
		if (!$Carrier1->update())
			return false;

		return true;
	}

	public static function installExternalCarrier($config)
	{
		$carrier = new Carrier();
		$carrier->name = $config['name'];
		$carrier->id_tax_rules_group = $config['id_tax_rules_group'];
		$carrier->id_zone = $config['id_zone'];
		$carrier->active = $config['active'];
		$carrier->deleted = $config['deleted'];
		$carrier->delay = $config['delay'];
		$carrier->shipping_handling = $config['shipping_handling'];
		$carrier->range_behavior = $config['range_behavior'];
		$carrier->is_module = $config['is_module'];
		$carrier->shipping_external = $config['shipping_external'];
		$carrier->external_module_name = $config['external_module_name'];
		$carrier->need_range = $config['need_range'];

		$languages = Language::getLanguages(true);
		foreach ($languages as $language)
		{
			if ($language['iso_code'] == 'fr')
				$carrier->delay[(int)$language['id_lang']] = $config['delay'][$language['iso_code']];
			if ($language['iso_code'] == 'en')
				$carrier->delay[(int)$language['id_lang']] = $config['delay'][$language['iso_code']];
			if ($language['iso_code'] == Language::getIsoById(Configuration::get('PS_LANG_DEFAULT')))
				$carrier->delay[(int)$language['id_lang']] = $config['delay'][$language['iso_code']];
		}

		if ($carrier->add())
		{
			$groups = Group::getGroups(true);
			foreach ($groups as $group)
				Db::getInstance()->autoExecute(_DB_PREFIX_.'carrier_group', array('id_carrier' => (int)($carrier->id), 'id_group' => (int)($group['id_group'])), 'INSERT');

			$rangePrice = new RangePrice();
			$rangePrice->id_carrier = $carrier->id;
			$rangePrice->delimiter1 = '0';
			$rangePrice->delimiter2 = '10000';
			$rangePrice->add();

			$rangeWeight = new RangeWeight();
			$rangeWeight->id_carrier = $carrier->id;
			$rangeWeight->delimiter1 = '0';
			$rangeWeight->delimiter2 = '10000';
			$rangeWeight->add();

			$zones = Zone::getZones(true);
			foreach ($zones as $zone)
			{
				Db::getInstance()->autoExecute(_DB_PREFIX_.'carrier_zone', array('id_carrier' => (int)($carrier->id), 'id_zone' => (int)($zone['id_zone'])), 'INSERT');
				Db::getInstance()->autoExecuteWithNullValues(_DB_PREFIX_.'delivery', array('id_carrier' => (int)($carrier->id), 'id_range_price' => (int)($rangePrice->id), 'id_range_weight' => NULL, 'id_zone' => (int)($zone['id_zone']), 'price' => '0'), 'INSERT');
				Db::getInstance()->autoExecuteWithNullValues(_DB_PREFIX_.'delivery', array('id_carrier' => (int)($carrier->id), 'id_range_price' => NULL, 'id_range_weight' => (int)($rangeWeight->id), 'id_zone' => (int)($zone['id_zone']), 'price' => '0'), 'INSERT');
			}

			// Copy Logo
			if (!copy(dirname(__FILE__).'/carrier.jpg', _PS_SHIP_IMG_DIR_.'/'.(int)$carrier->id.'.jpg'))
				return false;

			// Return ID Carrier
			return (int)($carrier->id);
		}

		return false;
	}


	/*
	** Hooks
	**
	*/

	public function hookHeader()
	{
		$html = '<script type="text/javascript" src="'.__PS_BASE_URI__.'/modules/pickme_chronopost/js/jquery.ddslick.min.js"></script>';
		return $html;
	}

	public function hookProcessCarrier($params)
	{

	}

	public function hookNewOrder($params)
	{
		// cart, customer, order, altern
		if (($params['cart']->id_carrier) == ((int)(Configuration::get('PICKME_CARRIER_ID')))) {
			error_log("pickme order");
			$query = '
				INSERT INTO `'._DB_PREFIX_.'pickme_shop_orders`
							 (id_pickme_shop, id_order)
				VALUES ("'.$_COOKIE["pickme_store"].'", '.(int)$params['order']->id.')';
			Db::getInstance()->execute($query);
			// print_r($_COOKIE["pickme_store"]);
			// print_r($params);
			// die();
		}
	}

	public function hookDisplayAdminOrder($params)
  {
  	if (($params['cart']->id_carrier) == ((int)(Configuration::get('PICKME_CARRIER_ID')))) {
	  	$pickme_shop_id = Db::getInstance()->getValue('
					SELECT id_pickme_shop FROM `'._DB_PREFIX_.'pickme_shop_orders`
					WHERE id_order='.$params['id_order']);

	  	$result = Db::getInstance()->getRow('
				SELECT *
				FROM `' . _DB_PREFIX_ . 'pickme_shops`
				WHERE id_pickme_shop='.$pickme_shop_id);

	    $html = '<br/><fieldset>';
	    $html .= '<legend><img src="../img/admin/delivery.gif"> PickMe Chronopost delivery locations:</legend>';
	    $html .= '<ul>';
	    $html .= '<li>Name: '.$result['name'].'</li>';
	    $html .= '<li>Address: '.$result['address'].'</li>';
	    $html .= '<li>Location: '.$result['location'].'</li>';
	    $html .= '<li>Postal Code: '.$result['postal_code'].'</li>';
	    $html .= '</ul>';
	    $html .= '</fieldset>';

	    return $html;
	  }
  }

  public function hookDisplayCarrierList($params)
  {
  	// print_r($params);
  	// die();
  	$script = '<script type="text/javascript">';
  	$script .= "$(document).ready(function() {";
  	$script .= "$('.pickme_stores_select:not(:first)').remove();";
  	$script .= "$($('input[value=\"".(Configuration::get('PICKME_CARRIER_ID'))."\"]').closest('label').find('table td')[1]).append($('#pickme_stores'));";
  	$script .= "document.cookie = 'pickme_store='+$('#pickme_stores').val();";
  	$script .= "$('#pickme_stores').change(function(){document.cookie = 'pickme_store='+$('#pickme_stores').val();})";
    //$script .= "$('#pickme_stores').ddslick();";
  	$script .= "});";
  	$script .= '</script>';

  	$html = $script;

  	$list = '<select id="pickme_stores" class="pickme_stores_select"><optgroup';

  	$sql = 'SELECT * FROM '._DB_PREFIX_.'pickme_shops order by location asc';// WHERE postal_code like "%'.substr(($params['address']->postcode), 0, 4).'%"';
		if ($results = Db::getInstance()->ExecuteS($sql)) {
			$curLocation = null;
			foreach ($results as $row) {
                $address = explode(' ',$row['address']);
                foreach ($address as $i=>$v) $address[$i] = ucfirst(strtolower($v));
                $address1 = implode(' ', $address);

                if ($curLocation==null) {
                    $list .= ' label="'.$row['location'].'">';
                    $curLocation = $row['location'];
                }
                if ($curLocation!=null && $row['location']!=$curLocation) {
                    $curLocation = $row['location'];
                    $list .= '</optgroup> <optgroup label="'.$curLocation.'">';
                }

				$list .= '<option value="'.$row['id_pickme_shop'].'">'.$row['name'].' - '.$address1.'</option>';
			}
		}
		/*
		$list .= '<option disabled="disabled">---</option>';

		$sql = 'SELECT * FROM '._DB_PREFIX_.'pickme_shops WHERE postal_code not like "%'.substr(($params['address']->postcode), 0, 4).'%"';
		if ($results = Db::getInstance()->ExecuteS($sql))
			foreach ($results as $row)
				$list .= '<option value="'.$row['id_pickme_shop'].'">'.$row['name'].' - '.$row['location'].'</option>';
		*/

    // $client = new SoapClient("https://83.240.239.170:7554/ChronoWSB2CPointsv3/GetB2CPoints_v3Service?wsdl");
    // //$result = $client->getPointList_V3(array('pPointPostalCode'=>4150));
    // $result = $client->getPointList_V3(array('pPointPostalCode'=>substr(($params['address']->postcode), 0, 4)));
    // // //$result = $client->getPointList_V3();
    // // $html = '<div class="block&quot"<h4>'. Configuration::get($this->name.'_message') . '</h4></div>';
    // $list = '<select id="pickme_stores" class="pickme_stores_select">';

    // foreach ($result->return->lB2CPointsArr as $message) {
    //     $list .= '<option value="'.$message->Number.'">'.$message->Name.'</option>';
    // }

    $list .= '</optgroup></select>';

    $html .= $list;

    return $html;
  }

  public function hookDisplayBeforeCarrier()
  {
  	return "before carrier hook";
  }



	/*
	** Form Config Methods
	**
	*/

	public function getContent()
	{
		$this->_html .= '<h2>' . $this->l('PickMe Chronopost').'</h2>';
		if (!empty($_POST) AND Tools::isSubmit('updateDatabase')) {
			if (Tools::getValue('pickme_refresh') == 'true') {
				$this->updateDatabase();
				$this->_html .= $this->displayConfirmation($this->l('Database updated'));
			}
		}

		if (!empty($_POST) AND Tools::isSubmit('submitSave'))
		{
			$this->_postValidation();
			if (!sizeof($this->_postErrors))
				$this->_postProcess();
			else
				foreach ($this->_postErrors AS $err)
					$this->_html .= '<div class="alert error"><img src="'._PS_IMG_.'admin/forbbiden.gif" alt="nok" />&nbsp;'.$err.'</div>';
		}
		$this->_displayForm();
		return $this->_html;
	}

	private function _displayForm()
	{
		$this->_html .= '<fieldset>
		<legend><img src="'.$this->_path.'logo.gif" alt="" /> '.$this->l('PickMe Chronopost Module Status').'</legend>';

		$alert = array();
		// if (!Configuration::get('PICKME_OVERCOST') || Configuration::get('PICKME_OVERCOST') == '')
		// 	$alert['overcost'] = 1;

		if (!count($alert))
			$this->_html .= '<img src="'._PS_IMG_.'admin/module_install.png" /><strong>'.$this->l('PickMe Chronopost is configured and online!').'</strong>';
		else
		{
			$this->_html .= '<img src="'._PS_IMG_.'admin/warn2.png" /><strong>'.$this->l('PickMe Chronopost is not configured yet, please:').'</strong>';
			$this->_html .= '<br />'.(isset($alert['overcost']) ? '<img src="'._PS_IMG_.'admin/warn2.png" />' : '<img src="'._PS_IMG_.'admin/module_install.png" />').' 1) '.$this->l('Configure the PickMe overcost');
		}

		$this->_html .= '</fieldset><div class="clear">&nbsp;</div>
			<style>
			</style>
			<div id="tabList">
				<div class="tabItem">
					<form action="index.php?tab='.Tools::getValue('tab').'&configure='.Tools::getValue('configure').'&token='.Tools::getValue('token').'&tab_module='.Tools::getValue('tab_module').'&module_name='.Tools::getValue('module_name').'&id_tab=1&section=general" method="post" class="form" id="configForm">

						<fieldset style="border: 0px;">
							<h4>'.$this->l('General configuration').' :</h4>
							<label>'.$this->l('PickMe webservice').' : </label>
							<div class="margin-form"><input type="text" size="100" name="pickme_webservice" value="'.Tools::getValue('pickme_webservice', Configuration::get('PICKME_WEBSERVICE')).'" /></div>
							<label>'.$this->l('PickMe overcost').' : </label>
							<div class="margin-form"><input type="text" size="20" name="pickme_overcost" value="'.Tools::getValue('pickme_overcost', Configuration::get('PICKME_OVERCOST')).'" /></div>
							<div class="margin-form"><input class="button" name="submitSave" type="submit" value="Save"></div>
						</fieldset>
					</form>
				</div>

				<div class="tabItem">
					<form action="index.php?tab='.Tools::getValue('tab').'&configure='.Tools::getValue('configure').'&token='.Tools::getValue('token').'&tab_module='.Tools::getValue('tab_module').'&module_name='.Tools::getValue('module_name').'&pickme_refresh=true" method="post" class="form" id="configForm">
						<fieldset style="border: 0px;">
							<h4>'.$this->l('Use this button to update the PickMe Available Stores').' :</h4>
							<div class="margin-form"><input class="button" name="updateDatabase" type="submit" value="Update Database"></div>
						</fieldset>
					</form>
				</div>
			</div>';
	}

	private function _postValidation()
	{
		// Check configuration values
		if (Tools::getValue('pickme_overcost') == '')
			$this->_postErrors[]  = $this->l('You have to configure PickMe Chronopost');
	}

	private function _postProcess()
	{
		// Saving new configurations
		if (Configuration::updateValue('PICKME_OVERCOST', Tools::getValue('pickme_overcost')) &&
				Configuration::updateValue('PICKME_WEBSERVICE', Tools::getValue('pickme_webservice')))
			$this->_html .= $this->displayConfirmation($this->l('Settings updated'));
		else
			$this->_html .= $this->displayErrors($this->l('Settings failed'));
	}


	/*
	** Hook update carrier
	**
	*/

	public function hookupdateCarrier($params)
	{
		if ((int)($params['id_carrier']) == (int)(Configuration::get('PICKME_CARRIER_ID')))
			Configuration::updateValue('PICKME_CARRIER_ID', (int)($params['carrier']->id));
	}




	/*
	** Front Methods
	**
	** If you set need_range at true when you created your carrier (in install method), the method called by the cart will be getOrderShippingCost
	** If not, the method called will be getOrderShippingCostExternal
	**
	** $params var contains the cart, the customer, the address
	** $shipping_cost var contains the price calculated by the range in carrier tab
	**
	*/

	public function getOrderShippingCost($params, $shipping_cost)
	{
		// This example returns shipping cost with overcost set in the back-office, but you can call a webservice or calculate what you want before returning the final value to the Cart
		if ($this->id_carrier == (int)(Configuration::get('PICKME_CARRIER_ID')) && Configuration::get('PICKME_OVERCOST') >= 0)
			return (float)(Configuration::get('PICKME_OVERCOST'));

		// If the carrier is not known, you can return false, the carrier won't appear in the order process
		return false;
	}

	public function getOrderShippingCostExternal($params)
	{
		// This example returns the overcost directly, but you can call a webservice or calculate what you want before returning the final value to the Cart
		if ($this->id_carrier == (int)(Configuration::get('PICKME_CARRIER_ID')) && Configuration::get('PICKME_OVERCOST') >= 0)
			return (float)(Configuration::get('PICKME_OVERCOST'));

		// If the carrier is not known, you can return false, the carrier won't appear in the order process
		return false;
	}

}