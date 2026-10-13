<?php
/**
 * Copyright (c) 2026 the hesabe SDK authors. MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Hesabe\PrestaShop;

use Hesabe\Amount;
use Hesabe\Exception\HesabeException;

/**
 * Starts one payment attempt for the customer's cart: records it, then asks Hesabe for a
 * payment session.
 *
 * @phpstan-import-type Payment from Payments
 */
final class Attempt
{
    /**
     * @return string The Hesabe page to send the customer to.
     * @throws \Hesabe\Exception\HesabeException when Hesabe refuses the session.
     */
    public static function start(\Context $context, \Cart $cart, string $method): string
    {
        $currency = new \Currency((int) $cart->id_currency);
        // Cart totals arrive as floats already rounded to the currency's precision; the SDK
        // turns them into the exact three-decimal string Hesabe accepts.
        $amount = Amount::format(number_format((float) $cart->getOrderTotal(true, \Cart::BOTH), 3, '.', ''));
        $reference = self::reference((int) $cart->id);

        $payment = Payments::create((int) $cart->id_shop, (int) $cart->id, $reference, $method, $amount, $currency->iso_code);

        $link = $context->link;
        $signed = ['ref' => $reference, 'key' => Settings::sign($reference)];
        $return = $link->getModuleLink('hesabe', 'return', $signed, true, null, (int) $cart->id_shop);
        $customer = new \Customer((int) $cart->id_customer);
        // Letters, marks, spaces and the punctuation names use; nothing for Hesabe to refuse.
        $name = trim((string) preg_replace("/[^\\p{L}\\p{M} .'-]+/u", '', $customer->firstname . ' ' . $customer->lastname));

        try {
            $session = Settings::client()->checkout->create(
                amount: $amount,
                orderReferenceNumber: $reference,
                responseUrl: $return,
                failureUrl: $return,
                paymentType: Methods::paymentType($method),
                currency: $currency->iso_code,
                // Email and phone are left out on purpose: Hesabe rejects an email whose domain does
                // not resolve, and a typo in a guest's address would then block the payment.
                name: $name === '' ? null : mb_substr($name, 0, 100),
                webhookUrl: $link->getModuleLink('hesabe', 'webhook', $signed, true, null, (int) $cart->id_shop),
            );
        } catch (HesabeException $exception) {
            // Hesabe refused the session, so nothing can ever be paid under this reference.
            Payments::markFinal((int) $payment['id_hesabe_payment'], Payments::FAILED, $exception->getMessage());
            throw $exception;
        }
        Payments::setToken((int) $payment['id_hesabe_payment'], $session['token']);
        return $session['paymentUrl'];
    }

    /**
     * Unique per attempt, short, and limited to letters, digits and hyphens, which Hesabe's
     * products keep intact (its invoice API strips other separators).
     */
    private static function reference(int $idCart): string
    {
        return 'PS' . $idCart . '-' . strtoupper(base_convert((string) time(), 10, 36)) . strtoupper(bin2hex(random_bytes(2)));
    }
}
