<?php
/**
 * Copyright (c) 2026 the hesabe SDK authors. MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Hesabe\PrestaShop\Command;

use Hesabe\PrestaShop\Methods;
use Hesabe\PrestaShop\Settings;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `php bin/console hesabe:configure`: sets the module up from the same `HESABE_*` variables
 * the Hesabe SDKs read, so an install can be scripted.
 *
 * Secrets come from the environment, never from arguments, which would land in the shell
 * history and the process list. PrestaShop's own `prestashop:module configure` cannot be
 * used for them: it writes plain text, and these settings are stored encrypted.
 */
final class ConfigureCommand extends Command
{
    private const VARIABLES = [
        'HESABE_ENVIRONMENT' => Settings::ENVIRONMENT,
        'HESABE_MERCHANT_CODE' => Settings::MERCHANT_CODE,
        'HESABE_ACCESS_CODE' => Settings::ACCESS_CODE,
        'HESABE_SECRET_KEY' => Settings::SECRET_KEY,
        'HESABE_IV_KEY' => Settings::IV_KEY,
        'HESABE_MERCHANT_USERNAME' => Settings::PANEL_USERNAME,
        'HESABE_MERCHANT_PASSWORD' => Settings::PANEL_PASSWORD,
    ];

    protected function configure(): void
    {
        $this->setName('hesabe:configure')
            ->setDescription('Configure the Hesabe module from HESABE_* environment variables')
            ->addOption('methods', null, InputOption::VALUE_REQUIRED, 'Payment options, comma separated: knet, card, hosted')
            ->addOption('card-type', null, InputOption::VALUE_REQUIRED, 'Card processor: 2 for MPGS, 5 for CyberSource');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $values = [];
        foreach (self::VARIABLES as $variable => $key) {
            $value = getenv($variable);
            if (is_string($value) && $value !== '') {
                $values[$key] = $value;
            }
        }

        $errors = [];
        if (isset($values[Settings::ENVIRONMENT]) && !in_array($values[Settings::ENVIRONMENT], ['sandbox', 'production'], true)) {
            $errors[] = 'HESABE_ENVIRONMENT must be sandbox or production.';
        }
        if (isset($values[Settings::MERCHANT_CODE]) && !ctype_digit($values[Settings::MERCHANT_CODE])) {
            $errors[] = 'HESABE_MERCHANT_CODE must be digits.';
        }
        if (isset($values[Settings::SECRET_KEY]) && strlen($values[Settings::SECRET_KEY]) !== 32) {
            $errors[] = 'HESABE_SECRET_KEY must be 32 characters.';
        }
        if (isset($values[Settings::IV_KEY]) && strlen($values[Settings::IV_KEY]) !== 16) {
            $errors[] = 'HESABE_IV_KEY must be 16 characters.';
        }
        $methods = $input->getOption('methods');
        if (is_string($methods)) {
            $list = array_filter(array_map('trim', explode(',', $methods)));
            if ($list === [] || array_diff($list, array_keys(Methods::all())) !== []) {
                $errors[] = '--methods takes knet, card and hosted, comma separated.';
            }
            $values[Settings::METHODS] = implode(',', $list);
        }
        $cardType = $input->getOption('card-type');
        if (is_string($cardType)) {
            if (!isset(Methods::CARD_TYPES[(int) $cardType])) {
                $errors[] = '--card-type is 2 (MPGS) or 5 (CyberSource).';
            }
            $values[Settings::CARD_TYPE] = (string) (int) $cardType;
        }
        if ($errors !== []) {
            foreach ($errors as $error) {
                $output->writeln("<error>{$error}</error>");
            }
            // Plain exit codes: Command::SUCCESS and FAILURE arrived in Symfony 5.1, after the 4.4
            // that PrestaShop 8 ships.
            return 1;
        }

        foreach ($values as $key => $value) {
            Settings::set($key, $value);
        }
        if (isset($values[Settings::PANEL_USERNAME]) || isset($values[Settings::PANEL_PASSWORD])) {
            Settings::set(Settings::API_TOKEN, '');
        }

        $output->writeln(sprintf('Updated: %s', $values === [] ? 'nothing' : implode(', ', array_keys($values))));
        $output->writeln(sprintf(
            'Environment %s, %s, refunds %s.',
            Settings::isProduction() ? 'production' : 'sandbox',
            Settings::isConfigured() ? 'checkout ready' : 'keys missing',
            Settings::canRefund() ? 'enabled' : 'off',
        ));
        return 0;
    }
}
