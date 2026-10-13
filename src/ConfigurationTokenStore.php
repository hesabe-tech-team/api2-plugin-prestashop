<?php
/**
 * Copyright (c) 2026 the hesabe SDK authors. MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Hesabe\PrestaShop;

use Hesabe\Session\TokenStore;

/**
 * Keeps the Merchant API token between requests, encrypted in the shop's configuration.
 *
 * Every new panel login ends every earlier token on the Hesabe account (probed on
 * production, October 2026), including tokens other integrations hold. Reusing one token
 * across refund requests keeps the shop from logging itself, and them, out.
 */
final class ConfigurationTokenStore implements TokenStore
{
    public function load(): ?array
    {
        $stored = Settings::get(Settings::API_TOKEN);
        if ($stored === '') {
            return null;
        }
        $token = json_decode($stored, true);
        if (!is_array($token) || !is_string($token['accessToken'] ?? null) || !is_numeric($token['expiresAt'] ?? null)) {
            return null;
        }
        return [
            'accessToken' => $token['accessToken'],
            'refreshToken' => is_string($token['refreshToken'] ?? null) ? $token['refreshToken'] : null,
            'expiresAt' => (float) $token['expiresAt'],
        ];
    }

    public function save(#[\SensitiveParameter] array $token): void
    {
        Settings::set(Settings::API_TOKEN, (string) json_encode($token));
    }

    public function clear(): void
    {
        Settings::set(Settings::API_TOKEN, '');
    }
}
