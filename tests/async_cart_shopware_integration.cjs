/* Verify a delayed cart initialization against an isolated local Shopware copy. */
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const assert = require('node:assert/strict');
const origin=process.env.EL_SEARCH_TEST_URL||'';
const host=process.env.EL_SEARCH_TEST_HOST||'';
const target=new URL(origin);
if(target.protocol!=='http:'||target.hostname!=='127.0.0.1'||!/^[a-zA-Z0-9.-]+$/.test(host))throw new Error('Set a loopback test origin and virtual host');
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox','--disable-web-security']});
 try{
  const page=await browser.newPage({viewport:{width:1440,height:900}});
  await page.route('**/*',route=>{
   const url=new URL(route.request().url());
   if(url.hostname===host&&url.protocol==='http:'){url.hostname=target.hostname;url.port=target.port;return route.continue({url:url.href});}
   return url.origin===target.origin?route.continue():route.abort();
  });
  await page.goto(origin+'/de/',{waitUntil:'domcontentloaded',timeout:60000});
  await page.addStyleTag({content:'.sf-toolbar{display:none!important}'});
  const cookie=page.getByRole('button',{name:/Ablehnen|Reject all/i}).first();if(await cookie.isVisible().catch(()=>false))await cookie.click();
  const toggle=page.locator('.search-toggle-btn').filter({visible:true}).first();
  if(await toggle.isVisible().catch(()=>false))await toggle.click();
  else await page.locator('.header-search-input').filter({visible:true}).first().focus();
  await page.waitForFunction(()=>document.querySelector('[data-el-results]')?.getAttribute('aria-busy')==='false');
  await page.evaluate(()=>{
   const manager=window.PluginManager,original=manager.initializePlugin.bind(manager);
   manager.initializePlugin=(name,selector)=>{
    if(name==='AddToCart'&&document.querySelector('[data-el-query]')?.value==='bach')return new Promise(resolve=>{window.releaseSearchCart=resolve;});
    return original(name,selector);
   };
  });
  const query=page.locator('[data-el-query]');await query.fill('bach');await page.waitForFunction(()=>typeof window.releaseSearchCart==='function');
  await query.fill('rescue');await page.waitForFunction(()=>document.querySelector('[data-el-results]')?.getAttribute('aria-busy')==='false'&&document.querySelectorAll('[data-el-results] article[data-product-id]').length>0);
  const position=await page.locator('[data-el-results]').evaluate(el=>{el.scrollTop=150;return el.scrollTop;});
  if(position<100)throw new Error('The test needs a scrollable result view.');
  await page.evaluate(()=>window.releaseSearchCart());await page.waitForTimeout(100);
  assert.equal(await page.locator('[data-el-results]').evaluate(el=>el.scrollTop),position,'An old cart initialization moved the newer Shopware results');
  console.log('Delayed cart initialization preserves the newer local Shopware result view.');
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
