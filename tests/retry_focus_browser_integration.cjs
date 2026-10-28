/* Check keyboard recovery from a local search outage. */
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const assert = require('node:assert/strict');
const origin = process.env.EL_SEARCH_TEST_URL || '';
const host = process.env.EL_SEARCH_TEST_HOST || '';
const target = new URL(origin);
if (target.protocol !== 'http:' || target.hostname !== '127.0.0.1' || !/^[a-zA-Z0-9.-]+$/.test(host)) throw new Error('Set a loopback test origin and virtual host');
(async () => {
    const browser = await chromium.launch({headless: true, args: ['--no-sandbox', '--disable-web-security']});
    try {
        for (const mobile of [false, true]) {
            const page = await browser.newPage({viewport: mobile ? {width: 390, height: 844} : {width: 1440, height: 1000}});
            let failure = '';
            await page.route('**/*', route => {
                if (new URL(route.request().url()).pathname.endsWith('/shopware-meilisearch') && failure) {
                    if (failure === 'network') return route.abort();
                    return route.fulfill({status: 503, contentType: 'text/html', body: '<div data-el-result-status="unavailable"><button type="button" data-el-retry>Retry</button></div>'});
                }
                const url = new URL(route.request().url());
                if (url.hostname === host && url.protocol === 'http:') {
                    url.hostname = target.hostname;
                    url.port = target.port;
                    return route.continue({url: url.href});
                }
                return url.origin === target.origin ? route.continue() : route.abort();
            });
            await page.goto(origin + '/de/', {waitUntil: 'domcontentloaded', timeout: 60000});
            await page.addStyleTag({content: '.sf-toolbar{display:none!important}'});
            const cookie = page.getByRole('button', {name: /Ablehnen|Reject all/i}).first();
            if (await cookie.isVisible().catch(() => false)) await cookie.click();
            await page.locator('.search-toggle-btn').filter({visible: true}).first().click();
            await page.waitForFunction(() => document.querySelector('[data-el-results]')?.getAttribute('aria-busy') === 'false');
            await page.locator('[data-el-query]').fill('bach');
            await page.waitForFunction(() => document.querySelector('details[data-el-facet] input[type=checkbox]') && document.querySelector('[data-el-results]')?.getAttribute('aria-busy') === 'false');
            if (mobile) {
                assert.equal(await page.locator('[data-el-show-all]').isVisible(), true, 'Mobile search should start in preview');
                assert.equal(await page.locator('.el-search__grid .el-search__card:visible').count(), 10);
                await page.locator('[data-el-show-all]').click();
                assert.equal(await page.locator('[data-el-filter-toggle]').isVisible(), true);
                await page.locator('[data-el-filter-toggle]').click();
            }
            const facet = page.locator('details[data-el-facet]').filter({has: page.locator('input[type=checkbox]')}).first();
            const key = await facet.getAttribute('data-el-facet');
            if (!(await facet.evaluate(el => el.open))) await facet.locator('summary').click();
            const checkbox = facet.locator('input[type=checkbox]').first();
            const name = await checkbox.getAttribute('name');
            const value = await checkbox.getAttribute('value');
            await checkbox.focus();
            failure = '503';
            await page.keyboard.press('Space');
            const retry = page.locator('[data-el-results] [data-el-retry]');
            await retry.waitFor({state: 'visible'});
            assert.equal(await retry.evaluate(el => document.activeElement === el), true, '503 response should focus retry');
            failure = '';
            const recovered = page.waitForResponse(r => r.url().includes('/shopware-meilisearch?') && new URL(r.url()).searchParams.getAll(name).includes(value));
            await page.keyboard.press('Enter');
            assert.equal((await recovered).status(), 200);
            await page.waitForFunction(() => document.querySelector('[data-el-results]')?.getAttribute('aria-busy') === 'false');
            assert.equal(await page.locator('[data-el-query]').evaluate(el => document.activeElement === el), true, 'Successful retry should restore usable focus');
            const selected = page.locator(`input[name="${name}"][value="${value}"]`);
            assert.equal(await selected.isChecked(), true, 'Retry lost selected filter');
            const selectedFacet = selected.locator('xpath=ancestor::details');
            if (!(await selectedFacet.evaluate(el => el.open))) await selectedFacet.locator('summary').click();
            await selected.focus();
            failure = 'network';
            await page.keyboard.press('Space');
            const networkRetry = page.locator('[data-el-network-error] [data-el-retry]');
            await networkRetry.waitFor({state: 'visible'});
            assert.equal(await networkRetry.evaluate(el => document.activeElement === el), true, 'Network failure should focus retry');
            failure = '';
            const recoveredNetwork = page.waitForResponse(r => r.url().includes('/shopware-meilisearch?') && !new URL(r.url()).searchParams.has(name));
            await page.keyboard.press('Enter');
            assert.equal((await recoveredNetwork).status(), 200);
            await page.waitForFunction(() => document.querySelector('[data-el-results]')?.getAttribute('aria-busy') === 'false');
            assert.equal(await page.locator('[data-el-query]').evaluate(el => document.activeElement === el), true, 'Network retry should restore usable focus');
            failure = '503';
            await page.locator('[data-el-query]').fill('testabfrage');
            await page.locator('[data-el-results] [data-el-retry]').waitFor({state: 'visible'});
            assert.equal(await page.locator('[data-el-query]').evaluate(el => document.activeElement === el), true, 'Error must not interrupt typing');
            failure = 'network';
            await page.locator('[data-el-query]').fill('andereabfrage');
            await page.locator('[data-el-network-error] [data-el-retry]').waitFor({state: 'visible'});
            assert.equal(await page.locator('[data-el-query]').evaluate(el => document.activeElement === el), true, 'Network error must not interrupt typing');
            await page.close();
            console.log((mobile ? 'Mobile' : 'Desktop') + ' error recovery focus passed against local Shopware.');
        }
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
