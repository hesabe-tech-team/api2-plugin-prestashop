<?php
/**
 * Copyright (c) 2026 the hesabe SDK authors. MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Hesabe\PrestaShop;

use Hesabe\PaymentType;

/**
 * Creates and removes everything the module owns: two tables, the hooks, and its settings.
 */
final class Installer
{
    private const HOOKS = ['paymentOptions', 'displayPaymentReturn', 'displayAdminOrderMainBottom'];

    public static function install(\Hesabe $module): bool
    {
        $engine = _MYSQL_ENGINE_;
        $prefix = _DB_PREFIX_;
        $tables = [
            "CREATE TABLE IF NOT EXISTS `{$prefix}" . Payments::TABLE . "` (
                `id_hesabe_payment` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_shop` INT UNSIGNED NOT NULL,
                `id_cart` INT UNSIGNED NOT NULL,
                `id_order` INT UNSIGNED NULL,
                `reference` VARCHAR(64) NOT NULL,
                `token` VARCHAR(64) NULL,
                `method` VARCHAR(16) NOT NULL,
                `amount` DECIMAL(20,3) NOT NULL,
                `currency` CHAR(3) NOT NULL,
                `status` VARCHAR(16) NOT NULL,
                `checks` INT UNSIGNED NOT NULL DEFAULT 0,
                `next_check_at` DATETIME NULL,
                `last_error` VARCHAR(255) NULL,
                `date_add` DATETIME NOT NULL,
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`id_hesabe_payment`),
                UNIQUE KEY `reference` (`reference`),
                KEY `id_cart` (`id_cart`),
                KEY `id_order` (`id_order`),
                KEY `due` (`id_shop`, `status`, `next_check_at`)
            ) ENGINE={$engine} DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$prefix}" . Refunds::TABLE . "` (
                `id_hesabe_refund` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_hesabe_payment` INT UNSIGNED NOT NULL,
                `id_order` INT UNSIGNED NOT NULL,
                `id_employee` INT UNSIGNED NOT NULL,
                `hesabe_refund_id` INT UNSIGNED NULL,
                `amount` DECIMAL(20,3) NOT NULL,
                `status` VARCHAR(16) NOT NULL,
                `message` VARCHAR(255) NULL,
                `date_add` DATETIME NOT NULL,
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`id_hesabe_refund`),
                KEY `id_order` (`id_order`)
            ) ENGINE={$engine} DEFAULT CHARSET=utf8mb4",
        ];
        foreach ($tables as $sql) {
            if (!\Db::getInstance()->execute($sql)) {
                return false;
            }
        }

        \Configuration::updateGlobalValue(Settings::ENVIRONMENT, 'sandbox');
        \Configuration::updateGlobalValue(Settings::METHODS, Methods::KNET . ',' . Methods::CARD);
        \Configuration::updateGlobalValue(Settings::CARD_TYPE, (string) PaymentType::MPGS);

        return $module->registerHook(self::HOOKS);
    }

    public static function uninstall(): bool
    {
        foreach (Settings::ALL as $key) {
            \Configuration::deleteByName($key);
        }
        return \Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . Payments::TABLE . '`, `'
            . _DB_PREFIX_ . Refunds::TABLE . '`');
    }
}
