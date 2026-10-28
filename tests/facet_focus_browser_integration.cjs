/* Check keyboard focus and facet state against the disposable local Shopware copy. */
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
            await page.route('**/*', route => {
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
            const response = page.waitForResponse(r => r.url().includes('/shopware-meilisearch?') && new URL(r.url()).searchParams.getAll(name).includes(value));
            await checkbox.focus();
            await page.keyboard.press('Space');
            assert.equal((await response).status(), 200);
            await page.waitForFunction(() => document.querySelector('[data-el-results]')?.getAttribute('aria-busy') === 'false');
            const updatedFacet = page.locator('details[data-el-facet]').filter({has: page.locator(`input[name="${name}"][value="${value}"]`)});
            assert.equal(await updatedFacet.getAttribute('data-el-facet'), key);
            assert.equal(await updatedFacet.evaluate(el => el.open), true, 'Facet closed after selecting a value');
            const selected = updatedFacet.locator(`input[name="${name}"][value="${value}"]`);
            assert.equal(await selected.isChecked(), true, 'Selected value disappeared');
            assert.equal(await selected.evaluate(el => document.activeElement === el), true, 'Keyboard focus was lost');
            if (mobile) assert.equal(await page.locator('[data-el-filter-toggle]').getAttribute('aria-expanded'), 'true');
            const reset = page.locator('[data-el-reset]');
            await reset.focus();
            const resetResponse = page.waitForResponse(r => r.url().includes('/shopware-meilisearch?') && new URL(r.url()).searchParams.get('q') === 'bach' && !new URL(r.url()).searchParams.has(name));
            await page.keyboard.press('Enter');
            assert.equal((await resetResponse).status(), 200);
            await page.waitForFunction(() => document.querySelector('[data-el-results]')?.getAttribute('aria-busy') === 'false');
            assert.equal(await page.locator('[data-el-reset]').evaluate(el => document.activeElement === el), true, 'Reset should keep keyboard focus');
            const multiwordResponse = page.waitForResponse(r => r.url().includes('/shopware-meilisearch?') && new URL(r.url()).searchParams.get('q') === 'Test Blume');
            await page.locator('[data-el-query]').fill('Test Blume');
            await multiwordResponse;
            await page.waitForFunction(() => document.querySelector('[data-el-facet-counts-may-change]') && document.querySelector('[data-el-results]')?.getAttribute('aria-busy') === 'false');
            if (mobile) await page.locator('[data-el-filter-toggle]').click();
            assert.equal(await page.locator('[data-el-facet-counts-may-change]').isVisible(), true, 'Changing-count notice is hidden');
            const marked = page.locator('details[data-el-facet] input[type=checkbox]').first();
            assert.equal(await marked.getAttribute('aria-describedby'), 'el-search-facet-counts-note');
            assert.match(await marked.locator('xpath=../span[contains(@class,"el-search__facet-count")]').textContent(), /\*$/);
            await page.close();
            console.log((mobile ? 'Mobile' : 'Desktop') + ' facet focus passed against local Shopware.');
        }
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
