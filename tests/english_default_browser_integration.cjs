/* Verify the English similar-results default in the disposable local storefront. */
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const assert = require('node:assert/strict');
const origin = process.env.EL_SEARCH_TEST_URL || '';
const host = process.env.EL_SEARCH_TEST_HOST || '';
const target = new URL(origin);
if (target.protocol !== 'http:' || target.hostname !== '127.0.0.1' || !/^[a-zA-Z0-9.-]+$/.test(host)) throw new Error('Set a loopback test origin and virtual host');
(async () => {
    const browser = await chromium.launch({headless: true, args: ['--no-sandbox', '--disable-web-security']});
    try {
        const page = await browser.newPage({viewport: {width: 1440, height: 1000}});
        const requests = [];
        page.on('request', req => {if (req.url().includes('/shopware-meilisearch?')) requests.push(new URL(req.url()));});
        await page.route('**/*', route => {
            const url = new URL(route.request().url());
            if (url.hostname === host && url.protocol === 'http:') {
                url.hostname = target.hostname; url.port = target.port;
                return route.continue({url: url.href});
            }
            return url.origin === target.origin ? route.continue() : route.abort();
        });
        await page.goto(origin + '/en/', {waitUntil: 'domcontentloaded', timeout: 60000});
        await page.addStyleTag({content: '.sf-toolbar{display:none!important}'});
        const cookie = page.getByRole('button', {name: /Ablehnen|Reject all/i}).first();
        if (await cookie.isVisible().catch(() => false)) await cookie.click();
        await page.locator('.search-toggle-btn').filter({visible: true}).first().click();
        await page.waitForFunction(() => document.querySelector('[data-el-results]')?.getAttribute('aria-busy') === 'false');
        await page.locator('[data-el-query]').fill('rose');
        await page.waitForFunction(() => document.querySelector('[data-el-similar]') && document.querySelector('[data-el-results]')?.getAttribute('aria-busy') === 'false');
        assert.equal(requests.at(-1).searchParams.has('similar'), false);
        assert.equal(await page.locator('[data-el-similar]').getAttribute('aria-pressed'), 'true');
        await page.locator('[data-el-similar]').click();
        await page.waitForFunction(() => document.querySelector('[data-el-results]')?.getAttribute('aria-busy') === 'false');
        assert.equal(requests.at(-1).searchParams.get('similar'), '0');
        assert.equal(await page.locator('[data-el-similar]').getAttribute('aria-pressed'), 'false');
        const nextResponse = page.waitForResponse(r => r.url().includes('/shopware-meilisearch?') && new URL(r.url()).searchParams.get('q') === 'love');
        await page.locator('[data-el-query]').fill('love');
        await nextResponse;
        await page.waitForFunction(() => document.querySelector('[data-el-similar]') && document.querySelector('[data-el-results]')?.getAttribute('aria-busy') === 'false');
        assert.equal(requests.at(-1).searchParams.get('q'), 'love');
        assert.equal(requests.at(-1).searchParams.has('similar'), false);
        assert.equal(await page.locator('[data-el-similar]').getAttribute('aria-pressed'), 'true');
        console.log('English default and explicit choice passed against local Shopware.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
