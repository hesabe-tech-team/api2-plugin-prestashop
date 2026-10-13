// Sends one refund request from the back-office order page and reads the result back from
// the Hesabe panel on that page. Uses the dev shop's admin account.
//
//   SHOP_URL=https://shop.example.com ORDER_ID=6 node refund.mjs [amount]
import { chromium } from "playwright";
import { mkdirSync } from "node:fs";

const SHOP = (process.env.SHOP_URL ?? "").replace(/\/+$/, "");
const ORDER = process.env.ORDER_ID ?? "";
const AMOUNT = process.argv[2] ?? "0.250";
const ADMIN = `${SHOP}/${process.env.ADMIN_DIR ?? "admin-dev"}`;
const SHOTS = new URL("./results/", import.meta.url);
if (!SHOP || !ORDER) throw new Error("Set SHOP_URL and ORDER_ID");
mkdirSync(SHOTS, { recursive: true });

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1400, height: 1800 } });
page.setDefaultTimeout(60000);
const shot = (name) => page.screenshot({ path: new URL(`refund-${name}.png`, SHOTS).pathname.replace(/^\/([A-Z]:)/, "$1"), fullPage: true });

try {
  await page.goto(`${ADMIN}/index.php?controller=AdminLogin`, { waitUntil: "domcontentloaded" });
  await page.locator("#email").fill(process.env.ADMIN_MAIL ?? "demo@example.com");
  await page.locator("#passwd").fill(process.env.ADMIN_PASSWD ?? "prestashop_demo");
  await page.locator("#submit_login").click();
  await page.waitForURL(/token=/, { timeout: 60000 });
  // PrestaShop 9 lands on a Symfony page with `_token` in the URL; 8 lands on a legacy page,
  // whose menu links to the Symfony pages carry it.
  const symfonyLink = await page.locator('a[href*="_token="]').first().getAttribute("href");
  const token = new URL(page.url()).searchParams.get("_token") ?? new URL(symfonyLink, page.url()).searchParams.get("_token");

  await page.goto(`${ADMIN}/index.php/sell/orders/${ORDER}/view?_token=${token}`, { waitUntil: "domcontentloaded" });
  const panel = page.locator("#hesabe-order-panel");
  await panel.waitFor();
  await shot("1-before");
  await panel.locator('input[name="hesabe_refund_amount"]').fill(AMOUNT);
  await panel.locator('button[name="submitHesabeRefund"]').click();
  await page.waitForLoadState("domcontentloaded");
  await page.locator("#hesabe-order-panel").waitFor();
  await shot("2-after");
  const text = await page.locator("#hesabe-order-panel").innerText();
  console.log(text.replace(/\n{2,}/g, "\n"));
  process.exitCode = /sent to Hesabe|إلى Hesabe/.test(text) ? 0 : 1;
} catch (error) {
  await shot("error").catch(() => {});
  console.log(`FAIL ${error.message} at ${page.url()}`);
  process.exitCode = 1;
} finally {
  await browser.close();
}
