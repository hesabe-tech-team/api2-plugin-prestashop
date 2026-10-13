<?php
/**
 * Copyright (c) 2026 the hesabe SDK authors. MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Hesabe\PrestaShop;

use Hesabe\PaymentType;

/**
 * The payment options the module can offer, each one a Hesabe `paymentType`.
 *
 * Which types work depends on the merchant account; others answer code 424 "Invalid Payment
 * Method" (probed October 2026). The settings page's connection test shows which ones do.
 */
final class Methods
{
    public const KNET = 'knet';
    public const CARD = 'card';
    public const HOSTED = 'hosted';

    /** Card payments go through MPGS or CyberSource, depending on the account. */
    public const CARD_TYPES = [PaymentType::MPGS => 'MPGS', PaymentType::CYBERSOURCE => 'CyberSource'];

    /**
     * @return array<string, string> Method key to the English label, translated by the module.
     */
    public static function all(): array
    {
        return [
            self::KNET => 'KNET',
            self::CARD => 'Visa / Mastercard',
            self::HOSTED => 'Hesabe (all payment methods)',
        ];
    }

    public static function paymentType(string $method): int
    {
        return match ($method) {
            self::KNET => PaymentType::KNET,
            self::CARD => (int) Settings::get(Settings::CARD_TYPE) === PaymentType::CYBERSOURCE
                ? PaymentType::CYBERSOURCE
                : PaymentType::MPGS,
            default => PaymentType::HOSTED_CHECKOUT,
        };
    }
}
