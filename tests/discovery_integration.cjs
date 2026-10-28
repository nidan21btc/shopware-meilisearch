/* Local Shopware check for desktop discovery and mobile search suggestions. */
const {chromium, devices} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const assert = require('node:assert/strict');
const origin = process.env.EL_SEARCH_TEST_URL || '';
const host = process.env.EL_SEARCH_TEST_HOST || '';
const target = new URL(origin);
if (target.protocol !== 'http:' || target.hostname !== '127.0.0.1' || !/^[a-zA-Z0-9.-]+$/.test(host)) {
    throw new Error('Set a loopback EL_SEARCH_TEST_URL and a test virtual host');
}

(async () => {
    const browser = await chromium.launch({headless: true, args: ['--no-sandbox', '--disable-web-security']});
    try {
        for (const mobile of [false, true]) {
            const options = mobile ? {...devices['iPhone 13'], viewport: {width: 390, height: 844}} : {viewport: {width: 1440, height: 1000}};
            const context = await browser.newContext(options);
            const page = await context.newPage();
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
            await page.waitForFunction(() => document.querySelector('[data-el-result-status]') && document.querySelector('[data-el-results]').getAttribute('aria-busy') === 'false');
            const dialog = page.locator('[data-el-search]');
            assert.equal(await dialog.evaluate(el => el.classList.contains('el-search--discovery')), true);
            const suggestions = page.locator('.el-search__popular [data-el-suggestion]:visible');
            if (mobile) {
                assert.equal(await suggestions.count(), 5, 'Mobile discovery should show five suggestions');
                assert.equal(await page.locator('.el-search__listing--discovery-with-suggestions').isVisible(), false);
                const selected = await suggestions.first().getAttribute('data-el-suggestion');
                await suggestions.first().click();
                await page.waitForFunction(() => !document.querySelector('[data-el-search]').classList.contains('el-search--discovery') && document.querySelector('[data-el-results]').getAttribute('aria-busy') === 'false');
                assert.equal(await page.locator('[data-el-query]').inputValue(), selected);
                assert.equal(await page.locator('.el-search__listing').isVisible(), true);
                assert.equal(await page.locator('[data-el-show-all]').isVisible(), true, 'A mobile search starts with a preview');
            } else {
                assert.equal(await suggestions.count(), 10, 'Desktop discovery should show all suggestions');
                assert.equal(await dialog.evaluate(el => el.getBoundingClientRect().height < innerHeight), true);
                const grid = page.locator('[data-el-carousel-grid]');
                assert.equal(await grid.evaluate(el => el.scrollWidth > el.clientWidth), true);
                await page.locator('[data-el-carousel="1"]').click();
                await page.waitForFunction(() => document.querySelector('[data-el-carousel-grid]').scrollLeft > 0);
                await page.mouse.click(100, 900);
                await page.waitForFunction(() => !document.querySelector('[data-el-search]').open);
            }
            await context.close();
        }
        console.log('Discovery checks passed: compact desktop, carousel, backdrop, mobile suggestions and search transition.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error.message); process.exitCode = 1; });
