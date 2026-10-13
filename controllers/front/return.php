<?php
/**
 * Copyright (c) 2026 the hesabe SDK authors. MIT License, see LICENSE.
 */

use Hesabe\PrestaShop\Payments;
use Hesabe\PrestaShop\Settings;
use Hesabe\PrestaShop\Settlement;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Where Hesabe sends the customer back, after success or failure alike.
 *
 * The `data` Hesabe appends is ignored: it is encrypted but carries no MAC and passed
 * through the customer's browser. The reference in the signed URL names the attempt, and a
 * lookup at Hesabe decides what happened.
 *
 * @property Hesabe $module
 */
class HesabeReturnModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    /** The page re-checks itself this many times, five seconds apart, before giving up. */
    private const MAX_WAITS = 6;

    public function postProcess(): void
    {
        $reference = (string) Tools::getValue('ref');
        $payment = $reference === '' ? null : Payments::byReference($reference);
        if ($payment === null || !hash_equals(Settings::sign($reference), (string) Tools::getValue('key'))) {
            Tools::redirect($this->context->link->getPageLink('order', true));
        }

        $settlement = new Settlement($this->module);
        // The webhook usually lands first and creates the order; if not, a few quick lookups
        // cover the second or two Hesabe takes to record the payment.
        for ($try = 0; $try < 3 && $payment['status'] === Payments::PENDING; $try++) {
            if ($try > 0) {
                usleep(1_500_000);
            }
            $payment = $settlement->settle($payment);
        }

        if ($payment['status'] === Payments::PAID) {
            $this->confirm($payment);
        }
        if ($payment['status'] === Payments::PENDING) {
            $this->wait($reference);
            return;
        }
        if (in_array($payment['status'], [Payments::DUPLICATE, Payments::ERROR], true)) {
            $this->errors[] = $this->module->l('Your payment was received but the order needs our attention. We will contact you; please do not pay again.', 'return');
        } else {
            $this->errors[] = $this->module->l('The payment was not completed. You have not been charged; please try again.', 'return');
        }
        $this->redirectWithNotifications($this->context->link->getPageLink('order', true));
    }

    /**
     * @param array{id_cart: string, id_order: ?string} $payment
     */
    private function confirm(array $payment): void
    {
        $cart = new Cart((int) $payment['id_cart']);
        $customer = new Customer((int) $cart->id_customer);
        // Only the customer who owns the cart sees the confirmation page; anyone else holding
        // the link (another device, a shared screen) lands on the shop's front page.
        if ((int) $this->context->customer->id !== (int) $customer->id) {
            Tools::redirect($this->context->link->getPageLink('index', true));
        }
        Tools::redirect($this->context->link->getPageLink('order-confirmation', true, null, [
            'id_cart' => (int) $cart->id,
            'id_module' => (int) $this->module->id,
            'id_order' => (int) $payment['id_order'],
            'key' => $customer->secure_key,
        ]));
    }

    private function wait(string $reference): void
    {
        $waits = (int) Tools::getValue('wait');
        $this->context->smarty->assign([
            'hesabe_retry_url' => $waits < self::MAX_WAITS
                ? $this->context->link->getModuleLink($this->module->name, 'return', [
                    'ref' => $reference,
                    'key' => Settings::sign($reference),
                    'wait' => $waits + 1,
                ], true)
                : '',
            'hesabe_checkout_url' => $this->context->link->getPageLink('order', true),
            'hesabe_history_url' => $this->context->link->getPageLink('history', true),
        ]);
        $this->setTemplate('module:hesabe/views/templates/front/pending.tpl');
    }
}
