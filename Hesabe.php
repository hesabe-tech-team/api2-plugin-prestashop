<?php
/**
* 2007-2020 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author    PrestaShop SA <contact@prestashop.com>
*  @copyright 2007-2020 PrestaShop SA
*  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/

if (!defined('_PS_VERSION_')) {
    exit;
}

class Hesabe extends PaymentModule
{
    protected $config_form = false;

    public function __construct()
    {
        $this->name = 'Hesabe';
        $this->tab = 'payments_gateways';
        $this->version = '2.0.0';
        $this->author = 'Atlantechgs';
        $this->need_instance = 0;

        /**
         * Set $this->bootstrap to true if your module is compliant with bootstrap (PrestaShop 1.6)
         */
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Hesabe Payment Gateway');
        $this->description = $this->l('Pay via KNET or MPGS');

        $this->confirmUninstall = $this->l('Are you sure you want to uninstall hesabe?');

        $this->limited_countries = array();//array('FR');

        $this->limited_currencies = array('KWD'); //array('EUR');

        $this->ps_versions_compliancy = array('min' => '1.6', 'max' => _PS_VERSION_);
    }

    /**
     * Don't forget to create update methods if needed:
     * http://doc.prestashop.com/display/PS16/Enabling+the+Auto-Update
     */
    public function install()
    {
        if (extension_loaded('curl') == false)
        {
            $this->_errors[] = $this->l('You have to enable the cURL extension on your server to install this module');
            return false;
        }

        $iso_code = Country::getIsoById(Configuration::get('PS_COUNTRY_DEFAULT'));

        if (!empty($this->limited_countries) and in_array($iso_code, $this->limited_countries) == false)
        {
            $this->_errors[] = $this->l('This module is not available in your country');
            return false;
        }

        $this->registerHook('header');

        Configuration::updateValue('HESABE_LIVE_MODE', false);

        return parent::install() &&
            $this->registerHook('header') &&
            $this->registerHook('backOfficeHeader') &&
            $this->registerHook('payment') &&
            $this->registerHook('paymentReturn') &&
            $this->registerHook('paymentOptions');
    }

    public function uninstall()
    {
        Configuration::deleteByName('HESABE_LIVE_MODE');
        Configuration::deleteByName('HESABE_MERCHANT_ID');
        Configuration::deleteByName('HESABE_ACCESS_CODE');
        Configuration::deleteByName('HESABE_SECRET_KEY');
        Configuration::deleteByName('HESABE_IV_KEY');
        Configuration::deleteByName('HESABE_VAR_1');
        Configuration::deleteByName('HESABE_VAR_2');
        Configuration::deleteByName('HESABE_VAR_3');
        Configuration::deleteByName('HESABE_VAR_4');
        Configuration::deleteByName('HESABE_VAR_5');

        return parent::uninstall();
    }

    /**
     * Load the configuration form
     */
    public function getContent()
    {
        /**
         * If values have been submitted in the form, process.
         */
        if (((bool)Tools::isSubmit('submitHesabeModule')) == true) {
            $this->postProcess();
        }

        $this->context->smarty->assign('module_dir', $this->_path);

        //$output = $this->context->smarty->fetch($this->local_path.'views/templates/admin/configure.tpl');

        return $this->renderForm(); //$output.$this->renderForm();
    }

    /**
     * Create the form that will be displayed in the configuration of your module.
     */
    protected function renderForm()
    {
        $helper = new HelperForm();

        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);

        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitHesabeModule';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            .'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFormValues(), /* Add values for your inputs */
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        );

        return $helper->generateForm(array($this->getConfigForm()));
    }

    /**
     * Create the structure of your form.
     */
    protected function getConfigForm()
    {
        return array(
            'form' => array(
                'legend' => array(
                'title' => $this->l('Settings'),
                'icon' => 'icon-cogs',
                ),
                'input' => array(
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Live mode'),
                        'name' => 'HESABE_LIVE_MODE',
                        'is_bool' => true,
                        'desc' => $this->l('Use this module in live mode'),
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => true,
                                'label' => $this->l('Enabled')
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => false,
                                'label' => $this->l('Disabled')
                            )
                        ),
                    ),
                    array(
                        'col' => 3,
                        'type' => 'text',
                        'desc' => $this->l('Enter your Hesabe Merchant ID.'),
                        'name' => 'HESABE_MERCHANT_ID',
                        'label' => $this->l('Merchant ID'),
                        'required' => true
                    ),
                    array(
                        'col' => 3,
                        'type' => 'text',
                        'desc' => $this->l('Enter your Hesabe Access Code.'),
                        'name' => 'HESABE_ACCESS_CODE',
                        'label' => $this->l('Access Code'),
                        'required' => true
                    ),
                    array(
                        'col' => 3,
                        'type' => 'text',
                        'desc' => $this->l('Enter your Hesabe Secret Key.'),
                        'name' => 'HESABE_SECRET_KEY',
                        'label' => $this->l('Secret Key'),
                        'required' => true
                    ),
                    array(
                        'col' => 3,
                        'type' => 'text',
                        'desc' => $this->l('Enter your Hesabe IV Key.'),
                        'name' => 'HESABE_IV_KEY',
                        'label' => $this->l('IV Key'),
                        'required' => true
                    ),
                    array(
                        'col' => 3,
                        'type' => 'text',
                        'name' => 'HESABE_VAR_1',
                        'desc' => $this->l('Optional'),
                        'label' => $this->l('Custom Return Variable 1'),
                    ),
                    array(
                        'col' => 3,
                        'type' => 'text',
                        'name' => 'HESABE_VAR_2',
                        'desc' => $this->l('Optional'),
                        'label' => $this->l('Custom Return Variable 2'),
                    ),
                    array(
                        'col' => 3,
                        'type' => 'text',
                        'name' => 'HESABE_VAR_3',
                        'desc' => $this->l('Optional'),
                        'label' => $this->l('Custom Return Variable 3'),
                    ),
                    array(
                        'col' => 3,
                        'type' => 'text',
                        'name' => 'HESABE_VAR_4',
                        'desc' => $this->l('Optional'),
                        'label' => $this->l('Custom Return Variable 4'),
                    ),
                    array(
                        'col' => 3,
                        'type' => 'text',
                        'name' => 'HESABE_VAR_5',
                        'desc' => $this->l('Optional'),
                        'label' => $this->l('Custom Return Variable 5'),
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                ),
            ),
        );
    }

    /**
     * Set values for the inputs.
     */
    protected function getConfigFormValues()
    {
        return array(
            'HESABE_LIVE_MODE' => Configuration::get('HESABE_LIVE_MODE', false),
            'HESABE_MERCHANT_ID' => Configuration::get('HESABE_MERCHANT_ID', null),
            'HESABE_ACCESS_CODE' => Configuration::get('HESABE_ACCESS_CODE', null),
            'HESABE_SECRET_KEY' => Configuration::get('HESABE_SECRET_KEY', null),
            'HESABE_IV_KEY' => Configuration::get('HESABE_IV_KEY', null),
            'HESABE_VAR_1' => Configuration::get('HESABE_VAR_1', null),
            'HESABE_VAR_2' => Configuration::get('HESABE_VAR_2', null),
            'HESABE_VAR_3' => Configuration::get('HESABE_VAR_3', null),
            'HESABE_VAR_4' => Configuration::get('HESABE_VAR_4', null),
            'HESABE_VAR_5' => Configuration::get('HESABE_VAR_5', null),
        );
    }

    /**
     * Save form data.
     */
    protected function postProcess()
    {
        $form_values = $this->getConfigFormValues();

        $err = 0;
        if(Tools::getValue('HESABE_MERCHANT_ID') == ""){
            $this->context->controller->errors[] = $this->l('You must specify merchant id.');
            $err = 1;
        }
        if(Tools::getValue('HESABE_ACCESS_CODE') == ""){
            $this->context->controller->errors[] = $this->l('You must specify access code.');
            $err = 1;
        }
        if(Tools::getValue('HESABE_SECRET_KEY') == ""){
            $this->context->controller->errors[] = $this->l('You must specify secret key.');
            $err = 1;
        }
        if(Tools::getValue('HESABE_IV_KEY') == ""){
            $this->context->controller->errors[] = $this->l('You must specify IV key.');
            $err = 1;
        }

        foreach (array_keys($form_values) as $key) {
            Configuration::updateValue($key, Tools::getValue($key));
        }

        if($err == 0){
            $this->context->controller->confirmations[] = $this->l('Module configured successfully.');    
        }
        

    }

    /**
    * Add the CSS & JavaScript files you want to be loaded in the BO.
    */
    public function hookBackOfficeHeader()
    {
        if (Tools::getValue('module_name') == $this->name) {
            $this->context->controller->addJS($this->_path.'views/js/back.js');
            $this->context->controller->addCSS($this->_path.'views/css/back.css');
        }
    }

    /**
     * Add the CSS & JavaScript files you want to be added on the FO.
     */
    public function hookHeader()
    {
        $this->context->controller->addJS($this->_path.'/views/js/front.js');
        $this->context->controller->addCSS($this->_path.'/views/css/front.css');
    }

    /**
     * This method is used to render the payment button,
     * Take care if the button should be displayed or not.
     */
    public function hookPayment($params)
    {
        $currency_id = $params['cart']->id_currency;
        $currency = new Currency((int)$currency_id);

        if (in_array($currency->iso_code, $this->limited_currencies) == false)
            return false;

        $this->smarty->assign('module_dir', $this->_path);

        return $this->display(__FILE__, 'views/templates/hook/payment.tpl');
    }

    /**
     * This hook is used to display the order confirmation page.
     */
    public function hookPaymentReturn($params)
    {
        if ($this->active == false)
            return;

        $order = $params['order'];
        
        if ($order->getCurrentOrderState()->id != Configuration::get('PS_OS_ERROR'))
            $this->smarty->assign('status', 'ok');
            
        $this->smarty->assign(array(
            'id_order' => $order->id,
            'reference' => $order->reference,
            'params' => $params,
            'total' => 'KWD '.round($order->total_paid,2)
        ));

        return $this->display(__FILE__, 'views/templates/hook/confirmation.tpl');
    }

    /**
     * Return payment options available for PS 1.7+
     *
     * @param array Hook parameters
     *
     * @return array|null
     */
    public function hookPaymentOptions($params)
    {
        if (!$this->active) {
            return;
        }
        if (!$this->checkCurrency($params['cart'])) {
            return;
        }
        $option = new \PrestaShop\PrestaShop\Core\Payment\PaymentOption();
        $option->setCallToActionText($this->l('Pay with Hesabe (KNET / MPGS)'))
            ->setAction($this->context->link->getModuleLink($this->name, 'validation', array(), true));

        return [
            $option
        ];
    }

    public function checkCurrency($cart)
    {
        $currency_order = new Currency($cart->id_currency);
        $currencies_module = $this->getCurrency($cart->id_currency);
        
        if (is_array($currencies_module)) {
            $kwdIndex = array_search('KWD', array_column($currencies_module,'iso_code'));
            /*foreach ($currencies_module as $currency_module) {
                if ($currency_order->id == $currency_module['id_currency']) {
                    return true;
                }
            }*/
            if($currencies_module[$kwdIndex]['id_currency'] == $currency_order->id){
                return true;
            }
        }
        return false;
    }
}
