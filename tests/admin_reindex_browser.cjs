/* Disposable loopback shop only; private synthetic account file as used by admin_reindex_integration.py. */
const {chromium}=require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fs=require('node:fs');
const assert=require('node:assert/strict');
const base=new URL(process.env.EL_TEST_SHOP_URL || 'http://127.0.0.1:8098');
if(base.protocol!=='http:' || !['127.0.0.1','[::1]'].includes(base.hostname))throw Error('Disposable loopback shop required');
const user=JSON.parse(fs.readFileSync(process.env.EL_TEST_ADMIN_CREDENTIALS,'utf8'))[0];
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox','--no-proxy-server']});
 try{
  const page=await browser.newPage();let accepted=0;
  page.on('response',r=>{if(r.url().endsWith('/api/_action/shopware-meilisearch/reindex')&&r.request().method()==='POST'&&r.status()===202)accepted++;});
  await page.route('**/*',r=>new URL(r.request().url()).origin===base.origin?r.continue():r.abort());
  await page.goto(base.origin+'/admin',{waitUntil:'domcontentloaded'});
  await page.locator('input[name="sw-field--username"]').fill(user.username);
  await page.locator('input[name="sw-field--password"]').fill(user.password);
  await page.locator('button[type="submit"]').click();
  await page.waitForFunction(()=>!!window.Shopware?.Context?.app?.config?.bundles,{}, {timeout:60000});
  await page.evaluate(()=>{location.hash='/sw/extension/config/ShopwareMeilisearch';});
  const button=page.getByRole('button',{name:'Suchindex jetzt neu aufbauen',exact:true});
  await button.waitFor({timeout:30000});
  await button.click();
  await page.getByText(/Neuaufbau vorgemerkt/).first().waitFor();
  assert.equal(accepted,1,'Button must queue exactly one authenticated background request');
  assert(await button.isDisabled(),'Pending rebuild must disable repeated clicks');
  assert(await page.getByRole('button',{name:'Status aktualisieren',exact:true}).isVisible());
  console.log('Backend browser checks passed: control visible, request accepted, duplicate clicks disabled.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e.message);process.exitCode=1;});
