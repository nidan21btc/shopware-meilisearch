/* Synthetic Vue + browser regression; no shop, account or external requests. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const vue = process.env.VUE_BROWSER_FILE || require.resolve('vue/dist/vue.global.prod.js');
const root = path.join(__dirname, '..');
const js = fs.readFileSync(path.join(root, 'src/Resources/public/administration/js/shopware-meilisearch.js'), 'utf8');
const source = fs.readFileSync(path.join(root, 'src/Resources/app/administration/src/main.js'), 'utf8');
const css = fs.readFileSync(path.join(root, 'src/Resources/public/administration/css/reindex.css'), 'utf8');
assert.equal(js, source, 'Source and public administration assets must match');
const manifest = JSON.parse(fs.readFileSync(path.join(root, 'src/Resources/public/administration/.vite/entrypoints.json')));
assert(manifest.entryPoints['shopware-meilisearch'].css.some(p => p.split('?')[0] === '/bundles/shopwaremeilisearch/administration/css/reindex.css'));
(async () => {
 const browser = await chromium.launch({headless: true, args: ['--no-sandbox']});
 try {
  const page = await browser.newPage({viewport: {width: 1000, height: 900}});
  await page.setContent('<style>body{font:14px Arial,sans-serif;background:#f4f5f7;padding:24px}.fixture{background:white;padding:24px;max-width:850px;border:1px solid #ddd}button{font:inherit;padding:10px 16px;border:1px solid #b7c4d1;background:white;border-radius:4px}button.primary{background:#189eff;color:white;border-color:#189eff}button:disabled{opacity:.5}.banner{padding:14px;background:#e8f3ff;border:1px solid #c6def8;border-radius:4px}</style><div class="fixture"><div id="app"></div></div>');
  await page.addStyleTag({content: css});
  await page.addScriptTag({path: vue});
  await page.evaluate(() => {
   window.fixture={pending:false, posts:0, allow:true};
   window.Shopware={Component:{override(){},register(name, config){if(name === 'sw-meilisearch-reindex') window.fixture.component=config;}},Mixin:{getByName(){return {methods:{createNotificationError(){},createNotificationSuccess(){}}};}},Application:{getContainer(){return {httpClient:{get:async()=>({data:{pending:window.fixture.pending,indexedAt:'2026-10-28 14:45:37.685'}}),post:async()=>{window.fixture.posts++;window.fixture.pending=true;return {data:{pending:true,indexedAt:'2026-10-28 14:45:37.685'}};}}};}}};
  });
  await page.addScriptTag({content: js});
  await page.evaluate(() => {
   const app=Vue.createApp(window.fixture.component);
   app.provide('loginService',{getToken:()=> 'synthetic'});
   app.provide('acl',{can:()=>window.fixture.allow});
   app.component('sw-button-process',{props:['disabled','isLoading'],template:'<button class="primary" :disabled="disabled" @click="$emit(\'click\')"><slot/></button>',emits:['click']});
   app.component('sw-button',{props:['disabled'],template:'<button :disabled="disabled" @click="$emit(\'click\')"><slot/></button>',emits:['click']});
   app.component('mt-banner',{template:'<div class="banner"><slot/></div>'});
   app.mount('#app');
  });
  const rebuild=page.getByRole('button',{name:'Suchindex jetzt neu aufbauen'});
  await rebuild.waitFor();
  await page.getByText('28.10.2026, 14:45 UTC').waitFor();
  assert(await rebuild.isEnabled());
  const before=await page.locator('.sw-meilisearch-reindex__actions').evaluate(e=>getComputedStyle(e).flexDirection);
  assert.equal(before,'row');
  if (process.env.UI_SCREENSHOT_DIR) await page.screenshot({path:path.join(process.env.UI_SCREENSHOT_DIR,'admin-index-desktop.png'),fullPage:true});
  await rebuild.click();
  await page.getByText('Neuaufbau vorgemerkt').waitFor();
  assert(await rebuild.isDisabled());
  assert.equal(await page.evaluate(()=>window.fixture.posts),1);
  await page.getByRole('button',{name:'Status aktualisieren'}).click();
  assert(await rebuild.isDisabled());
  await page.setViewportSize({width:375,height:900});
  assert.equal(await page.locator('.sw-meilisearch-reindex__actions').evaluate(e=>getComputedStyle(e).flexDirection),'column');
  const overflow=await page.evaluate(()=>document.documentElement.scrollWidth>window.innerWidth);
  assert.equal(overflow,false,'Mobile UI must not overflow horizontally');
  if (process.env.UI_SCREENSHOT_DIR) await page.screenshot({path:path.join(process.env.UI_SCREENSHOT_DIR,'admin-index-mobile.png'),fullPage:true});
  console.log('Backend UI: desktop/mobile layout, timestamp, pending state and rebuild/refresh behavior passed.');
 } finally { await browser.close(); }
})().catch(e=>{console.error(e);process.exitCode=1;});
