<?php
/**
 * Copyright (c) 2026 the hesabe SDK authors. MIT License, see LICENSE.
 */

use Hesabe\Exception\HesabeException;
use Hesabe\PrestaShop\Attempt;
use Hesabe\PrestaShop\Methods;
use Hesabe\PrestaShop\Settings;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Where checkout's "Place order" posts: starts a payment attempt and sends the customer to
 * Hesabe. No order exists yet, so an abandoned KNET page leaves no cancelled order or held
 * stock behind.
 *
 * @property Hesabe $module
 */
class HesabeRedirectModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function postProcess(): void
    {
        $cart = $this->context->cart;
        $checkout = $this->context->link->getPageLink('order', true);
        if (
            !$this->module->active
            || !Settings::isConfigured()
            || !Validate::isLoadedObject($cart)
            || (int) $cart->id_customer === 0
            || (int) $cart->id_address_delivery === 0
            || (int) $cart->id_address_invoice === 0
            || $cart->nbProducts() === 0
            || !$this->isOffered()
        ) {
            Tools::redirect($checkout);
        }
        if ($cart->orderExists()) {
            Tools::redirect($this->context->link->getPageLink('history', true));
        }
        $customer = new Customer((int) $cart->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            Tools::redirect($checkout);
        }

        $method = (string) Tools::getValue('method');
        if (!in_array($method, Settings::methods(), true)) {
            Tools::redirect($checkout);
        }

        try {
            Tools::redirect(Attempt::start($this->context, $cart, $method));
        } catch (HesabeException $exception) {
            $this->errors[] = $this->module->customerError($exception);
            $this->redirectWithNotifications($checkout);
        }
    }

    /** The cart's currency and the shop must still allow this module. */
    private function isOffered(): bool
    {
        foreach (Module::getPaymentModules() as $module) {
            if ($module['name'] === $this->module->name) {
                return in_array((string) Tools::getValue('method'), array_keys(Methods::all()), true);
            }
        }
        return false;
    }
}
