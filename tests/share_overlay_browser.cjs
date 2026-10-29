/* Share links and desktop controls, without real customer data. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {chromium, firefox} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const script = fs.readFileSync(path.join(__dirname, '../src/Resources/public/storefront/js/search.js'), 'utf8');
const css = fs.readFileSync(path.join(__dirname, '../src/Resources/public/storefront/css/search.css'), 'utf8');
const id = '0123456789abcdef0123456789abcdef';
const html = `<!doctype html><style>${css}</style><button class="search-toggle-btn">Search</button><dialog class="el-search" data-el-search data-locale="de-DE" data-endpoint="/de/shopware-meilisearch" data-loading="Loading" data-error="Error"><header class="el-search__header"><form data-el-query-form class="el-search__searchbox"><input type="search" data-el-query><button type="button" class="el-search__clear" data-el-clear hidden>×</button></form><button data-el-close>Close</button></header><div><button data-el-share>Share</button><label data-el-share-fallback hidden>Copy <input data-el-share-link readonly></label><span data-el-share-status data-copied="Copied"></span></div><p data-el-status></p><div data-el-results></div><div data-el-network-error hidden><button data-el-retry>Retry</button></div></dialog><script type="module">${script}</script>`;
const response = p => `<form data-el-filters><label><input type="checkbox" name="category[]" value="${id}" ${p.getAll('category[]').includes(id)?'checked':''}>Category</label></form><select data-el-sort><option value="relevance">Relevance</option><option value="price-asc" ${p.get('sort')==='price-asc'?'selected':''}>Price</option></select><span data-el-result-status="OK"></span>`;
(async()=>{for(const [name,engine] of Object.entries({chromium,firefox})){
 const browser=await engine.launch({headless:true,args:name==='chromium'?['--no-sandbox']:[]});
 try {const context=await browser.newContext({viewport:{width:1280,height:800}}); const page=await context.newPage(); await context.addInitScript(() => Object.defineProperty(navigator, 'clipboard', {value: {writeText: async () => {throw new Error('blocked');}}, configurable: true})); const requests=[];
 await context.route('https://example.test/**',route=>{const u=new URL(route.request().url()); if(u.pathname.endsWith('/shopware-meilisearch')){requests.push(u);return route.fulfill({contentType:'text/html',body:response(u.searchParams)});}return route.fulfill({contentType:'text/html',body:html});});
 await page.goto('https://example.test/de/'); await page.locator('.search-toggle-btn').click(); const input=page.locator('[data-el-query]'); await Promise.all([page.waitForResponse(r=>new URL(r.url()).searchParams.get('q')==='Rescue'), input.fill('Rescue')]);
 await Promise.all([page.waitForResponse(r=>new URL(r.url()).searchParams.has('category[]')), page.locator('[data-el-filters] input').check()]); await Promise.all([page.waitForResponse(r=>new URL(r.url()).searchParams.get('sort')==='price-asc'), page.locator('[data-el-sort]').selectOption('price-asc')]);
 assert.equal(await page.locator('[data-el-clear]').isVisible(),true, 'Desktop clear button');
 await page.locator('[data-el-share]').click(); const fallback=page.locator('[data-el-share-link]'); await fallback.waitFor({state:'visible'}); const url=await fallback.inputValue(); assert.ok(url.includes('#el-search=')); assert.equal(new URL(url).search,'');
 const shared=await context.newPage(); await shared.goto(url); await shared.locator('dialog[open]').waitFor(); await shared.waitForTimeout(300); const req=requests.at(-1); assert.equal(req.searchParams.get('q'),'Rescue'); assert.equal(req.searchParams.get('category[]'),id); assert.equal(req.searchParams.get('sort'),'price-asc'); assert.equal(await shared.locator('[data-el-filters] input').isChecked(),true); assert.equal(await shared.locator('[data-el-sort]').inputValue(),'price-asc');
 await page.locator('[data-el-close]').click(); await page.reload(); await page.locator('.search-toggle-btn').click(); assert.equal(await input.inputValue(),'Rescue'); const selected=await input.evaluate(e=>[e.selectionStart,e.selectionEnd,e.value.length]); assert.deepEqual(selected,[0,6,6]); await page.keyboard.type('New'); assert.equal(await input.inputValue(),'New'); await page.setViewportSize({width:390,height:844}); assert.equal(await page.locator('[data-el-clear]').isVisible(),true, 'Mobile clear button'); await page.locator('[data-el-clear]').click(); assert.equal(await input.inputValue(),'');
 await context.close();
 const copyContext=await browser.newContext({viewport:{width:1280,height:800}});
 await copyContext.addInitScript(() => Object.defineProperty(navigator, 'clipboard', {value: {writeText: async text => {window.copiedLink=text;}}, configurable: true}));
 await copyContext.route('https://example.test/**',route=>{const u=new URL(route.request().url()); return route.fulfill({contentType:'text/html',body:u.pathname.endsWith('/shopware-meilisearch')?response(u.searchParams):html});});
 const copyPage=await copyContext.newPage(); await copyPage.goto('https://example.test/de/'); await copyPage.locator('.search-toggle-btn').click(); await copyPage.locator('[data-el-query]').fill('Öl'); await copyPage.locator('[data-el-share]').click();
 assert.equal(await copyPage.locator('[data-el-share-status]').innerText(),'Copied'); assert.ok((await copyPage.evaluate(()=>window.copiedLink)).includes('#el-search=')); await copyContext.close();
 console.log(name+': share, filters, sort, reload selection, clipboard and desktop/mobile clear passed');
 } finally {await browser.close();}
}})().catch(e=>{console.error(e);process.exitCode=1});
