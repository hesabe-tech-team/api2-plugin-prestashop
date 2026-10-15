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
    /** How long a second request for the same payment waits for the first to be answered. */
    private const LOCK_SECONDS = 30;

    public function postProcess()
    {
        $idOrder = (int) Tools::getValue('id_order');
        // POST only: PrestaShop answers a link with a wrong token by offering the same URL with
        // the employee's valid one, so a GET could turn a mailed link into a refund.
        if (!Tools::isSubmit('submitHesabeRefund') || $_SERVER['REQUEST_METHOD'] !== 'POST' || $idOrder === 0) {
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
        if (self::thousandths($amount) < self::thousandths(Refunds::MINIMUM)) {
            return ['error', sprintf($m->l('Hesabe refunds start at %s KWD.', 'adminhesaberefund'), Refunds::MINIMUM)];
        }

        // A double click sends the form twice at once. Without the lock both requests would pass
        // the open-amount check before either is recorded, and Hesabe has no idempotency keys.
        // The lock is MySQL's and uncached, as in Settlement::settle.
        $lock = "'hesabe_refund_" . (int) $payment['id_hesabe_payment'] . "'";
        if ((int) Db::getInstance()->getValue('SELECT GET_LOCK(' . $lock . ', ' . self::LOCK_SECONDS . ')', false) !== 1) {
            return ['warning', $m->l('Another refund for this order is being sent. Reload the order page to see it.', 'adminhesaberefund')];
        }
        try {
            return $this->send($payment, $amount, $idOrder);
        } finally {
            Db::getInstance()->getValue('SELECT RELEASE_LOCK(' . $lock . ')', false);
        }
    }

    /**
     * Checks the amount against what is still open and sends the request. Runs under the
     * payment's lock.
     *
     * @param array{id_hesabe_payment: string, id_order: ?string, token: ?string, amount: string} $payment
     * @return array{0: string, 1: string} Flash type and text.
     */
    private function send(array $payment, string $amount, int $idOrder): array
    {
        $m = $this->module;
        $open = Refunds::refundable($payment, Refunds::forOrder($idOrder));
        if (self::thousandths($amount) > self::thousandths($open)) {
            return ['error', sprintf($m->l('At most %s KWD is left to refund on this payment.', 'adminhesaberefund'), $open)];
        }

        $result = Refunds::request($payment, $amount, (int) $this->context->employee->id);
        if ($result['signedOut']) {
            return ['error', $m->l('Nothing was sent: Hesabe signed the shop out, which happens after another sign-in with the same Merchant Panel login. Send the refund again. If it repeats, press Test connection on the Hesabe settings page.', 'adminhesaberefund')];
        }
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
