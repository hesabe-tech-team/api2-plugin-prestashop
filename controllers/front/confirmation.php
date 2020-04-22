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
class HesabeConfirmationModuleFrontController extends ModuleFrontController
{
    public function postProcess()
    {
        //ini_set('display_errors',1);
        //error_reporting(E_ALL);

        //phpinfo(); exit;

        require_once _PS_MODULE_DIR_.'Hesabe/HesabeCrypt.php';

        $ivKey = Configuration::get('HESABE_IV_KEY');
        $encryptionKey = Configuration::get('HESABE_SECRET_KEY'); 
        $decryptedResponse = HesabeCrypt::decrypt($_GET['data'], $encryptionKey, $ivKey);

        $payment_response = json_decode($decryptedResponse);
        $payment_status = $payment_response->response->resultCode;
        $payment_id = $payment_response->response->paymentId;
        $message = $payment_response->message;

        if($payment_response->code === 0){
            $this->context->smarty->assign(['errors' => $this->module->l('Transaction Failed. Response Code : '.$payment_status)]);
            return $this->setTemplate('module:/Hesabe/views/templates/front/error.tpl');
        }

        if ((Tools::getValue('cart_id') == false) || (Tools::getValue('secure_key') == false)) {
            return false;
        }

        
        $cart_id = Tools::getValue('cart_id');
        $secure_key = Tools::getValue('secure_key');
        
        $cart = new Cart((int) $cart_id);
        $customer = new Customer((int) $cart->id_customer);
        
        $extraVars = array(
            'PaymentToken' => $payment_response->response->paymentToken,
            'PaymentId' => $payment_response->response->paymentId,
            'PaidOn' => $payment_response->response->paidOn
        );

        /**
         * Converting cart into a valid order
         */
        $module_name = $this->module->displayName;
        $currency_id = (int) Context::getContext()->currency->id;
        
        $this->module->validateOrder($cart_id, Configuration::get('PS_OS_PAYMENT'), $cart->getOrderTotal(), $module_name, $message, $extraVars, $currency_id, false, $secure_key);
        
        /**
         * If the order has been validated we try to retrieve it
         */
        $order_id = Order::getOrderByCartId((int) $cart->id);

        if ($order_id && ($secure_key == $customer->secure_key)) {
            
            //update transaction id
            $orderPayment = OrderPaymentCore::getByOrderId($order_id);
            $order_payment_obj = new OrderPayment($orderPayment[0]->id);
            $order_payment_obj->transaction_id = $extraVars['PaymentToken'];
            $order_payment_obj->update();

            /**
             * The order has been placed so we redirect the customer on the confirmation page.
             */
            $module_id = $this->module->id;
            Tools::redirect('index.php?controller=order-confirmation&id_cart=' . $cart_id . '&id_module=' . $module_id . '&id_order=' . $order_id . '&key=' . $secure_key);
        } else {
            /*
             * An error occured and is shown on a new page.
             */
            $this->errors[] = $this->module->l('An error occured. Please contact the merchant to have more informations');
            return $this->setTemplate('error.tpl');
        }
    }
}
