/* Read-only check: block external traffic and exercise local search in five locales. */
const {chromium, devices} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const assert = require('node:assert/strict');
const origin = process.env.EL_SEARCH_TEST_URL || '';
const virtualHost = process.env.EL_SEARCH_TEST_HOST || '';
const isolateRecaptcha = process.env.EL_SEARCH_TEST_ISOLATE_RECAPTCHA === '1';
const target = new URL(origin);
if (target.protocol !== 'http:' || target.hostname !== '127.0.0.1' || !/^[a-zA-Z0-9.-]+$/.test(virtualHost)) {
    throw new Error('Set EL_SEARCH_TEST_URL to an HTTP loopback origin and EL_SEARCH_TEST_HOST to its virtual host');
}
(async () => {
    const browser = await chromium.launch({headless: true, args: ['--no-sandbox', '--disable-web-security', `--host-resolver-rules=MAP ${virtualHost} 127.0.0.1`]});
    try {
        for (const locale of ['de', 'en', 'es', 'fr', 'it']) {
            const context = await browser.newContext({...devices['iPhone 13']});
            const page = await context.newPage();
            const doofinder = [], externalDuringSearch = [];
            let searchStarted = false;
            await page.route('**/*', route => {
                const url = new URL(route.request().url());
                if (isolateRecaptcha && url.hostname === virtualHost && url.protocol === 'http:' && url.port === target.port
                    && /\/storefront\.google-re-captcha-v3\.plugin\.[^/]+\.js$/.test(url.pathname)) {
                    return route.fulfill({status: 200, contentType: 'application/javascript', body: ''});
                }
                if (url.hostname === virtualHost && url.protocol === 'http:' && url.port === target.port) return route.continue();
                if (url.origin === target.origin) return route.continue();
                if (['doofinder.com', 'doofinder.net'].some(domain => url.hostname === domain || url.hostname.endsWith('.' + domain))) doofinder.push(url.hostname);
                if (searchStarted) externalDuringSearch.push(url.hostname);
                return route.abort();
            });
            await page.goto(`http://${virtualHost}${target.port ? ':' + target.port : ''}/${locale}/`, {waitUntil: 'domcontentloaded', timeout: 60000});
            const cookie = page.getByRole('button', {name: /Ablehnen|Reject all|Rechazar|Refuser|Rifiuta/i}).first();
            if (await cookie.isVisible().catch(() => false)) await cookie.click();
            searchStarted = true;
            await page.locator('.search-toggle-btn').filter({visible: true}).first().click();
            const searchResponse = page.waitForResponse(response => response.url().includes('/shopware-meilisearch?') && new URL(response.url()).searchParams.get('q') === 'bach');
            await page.locator('[data-el-query]').fill('bach');
            assert.equal((await searchResponse).status(), 200);
            await page.waitForFunction(() => document.querySelector('[data-el-results]')?.getAttribute('aria-busy') === 'false');
            await page.locator('[data-el-show-all]').click();
            await page.locator('[data-el-filter-toggle]').click();
            const facet = page.locator('details[data-el-facet]').filter({has: page.locator('input[type=checkbox]')}).first();
            if (!(await facet.evaluate(el => el.open))) await facet.locator('summary').click();
            const checkbox = facet.locator('input[type=checkbox]').first();
            const name = await checkbox.getAttribute('name'), value = await checkbox.getAttribute('value');
            const filterResponse = page.waitForResponse(response => response.url().includes('/shopware-meilisearch?') && new URL(response.url()).searchParams.getAll(name).includes(value));
            await checkbox.check();
            assert.equal((await filterResponse).status(), 200);
            await page.waitForFunction(() => document.querySelector('[data-el-results]')?.getAttribute('aria-busy') === 'false');
            await page.waitForTimeout(1000);
            assert.deepEqual(doofinder, [], 'The page attempted to contact Doofinder; disable the old app for this test');
            assert.deepEqual(externalDuringSearch, [], 'An external request was attempted while the local search was in use');
            await context.close();
            console.log(locale + ': local search and filter completed without observed external requests.' + (isolateRecaptcha ? ' Shopware reCAPTCHA script isolated in browser.' : ''));
        }
    } finally { await browser.close(); }
})().catch(error => { console.error(error.message); process.exitCode = 1; });
