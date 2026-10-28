/* Session-only, language-local search recovery after product navigation. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const script = fs.readFileSync(path.join(__dirname, '../src/Resources/public/storefront/js/search.js'), 'utf8');
function pageHtml(lang) { return `<!doctype html><button class="search-toggle-btn">Search</button>
<dialog data-el-search data-locale="${lang}" data-endpoint="/${lang}/shopware-meilisearch" data-loading="Loading" data-error="Error">
<form data-el-query-form><input type="search" data-el-query><button type="button" data-el-clear hidden>Clear</button></form><button data-el-close>Close</button>
<p data-el-status></p><div data-el-results></div><div data-el-network-error hidden><button data-el-retry>Retry</button></div></dialog>
<script type="module">${script}</script>`; }
(async () => {
 const browser = await chromium.launch({headless:true,args:['--no-sandbox']});
 try {
  const page = await browser.newPage();
  await page.route('https://example.test/**', route => {
   const u = new URL(route.request().url());
   if (u.pathname.endsWith('/shopware-meilisearch')) return route.fulfill({contentType:'text/html',body:`<section data-el-result-status="OK"><a href="/${u.pathname.split('/')[1]}/product">Product</a></section>`});
   const lang = u.pathname.split('/')[1] || 'de-DE';
   return route.fulfill({contentType:'text/html',body:pageHtml(lang)});
  });
  await page.goto('https://example.test/de-DE/');
  await page.locator('.search-toggle-btn').click();
  await page.locator('[data-el-query]').fill('Lavendel');
  await page.waitForResponse(r=>new URL(r.url()).searchParams.get('q')==='Lavendel');
  await page.locator('[data-el-results] a').click();
  await page.locator('.search-toggle-btn').click();
  assert.equal(await page.locator('[data-el-query]').inputValue(),'Lavendel');
  await page.waitForResponse(r=>new URL(r.url()).searchParams.get('q')==='Lavendel');
  assert.equal(await page.locator('[data-el-clear]').isVisible(),true);
  await page.goto('https://example.test/en-GB/');
  await page.locator('.search-toggle-btn').click();
  assert.equal(await page.locator('[data-el-query]').inputValue(),'');
  await page.locator('[data-el-query]').fill('lavender');
  await page.goto('https://example.test/de-DE/product');
  await page.locator('.search-toggle-btn').click();
  assert.equal(await page.locator('[data-el-query]').inputValue(),'Lavendel');
  await page.locator('[data-el-clear]').click();
  await page.reload();
  await page.locator('.search-toggle-btn').click();
  assert.equal(await page.locator('[data-el-query]').inputValue(),'');
  await page.goto('https://example.test/en-GB/product');
  await page.locator('.search-toggle-btn').click();
  assert.equal(await page.locator('[data-el-query]').inputValue(),'lavender');
  const isolated = await browser.newContext(); const other = await isolated.newPage();
  await other.route('https://example.test/**', route => route.fulfill({contentType:'text/html',body:pageHtml('de-DE')}));
  await other.goto('https://example.test/de-DE/');
  await other.locator('.search-toggle-btn').click();
  assert.equal(await other.locator('[data-el-query]').inputValue(),'');
  await isolated.close();
  console.log('Search survives product navigation, remains language-local and can be cleared; separate sessions stay empty.');
 } finally { await browser.close(); }
})().catch(e=>{console.error(e.message);process.exitCode=1});
