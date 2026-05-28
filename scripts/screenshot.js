const puppeteer = require('puppeteer');
const path = require('path');
const fs = require('fs');

const BASE_URL = 'http://localhost:8080';
const DOCS_DIR = path.resolve(__dirname, '../docs');

const pages = [
  { url: BASE_URL,                   output: 'dashboard.png' },
  { url: `${BASE_URL}/reports.html`, output: 'reports.png',
    beforeScreenshot: async (page) => {
      await page.click('#btn-run');
      await page.waitForFunction(
        () => document.getElementById('chart-section').style.display !== 'none',
        { timeout: 15000 }
      );
      // Allow the chart to finish rendering
      await new Promise(r => setTimeout(r, 1000));
    }
  },
];

(async () => {
  if (!fs.existsSync(DOCS_DIR)) fs.mkdirSync(DOCS_DIR, { recursive: true });

  const browser = await puppeteer.launch({ args: ['--no-sandbox'] });

  for (const { url, output, beforeScreenshot } of pages) {
    const page = await browser.newPage();
    await page.setViewport({ width: 1560, height: 1000, deviceScaleFactor: 2 });
    await page.goto(url, { waitUntil: 'networkidle2', timeout: 30000 });
    if (beforeScreenshot) await beforeScreenshot(page);
    const dest = path.join(DOCS_DIR, output);
    await page.screenshot({ path: dest });
    await page.close();
    console.log(`Saved: ${dest}`);
  }

  await browser.close();
})();
