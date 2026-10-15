<?php
/**
 * Copyright (c) 2026 the hesabe SDK authors. MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Hesabe\PrestaShop;

use Hesabe\Config;
use Hesabe\Hesabe as Client;

/**
 * The module's settings, per shop, with the secrets encrypted at rest.
 *
 * PrestaShop's SQL Manager masks only `passwd` and `secure_key`, so a plain value in
 * `ps_configuration` is readable by any employee with SQL access. Secrets are encrypted with
 * the shop's own `_NEW_COOKIE_KEY_`, the key PrestaShop uses for its cookies.
 */
final class Settings
{
    public const ENVIRONMENT = 'HESABE_ENVIRONMENT';
    public const MERCHANT_CODE = 'HESABE_MERCHANT_CODE';
    public const ACCESS_CODE = 'HESABE_ACCESS_CODE';
    public const SECRET_KEY = 'HESABE_SECRET_KEY';
    public const IV_KEY = 'HESABE_IV_KEY';
    public const METHODS = 'HESABE_METHODS';
    public const CARD_TYPE = 'HESABE_CARD_TYPE';
    public const PANEL_USERNAME = 'HESABE_PANEL_USERNAME';
    public const PANEL_PASSWORD = 'HESABE_PANEL_PASSWORD';
    public const URL_SECRET = 'HESABE_URL_SECRET';
    public const API_TOKEN = 'HESABE_API_TOKEN';
    /** When the reconcile address last ran, as a Unix time. */
    public const RECONCILED_AT = 'HESABE_RECONCILED_AT';

    /** Every key the module owns, so uninstall leaves nothing behind. */
    public const ALL = [
        self::ENVIRONMENT, self::MERCHANT_CODE, self::ACCESS_CODE, self::SECRET_KEY, self::IV_KEY,
        self::METHODS, self::CARD_TYPE, self::PANEL_USERNAME, self::PANEL_PASSWORD,
        self::URL_SECRET, self::API_TOKEN, self::RECONCILED_AT,
    ];

    private const ENCRYPTED = [
        self::ACCESS_CODE, self::SECRET_KEY, self::IV_KEY, self::PANEL_PASSWORD, self::URL_SECRET, self::API_TOKEN,
    ];

    public static function get(string $key): string
    {
        $value = (string) \Configuration::get($key);
        if ($value === '' || !in_array($key, self::ENCRYPTED, true)) {
            return $value;
        }
        try {
            $plain = (new \PhpEncryption(_NEW_COOKIE_KEY_))->decrypt($value);
        } catch (\Exception) {
            // A value written before encryption, or under another cookie key after a migration,
            // reads as unset so the settings page asks for it again.
            return '';
        }
        return is_string($plain) ? $plain : '';
    }

    public static function set(string $key, #[\SensitiveParameter] string $value): void
    {
        if ($value !== '' && in_array($key, self::ENCRYPTED, true)) {
            $value = (new \PhpEncryption(_NEW_COOKIE_KEY_))->encrypt($value);
        }
        \Configuration::updateValue($key, $value);
    }

    /** True once the gateway keys are in, which is all checkout needs. */
    public static function isConfigured(): bool
    {
        return self::get(self::MERCHANT_CODE) !== ''
            && strlen(self::get(self::ACCESS_CODE)) > 0
            && strlen(self::get(self::SECRET_KEY)) === 32
            && strlen(self::get(self::IV_KEY)) === 16;
    }

    /** Refunds go through the Merchant API, which needs the Merchant Panel login too. */
    public static function canRefund(): bool
    {
        return self::isConfigured() && self::get(self::PANEL_USERNAME) !== '' && self::get(self::PANEL_PASSWORD) !== '';
    }

    public static function isProduction(): bool
    {
        return self::get(self::ENVIRONMENT) === 'production';
    }

    /**
     * @return list<string> Enabled method keys, in the order they show at checkout.
     */
    public static function methods(): array
    {
        $enabled = array_filter(explode(',', self::get(self::METHODS)));
        return array_values(array_intersect(array_keys(Methods::all()), $enabled));
    }

    public static function client(): Client
    {
        return new Client(
            Config::resolve([
                'environment' => self::isProduction() ? 'production' : 'sandbox',
                'merchantCode' => self::get(self::MERCHANT_CODE),
                'accessCode' => self::get(self::ACCESS_CODE),
                'secretKey' => self::get(self::SECRET_KEY),
                'ivKey' => self::get(self::IV_KEY),
                'username' => self::get(self::PANEL_USERNAME) ?: null,
                'password' => self::get(self::PANEL_PASSWORD) ?: null,
                // Kept short: a customer waits on these calls, and the reconciler catches up later.
                'timeout' => 20,
            ]),
            tokenStore: new ConfigurationTokenStore(),
        );
    }

    /**
     * Signs a payment reference for the return and webhook URLs. Hesabe's webhook carries no
     * signature, so the URL itself is what tells a real callback from a guessed one.
     */
    public static function sign(string $reference): string
    {
        return hash_hmac('sha256', $reference, self::urlSecret());
    }

    public static function cronToken(): string
    {
        return hash_hmac('sha256', 'reconcile', self::urlSecret());
    }

    private static function urlSecret(): string
    {
        $secret = self::get(self::URL_SECRET);
        if ($secret === '') {
            $secret = bin2hex(random_bytes(32));
            self::set(self::URL_SECRET, $secret);
        }
        return $secret;
    }
}
