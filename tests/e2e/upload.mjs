// Installs the built zip the way most merchants will: Modules, Module Manager, "Upload a
// module". Expects PrestaShop to report the module installed.
//
//   SHOP_URL=https://shop.example.com node upload.mjs ../../build/hesabe.zip
import { chromium } from "playwright";
import { mkdirSync } from "node:fs";
import { resolve } from "node:path";

const SHOP = (process.env.SHOP_URL ?? "").replace(/\/+$/, "");
const ZIP = resolve(process.argv[2] ?? "../../build/hesabe.zip");
const ADMIN = `${SHOP}/${process.env.ADMIN_DIR ?? "admin-dev"}`;
const SHOTS = new URL("./results/", import.meta.url);
if (!SHOP) throw new Error("Set SHOP_URL");
mkdirSync(SHOTS, { recursive: true });

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1400, height: 1400 } });
page.setDefaultTimeout(90000);
const shot = (name) => page.screenshot({ path: new URL(`upload-${name}.png`, SHOTS).pathname.replace(/^\/([A-Z]:)/, "$1"), fullPage: true });

try {
  await page.goto(`${ADMIN}/index.php?controller=AdminLogin`, { waitUntil: "domcontentloaded" });
  await page.locator("#email").fill(process.env.ADMIN_MAIL ?? "demo@example.com");
  await page.locator("#passwd").fill(process.env.ADMIN_PASSWD ?? "prestashop_demo");
  await page.locator("#submit_login").click();
  await page.waitForURL(/token=/, { timeout: 90000 });
  const symfonyLink = await page.locator('a[href*="_token="]').first().getAttribute("href");
  const token = new URL(page.url()).searchParams.get("_token") ?? new URL(symfonyLink, page.url()).searchParams.get("_token");

  await page.goto(`${ADMIN}/index.php/improve/modules/manage?_token=${token}`, { waitUntil: "domcontentloaded" });
  await page.locator("#page-header-desc-configuration-add_module").click();
  await page.locator('#importDropzone input[type="file"], input.dz-hidden-input').first().setInputFiles(ZIP);
  const done = page.locator(".module-import-success, .module-import-failure");
  await done.first().waitFor({ state: "visible", timeout: 180000 });
  await shot("result");
  const text = (await done.first().innerText()).trim();
  console.log(text.replace(/\s+/g, " "));
  process.exitCode = /success/.test(await done.first().getAttribute("class")) ? 0 : 1;
} catch (error) {
  await shot("error").catch(() => {});
  console.log(`FAIL ${error.message} at ${page.url()}`);
  process.exitCode = 1;
} finally {
  await browser.close();
}
