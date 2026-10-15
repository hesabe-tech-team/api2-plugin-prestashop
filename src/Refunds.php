<?php
/**
 * Copyright (c) 2026 the hesabe SDK authors. MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Hesabe\PrestaShop;

use Hesabe\Amount;
use Hesabe\Exception\AuthenticationException;
use Hesabe\Exception\ConfigurationException;
use Hesabe\Exception\HesabeException;
use Hesabe\RefundMethod;
use Hesabe\RefundStatus;

/**
 * Refund requests sent to Hesabe, and the `hesabe_refund` table that remembers them.
 *
 * A request waits for Hesabe to approve it before any money moves, and the minimum is
 * 0.250 KWD (sandbox and production, October 2026). On the sandbox a payment holds one
 * pending request at a time. A request is never sent twice by the module: Hesabe has no
 * idempotency keys, so a lost answer could mean it was recorded.
 *
 * @phpstan-type Refund array{id_hesabe_refund: string, id_hesabe_payment: string, id_order: string,
 *     id_employee: string, hesabe_refund_id: ?string, amount: string, status: string,
 *     message: ?string, date_add: string, date_upd: string}
 */
final class Refunds
{
    public const TABLE = 'hesabe_refund';

    public const MINIMUM = '0.250';

    /** Sent, waiting for Hesabe's approval. */
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const CANCELLED = 'cancelled';
    /** Hesabe refused the request, or its staff rejected it; nothing is pending. */
    public const REFUSED = 'refused';
    /** No answer arrived. It may or may not exist at Hesabe; check the Merchant Panel. */
    public const UNKNOWN = 'unknown';

    /** Refreshing a pending request's status at most this often keeps the order page fast. */
    private const REFRESH_SECONDS = 300;

    /**
     * @return list<Refund>
     */
    public static function forOrder(int $idOrder): array
    {
        // Uncached: under the refund lock this must see the request recorded a moment ago.
        $rows = \Db::getInstance()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `id_order` = ' . $idOrder . ' ORDER BY `id_hesabe_refund`',
            true,
            false,
        );
        if (!is_array($rows)) {
            return [];
        }
        /** @var list<Refund> $rows */
        return $rows;
    }

    /**
     * The amount still open to refund: paid, less every request not refused or cancelled.
     *
     * @param array{amount: string} $payment
     * @param list<Refund> $refunds
     */
    public static function refundable(array $payment, array $refunds): string
    {
        $open = (int) round((float) $payment['amount'] * 1000);
        foreach ($refunds as $refund) {
            if (!in_array($refund['status'], [self::REFUSED, self::CANCELLED], true)) {
                $open -= (int) round((float) $refund['amount'] * 1000);
            }
        }
        return Amount::format(max(0, $open) / 1000);
    }

    /**
     * Sends one refund request and records the outcome, whatever it is.
     *
     * `signedOut` is true when Hesabe did not accept the shop's Merchant API session, which
     * happens after another sign-in with the same login.
     *
     * @param array{id_hesabe_payment: string, id_order: ?string, token: ?string, amount: string} $payment
     * @return array{status: string, message: string, signedOut: bool}
     */
    public static function request(array $payment, string $amount, int $idEmployee): array
    {
        $amount = Amount::format($amount);
        $method = $amount === Amount::format($payment['amount']) ? RefundMethod::FULL : RefundMethod::PARTIAL;

        $id = self::insert($payment, $amount, $idEmployee);
        try {
            $result = Settings::client()->refunds->create((string) $payment['token'], $amount, $method);
        } catch (HesabeException $exception) {
            $status = self::outcome($exception);
            self::update($id, ['status' => $status, 'message' => pSQL(mb_substr($exception->getMessage(), 0, 255))]);
            return [
                'status' => $status,
                'message' => $exception->getMessage(),
                'signedOut' => $exception instanceof AuthenticationException && $exception->getStatusCode() === 401,
            ];
        }

        $hesabeId = self::refundId($result);
        self::update($id, [
            'status' => self::status($result['status'] ?? RefundStatus::PENDING),
            'hesabe_refund_id' => $hesabeId,
            'message' => pSQL(mb_substr(is_string($result['message'] ?? null) ? $result['message'] : '', 0, 255)),
        ]);
        return ['status' => self::PENDING, 'message' => '', 'signedOut' => false];
    }

    /**
     * Re-reads pending requests from Hesabe, at most every five minutes each.
     *
     * @param list<Refund> $refunds
     */
    public static function refresh(array $refunds): void
    {
        foreach ($refunds as $refund) {
            if (
                $refund['status'] !== self::PENDING
                || $refund['hesabe_refund_id'] === null
                || time() - (int) strtotime($refund['date_upd']) < self::REFRESH_SECONDS
            ) {
                continue;
            }
            try {
                $result = Settings::client()->refunds->retrieve((int) $refund['hesabe_refund_id']);
            } catch (HesabeException $exception) {
                self::update((int) $refund['id_hesabe_refund'], ['message' => pSQL(mb_substr($exception->getMessage(), 0, 255))]);
                continue;
            }
            $values = ['status' => self::status($result['status'] ?? null)];
            // Hesabe's staff write the reason here when they approve or reject a request.
            if (is_string($result['remarks'] ?? null) && $result['remarks'] !== '') {
                $values['message'] = pSQL(mb_substr($result['remarks'], 0, 255));
            }
            self::update((int) $refund['id_hesabe_refund'], $values);
        }
    }

    /**
     * Only a definite refusal frees the amount for another request. A lost connection or a 5xx
     * might come after Hesabe recorded the request, and a second one could refund twice. A
     * 401 is definite: Hesabe checks the session before it reads the request, and a refund
     * that got one was not recorded (sandbox, October 2026).
     */
    private static function outcome(HesabeException $exception): string
    {
        if ($exception instanceof ConfigurationException || $exception instanceof AuthenticationException) {
            return self::REFUSED;
        }
        $status = $exception->getStatusCode();
        if ($status === null || $status >= 500) {
            return self::UNKNOWN;
        }
        return self::REFUSED;
    }

    /**
     * @param array{id_hesabe_payment: string, id_order: ?string} $payment
     */
    private static function insert(array $payment, string $amount, int $idEmployee): int
    {
        $now = date('Y-m-d H:i:s');
        \Db::getInstance()->insert(self::TABLE, [
            'id_hesabe_payment' => (int) $payment['id_hesabe_payment'],
            'id_order' => (int) $payment['id_order'],
            'id_employee' => $idEmployee,
            'amount' => pSQL($amount),
            // Recorded before the call, so a request whose answer is lost still holds its
            // amount and cannot be sent a second time.
            'status' => self::UNKNOWN,
            'date_add' => $now,
            'date_upd' => $now,
        ]);
        return (int) \Db::getInstance()->Insert_ID();
    }

    /**
     * @param array<string, scalar|null> $values Already escaped.
     */
    private static function update(int $id, array $values): void
    {
        $values['date_upd'] = date('Y-m-d H:i:s');
        \Db::getInstance()->update(self::TABLE, $values, '`id_hesabe_refund` = ' . $id, 0, true);
    }

    /**
     * Hesabe's refund response is undocumented beyond its status; the id has been seen as `id`.
     *
     * @param array<string, mixed> $result
     */
    private static function refundId(array $result): ?int
    {
        foreach (['id', 'refund_id', 'refundId'] as $key) {
            if (is_int($result[$key] ?? null) || (is_string($result[$key] ?? null) && ctype_digit($result[$key]))) {
                return (int) $result[$key];
            }
        }
        return null;
    }

    /**
     * A code Hesabe has not used before holds the amount and points the merchant at the
     * Merchant Panel, rather than reading as pending for good.
     */
    private static function status(mixed $value): string
    {
        return match (is_numeric($value) ? (int) $value : null) {
            RefundStatus::PENDING => self::PENDING,
            RefundStatus::APPROVED => self::APPROVED,
            RefundStatus::REJECTED => self::REFUSED,
            RefundStatus::CANCELLED => self::CANCELLED,
            default => self::UNKNOWN,
        };
    }
}
