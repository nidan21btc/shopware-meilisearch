/* A delayed cart-plugin initialization must not alter a newer result view. */
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const script = fs.readFileSync(path.join(__dirname, '../src/Resources/public/storefront/js/search.js'), 'utf8');
const fixture = `<!doctype html><html><head><style>[data-el-results]{height:200px;overflow:auto}.spacer{height:1200px}</style></head><body>
<button class="search-toggle-btn">Search</button><dialog data-el-search data-endpoint="/search" data-loading="Loading" data-error="Error"><button data-el-close>Close</button><form data-el-query-form><input data-el-query><button data-el-clear hidden>Clear</button></form><p data-el-status></p><div data-el-results></div><div hidden data-el-network-error><button data-el-retry>Retry</button></div></dialog>
<script>window.PluginManager={initializePlugin:async()=>{if(document.querySelector('.query')?.textContent==='first')await new Promise(resolve=>{window.releaseFirst=resolve;});}};</script></body></html>`;
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
 try{
  const page=await browser.newPage();
  await page.route('https://search.test/**',async route=>{
   const url=new URL(route.request().url());
   if(url.pathname==='/')return route.fulfill({contentType:'text/html',body:fixture});
   const q=url.searchParams.get('q')||'';
   return route.fulfill({contentType:'text/html',body:`<section data-el-result-status="ok"><span class="query">${q}</span><div class="spacer"></div><form data-add-to-cart><button>Add</button></form></section>`});
  });
  await page.goto('https://search.test/');await page.addScriptTag({type:'module',content:script});await page.locator('.search-toggle-btn').click();
  await page.waitForFunction(()=>document.querySelector('[data-el-results]').getAttribute('aria-busy')==='false');
  await page.locator('[data-el-query]').fill('first');await page.waitForFunction(()=>typeof window.releaseFirst==='function');
  await page.locator('[data-el-query]').fill('second');await page.waitForFunction(()=>document.querySelector('.query')?.textContent==='second'&&document.querySelector('[data-el-results]').getAttribute('aria-busy')==='false');
  await page.locator('[data-el-results]').evaluate(el=>{el.scrollTop=150;});
  assert.equal(await page.locator('[data-el-results]').evaluate(el=>el.scrollTop),150);
  await page.evaluate(()=>window.releaseFirst());await page.waitForTimeout(50);
  assert.equal(await page.locator('[data-el-results]').evaluate(el=>el.scrollTop),150,'A previous initialization changed the newer result view');
  console.log('Delayed cart initialization leaves newer results and scroll position unchanged.');
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
