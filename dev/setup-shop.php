<?php
/**
 * Copyright (c) 2026 the hesabe SDK authors. MIT License, see LICENSE.
 *
 * Prepares a fresh development shop for Hesabe: KWD with three decimals as the default
 * currency, allowed for the module. Run inside the shop container from the shop root:
 *
 *     php modules/hesabe/dev/setup-shop.php
 */

declare(strict_types=1);

require __DIR__ . '/../../../config/config.inc.php';

$id = (int) Currency::getIdByIsoCode('KWD');
if ($id === 0) {
    $currency = new Currency();
    $currency->iso_code = 'KWD';
    $currency->numeric_iso_code = '414';
    $currency->precision = 3;
    $currency->conversion_rate = 1;
    $currency->active = true;
    foreach (Language::getIDs(false) as $idLang) {
        $currency->name[$idLang] = 'Kuwaiti Dinar';
        $currency->symbol[$idLang] = 'KWD';
    }
    $currency->add();
    $id = (int) $currency->id;
}
Configuration::updateValue('PS_CURRENCY_DEFAULT', $id);

$module = Module::getInstanceByName('hesabe');
foreach (Shop::getShops(false, null, true) as $idShop) {
    Db::getInstance()->insert('module_currency', [
        'id_module' => (int) $module->id,
        'id_shop' => (int) $idShop,
        'id_currency' => $id,
    ], false, true, Db::INSERT_IGNORE);
}

// The demo carriers deliver to Europe only; checkout needs one for Kuwait.
$idZone = (int) Country::getIdZone((int) Country::getByIso('KW'));
foreach (Carrier::getCarriers((int) Configuration::get('PS_LANG_DEFAULT'), true) as $row) {
    $carrier = new Carrier((int) $row['id_carrier']);
    if (!$carrier->getZone($idZone)) {
        $carrier->addZone($idZone);
    }
}

echo "KWD is currency {$id}, the default, allowed for hesabe. Carriers deliver to zone {$idZone}.\n";
