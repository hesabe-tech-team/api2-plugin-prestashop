<?php
/**
 * Copyright (c) 2026 the hesabe SDK authors. MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Hesabe\PrestaShop;

/**
 * The `hesabe_payment` table: one row per attempt to pay a cart.
 *
 * Each attempt has its own order reference, so a lookup at Hesabe always names exactly one
 * payment, and a customer who pays twice for one cart is caught instead of merged.
 *
 * @phpstan-type Payment array{id_hesabe_payment: string, id_shop: string, id_cart: string,
 *     id_order: ?string, reference: string, token: ?string, method: string, amount: string,
 *     currency: string, status: string, checks: string, next_check_at: ?string,
 *     last_error: ?string, date_add: string, date_upd: string}
 */
final class Payments
{
    public const TABLE = 'hesabe_payment';

    /** Created at checkout; nothing heard from Hesabe yet. */
    public const PENDING = 'pending';
    /** Hesabe reports the money moved and the order exists. */
    public const PAID = 'paid';
    /** Hesabe reports the attempt failed (a KNET decline, for example). */
    public const FAILED = 'failed';
    /** Nothing reached Hesabe within four days: abandoned, or a card decline. */
    public const EXPIRED = 'expired';
    /** Paid, but the cart already had an order: the money needs refunding by hand. */
    public const DUPLICATE = 'duplicate';
    /** Paid, but PrestaShop refused to create the order. Needs a person. */
    public const ERROR = 'error';

    /** Card declines never reach Hesabe's records, so a pending attempt is given up on after this. */
    private const GIVE_UP_AFTER_SECONDS = 4 * 86400;

    /**
     * @return Payment
     */
    public static function create(int $idShop, int $idCart, string $reference, string $method, string $amount, string $currency): array
    {
        $now = date('Y-m-d H:i:s');
        \Db::getInstance()->insert(self::TABLE, [
            'id_shop' => $idShop,
            'id_cart' => $idCart,
            'reference' => pSQL($reference),
            'method' => pSQL($method),
            'amount' => pSQL($amount),
            'currency' => pSQL($currency),
            'status' => self::PENDING,
            'checks' => 0,
            'next_check_at' => date('Y-m-d H:i:s', time() + 900),
            'date_add' => $now,
            'date_upd' => $now,
        ]);
        $payment = self::byReference($reference);
        if ($payment === null) {
            throw new \PrestaShopException('Hesabe payment row was not stored');
        }
        return $payment;
    }

    /**
     * @return Payment|null
     */
    public static function byReference(string $reference): ?array
    {
        return self::one('`reference` = \'' . pSQL($reference) . '\'');
    }

    /**
     * @return Payment|null
     */
    public static function byId(int $id): ?array
    {
        return self::one('`id_hesabe_payment` = ' . $id);
    }

    /**
     * @return list<Payment>
     */
    public static function forOrder(int $idOrder): array
    {
        return self::many('`id_order` = ' . $idOrder . ' ORDER BY `id_hesabe_payment`');
    }

    /**
     * @return list<Payment>
     */
    public static function pendingForCart(int $idCart): array
    {
        return self::many('`id_cart` = ' . $idCart . ' AND `status` = \'' . self::PENDING . '\' ORDER BY `id_hesabe_payment`');
    }

    /**
     * Payments Hesabe took for a cart after its order was made. Each needs a refund by hand.
     *
     * @return list<Payment>
     */
    public static function duplicatesForCart(int $idCart): array
    {
        return self::many('`id_cart` = ' . $idCart . ' AND `status` = \'' . self::DUPLICATE . '\' ORDER BY `id_hesabe_payment`');
    }

    /**
     * Payments Hesabe took that have no order of their own, newest first: a second payment for
     * a cart, or one PrestaShop refused to turn into an order.
     *
     * @return list<Payment>
     */
    public static function withoutOrder(int $idShop, int $days, int $limit): array
    {
        return self::many(
            '`id_shop` = ' . $idShop
            . ' AND `status` IN (\'' . self::DUPLICATE . '\', \'' . self::ERROR . '\')'
            . ' AND `date_upd` >= \'' . pSQL(date('Y-m-d H:i:s', time() - $days * 86400)) . '\''
            . ' ORDER BY `id_hesabe_payment` DESC LIMIT ' . $limit
        );
    }

    /**
     * Pending attempts whose next check is due, oldest first.
     *
     * @return list<Payment>
     */
    public static function due(int $idShop, int $limit): array
    {
        return self::many(
            '`id_shop` = ' . $idShop . ' AND `status` = \'' . self::PENDING . '\''
            . ' AND `next_check_at` <= \'' . pSQL(date('Y-m-d H:i:s')) . '\''
            . ' ORDER BY `next_check_at` LIMIT ' . $limit
        );
    }

    public static function setToken(int $id, string $token): void
    {
        self::update($id, ['token' => pSQL($token)]);
    }

    public static function markPaid(int $id, int $idOrder): void
    {
        self::update($id, ['status' => self::PAID, 'id_order' => $idOrder, 'next_check_at' => null, 'last_error' => null]);
    }

    public static function markFinal(int $id, string $status, ?string $reason = null): void
    {
        self::update($id, ['status' => pSQL($status), 'next_check_at' => null, 'last_error' => self::reason($reason)]);
    }

    /**
     * Schedules the next lookup: 15 minutes, doubling to a day, until four days have passed.
     *
     * @param Payment $payment
     */
    public static function postpone(array $payment, ?string $reason = null): void
    {
        $checks = (int) $payment['checks'] + 1;
        if (time() - (int) strtotime($payment['date_add']) > self::GIVE_UP_AFTER_SECONDS) {
            self::update((int) $payment['id_hesabe_payment'], [
                'status' => self::EXPIRED,
                'checks' => $checks,
                'next_check_at' => null,
                'last_error' => self::reason($reason),
            ]);
            return;
        }
        $delay = min(900 * 2 ** min($checks - 1, 10), 86400);
        self::update((int) $payment['id_hesabe_payment'], [
            'checks' => $checks,
            'next_check_at' => date('Y-m-d H:i:s', time() + $delay),
            'last_error' => self::reason($reason),
        ]);
    }

    /**
     * @param array<string, scalar|null> $values Already escaped.
     */
    private static function update(int $id, array $values): void
    {
        $values['date_upd'] = date('Y-m-d H:i:s');
        \Db::getInstance()->update(self::TABLE, $values, '`id_hesabe_payment` = ' . $id, 0, true);
    }

    private static function reason(?string $reason): ?string
    {
        return $reason === null ? null : pSQL(mb_substr($reason, 0, 255));
    }

    /**
     * Reads skip PrestaShop's query cache: a status is read again under the per-cart lock, and a
     * cached row would hide what the process before it just wrote.
     *
     * @return Payment|null
     */
    private static function one(string $where): ?array
    {
        $row = \Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE ' . $where, false);
        /** @var Payment|false $row */
        return is_array($row) ? $row : null;
    }

    /**
     * @return list<Payment>
     */
    private static function many(string $where): array
    {
        $rows = \Db::getInstance()->executeS('SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE ' . $where, true, false);
        if (!is_array($rows)) {
            return [];
        }
        /** @var list<Payment> $rows */
        return $rows;
    }
}
