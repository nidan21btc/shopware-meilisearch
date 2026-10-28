/* Cart icon geometry in an isolated Shopware storefront with its active theme. */
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const assert = require('node:assert/strict');
const origin = new URL(process.env.EL_SEARCH_TEST_URL || '');
const host = process.env.EL_SEARCH_TEST_HOST || '';
const query = process.env.EL_TEST_QUERY || '';
if (origin.protocol !== 'http:' || origin.hostname !== '127.0.0.1' || !/^[a-zA-Z0-9.-]+$/.test(host)) throw new Error('A loopback test origin and virtual host are required.');
if (!query) throw new Error('Set EL_TEST_QUERY to a term with purchasable products in the isolated shop.');
(async () => {
 const browser = await chromium.launch({headless:true, args:['--no-sandbox', `--host-resolver-rules=MAP ${host} 127.0.0.1`]});
 try {
  for (const width of [390, 1200]) {
   const page = await browser.newPage({viewport:{width,height:900}});
   await page.route('**/*', route => { const u = new URL(route.request().url()); return u.hostname === host && u.protocol === 'http:' && u.port === origin.port ? route.continue() : route.abort(); });
   await page.goto(`http://${host}:${origin.port}/de/`, {waitUntil:'domcontentloaded'});
   const cookie = page.getByRole('button', {name:/Ablehnen|Reject all/i}).first(); if (await cookie.isVisible().catch(()=>false)) await cookie.click();
   const toggle = page.locator('.search-toggle-btn').filter({visible:true}).first(); if (await toggle.isVisible().catch(()=>false)) await toggle.click(); else await page.locator('.header-search-input').filter({visible:true}).first().focus();
   await page.locator('[data-el-query]').fill(query);
   const button = page.locator('[data-el-results] button.el-search__cart').first(); await button.waitFor({state:'visible',timeout:30000});
   const geometry = await button.evaluate(button => {const icon=button.querySelector('svg'); if(!icon)return null;const b=button.getBoundingClientRect(),i=icon.getBoundingClientRect();return {width:i.width,height:i.height,left:i.left-b.left,right:b.right-i.right,top:i.top-b.top,bottom:b.bottom-i.bottom};});
   console.log('Width',width,'geometry',geometry);
   assert(geometry && geometry.width>0 && geometry.height>0,'Expected a visible Shopware cart icon');
   assert(geometry.left>=-1 && geometry.right>=-1 && geometry.top>=-1 && geometry.bottom>=-1,'Icon must remain inside its button');
   assert(Math.abs(geometry.left-geometry.right)<=2 && Math.abs(geometry.top-geometry.bottom)<=2,'Icon must be centered');
   await page.close();
  }
  console.log('Shopware cart icon is visible, centered and within the button at mobile and desktop widths.');
 } finally { await browser.close(); }
})().catch(error=>{console.error(error.message);process.exitCode=1});
