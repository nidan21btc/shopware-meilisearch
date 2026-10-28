/* Check keyboard focus after pagination against the disposable local Shopware copy. */
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
            const next = page.locator('[data-el-page]').last();
            assert.equal(await next.isEnabled(), true, 'Second page must be available');
            const nextPage = await next.getAttribute('data-el-page');
            await next.focus();
            const response = page.waitForResponse(r => r.url().includes('/shopware-meilisearch?') && new URL(r.url()).searchParams.get('page') === nextPage);
            await page.keyboard.press('Enter');
            assert.equal((await response).status(), 200);
            await page.waitForFunction(() => document.querySelector('[data-el-results]')?.getAttribute('aria-busy') === 'false');
            assert.equal(await page.locator('[data-el-page]').last().evaluate(el => document.activeElement === el), true, 'Keyboard focus was lost after paging');
            // Jump to the last page through the same next-button path; this
            // also checks the focus fallback when the next button is disabled.
            const lastPage = Number((await page.locator('.el-search__pagination span').textContent()).split('/')[1].trim());
            assert.ok(lastPage > 2, 'Test query needs at least three pages');
            await page.locator('[data-el-page-direction="next"]').evaluate((el, value) => el.dataset.elPage = String(value), lastPage);
            const lastResponse = page.waitForResponse(r => r.url().includes('/shopware-meilisearch?') && new URL(r.url()).searchParams.get('page') === String(lastPage));
            await page.keyboard.press('Enter');
            assert.equal((await lastResponse).status(), 200);
            await page.waitForFunction(() => document.querySelector('[data-el-results]')?.getAttribute('aria-busy') === 'false');
            assert.equal(await page.locator('[data-el-page-direction="previous"]').evaluate(el => document.activeElement === el), true, 'Focus should move to the enabled previous button on the last page');
            const multiword = page.waitForResponse(r => r.url().includes('/shopware-meilisearch?') && new URL(r.url()).searchParams.get('q') === 'Test Blume');
            await page.locator('[data-el-query]').fill('Test Blume');
            assert.equal((await multiword).status(), 200);
            await page.waitForFunction(() => document.querySelector('[data-el-results]')?.getAttribute('aria-busy') === 'false');
            const similar = page.locator('[data-el-similar]');
            assert.equal(await similar.isVisible(), true, 'Similar-results control should be available');
            await similar.focus();
            const similarResponse = page.waitForResponse(r => r.url().includes('/shopware-meilisearch?') && new URL(r.url()).searchParams.get('similar') === '0');
            await page.keyboard.press('Enter');
            assert.equal((await similarResponse).status(), 200);
            await page.waitForFunction(() => document.querySelector('[data-el-results]')?.getAttribute('aria-busy') === 'false');
            if (await similar.count()) assert.equal(await similar.evaluate(el => document.activeElement === el), true, 'Keyboard focus was lost after changing similar results');
            else assert.equal(await page.locator('[data-el-query]').evaluate(el => document.activeElement === el), true, 'Focus should return to search when the control disappears');
            await page.close();
            console.log((mobile ? 'Mobile' : 'Desktop') + ' pagination focus passed against local Shopware.');
        }
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
