<?php
/**
 * Copyright (c) 2026 the hesabe SDK authors. MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Hesabe\PrestaShop;

use Hesabe\Exception\HesabeException;
use Hesabe\PaymentType;

/**
 * The module's settings page, on HelperForm so one page serves PrestaShop 8 and 9 alike.
 */
final class SettingsForm
{
    private const SAVE = 'submitHesabeSettings';
    private const TEST = 'submitHesabeTest';
    /** How far back the page lists payments that have no order. */
    private const WITHOUT_ORDER_DAYS = 30;

    public function __construct(private readonly \Hesabe $module)
    {
    }

    public function render(): string
    {
        $output = '';
        // The test button first: HelperForm adds a hidden field named after the save action to
        // every form it renders, so the save action is present on every submit.
        if (\Tools::isSubmit(self::TEST)) {
            $output .= $this->test();
        } elseif (\Tools::isSubmit(self::SAVE)) {
            $output .= $this->save();
        }
        return $output . $this->overview() . $this->form();
    }

    private function save(): string
    {
        $m = $this->module;
        $errors = [];

        $environment = (string) \Tools::getValue(Settings::ENVIRONMENT);
        if (!in_array($environment, ['sandbox', 'production'], true)) {
            $errors[] = $m->l('Choose the sandbox or production.');
        }
        $merchantCode = trim((string) \Tools::getValue(Settings::MERCHANT_CODE));
        if (!ctype_digit($merchantCode)) {
            $errors[] = $m->l('The merchant code is the number shown in Merchant Panel, Profile, Merchant Keys.');
        }
        // Secret fields come back empty unless the merchant typed a new value.
        $secrets = [
            Settings::ACCESS_CODE => [trim((string) \Tools::getValue(Settings::ACCESS_CODE)), null],
            Settings::SECRET_KEY => [trim((string) \Tools::getValue(Settings::SECRET_KEY)), 32],
            Settings::IV_KEY => [trim((string) \Tools::getValue(Settings::IV_KEY)), 16],
        ];
        foreach ($secrets as $key => [$value, $length]) {
            if ($value === '' && Settings::get($key) === '') {
                $errors[] = sprintf($m->l('%s is required.'), $this->label($key));
            } elseif ($value !== '' && $length !== null && strlen($value) !== $length) {
                $errors[] = sprintf($m->l('%1$s must be exactly %2$d characters.'), $this->label($key), $length);
            }
        }
        $methods = array_values(array_filter(
            array_keys(Methods::all()),
            static fn (string $method): bool => (bool) \Tools::getValue(Settings::METHODS . '_' . $method),
        ));
        if ($methods === []) {
            $errors[] = $m->l('Choose at least one payment option.');
        }
        $cardType = (int) \Tools::getValue(Settings::CARD_TYPE);
        if (!isset(Methods::CARD_TYPES[$cardType])) {
            $errors[] = $m->l('Choose the card processor your Hesabe account uses.');
        }
        if ($errors !== []) {
            return $m->displayError(implode('<br>', array_map('htmlspecialchars', $errors)));
        }

        Settings::set(Settings::ENVIRONMENT, $environment);
        Settings::set(Settings::MERCHANT_CODE, $merchantCode);
        foreach ($secrets as $key => [$value]) {
            if ($value !== '') {
                Settings::set($key, $value);
            }
        }
        Settings::set(Settings::METHODS, implode(',', $methods));
        Settings::set(Settings::CARD_TYPE, (string) $cardType);

        $username = trim((string) \Tools::getValue(Settings::PANEL_USERNAME));
        if ($username !== Settings::get(Settings::PANEL_USERNAME)) {
            // A different login invalidates the stored Merchant API token.
            Settings::set(Settings::API_TOKEN, '');
        }
        Settings::set(Settings::PANEL_USERNAME, $username);
        $password = (string) \Tools::getValue(Settings::PANEL_PASSWORD);
        if ($password !== '') {
            Settings::set(Settings::PANEL_PASSWORD, $password);
            Settings::set(Settings::API_TOKEN, '');
        }
        if (\Tools::getValue('HESABE_PANEL_FORGET')) {
            Settings::set(Settings::PANEL_USERNAME, '');
            Settings::set(Settings::PANEL_PASSWORD, '');
            Settings::set(Settings::API_TOKEN, '');
        }
        return $m->displayConfirmation($m->l('Settings saved.'));
    }

    /**
     * Creates one payment session per enabled option, which charges nothing and shows which
     * payment types the account accepts, then checks the Merchant Panel login if one is set.
     */
    private function test(): string
    {
        $m = $this->module;
        if (!Settings::isConfigured()) {
            return $m->displayError($m->l('Save the merchant code, access code, secret key and IV key first.'));
        }
        $client = Settings::client();
        $lines = [];
        $failed = false;
        $return = \Context::getContext()->link->getModuleLink('hesabe', 'return', [], true);
        foreach (Settings::methods() as $method) {
            try {
                $client->checkout->create(
                    amount: '1.000',
                    orderReferenceNumber: 'PSTEST-' . strtoupper(bin2hex(random_bytes(4))),
                    responseUrl: $return,
                    failureUrl: $return,
                    paymentType: Methods::paymentType($method),
                );
                $lines[] = sprintf($m->l('%s: Hesabe accepted a payment session.'), $m->methodLabel($method));
            } catch (HesabeException $exception) {
                $failed = true;
                $lines[] = sprintf($m->l('%1$s: Hesabe refused it (%2$s).'), $m->methodLabel($method), $exception->getMessage());
            }
        }
        if (Settings::canRefund()) {
            try {
                $client->refunds->list();
                $lines[] = $m->l('Refunds: the Merchant Panel login works.');
            } catch (HesabeException $exception) {
                $failed = true;
                $lines[] = sprintf($m->l('Refunds: the Merchant Panel login failed (%s).'), $exception->getMessage());
            }
        }
        $text = implode('<br>', array_map('htmlspecialchars', $lines));
        return $failed ? $m->displayError($text) : $m->displayConfirmation($text);
    }

    private function overview(): string
    {
        $m = $this->module;
        $context = \Context::getContext();
        $reconciledAt = (int) Settings::get(Settings::RECONCILED_AT);
        $withoutOrder = [];
        foreach (Payments::withoutOrder((int) $context->shop->id, self::WITHOUT_ORDER_DAYS, 20) as $payment) {
            $withoutOrder[] = $payment + ['status_label' => $m->statusLabel($payment['status'])];
        }
        $context->smarty->assign([
            'hesabe_configured' => Settings::isConfigured(),
            'hesabe_production' => Settings::isProduction(),
            'hesabe_can_refund' => Settings::canRefund(),
            'hesabe_cron_url' => $context->link->getModuleLink('hesabe', 'reconcile', ['token' => Settings::cronToken()], true),
            'hesabe_reconciled_at' => $reconciledAt === 0 ? '' : date('Y-m-d H:i', $reconciledAt),
            // An hour is four missed 15-minute runs, so one slow or skipped run does not warn.
            'hesabe_reconcile_late' => $reconciledAt === 0 || time() - $reconciledAt > 3600,
            'hesabe_without_order' => $withoutOrder,
            'hesabe_without_order_days' => self::WITHOUT_ORDER_DAYS,
        ]);
        return $m->display(_PS_MODULE_DIR_ . 'hesabe/hesabe.php', 'views/templates/admin/overview.tpl');
    }

    private function form(): string
    {
        $m = $this->module;
        $methods = [];
        foreach (array_keys(Methods::all()) as $method) {
            $methods[] = ['id' => $method, 'name' => $m->methodLabel($method)];
        }
        $cardTypes = [];
        foreach (Methods::CARD_TYPES as $type => $name) {
            $cardTypes[] = ['id' => $type, 'name' => "{$name} ({$type})"];
        }
        $keep = $m->l('Saved. Leave empty to keep the saved value.');

        $account = [
            'form' => [
                'legend' => ['title' => $m->l('Hesabe account'), 'icon' => 'icon-cogs'],
                'description' => $m->l('Find these in Hesabe Merchant Panel, Profile, Merchant Keys.'),
                'input' => [
                    [
                        'type' => 'select',
                        'label' => $m->l('Environment'),
                        'name' => Settings::ENVIRONMENT,
                        'options' => [
                            'query' => [
                                ['id' => 'sandbox', 'name' => $m->l('Sandbox: test payments, no money moves')],
                                ['id' => 'production', 'name' => $m->l('Production: real payments')],
                            ],
                            'id' => 'id',
                            'name' => 'name',
                        ],
                    ],
                    ['type' => 'text', 'label' => $m->l('Merchant code'), 'name' => Settings::MERCHANT_CODE, 'required' => true],
                    $this->secret(Settings::ACCESS_CODE, $keep),
                    $this->secret(Settings::SECRET_KEY, $keep, $m->l('32 characters.')),
                    $this->secret(Settings::IV_KEY, $keep, $m->l('16 characters.')),
                    [
                        'type' => 'checkbox',
                        'label' => $m->l('Payment options'),
                        'name' => Settings::METHODS,
                        'desc' => $m->l('Each one shows as its own option at checkout. Use the connection test to see which ones your account accepts.'),
                        'values' => ['query' => $methods, 'id' => 'id', 'name' => 'name'],
                    ],
                    [
                        'type' => 'select',
                        'label' => $m->l('Card processor'),
                        'name' => Settings::CARD_TYPE,
                        'desc' => $m->l('The card payment type on your Hesabe account. Ask Hesabe if unsure.'),
                        'options' => ['query' => $cardTypes, 'id' => 'id', 'name' => 'name'],
                    ],
                ],
                'submit' => ['title' => $m->l('Save'), 'name' => self::SAVE],
                'buttons' => [
                    ['type' => 'submit', 'title' => $m->l('Test connection'), 'name' => self::TEST, 'icon' => 'process-icon-refresh'],
                ],
            ],
        ];
        $refunds = [
            'form' => [
                'legend' => ['title' => $m->l('Refunds (optional)'), 'icon' => 'icon-undo'],
                'description' => $m->l('Hesabe takes refund requests only through its Merchant API, which signs in with your Merchant Panel login. Every new sign-in ends earlier Merchant API sessions on the account, including those of other systems connected to it.'),
                'input' => [
                    ['type' => 'text', 'label' => $m->l('Merchant Panel username'), 'name' => Settings::PANEL_USERNAME],
                    $this->secret(Settings::PANEL_PASSWORD, $keep),
                    [
                        'type' => 'switch',
                        'label' => $m->l('Forget the login'),
                        'name' => 'HESABE_PANEL_FORGET',
                        'is_bool' => true,
                        'values' => [['id' => 'forget_on', 'value' => 1, 'label' => $m->l('Yes')], ['id' => 'forget_off', 'value' => 0, 'label' => $m->l('No')]],
                    ],
                ],
                'submit' => ['title' => $m->l('Save'), 'name' => self::SAVE],
            ],
        ];

        $values = [
            Settings::ENVIRONMENT => Settings::isProduction() ? 'production' : 'sandbox',
            Settings::MERCHANT_CODE => Settings::get(Settings::MERCHANT_CODE),
            Settings::ACCESS_CODE => '',
            Settings::SECRET_KEY => '',
            Settings::IV_KEY => '',
            Settings::CARD_TYPE => Settings::get(Settings::CARD_TYPE) ?: (string) PaymentType::MPGS,
            Settings::PANEL_USERNAME => Settings::get(Settings::PANEL_USERNAME),
            Settings::PANEL_PASSWORD => '',
            'HESABE_PANEL_FORGET' => 0,
        ];
        foreach (array_keys(Methods::all()) as $method) {
            $values[Settings::METHODS . '_' . $method] = in_array($method, Settings::methods(), true);
        }

        $helper = new \HelperForm();
        $helper->module = $m;
        $helper->table = $m->name;
        $helper->name_controller = $m->name;
        $helper->default_form_language = (int) \Configuration::get('PS_LANG_DEFAULT');
        $helper->allow_employee_form_lang = (int) \Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');
        $helper->token = \Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = \AdminController::$currentIndex . '&configure=' . $m->name;
        $helper->submit_action = self::SAVE;
        $helper->tpl_vars = ['fields_value' => $values];
        return $helper->generateForm([$account, $refunds]);
    }

    /**
     * @return array<string, mixed>
     */
    private function secret(string $key, string $keep, string $hint = ''): array
    {
        return [
            'type' => 'password',
            'label' => $this->label($key),
            'name' => $key,
            'desc' => trim((Settings::get($key) !== '' ? $keep : '') . ' ' . $hint),
        ];
    }

    private function label(string $key): string
    {
        $m = $this->module;
        return match ($key) {
            Settings::ACCESS_CODE => $m->l('Access code'),
            Settings::SECRET_KEY => $m->l('Secret key'),
            Settings::IV_KEY => $m->l('IV key'),
            Settings::PANEL_PASSWORD => $m->l('Merchant Panel password'),
            default => $key,
        };
    }
}
