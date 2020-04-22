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

class HesabeRedirectModuleFrontController extends ModuleFrontController
{
    /**
     * Do whatever you have to before redirecting the customer on the website of your payment processor.
     */
    public function postProcess()
    {
        /*
         * Oops, an error occured.
         */
        if (Tools::getValue('action') == 'error') {
            return $this->displayError('An error occurred while trying to redirect the customer');
        } else {
            /*$arr = array(
                'cart_id' => Context::getContext()->cart->id,
                'secure_key' => Context::getContext()->customer->secure_key,
            );
            $this->context->smarty->assign($arr);*/

            require_once _PS_MODULE_DIR_.'Hesabe/HesabeCrypt.php';
            
            if(Configuration::get('HESABE_LIVE_MODE') == false){
                //$baseUrl = 'http://api.hesbstaging.com';    
                $baseUrl = 'https://sandbox.hesabe.com';    
            } else {
                $baseUrl = 'https://api.hesabe.com';
            }

            $ivKey = Configuration::get('HESABE_IV_KEY');
            $encryptionKey = Configuration::get('HESABE_SECRET_KEY'); 
            $accessCode = Configuration::get('HESABE_ACCESS_CODE');
            
            $paymentUrl = $baseUrl.'/payment';
            $checkoutApiUrl = $baseUrl.'/checkout'; 

            $custom_vars = array();
            for($i = 1; $i<=5; $i++){
                $var = 'var'.$i;
                $custom_vars[$var] = Configuration::get('HESABE_VAR_'.$i); 
            }

            $custom_vars = array_filter($custom_vars);

            $amount = (float) Context::getContext()->cart->getOrderTotal(true, Cart::BOTH);

            $requestArr = array(
                'amount' => $amount,
                'responseUrl' => $_SERVER['SCRIPT_URI'].'?fc=module&module=Hesabe&controller=confirmation&cart_id='.Context::getContext()->cart->id.'&secure_key='.Context::getContext()->customer->secure_key,
                'failureUrl' => $_SERVER['SCRIPT_URI'].'?fc=module&module=Hesabe&controller=confirmation&cart_id='.Context::getContext()->cart->id.'&secure_key='.Context::getContext()->customer->secure_key,
                'merchantCode' => Configuration::get('HESABE_MERCHANT_ID'),
                'paymentType' => 0,
                'version' => '2.0',
                'orderReferenceNumber' => Context::getContext()->cart->id
            );

            $requestArr = array_merge($requestArr, $custom_vars);

            $requestDataJson = json_encode($requestArr);
            $encryptedData = HesabeCrypt::encrypt($requestDataJson, $encryptionKey, $ivKey);
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL,$checkoutApiUrl);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(array('data' => $encryptedData)));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["accesscode: $accessCode"]);
            $server_output = curl_exec($ch);
            $curl_info = curl_getinfo($ch);

            //echo '<pre>'; print_r($curl_info); exit;
            
            curl_close ($ch);

            //checking if payment gateway is available
            if($curl_info['http_code'] == 404){
                return $this->displayError('Payment Gateway Unavailable. Please try again later.');
            } else if($curl_info['http_code'] == 403){
                return $this->displayError('Incorrect Hesabe Credentials or invalid request');
            } else if($curl_info['http_code'] == 401){
                return $this->displayError('Merchant is inactive');
            }

            $decryptedResponse = HesabeCrypt::decrypt($server_output, $encryptionKey, $ivKey);
            $responseDataJson = json_decode($decryptedResponse);
            
            if(isset($responseDataJson->code) and $responseDataJson->code == 200){
                $responseToken = $responseDataJson->response->data;
                header('Location: '.$paymentUrl . '?data='. $responseToken);
            } else if(isset($responseDataJson->code) and $responseDataJson->code == 0){
                return $this->displayError('Invalid Response');
            } else if(isset($responseDataJson->code) and $responseDataJson->code == 422){
                return $this->displayError('Invalid Response');
            } else if(isset($responseDataJson->code) and $responseDataJson->code == 500){
                return $this->displayError('Invalid Token');
            } else if(isset($responseDataJson->code) and $responseDataJson->code == 501){
                return $this->displayError('Invalid Merchant');
            } else if(isset($responseDataJson->code) and $responseDataJson->code == 503){
                return $this->displayError('Invalid Merchant Service');
            } else if(isset($responseDataJson->code) and $responseDataJson->code == 504){
                return $this->displayError('Invalid Merchant Login Credentials');
            } else if(isset($responseDataJson->code) and $responseDataJson->code == 505){
                return $this->displayError('Invalid Payment Token');
            } else if(isset($responseDataJson->code) and $responseDataJson->code == 506){
                return $this->displayError('Invalid Request Data');
            } else if(isset($responseDataJson->code) and $responseDataJson->code == 507){
                return $this->displayError('Transaction Error');
            } else {
                return $this->displayError('Incorrect Hesabe Credentials');
            }

            //return $this->setTemplate('module:/Hesabe/views/templates/front/redirect.tpl');
        }
    }

    protected function displayError($message, $description = false)
    {
        /*
         * Create the breadcrumb for your ModuleFrontController.
         */
        $this->context->smarty->assign('path', '
			<a href="' . $this->context->link->getPageLink('order', null, null, 'step=3') . '">' . $this->module->l('Payment') . '</a>
			<span class="navigation-pipe">&gt;</span>' . $this->module->l('Error'));

        /*
         * Set error message and description for the template.
         */
        $this->context->smarty->assign(['errors' => $this->module->l($message)]);

        return $this->setTemplate('module:/Hesabe/views/templates/front/error.tpl');
    }
}
