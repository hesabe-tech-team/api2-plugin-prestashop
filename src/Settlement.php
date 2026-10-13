<?php
/**
 * Copyright (c) 2026 the hesabe SDK authors. MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Hesabe\PrestaShop;

use Hesabe\Amount;
use Hesabe\Exception\HesabeException;
use Hesabe\Status;

/**
 * Turns what Hesabe reports about one payment attempt into a PrestaShop order.
 *
 * The browser return, the webhook and the reconciler all land here, and none of them is
 * trusted: the redirect is decrypt-only and the webhook unsigned, so each is only a reason
 * to look the payment up. The lookup decides, and the order is created only when Hesabe
 * says the money moved, with the amount Hesabe reports.
 *
 * @phpstan-import-type Payment from Payments
 */
final class Settlement
{
    /** Seconds to wait for the per-cart lock before leaving the work to whoever holds it. */
    private const LOCK_SECONDS = 10;

    public function __construct(private readonly \PaymentModule $module)
    {
    }

    /**
     * Looks the attempt up at Hesabe and settles it. Safe to call any number of times, from
     * any number of requests at once.
     *
     * @param Payment $payment
     * @return Payment The row as it stands afterwards.
     */
    public function settle(array $payment): array
    {
        if ($payment['status'] !== Payments::PENDING) {
            return $payment;
        }

        // PrestaShop does not stop two orders for one cart (`orders.id_cart` is not unique), and
        // the webhook arrives one to three seconds before the customer's browser. The lock is
        // MySQL's, so it holds across web servers.
        $lock = 'hesabe_cart_' . (int) $payment['id_cart'];
        if ((int) \Db::getInstance()->getValue("SELECT GET_LOCK('" . pSQL($lock) . "', " . self::LOCK_SECONDS . ')') !== 1) {
            return $payment;
        }
        try {
            $current = Payments::byId((int) $payment['id_hesabe_payment']) ?? $payment;
            if ($current['status'] === Payments::PENDING) {
                $this->settleLocked($current);
            }
        } finally {
            \Db::getInstance()->getValue("SELECT RELEASE_LOCK('" . pSQL($lock) . "')");
        }
        return Payments::byId((int) $payment['id_hesabe_payment']) ?? $payment;
    }

    /**
     * @param Payment $payment
     */
    private function settleLocked(array $payment): void
    {
        $id = (int) $payment['id_hesabe_payment'];
        try {
            $transaction = Settings::client()->transactions->retrieveByOrderReference($payment['reference']);
        } catch (HesabeException $exception) {
            // A 404 is the normal answer until the customer finishes paying, and the only answer
            // for a card decline, which Hesabe never records. Anything else is retried later too.
            Payments::postpone($payment, $exception->getStatusCode() === 404 ? null : $exception->getMessage());
            return;
        }

        if (!Status::isSuccessful($transaction)) {
            $status = is_string($transaction['status'] ?? null) ? $transaction['status'] : 'unknown';
            Payments::markFinal($id, Payments::FAILED, "Hesabe reports {$status}");
            return;
        }

        $cart = new \Cart((int) $payment['id_cart']);
        if (!\Validate::isLoadedObject($cart)) {
            Payments::markFinal($id, Payments::ERROR, 'The cart no longer exists');
            return;
        }
        $existing = (int) \Order::getIdByCartId((int) $cart->id);
        if ($existing > 0) {
            // Paid twice for one cart (two tabs, or a retry after a slow return). The second
            // payment has no order to belong to, so it is flagged for a manual refund.
            Payments::markFinal($id, Payments::DUPLICATE, "Cart already has order {$existing}");
            \PrestaShopLogger::addLog(
                "Hesabe payment {$payment['reference']} was paid after cart {$cart->id} already had order "
                . "{$existing}. Refund it from the Hesabe Merchant Panel.",
                3,
                null,
                'Cart',
                (int) $cart->id,
                true,
            );
            return;
        }

        $this->createOrder($payment, $cart, $transaction);
    }

    /**
     * @param Payment $payment
     * @param array<string, mixed> $transaction
     */
    private function createOrder(array $payment, \Cart $cart, array $transaction): void
    {
        $id = (int) $payment['id_hesabe_payment'];
        $paid = Amount::format(is_scalar($transaction['amount'] ?? null) ? (string) $transaction['amount'] : '0');

        $this->useCartCurrency($cart);
        $label = $payment['method'] === Methods::HOSTED
            ? $this->module->displayName
            : $this->module->displayName . ' (' . (Methods::all()[$payment['method']] ?? $payment['method']) . ')';
        try {
            // The amount is Hesabe's, never the cart's. If the cart changed after checkout,
            // PrestaShop sees the difference and files the order under "Payment error", which
            // is where a person should look at it.
            $this->module->validateOrder(
                (int) $cart->id,
                (int) \Configuration::get('PS_OS_PAYMENT'),
                (float) $paid,
                $label,
                null,
                ['transaction_id' => is_scalar($transaction['token'] ?? null) ? (string) $transaction['token'] : ''],
                (int) $cart->id_currency,
                false,
                $cart->secure_key,
            );
        } catch (\Exception $exception) {
            Payments::markFinal($id, Payments::ERROR, $exception->getMessage());
            \PrestaShopLogger::addLog(
                "Hesabe payment {$payment['reference']} is paid but the order could not be created: "
                . $exception->getMessage(),
                3,
                null,
                'Cart',
                (int) $cart->id,
                true,
            );
            return;
        }

        $token = is_scalar($transaction['token'] ?? null) ? (string) $transaction['token'] : '';
        if ($token !== '' && $token !== $payment['token']) {
            Payments::setToken($id, $token);
        }
        Payments::markPaid($id, (int) $this->module->currentOrder);
    }

    /**
     * A webhook or cron request starts in the shop's default currency, and `Context` caches the
     * price precision of the first currency it sees with no way to reset it. A two-decimal
     * default would round a KWD order to two decimals (PrestaShop issue #43205), so the cart's
     * currency goes in and the cache comes out before any total is computed.
     */
    private function useCartCurrency(\Cart $cart): void
    {
        $context = \Context::getContext();
        $context->currency = new \Currency((int) $cart->id_currency, null, (int) $cart->id_shop);
        (function (): void {
            // @phpstan-ignore property.protected (Closure::call binds $this to the Context)
            $this->priceComputingPrecision = null;
        })->call($context);
    }
}
