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
 * Hesabe's webhook. The body is unsigned plain JSON, so it is not read at all: the signed URL
 * names the attempt, and the lookup decides. Anyone who replays the URL only triggers another
 * lookup.
 *
 * @property Hesabe $module
 */
class HesabeWebhookModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function postProcess(): void
    {
        $reference = (string) Tools::getValue('ref');
        $payment = $reference === '' ? null : Payments::byReference($reference);
        if ($payment === null || !hash_equals(Settings::sign($reference), (string) Tools::getValue('key'))) {
            $this->respond(404, ['received' => false]);
        }

        $payment = (new Settlement($this->module))->settle($payment);
        $this->respond(200, ['received' => true, 'status' => $payment['status']]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function respond(int $status, array $body): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        $this->ajaxRender((string) json_encode($body));
        exit;
    }
}
