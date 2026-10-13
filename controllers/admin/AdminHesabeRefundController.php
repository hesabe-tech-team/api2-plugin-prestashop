<?php
/**
 * Copyright (c) 2026 the hesabe SDK authors. MIT License, see LICENSE.
 */

use Hesabe\Amount;
use Hesabe\PrestaShop\Payments;
use Hesabe\PrestaShop\Refunds;
use Hesabe\PrestaShop\Settings;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Receives the refund form on the order page. A hidden tab, so its URL carries PrestaShop's
 * admin token and the employee's order permissions apply.
 *
 * @property Hesabe $module
 */
class AdminHesabeRefundController extends ModuleAdminController
{
    public function postProcess()
    {
        $idOrder = (int) Tools::getValue('id_order');
        if (!Tools::isSubmit('submitHesabeRefund') || $idOrder === 0) {
            Tools::redirectAdmin($this->context->link->getAdminLink('AdminOrders'));
        }
        if ($this->access('edit')) {
            $this->flash($this->refund($idOrder, (string) Tools::getValue('hesabe_refund_amount')));
        } else {
            $this->flash(['error', $this->module->l('You do not have permission to refund orders.', 'adminhesaberefund')]);
        }
        Tools::redirectAdmin($this->context->link->getAdminLink('AdminOrders', true, [], [
            'id_order' => $idOrder,
            'vieworder' => 1,
        ]));
    }

    /**
     * @return array{0: string, 1: string} Flash type and text.
     */
    private function refund(int $idOrder, string $requested): array
    {
        $m = $this->module;
        if (!Settings::canRefund()) {
            return ['error', $m->l('Add the Merchant Panel login on the Hesabe settings page to send refunds.', 'adminhesaberefund')];
        }
        $payment = null;
        foreach (Payments::forOrder($idOrder) as $candidate) {
            if ($candidate['status'] === Payments::PAID && $candidate['token'] !== null) {
                $payment = $candidate;
            }
        }
        if ($payment === null) {
            return ['error', $m->l('This order has no paid Hesabe payment to refund.', 'adminhesaberefund')];
        }

        try {
            $amount = Amount::format(str_replace(',', '.', trim($requested)));
        } catch (InvalidArgumentException) {
            return ['error', $m->l('Enter the amount as a number, for example 1.250.', 'adminhesaberefund')];
        }
        $open = Refunds::refundable($payment, Refunds::forOrder($idOrder));
        if (self::thousandths($amount) < self::thousandths(Refunds::MINIMUM)) {
            return ['error', sprintf($m->l('Hesabe refunds start at %s KWD.', 'adminhesaberefund'), Refunds::MINIMUM)];
        }
        if (self::thousandths($amount) > self::thousandths($open)) {
            return ['error', sprintf($m->l('At most %s KWD is left to refund on this payment.', 'adminhesaberefund'), $open)];
        }

        $result = Refunds::request($payment, $amount, (int) $this->context->employee->id);
        return match ($result['status']) {
            Refunds::PENDING => ['success', sprintf(
                $m->l('Refund of %s KWD sent to Hesabe. It waits for Hesabe to approve it.', 'adminhesaberefund'),
                $amount,
            )],
            Refunds::REFUSED => ['error', sprintf($m->l('Hesabe refused the refund: %s', 'adminhesaberefund'), $result['message'])],
            default => ['warning', $m->l('No answer from Hesabe. The refund may or may not have been recorded: check Refunds in the Hesabe Merchant Panel before trying again.', 'adminhesaberefund')],
        };
    }

    /**
     * @param array{0: string, 1: string} $flash
     */
    private function flash(array $flash): void
    {
        $this->context->cookie->__set('hesabe_flash', (string) json_encode(['type' => $flash[0], 'text' => $flash[1]]));
        $this->context->cookie->write();
    }

    private static function thousandths(string $amount): int
    {
        return (int) round((float) $amount * 1000);
    }
}
