<?php
/**
 * Copyright (c) 2026 the hesabe SDK authors. MIT License, see LICENSE.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

use Hesabe\Exception\HesabeException;
use Hesabe\PrestaShop\Installer;
use Hesabe\PrestaShop\Methods;
use Hesabe\PrestaShop\Payments;
use Hesabe\PrestaShop\Refunds;
use Hesabe\PrestaShop\Settings;
use Hesabe\PrestaShop\SettingsForm;
use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

/**
 * Hesabe payments for PrestaShop 8.1 and later: KNET, cards and Hesabe's hosted page, with
 * refunds from the order page.
 */
class Hesabe extends PaymentModule
{
    public function __construct()
    {
        $this->name = 'hesabe';
        $this->tab = 'payments_gateways';
        $this->version = '1.0.1';
        $this->author = 'the hesabe SDK authors';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '8.1.0', 'max' => _PS_VERSION_];
        $this->bootstrap = true;
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';
        $this->tabs = [
            [
                'class_name' => 'AdminHesabeRefund',
                'visible' => false,
                'name' => 'Hesabe refunds',
                'parent_class_name' => 'AdminParentOrders',
            ],
        ];

        parent::__construct();

        $this->displayName = $this->l('Hesabe');
        $this->description = $this->l('Accept KNET and card payments through Hesabe, and refund them from the order page.');
        $this->confirmUninstall = $this->l('Uninstalling deletes the Hesabe payment records and settings. Continue?');
    }

    public function install(): bool
    {
        return parent::install() && Installer::install($this);
    }

    public function uninstall(): bool
    {
        return Installer::uninstall() && parent::uninstall();
    }

    public function getContent(): string
    {
        return (new SettingsForm($this))->render();
    }

    /**
     * @param array{cart: Cart} $params
     * @return list<PaymentOption>
     */
    public function hookPaymentOptions(array $params): array
    {
        $cart = $params['cart'];
        if (!$this->active || !Settings::isConfigured() || !$this->checkCurrency($cart)) {
            return [];
        }
        // Hesabe settles in KWD; other currencies are listed in its docs but no non-KWD
        // payment has been completed end to end (October 2026), so they are not offered.
        if ((new Currency((int) $cart->id_currency))->iso_code !== 'KWD') {
            return [];
        }

        $options = [];
        foreach (Settings::methods() as $method) {
            $option = new PaymentOption();
            $option->setModuleName($this->name)
                ->setCallToActionText($this->methodLabel($method))
                ->setAction($this->context->link->getModuleLink($this->name, 'redirect', ['method' => $method], true));
            $options[] = $option;
        }
        return $options;
    }

    /**
     * @param array{order: Order} $params
     */
    public function hookDisplayPaymentReturn(array $params): string
    {
        $order = $params['order'];
        if (!$this->active || $order->module !== $this->name) {
            return '';
        }
        $payments = Payments::forOrder((int) $order->id);
        $this->context->smarty->assign([
            'hesabe_reference' => $payments === [] ? '' : $payments[0]['reference'],
        ]);
        return $this->display(__FILE__, 'views/templates/hook/payment_return.tpl');
    }

    /**
     * The Hesabe panel on the back-office order page: what Hesabe reports, and refunds.
     *
     * @param array{id_order: int} $params
     */
    public function hookDisplayAdminOrderMainBottom(array $params): string
    {
        $payments = Payments::forOrder((int) $params['id_order']);
        // Shown on any order of the cart, so the second payment is seen whichever module made it.
        $duplicates = Payments::duplicatesForCart((int) (new Order((int) $params['id_order']))->id_cart);
        if ($payments === [] && $duplicates === []) {
            return '';
        }
        $payment = $payments[0] ?? null;
        $refunds = $payment === null ? [] : Refunds::forOrder((int) $params['id_order']);
        if (Settings::canRefund() && $refunds !== []) {
            Refunds::refresh($refunds);
            $refunds = Refunds::forOrder((int) $params['id_order']);
        }

        $flash = $this->context->cookie->__get('hesabe_flash');
        if ($flash) {
            $this->context->cookie->__unset('hesabe_flash');
        }
        $this->context->smarty->assign([
            'hesabe_payment' => $payment,
            'hesabe_duplicates' => $duplicates,
            'hesabe_method' => $payment === null ? '' : $this->methodLabel($payment['method']),
            'hesabe_status' => $payment === null ? '' : $this->statusLabel($payment['status']),
            'hesabe_refunds' => array_map(fn (array $refund): array => $refund + ['status_label' => $this->statusLabel($refund['status'])], $refunds),
            'hesabe_refundable' => $payment === null ? '' : Refunds::refundable($payment, $refunds),
            'hesabe_minimum' => Refunds::MINIMUM,
            'hesabe_can_refund' => $payment !== null && Settings::canRefund() && $payment['status'] === Payments::PAID,
            'hesabe_refund_action' => $this->context->link->getAdminLink('AdminHesabeRefund'),
            'hesabe_environment' => Settings::isProduction() ? 'production' : 'sandbox',
            'hesabe_flash' => $flash ? json_decode($flash, true) : null,
        ]);
        return $this->display(__FILE__, 'views/templates/hook/admin_order.tpl');
    }

    public function methodLabel(string $method): string
    {
        return match ($method) {
            Methods::KNET => $this->l('KNET'),
            Methods::CARD => $this->l('Visa / Mastercard'),
            default => $this->l('Hesabe (all payment methods)'),
        };
    }

    /**
     * The words shown for a payment or refund status. The tables keep the English codes.
     */
    public function statusLabel(string $status): string
    {
        return match ($status) {
            // Payments and refunds share this one.
            Payments::PENDING => $this->l('Pending'),
            Payments::PAID => $this->l('Paid'),
            Payments::FAILED => $this->l('Failed'),
            Payments::EXPIRED => $this->l('Expired'),
            Payments::DUPLICATE => $this->l('Paid twice: refund by hand'),
            Payments::ERROR => $this->l('Paid, order not created'),
            Refunds::APPROVED => $this->l('Approved'),
            Refunds::CANCELLED => $this->l('Cancelled'),
            Refunds::REFUSED => $this->l('Refused'),
            Refunds::UNKNOWN => $this->l('No answer: check the Merchant Panel'),
            default => $status,
        };
    }

    /**
     * A message safe to show a customer. Hesabe's own text can name fields and codes.
     */
    public function customerError(HesabeException $exception): string
    {
        PrestaShopLogger::addLog('Hesabe: ' . get_class($exception) . ': ' . $exception->getMessage(), 3, null, 'Hesabe');
        return $this->l('The payment could not be started. Please try again or choose another payment method.');
    }

    private function checkCurrency(Cart $cart): bool
    {
        $currency = new Currency((int) $cart->id_currency);
        foreach ($this->getCurrency((int) $cart->id_currency) ?: [] as $allowed) {
            if ((int) $allowed['id_currency'] === (int) $currency->id) {
                return true;
            }
        }
        return false;
    }
}
