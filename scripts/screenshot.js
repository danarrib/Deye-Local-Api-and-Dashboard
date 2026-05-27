const puppeteer = require('puppeteer');
const path = require('path');
const fs = require('fs');

const URL = 'http://localhost:8080';
const OUTPUT = path.resolve(__dirname, '../docs/dashboard.png');

(async () => {
  const docsDir = path.dirname(OUTPUT);
  if (!fs.existsSync(docsDir)) fs.mkdirSync(docsDir, { recursive: true });

  const browser = await puppeteer.launch({ args: ['--no-sandbox'] });
  const page = await browser.newPage();
  await page.setViewport({ width: 1560, height: 1000, deviceScaleFactor: 2 });
  await page.goto(URL, { waitUntil: 'networkidle2', timeout: 30000 });
  await page.screenshot({ path: OUTPUT });
  await browser.close();

  console.log(`Saved: ${OUTPUT}`);
})();
