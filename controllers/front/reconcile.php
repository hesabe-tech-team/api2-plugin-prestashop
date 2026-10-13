<?php
/**
 * Copyright (c) 2026 the hesabe SDK authors. MIT License, see LICENSE.
 */

use Hesabe\PrestaShop\Payments;
use Hesabe\PrestaShop\Settings;
use Hesabe\PrestaShop\Settlement;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The cron endpoint. Looks up attempts nobody came back for: customers who closed the tab,
 * webhooks that never arrived, and card declines, which Hesabe never reports at all. Run it
 * every 15 minutes; the URL is on the settings page.
 *
 * @property Hesabe $module
 */
class HesabeReconcileModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    /** Hesabe announces 60 lookups a minute; a batch stays well under that. */
    private const BATCH = 20;

    public function postProcess(): void
    {
        if (!Settings::isConfigured() || !hash_equals(Settings::cronToken(), (string) Tools::getValue('token'))) {
            http_response_code(403);
            header('Content-Type: application/json');
            $this->ajaxRender('{"ok":false}');
            exit;
        }

        $settlement = new Settlement($this->module);
        $counts = [];
        foreach (Payments::due((int) $this->context->shop->id, self::BATCH) as $payment) {
            $status = $settlement->settle($payment)['status'];
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }

        header('Content-Type: application/json');
        $this->ajaxRender((string) json_encode(['ok' => true, 'checked' => $counts]));
        exit;
    }
}
