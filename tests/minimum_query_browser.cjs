/* Synthetic transport checks: no merchant requests, delayed responses exercise cancellation. */
const {chromium,firefox}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const fs=require('fs'),path=require('path'),assert=require('node:assert/strict');
const script=fs.readFileSync(path.join(__dirname,'../src/Resources/public/storefront/js/search.js'),'utf8');
(async()=>{for(const [name,engine] of Object.entries({chromium,firefox})){
 const browser=await engine.launch({headless:true,args:name==='chromium'?['--no-sandbox']:[]});
 try{for(const min of [1,2,3,150])for(const mobile of [false,true]){
  const ctx=await browser.newContext({viewport:mobile?{width:390,height:844}:{width:1280,height:800}});const p=await ctx.newPage(),requests=[],errors=[];p.on('pageerror',e=>errors.push(e.message));
  const hint=`Please enter at least ${min} characters.`;
  const html=`<!doctype html><button class="search-toggle-btn">Search</button><dialog data-el-search data-endpoint="/de/shopware-meilisearch" data-minimum-query-length="${min}" data-minimum-query-hint="${hint}" data-loading="Loading" data-error="Unavailable"><button data-el-close>Close</button><form data-el-query-form><input data-el-query><button type="button" data-el-clear>Clear</button><button type="submit">Go</button></form><button data-el-share>Share</button><p data-el-status role="status"></p><div data-el-results></div><div data-el-network-error hidden><button data-el-retry>Retry</button></div></dialog><script type="module">${script}</script>`;
  await ctx.route('https://minimum.test/**',async r=>{const u=new URL(r.request().url());if(!u.pathname.endsWith('/shopware-meilisearch'))return r.fulfill({contentType:'text/html',body:html});const q=u.searchParams.get('q');requests.push(q);if(q==='Delayed')await new Promise(resolve=>setTimeout(resolve,700));await r.fulfill({contentType:'text/html',body:`<p data-el-result-status="Results">${q||'Discovery'}</p>`}).catch(()=>{});});
  async function idle(){await p.waitForFunction(()=>document.querySelector('[data-el-results]').getAttribute('aria-busy')==='false')}
  await p.goto('https://minimum.test/de/');await p.locator('.search-toggle-btn').click();await idle();assert.deepEqual(requests,['']);
  const input=p.locator('[data-el-query]');const valid='a'.repeat(min),short='a'.repeat(min-1);
  if(short){const n=requests.length;
   await p.clock.install({time:new Date('2030-01-01T00:00:00Z')});await p.clock.pauseAt(new Date('2030-01-01T00:00:01Z'));
   await input.fill(short);assert.notEqual(await p.locator('[data-el-status]').innerText(),hint,'no immediate hint');
   await p.clock.runFor(150);await input.fill(short.length>1?short.slice(0,-1):'b');
   await p.clock.runFor(299);assert.notEqual(await p.locator('[data-el-status]').innerText(),hint,'typing restarts the hint delay');
   await p.clock.runFor(1);assert.equal(await p.locator('[data-el-status]').innerText(),hint,'hint appears after 300 ms without input');
   await p.clock.resume();assert.equal(requests.length,n);assert.equal(await p.locator('[data-el-status]').innerText(),hint);assert(await p.locator('[data-el-share]').isDisabled());await input.press('Enter');await p.waitForTimeout(50);assert.equal(requests.length,n);assert.equal(await p.locator('[data-el-network-error]').isVisible(),false)}
  await input.fill(valid);await p.waitForTimeout(400);await idle();assert.equal(requests.at(-1),valid);assert(!await p.locator('[data-el-share]').isDisabled());
  if(min===2){for(const q of ['Ö','O\u0308','😀']){const n=requests.length;await input.fill(q);await p.waitForTimeout(350);assert.equal(requests.length,n,'one Unicode codepoint after NFC must not search')}
   await input.fill('Öl');await p.waitForTimeout(400);await idle();assert.equal(requests.at(-1),'Öl');await input.fill('Delayed');await p.waitForTimeout(340);await input.fill('x');await p.waitForTimeout(800);assert.equal(await p.locator('[data-el-results]').innerText(),hint);assert.equal(await p.locator('[data-el-results]').getAttribute('aria-busy'),'false');
  }
  await p.locator('[data-el-clear]').click();await p.waitForTimeout(50);await idle();assert.equal(requests.at(-1),'');assert.equal(await p.locator('[data-el-results]').innerText(),'Discovery');
  if(min>1){await input.fill(short);await p.locator('[data-el-close]').click();await p.waitForTimeout(20);await p.reload();const n=requests.length;await p.locator('.search-toggle-btn').click();await p.waitForTimeout(350);assert.equal(requests.length,n,'restored short query');assert.equal(await p.locator('[data-el-status]').innerText(),hint);
   const shared=await ctx.newPage();const n2=requests.length;await shared.goto('https://minimum.test/de/#el-search='+encodeURIComponent(new URLSearchParams({q:short}).toString()));await shared.locator('dialog[open]').waitFor();await shared.waitForTimeout(350);assert.equal(requests.length,n2,'shared short query');assert.equal(await shared.locator('[data-el-status]').innerText(),hint);
  }
  assert.deepEqual(errors,[]);await ctx.close();
 }
 console.log(name+': minimum 1/2/3/150, desktop/mobile, boundary, debounced hints, Enter, clear, share, reload, Unicode and delayed-response checks passed');
 }finally{await browser.close()}
}})().catch(e=>{console.error(e);process.exitCode=1});
