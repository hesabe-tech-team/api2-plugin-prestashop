// Pays one real sandbox order through the module, end to end: guest checkout in the shop,
// Hesabe's sandbox KNET page with Hesabe's published test card, the return to the shop, and
// the order confirmation. Hesabe must reach the shop, so SHOP_URL is its public HTTPS address.
//
//   SHOP_URL=https://shop.example.com node checkout.mjs [knet|hosted] [cancel]
//
// SHOP_LANG=ar shops in Arabic, when the shop has that language installed.
//
// With "cancel" the customer presses Cancel on KNET's page, and the test expects to land back
// at checkout with the order not created.
//
// The test card (8888880000000001, 09/30, PIN 1234) is Hesabe's public sandbox card.
import { chromium } from "playwright";
import { mkdirSync } from "node:fs";

const SHOP = (process.env.SHOP_URL ?? "").replace(/\/+$/, "");
const METHOD = process.argv[2] ?? "knet";
const CANCEL = process.argv[3] === "cancel";
// With friendly URLs on, PrestaShop takes the language from the path alone.
const PAGES = process.env.SHOP_LANG ? `${SHOP}/${process.env.SHOP_LANG}` : SHOP;
const LABELS = {
  knet: /^\s*(KNET|كي نت)\s*$/i,
  card: /Visa \/ Mastercard|فيزا \/ ماستركارد/i,
  hosted: /Hesabe \((all payment methods|جميع طرق الدفع)\)/i,
};
const SHOTS = new URL("./results/", import.meta.url);
if (!SHOP) throw new Error("Set SHOP_URL to the shop's public HTTPS address");
mkdirSync(SHOTS, { recursive: true });

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1200, height: 1600 } });
page.setDefaultTimeout(60000);
const shot = (name) => page.screenshot({ path: new URL(`${METHOD}-${name}.png`, SHOTS).pathname.replace(/^\/([A-Z]:)/, "$1"), fullPage: true });
const step = (text) => console.log(`${new Date().toISOString().slice(11, 19)} ${text}`);

try {
  step("add a product to the cart");
  await page.goto(`${PAGES}/index.php?id_product=1&controller=product`, { waitUntil: "domcontentloaded" });
  await page.locator('[data-button-action="add-to-cart"]').first().click();
  await page.waitForTimeout(3000);

  step("guest checkout: personal information");
  await page.goto(`${PAGES}/index.php?controller=order`, { waitUntil: "domcontentloaded" });
  await page.locator('input[name="firstname"]').first().fill("Test");
  await page.locator('input[name="lastname"]').first().fill("Customer");
  await page.locator('#customer-form input[name="email"], form input[name="email"]').first().fill(`e2e+${Date.now()}@hesabe-test.dev`);
  for (const name of ["customer_privacy", "psgdpr"]) {
    const box = page.locator(`input[name="${name}"]`);
    if (await box.count()) await box.first().check();
  }
  await page.locator('#customer-form button[name="continue"], button[name="continue"]').first().click();

  step("address");
  await page.locator('input[name="address1"]').first().fill("Block 1, Street 1");
  await page.locator('input[name="city"]').first().fill("Kuwait City");
  const postcode = page.locator('input[name="postcode"]');
  if (await postcode.count() && await postcode.first().isVisible()) await postcode.first().fill("13001");
  await page.locator('button[name="confirm-addresses"]').first().click();

  step("delivery");
  await page.locator('button[name="confirmDeliveryOption"]').first().click();

  step(`payment: ${METHOD}`);
  const option = page.locator("label").filter({ hasText: LABELS[METHOD] }).first();
  await option.click();
  const terms = page.locator('input[name^="conditions_to_approve"]');
  if (await terms.count()) await terms.first().check();
  await shot("1-payment-step");
  await page.locator('#payment-confirmation button, .js-payment-confirmation button, button.js-place-order').first().click();

  step("Hesabe sandbox payment page");
  // Hesabe forwards KNET payments straight on to KNET's own test gateway.
  await page.waitForURL(/hesabe\.com|kpaytest\.com\.kw/, { timeout: 90000 });
  await page.waitForTimeout(2000);
  if (METHOD === "hosted") {
    // Hesabe's own page lists every method on the account; pick plain KNET, then pay.
    await page.getByText("KNET", { exact: true }).click();
    await page.locator("button:visible").filter({ hasText: /\d+\.\d{3}/ }).first().click();
  }
  await page.locator("#debitNumber").waitFor({ timeout: 60000 });
  // Typed key by key: KNET's page keeps the number from its key handlers and masks the field.
  // Set in one go, the field showed the number but Submit answered "Please Enter The Card
  // Number" (sandbox, 13 and 14 October 2026).
  await page.locator("#debitNumber").pressSequentially("8888880000000001");
  // KNET's test page has shown the expiry both as one MM/YY field and as two selects.
  const expiry = page.locator("#expiryDateInput");
  if (await expiry.count()) await expiry.pressSequentially("0930");
  for (const [sel, val] of [["select[name=debitExpiryMonth]", "09"], ["select[name=debitExpiryYear]", "2030"]]) {
    if (await page.locator(sel).count()) await page.selectOption(sel, val);
  }
  await page.locator("#cardPin").fill("1234");
  await shot("2-knet");
  if (CANCEL) {
    await page.locator("#cancel").click();
    const sure = page.locator("#cancelConfirm, #proceedConfirm");
    await sure.first().waitFor({ state: "visible", timeout: 15000 }).then(() => sure.first().click()).catch(() => {});
  } else {
    await page.locator("#proceed").click();
    // Submit disables itself until KNET answers with a confirmation panel, and nothing is
    // charged before that panel is accepted. For payments started on Hesabe's own page the
    // answer never came in 6 of 12 tries (sandbox, 13 October 2026); exit 75 lets CI start a
    // fresh payment.
    const confirm = page.locator("#proceedConfirm");
    const shown = await confirm.waitFor({ state: "visible", timeout: 60000 }).then(() => true, () => false);
    if (shown) {
      await confirm.click();
    } else if (/kpaytest\.com\.kw/.test(page.url()) && await page.locator("#proceed").isDisabled()) {
      throw Object.assign(new Error("KNET's test page did not answer Submit"), { exitCode: 75 });
    }
  }

  step("back at the shop");
  await page.waitForURL((url) => url.href.startsWith(SHOP), { timeout: 120000 });
  await page.waitForLoadState("domcontentloaded");
  for (let i = 0; i < 8 && /module\/hesabe\/return|controller=return/.test(page.url()); i += 1) {
    await page.waitForTimeout(5000);
  }
  await shot("3-final");
  const final = page.url();
  const ok = CANCEL ? /\/order(\?|$)|controller=order(&|$)/.test(final) : /order-confirmation/.test(final);
  step(`${ok ? "PASS" : "FAIL"} ${final.replace(/key=[^&]+/, "key=...")}`);
  process.exitCode = ok ? 0 : 1;
} catch (error) {
  await shot("error").catch(() => {});
  step(`FAIL ${error.message} at ${page.url()}`);
  process.exitCode = error.exitCode ?? 1;
} finally {
  await browser.close();
}
