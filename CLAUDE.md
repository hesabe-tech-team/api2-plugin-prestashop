# hesabe-prestashop

PrestaShop 8.1+ and 9 payment module for the Hesabe payment gateway (Kuwait), built and
verified against the live sandbox. Offers KNET, Visa / Mastercard and Hesabe's hosted page
as separate checkout options, sends refunds from the back-office order page, and speaks
English and Arabic. Talks to Hesabe through [hesabe-php](https://github.com/ruptyx/hesabe-php),
bundled in the release zip; sibling plugin: [hesabe-odoo](https://github.com/ruptyx/hesabe-odoo).

## Testing

- Lint and translations: `php scripts/translations.php` (every translated string has an
  Arabic entry, keyed as PrestaShop looks it up).
- PHPStan runs inside the official images, against the core classes of each version:
  `docker run --rm --entrypoint sh -v "$PWD:/var/www/html/modules/hesabe" -w /var/www/html/modules/hesabe -e _PS_ROOT_DIR_=/var/www/html prestashop/prestashop:9.2.0-8.1 -c 'php -d memory_limit=2G vendor/bin/phpstan analyse --no-progress'`
  (and `8.2.8-8.1-apache`). Run `composer install` first.
- End to end: `tests/e2e/` pays real sandbox orders with Playwright through a shop behind a
  public tunnel (`dev/`, see the README's Local testing). CI does this on every push in clean
  8.2.8 and 9.2.0 shops, installing the built zip the way a merchant does.
- On Windows, prefix `docker compose exec` commands that name a container path with
  `MSYS_NO_PATHCONV=1`; Git Bash rewrites `/tmp/...` into a Windows path otherwise.

There is no unit suite: PHPStan, the translation check and real sandbox payments are the
checks. Hesabe's sandbox credentials are public documentation and live in `ci.yml`; the
module ships blank, on the sandbox.

## Key Locations

- Payment options, the order-page panel, status labels: `hesabe.php`
- Starting a payment and the reference format: `controllers/front/redirect.php`, `src/Attempt.php`
- Inbound routes: `controllers/front/return.php`, `webhook.php`, `reconcile.php` (the cron)
- Settlement, the only place an order is created, with the per-cart lock and the KWD
  precision fix: `src/Settlement.php`
- The attempt table and the reconciler schedule: `src/Payments.php`
- Refunds: `src/Refunds.php`, `controllers/admin/AdminHesabeRefundController.php` (a hidden
  admin tab, so PrestaShop's token and order permissions apply), `views/templates/hook/admin_order.tpl`
- Settings, encryption at rest, signed URLs: `src/Settings.php`, `src/SettingsForm.php`,
  `src/ConfigurationTokenStore.php` (the Merchant API session)
- `php bin/console hesabe:configure`: `src/Command/ConfigureCommand.php`, `config/services.yml`
- Tables, hooks, uninstall: `src/Installer.php`
- Arabic: `translations/ar.php`, checked by `scripts/translations.php`
- Release: `scripts/build.sh` builds `build/hesabe.zip`; a `v*` tag makes CI attach it to a
  GitHub release
- README screenshots: `docs/screenshots/`, taken on PrestaShop 9.2.0 against the sandbox

## Cardinal Rules

1. **Never trust an inbound payload.** Hesabe signs nothing. The webhook body and the
   redirect's `data` are never read: the HMAC-signed URL names the attempt and
   `Settlement::settle` looks it up. A new inbound path goes through `settle` too. A
   notification is a trigger, never evidence; keep that framing in docs and examples.
2. **Orders are created in one place.** Only `Settlement::createOrder` calls
   `validateOrder`, with Hesabe's amount, under the per-cart MySQL lock. Never create an order
   before payment: an abandoned KNET page must leave no cancelled order and hold no stock.
3. **One reference per attempt.** `PS<cart>-<suffix>`, letters, digits and hyphens only.
   Reusing a reference across attempts makes a lookup ambiguous; other characters are
   stripped by some Hesabe products.
4. **A refund request is never sent twice.** The row is recorded as `unknown` before the
   call, and only a definite refusal frees its amount. Hesabe has no idempotency keys; a POST
   is never replayed, by the module or the SDK.
5. **Secrets go through `Settings::set`.** It encrypts with `_NEW_COOKIE_KEY_`. Never write a
   `HESABE_*` key with `Configuration::updateValue` directly, and never point merchants at
   `prestashop:module configure`, which stores plain text. Secrets never enter a log line.
6. **KWD only, three decimals.** Amounts are strings through `Hesabe\Amount`. Webhook and
   cron requests switch to the cart's currency before any total (`useCartCurrency`), or a
   two-decimal default currency rounds 19.120 to 19.12.
7. **PrestaShop 8 and 9 alike.** HelperForm for settings, no Symfony API newer than the 4.4
   that PrestaShop 8 ships (`Command::SUCCESS` does not exist there), PHPStan against both
   images. Read core in the container rather than trusting memory or 1.7-era examples.
8. **Translations move with the English.** Rewording a `$this->l()` or `{l s=...}` string
   drops its Arabic entry; regenerate `translations/ar.php` in the same commit. CI fails
   otherwise.
9. Comments explain why, or document a function. Never what the code obviously does, never
   what used to be there.
10. Work is not complete until CI passes and, for anything touching checkout, settlement or
    refunds, a real sandbox payment has gone through the module.

## Known Loose Ends

- **A refund after another sign-in fails until the session is renewed.** Hesabe keeps one
  Merchant API session per account. hesabe-php replays a 401 with a fresh token only for GETs;
  on the refund POST it rethrows and leaves the dead token stored, so every later refund fails
  the same way until the token expires (15 minutes) or a GET signs in again (Test connection,
  or the refresh of a pending refund on the order page). Each failure is recorded as
  `unknown`, which holds its amount, and nothing in the back office releases such a row. On
  14 October 2026 other sign-ins on the shared sandbox account ended the shop's token within
  seconds, twice in two minutes. That 401'd request was not recorded by Hesabe: a later
  request on the same payment was accepted, which one pending request per payment would have
  refused. The fix belongs in hesabe-php, with node and python for parity: drop the stored
  token on a 401 without replaying the call.
- **KNET's sandbox page stalls.** Payments started on Hesabe's own page often stall after
  Submit (10 of 16 on 13 October 2026); `checkout.mjs` exits 75 and CI retries. A second
  failure, "Please Enter The Card Number" with the number visible, came from setting the
  field with `fill()` (runs 38486336949 and 38623601916); the test now types it key by key,
  as KNET's page expects.
- **Card payments are not in CI.** An MPGS payment was run by hand on 14 October 2026: Hesabe
  sends the customer to Mastercard's checkout (NBK test gateway), each card field sits in its
  own `/form/` iframe next to hidden `aria-hidden` decoys with the same ids, then a 3-D Secure
  emulator. Test card `5123450000000008`, `01/39`, `100`. CyberSource (`5`) was never paid end
  to end.
- **No production payment has gone through the module.** Production lookups and refunds were
  probed with hesabe-php on 13 October 2026: 0.250 KWD minimum, and a full refund
  auto-approved 21 minutes later.
- **The webhook is verified on the sandbox only.** It arrives as a POST from `GuzzleHttp/7`,
  five to six seconds before the customer, and creates the order on its own when the browser
  never returns (14 October 2026). No production webhook has been captured.
- The reconcile answer is `"checked":[]` when nothing was due and an object otherwise: PHP's
  empty array. Harmless, but not a stable JSON shape.
- Tag `v1.0.0` carries the e2e test from before the stall retry, so re-running that tag's CI
  can take several attempts.
- The Arabic has not been reviewed by a native speaker or by Hesabe. "Hesabe" and the
  Merchant Panel menu names stay in Latin script because Hesabe's Arabic spelling was not
  verified.
- On PrestaShop 8, a command-line install needs `php bin/console cache:clear --no-warmup`
  before `hesabe:configure` exists.
- Uninstalling drops both tables and every setting; the confirmation says so.
