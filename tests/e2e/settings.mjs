// Opens the module's settings page, saves it unchanged (secret fields stay empty, which keeps
// the stored values), then runs the connection test, and prints both messages.
//
//   SHOP_URL=https://shop.example.com node settings.mjs
import { chromium } from "playwright";
import { mkdirSync } from "node:fs";

const SHOP = (process.env.SHOP_URL ?? "").replace(/\/+$/, "");
const ADMIN = `${SHOP}/${process.env.ADMIN_DIR ?? "admin-dev"}`;
const SHOTS = new URL("./results/", import.meta.url);
if (!SHOP) throw new Error("Set SHOP_URL");
mkdirSync(SHOTS, { recursive: true });

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1400, height: 2200 } });
page.setDefaultTimeout(60000);
const shot = (name) => page.screenshot({ path: new URL(`settings-${name}.png`, SHOTS).pathname.replace(/^\/([A-Z]:)/, "$1"), fullPage: true });
const alerts = async () => (await page.locator(".alert").allInnerTexts()).map((t) => t.trim()).filter(Boolean).join(" | ");

try {
  await page.goto(`${ADMIN}/index.php?controller=AdminLogin`, { waitUntil: "domcontentloaded" });
  await page.locator("#email").fill(process.env.ADMIN_MAIL ?? "demo@example.com");
  await page.locator("#passwd").fill(process.env.ADMIN_PASSWD ?? "prestashop_demo");
  await page.locator("#submit_login").click();
  await page.waitForURL(/token=/, { timeout: 60000 });
  const symfonyLink = await page.locator('a[href*="_token="]').first().getAttribute("href");
  const token = new URL(page.url()).searchParams.get("_token") ?? new URL(symfonyLink, page.url()).searchParams.get("_token");

  await page.goto(`${ADMIN}/index.php/improve/modules/manage/action/configure/hesabe?_token=${token}`, { waitUntil: "domcontentloaded" });
  await page.locator('button[name="submitHesabeSettings"]').first().waitFor();
  await shot("1-page");
  await page.locator('button[name="submitHesabeSettings"]').first().click();
  await page.waitForLoadState("domcontentloaded");
  console.log(`save: ${await alerts()}`);
  await page.locator('button[name="submitHesabeTest"]').click();
  await page.waitForLoadState("domcontentloaded");
  await shot("2-test");
  const result = await alerts();
  console.log(`test: ${result}`);
  process.exitCode = /accepted a payment session|قبل Hesabe جلسة الدفع/.test(result) ? 0 : 1;
} catch (error) {
  await shot("error").catch(() => {});
  console.log(`FAIL ${error.message} at ${page.url()}`);
  process.exitCode = 1;
} finally {
  await browser.close();
}
