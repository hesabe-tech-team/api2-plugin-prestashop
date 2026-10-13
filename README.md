# KNET and card payments in PrestaShop with Hesabe

The module adds Hesabe to PrestaShop's checkout as one payment option per method: KNET,
Visa / Mastercard, and Hesabe's own page with every method on your account. The customer
pays on Hesabe's page and comes back to your shop. Refunds are sent from the order page.

The order is created only after Hesabe confirms the payment through its transaction
lookup. Nothing the customer's browser brings back, and nothing in Hesabe's webhook, is
trusted on its own. A cron job catches payments whose customer never came back.

It needs PrestaShop 8.1 or later and PHP 8.1 or later, and nothing beyond what
PrestaShop already needs. It was tested end to end against Hesabe's sandbox on PrestaShop
8.2.8 and 9.2.0, on PHP 8.1, in October 2026. The release is one zip, `hesabe.zip`.

Customers and employees see the module in English or Arabic, whichever language PrestaShop
shows them. The Arabic was checked on PrestaShop 9.2.0: checkout, the confirmation page,
the settings page and the order panel.

## What you need before starting

1. A Hesabe merchant account with the payment types you want enabled: KNET (`1`), cards
   through MPGS (`2`) or CyberSource (`5`), or Hesabe's page (`0`). Email
   support@hesabe.com to enable one. The settings page shows which ones your account
   accepts.
2. Your keys from Merchant Panel, Profile, Merchant Keys: merchant code, access code,
   secret key (32 characters), IV key (16 characters).
3. For refunds only: your Merchant Panel username and password. Hesabe takes refund
   requests only through its Merchant API, which signs in with that login.
4. PrestaShop 8.1 or later on PHP 8.1 or later, served over HTTPS on a public address.
   Hesabe sends the customer and its webhook back to your shop, so it must reach it.
5. KWD as a currency in your shop (Localization, Currencies), with three decimals. The
   module shows its options only when the cart is in KWD.

## Step 1. Install the module

Download `hesabe.zip` from the
[latest release](https://github.com/ruptyx/hesabe-prestashop/releases/latest).

In the back office: Modules, Module Manager, Upload a module, and drop the zip.
PrestaShop installs it and shows "Module installed!".

With a shell on the server, from the PrestaShop folder:

```bash
curl -fL -o /tmp/hesabe.zip \
  https://github.com/ruptyx/hesabe-prestashop/releases/latest/download/hesabe.zip
unzip -o /tmp/hesabe.zip -d modules/
php bin/console prestashop:module install hesabe
```

Check: the module is listed under Modules, Payment, as "Hesabe".

## Step 2. Enter your keys

Open Configure on the module. Choose the sandbox first, enter the four keys, tick the
payment options to show, and save. Press Test connection: for each option, Hesabe either
accepts a payment session or says why not. Nothing is charged.

The same can be scripted. `hesabe:configure` reads the variables the Hesabe SDKs read,
from the environment, so no key lands in the shell history:

```bash
HESABE_ENVIRONMENT=sandbox \
HESABE_MERCHANT_CODE=... HESABE_ACCESS_CODE=... \
HESABE_SECRET_KEY=... HESABE_IV_KEY=... \
HESABE_MERCHANT_USERNAME=... HESABE_MERCHANT_PASSWORD=... \
php bin/console hesabe:configure --methods=knet,card --card-type=2
```

Check: Test connection reports "Hesabe accepted a payment session" for every option.

### Why the keys are stored encrypted

PrestaShop's SQL Manager hides only passwords and secure keys, so a plain value in the
configuration table is readable by any employee with SQL access. The module encrypts the
access code, secret key, IV key and panel password with your shop's own cookie key.
PrestaShop's `prestashop:module configure` writes plain text, which is why the module has
its own command.

## Step 3. Pay once in the sandbox

Put something in the cart, choose KNET, and pay with Hesabe's KNET test card:
`8888880000000001`, expiry `09/30`, PIN `1234`.

Check: you land on the order confirmation, and the order shows "Payment accepted" with
the amount to three decimals. The order page has a Hesabe panel with the payment
reference and Hesabe's token.

## Step 4. Schedule the reconciler

The settings page shows a reconcile address with a token. Call it every 15 minutes:

```bash
*/15 * * * * curl -fsS "https://your-shop.example.com/module/hesabe/reconcile?token=..." >/dev/null
```

It looks up every attempt that is still open: customers who closed the tab, webhooks that
never arrived, and card declines, which Hesabe does not report at all. An attempt is
checked after 15 minutes, then at doubling intervals up to a day, for four days.

Check: the address answers `{"ok":true,...}`.

## Step 5. Go live

Switch the environment to production, enter the production keys, save, and run Test
connection again. Pay one small real order with KNET, then refund it from the order page
(step 6). Hesabe's smallest refund is 0.250 KWD, so pay at least that.

## Step 6. Refund from the order page

Enter the Merchant Panel login in the Refunds section of the settings. The order page
then has a refund form in the Hesabe panel.

What Hesabe does with a refund, as probed on the sandbox and on production in October
2026:

- A refund is a request. Hesabe approves it before any money moves; the panel shows it as
  pending until then.
- The minimum is 0.250 KWD on both. A payment holds one pending request at a time
  (sandbox).
- A partial refund is sent as such; a refund of the full amount is sent as a full refund.

The form does not create a credit slip. Use PrestaShop's own refund buttons for the
accounting side.

Every new Merchant API sign-in ends earlier ones on the same Hesabe account. The module
keeps its token, encrypted, and reuses it. Another system that signs in with the same
login, Odoo for example, will sign the shop out and the shop will sign it out in turn.

## How the module works

| File | What it does |
|---|---|
| `hesabe.php` | Adds one payment option per enabled method when the cart is in KWD |
| `controllers/front/redirect.php` | Records the attempt, asks Hesabe for a payment session, sends the customer there |
| `src/Attempt.php` | Builds the session: amount to three decimals, a reference unique to the attempt, signed return and webhook addresses |
| `controllers/front/webhook.php` | Hesabe's webhook. Reads nothing from the body; triggers a lookup |
| `controllers/front/return.php` | Where the customer comes back. Triggers a lookup, then shows the confirmation, a retry, or a short wait |
| `controllers/front/reconcile.php` | The cron address. Triggers lookups for open attempts |
| `src/Settlement.php` | The lookup, and the only place an order is created |
| `controllers/admin/AdminHesabeRefundController.php` | Receives the refund form |
| `src/Refunds.php` | Sends a refund request and records the outcome |
| `src/Settings.php` | Settings per shop, secrets encrypted |

The Hesabe calls go through [hesabe-php](https://github.com/ruptyx/hesabe-php), bundled
in the zip.

### Things that matter

- **The lookup decides.** Hesabe's redirect data is encrypted but carries no MAC, and its
  webhook is unsigned plain JSON. Both only trigger `GET api/transaction/<reference>`,
  and an order is created only when that answers `SUCCESSFUL`.
- **The amount is Hesabe's.** The order is created with the amount Hesabe reports. If
  the cart changed during payment, PrestaShop files the order under "Payment error".
- **One order per cart.** PrestaShop does not prevent two orders for one cart, and the
  webhook arrives one to three seconds before the customer. Settlement holds a MySQL lock
  per cart. A second payment for a cart that already has an order is marked `duplicate`
  and logged for a manual refund.
- **Each attempt has its own reference.** A retry never shares a reference with an
  earlier attempt, so a lookup always names one payment.
- **Card declines leave no trace.** Hesabe sends no redirect and no webhook for a
  declined card or a failed 3-D Secure, and the lookup answers 404 (sandbox, October
  2026). The attempt stays open until the reconciler gives up after four days.
- **Signed addresses.** The return and webhook addresses carry an HMAC of the reference,
  so a guessed address is answered 404 and triggers nothing.
- **No order before payment.** An abandoned KNET page leaves no cancelled order and holds
  no stock.
- **KWD keeps three decimals.** In a webhook or cron request, PrestaShop starts in the
  shop's default currency and caches its rounding precision. Settlement switches to the
  cart's currency and clears that cache first, so a shop whose default currency has two
  decimals still records 19.120, not 19.12.

## If something goes wrong

- **No Hesabe option at checkout.** The cart is not in KWD, the keys are not saved, or
  KWD is not ticked for the module under Payment, Preferences.
- **"The payment could not be started."** Hesabe refused the session. Run Test
  connection: it shows the method and Hesabe's reason. Code 424 means the payment type is
  not enabled on the account.
- **The customer paid but no order appeared.** Check that the shop is reachable over
  HTTPS from outside and not in maintenance mode, then call the reconcile address. Look in
  Advanced Parameters, Logs for lines starting with "Hesabe".
- **An order is under "Payment error".** The amount Hesabe reports differs from the cart
  total, usually because the cart changed in another tab during payment.
- **A log line says a payment was paid after the cart already had an order.** The
  customer paid twice. Refund the second payment from the Hesabe Merchant Panel.
- **Refund: "No answer from Hesabe".** The request may or may not exist at Hesabe. Check
  Refunds in the Merchant Panel before sending another.
- **Refunds stopped working after another system was connected.** It signs in with the
  same Merchant Panel login. Give one of them its own Hesabe user, if your account allows
  it.

## Development

`dev/docker-compose.yml` runs a shop with this repository mounted as the module.
Hesabe must reach it, so put a public HTTPS address in front of port 8080, a Cloudflare
quick tunnel for example, and pass its host:

```bash
docker run -d --name tunnel cloudflare/cloudflared tunnel --url http://host.docker.internal:8080
PS_DOMAIN=<tunnel host> PS_ENABLE_SSL=1 docker compose -f dev/docker-compose.yml up -d
docker compose -f dev/docker-compose.yml exec -u www-data shop sh -c \
  'php bin/console prestashop:module install hesabe && php modules/hesabe/dev/setup-shop.php'
```

`PS_IMAGE=prestashop/prestashop:8.2.8-8.1-apache` selects PrestaShop 8.
`dev/setup-shop.php` adds KWD and a carrier for Kuwait to the demo shop.

`tests/e2e/` drives a real sandbox payment with Playwright: guest checkout, KNET's test
page, the return, the order confirmation. `refund.mjs` sends a refund from the order page
and `upload.mjs` installs the zip through Module Manager.

```bash
cd tests/e2e && npm install
SHOP_URL=https://<tunnel host> node checkout.mjs knet          # or hosted, or: knet cancel
SHOP_URL=https://<tunnel host> ORDER_ID=1 node refund.mjs 0.250
```

`SHOP_LANG=ar` runs the checkout in Arabic, once the shop has the Arabic language
(International, Translations, Add / Update a language).

`translations/ar.php` keys each Arabic string by the source file and the md5 of the English
text, as PrestaShop looks it up, so rewording an English string drops its translation.
`php scripts/translations.php` lists any string without an entry and any entry no string
uses; CI runs it.

`scripts/build.sh` builds `build/hesabe.zip`.

MIT licensed.
