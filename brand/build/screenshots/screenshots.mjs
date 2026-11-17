// Capture the WordPress.org screenshots of 99Trace from the local sandbox (WordPress Playground on 127.0.0.1:4401).
// Usage: node screenshots.mjs <playwright_dir> <out_dir>
import { createRequire } from 'node:module';
import path from 'node:path';

const [pwDir, out] = process.argv.slice(2);
const require = createRequire(path.join(pwDir, 'noop.js'));
const { chromium } = require('playwright');

const base = 'http://127.0.0.1:4401';
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1280, height: 860 }, deviceScaleFactor: 2 });
await page.goto(`${base}/wp-admin/?autologin=1`);
// Quieter admin chrome for the listing: no update or welcome notices.
const css = '.notice:not(.inline), .update-nag, #wp-admin-bar-comments, #wp-admin-bar-new-content { display: none !important; }';

async function shot(url, file, prepare) {
  await page.goto(base + url, { waitUntil: 'networkidle' });
  await page.addStyleTag({ content: css });
  if (prepare) {
    await prepare();
  }
  await page.waitForTimeout(400);
  await page.screenshot({ path: path.join(out, file) });
  console.log(file);
}

// An invented project key that looks real, and no sandbox proxy, on the Connect screen (saved through the form itself).
await page.goto(`${base}/wp-admin/admin.php?page=wc-settings&tab=99trace`, { waitUntil: 'networkidle' });
await page.fill('#w99_phwc_key', 'phc_Hx7qL2mZ9vN4pX1cR5tY8bK3wQ6sD0fGj');
await page.fill('#w99_phwc_browser_host', '');
await Promise.all([page.waitForNavigation(), page.click('.woocommerce-save-button')]);
await shot('/wp-admin/admin.php?page=wc-settings&tab=99trace', 'screenshot-1.png');
await shot('/wp-admin/admin.php?page=wc-settings&tab=99trace&section=consent', 'screenshot-2.png');
await shot('/wp-admin/admin.php?page=wc-settings&tab=99trace&section=events', 'screenshot-3.png');
await shot('/wp-admin/admin.php?page=wc-settings&tab=99trace&section=visitors', 'screenshot-4.png');
await shot('/wp-admin/post.php?action=edit&post=14', 'screenshot-5.png', async () => {
  // The order's own data on the left, 99Trace's box at the top of the right column.
  await page.evaluate(() => {
    const box = document.querySelector('#w99-phwc');
    const side = document.querySelector('#side-sortables');
    if (box && side) {
      side.insertBefore(box, side.firstChild);
    }
    window.scrollTo(0, 0);
  });
});
await shot('/wp-admin/admin.php?page=wc-status&tab=99trace', 'screenshot-6.png');
await browser.close();
